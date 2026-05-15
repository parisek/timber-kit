# Design — `BlockRenderer` class

**Date**: 2026-05-15
**Status**: Revised (source audit complete; decisions locked; ready for implementation)
**Revision history**:
- v1 (2026-05-15 commit 99d1776): initial design based on roadmap claims about PR #27 behavior
- v2 (2026-05-15, this revision): source audit revealed roadmap diverged from actual `timber_block_render_callback()` behavior on six points (discriminator mechanism, cache group naming, side-effect detection mechanism, has_filter check, cache_key composition, empty alert payload). This revision aligns the spec with the actual function so the migration is a **drop-in upgrade** preserving 100 % of current behavior, while keeping the architectural improvements (Twig template in package, WP filter extensibility, package-native i18n) on top.

**Supersedes**: [`docs/superpowers/specs/2026-05-15-block-renderer-roadmap.md`](./2026-05-15-block-renderer-roadmap.md) (the Open Questions section is closed by this document)
**Source of truth for behavior parity**: `portadesign/wordpress-base` branch `feat/move-block-renderer-to-timber-kit` → `wp-content/themes/starter_theme/functions.php:84` (function `timber_block_render_callback()`).
**Predecessor PR**: [`portadesign/wordpress-base` PR #27](https://github.com/portadesign/wordpress-base/pull/27) — added the `$is_inserter_preview` empirical discriminator and the inserter-preview content-fallback mechanism.
**Audience**: timber-kit maintainers + reviewers of the implementation PR

---

## Summary

Move `timber_block_render_callback()` (~140 lines of orchestration carried verbatim in ~109 WordPress themes) from per-theme `functions.php` into `parisek/timber-kit` as `Parisek\TimberKit\BlockRenderer`. **The class is a faithful port of current behavior** — same cache keys, same cache groups, same invalidation hook, same side-effect detection mechanism, same real-post-ID resolution. On top of the faithful port we add four architectural improvements: (1) Twig template owned by the package for the empty-block alert, (2) four WordPress filters for fine-grained extensibility, (3) package-native translation domain, (4) completion of the content-filter gating that PR #27 designed but didn't actually implement.

---

## Decisions (resolved Open Questions + audit corrections)

| # | Question | Decision | Rationale |
|---|----------|----------|-----------|
| 1 | Extensibility hooks | **WordPress filters as sole channel.** `final class`, no static registries, no inheritance. | WP-native idiom, no API breaking changes when filters evolve. |
| 2 | `Helpers::formatFields()` coupling | **Direct call.** No `BlockDataLoader` interface (YAGNI). | Only ACF blocks exist today. |
| 3 | Empty-render alert | **Twig template in package** (`src/templates/empty-alert.twig`) using **WP-native `.block-editor-warning` classes**. Translation domain `'timber-kit'`. Filter `empty_alert_html` for full override. Context includes **`block_label`** (= `$attributes['title']` ?? `$attributes['name']`) for theme overrides. | Package owns what it renders. Native classes mean zero package CSS while looking like a Gutenberg warning in the editor. |
| 4 | Cache backend | **Faithful port of current detection**: `wp_using_ext_object_cache()` + `wp_cache_supports('flush_group')`. **`has_filter('block_<name>_content')` check skips Redis cache for dynamic blocks** (faithful — current function does this). | The detection logic already works correctly. |
| 5 | Cache key composition | **Faithful port**: `'acf_block_' . md5(wp_json_encode($cache_data))` where `$cache_data` has all 7 fields (`name`, `data`, `anchor`, `className`, `post_id`, `lang`, `paged`). **Cache group**: `'acf_block_' . $real_post_id` (per-post). Filter `cache_key` for override. | Drop-in cache compatibility; existing invalidation hook keeps working. |
| 6 *(audit)* | Discriminator mechanism | **Empirical, not identity-check.** `$is_inserter_preview = $is_preview && empty($content_from_formatFields) && !empty($attributes['data']) && is_array($attributes['data'])`. The earlier "identity-check against `WP_Block_Type::$example`" wording came from PR #27's design doc; PR #27 actually shipped the empirical check. Spec aligns with reality. | Robust across blocks that don't register an `example`; matches deployed behavior. |
| 7 *(audit)* | Side-effect detection | **Faithful port**: `wp_scripts()->queue` + `wp_styles()->queue` snapshot before render, `array_diff` after. | Universal across form plugins (CF7, WPForms, Gravity, …); no hardcoded filter list. |
| 8 *(audit)* | Content-filter gating | **NEW behavior**: gate `block_<name>_content` filter on `$is_inserter_preview`. When true, skip the filter. PR #27 was supposed to do this but didn't — we complete the work as part of the migration. | Prevents `block_<name>_content` filters from enriching fake example data with derived values that would distort inserter-library thumbnails. Documented as consumer-side doctrine in `portadesign/tailwind-base` rules. |
| 9 *(audit)* | Cache invalidation | **Migrate the per-post `acf/save_post` flush hook into the package** as `BlockRenderer::registerInvalidation()`, called from `StarterBase`. | Hook depends on cache group naming; co-locating with the renderer prevents drift between cache writer and invalidator. |

---

## Target architecture

```
parisek/timber-kit/
├── src/
│   ├── DevMediaProxy.php          (existing)
│   ├── Helpers.php                (existing)
│   ├── Resizer.php                (existing)
│   ├── StarterBase.php            (existing — adds @timber-kit/ namespace + BlockRenderer invalidation)
│   ├── WPFormsConfigBridge.php    (existing)
│   ├── BlockRenderer.php          (NEW)
│   └── templates/                 (NEW)
│       └── empty-alert.twig
├── tests/
│   └── Unit/
│       ├── (existing test dirs)   (unchanged)
│       └── BlockRenderer/         (NEW)
│           ├── IsInserterPreviewTest.php
│           ├── RenderTest.php
│           └── Fixtures.php
├── composer.json
└── phpunit.xml
```

### Public API

```php
namespace Parisek\TimberKit;

final class BlockRenderer
{
    /**
     * Render callback for ACF Gutenberg blocks defined via block.json.
     *
     * Wire as:
     *   "acf": { "renderCallback": "Parisek\\TimberKit\\BlockRenderer::render" }
     *
     * Or via the backwards-compat wrapper in downstream themes.
     */
    public static function render(
        array $attributes,
        string $content = '',
        bool $is_preview = false,
        int|string $post_id = 0,
        ?\WP_Block $wp_block = null
    ): void;

    /**
     * Empirical inserter-preview detector. Public for tests + downstream
     * introspection. Pure: no I/O, no WP side effects.
     *
     * Returns true when the block is being rendered for the inserter library,
     * detected by: preview mode AND ACF returned no fields for the resolved
     * post AND attributes carry an example data payload.
     */
    public static function isInserterPreview(
        bool $is_preview,
        array $formatted_fields,
        array $attributes
    ): bool;

    /**
     * Register the per-post cache invalidation hook (acf/save_post → flush
     * cache group "acf_block_$post_id"). Called from StarterBase boot.
     */
    public static function registerInvalidation(): void;
}
```

### Twig namespace registration

`StarterBase::__construct()` registers the `@timber-kit/` namespace and calls `BlockRenderer::registerInvalidation()`:

```php
// In StarterBase::__construct(), near the other timber/* filter registrations
add_filter( 'timber/locations', [ $this, 'register_timber_kit_namespace' ], 5 );

// And in a separate WP boot step
\Parisek\TimberKit\BlockRenderer::registerInvalidation();
```

```php
// StarterBase method
public function register_timber_kit_namespace( array $paths ): array {
    $paths['timber-kit'] = [ __DIR__ . '/templates' ];
    return $paths;
}
```

Priority 5 (vs default 10) so downstream themes registering at default priority can override individual templates.

---

## Render flow (matches `timber_block_render_callback()` exactly)

The numbered steps below mirror the source function block-for-block, with our additions called out.

1. **Slug + filter name derivation**
   - `$slug = str_replace('acf/', '', $attributes['name'])`
   - Filter base: `$filter_base = 'block_' . str_replace('-', '_', $slug)` → used for `_content` and `_template` filter names.

2. **Real post ID resolution** (for cache group)
   - Priority: callback `$post_id` if numeric > 0 → `acf_get_valid_post_id()` → if result starts with `block_`, fall back to `global $post->ID`.

3. **Dynamic filter detection**
   - `$has_dynamic_filter = has_filter("{$filter_base}_content")` — gates frontend Redis cache.

4. **Cache key composition** (`cache_key` filter override available)
   - `$cache_data = ['name' => $attributes['name'], 'data' => $attributes['data'] ?? [], 'anchor' => $attributes['anchor'] ?? '', 'className' => $attributes['className'] ?? '', 'post_id' => $post_id, 'lang' => apply_filters('wpml_current_language', ''), 'paged' => get_query_var('paged', 0)]`
   - `$default_key = 'acf_block_' . md5(wp_json_encode($cache_data))`
   - `$cache_key = apply_filters('timber_kit/block_renderer/cache_key', $default_key, $cache_data, $block_name)`

5. **Cache lookup**
   - Preview path: in-request memo (static array, keyed by `$cache_key`). Hit → print + return.
   - Frontend path: when `!$has_dynamic_filter && wp_using_ext_object_cache() && wp_cache_supports('flush_group')` (or filter `use_cache` returns true), check `wp_cache_get($cache_key, "acf_block_$real_post_id")`. Hit → print + return.

6. **Side-effect snapshot (pre-render)**
   - `$scripts_before = wp_scripts()->queue`
   - `$styles_before = wp_styles()->queue`

7. **Data hydration + inserter-preview content fallback**
   - `$content = Helpers::formatFields($post_id, $is_preview)`
   - **Discriminator** (matches the function exactly):
     ```php
     $is_inserter_preview = false;
     if ($is_preview && empty($content) && !empty($attributes['data']) && is_array($attributes['data'])) {
         $content = array_filter(
             $attributes['data'],
             static fn($k) => is_string($k) && $k !== '' && $k[0] !== '_',
             ARRAY_FILTER_USE_KEY
         );
         $is_inserter_preview = true;
     }
     ```
   - Append context flags to `$content`: `is_preview`, `wrapper_id` (from `$attributes['anchor']`), `wrapper_classes` (from `$attributes['className']`).

8. **Content filter (NEW: gated on discriminator)** — **this is the only behavior change vs. the current function**:
   - If `$is_inserter_preview` → **skip** `block_<slug>_content` filter (PR #27 aspiration, now actually implemented).
   - Otherwise → `$content = apply_filters("{$filter_base}_content", $content)`.

9. **Template filter dispatch (always)**
   - Default: `$template_path = '@component/' . $slug . '/' . $slug . '.twig'`
   - `$template_path = apply_filters("{$filter_base}_template", $template_path, $content)`

10. **Context assembly + `context` filter + Timber compile**
    - `$context = Timber::context()`
    - `$context['content'] = $content`
    - `$context = apply_filters('timber_kit/block_renderer/context', $context, $block_name, $is_preview)`
    - `$template = Timber::compile($template_path, $context)`

11. **Empty render → editor alert (Twig + filter override)**
    - When `empty($template) && is_user_logged_in()`:
      - `$block_label = $attributes['title'] ?? $attributes['name']`
      - Default HTML: `Timber::compile('@timber-kit/empty-alert.twig', ['block_name' => ..., 'block_label' => ..., 'message' => __('Pro zobrazení vyplňte požadované údaje v pravém panelu.', 'timber-kit')])`
      - Fallback (no Timber namespace): inline HTML preserving same DOM contract (`.block-editor-warning` + `.timber-kit-block-empty` + `data-block`).
      - `$template = apply_filters('timber_kit/block_renderer/empty_alert_html', $template, $block_name, $attributes)`

12. **Inserter-preview aspect-ratio wrap**
    - `if ($is_inserter_preview && !empty($template)) { $template = '<div style="aspect-ratio: 16/9; overflow: hidden;">' . $template . '</div>'; }`

13. **Side-effect detection (post-render)**
    - `$has_side_effects = array_diff(wp_scripts()->queue, $scripts_before) || array_diff(wp_styles()->queue, $styles_before)`

14. **Cache write (with all guards)**
    - When `!empty($template)`:
      - Preview path: `$preview_memo[$cache_key] = $template` (in-request only).
      - Frontend path: when `!$has_dynamic_filter && !$has_side_effects && wp_using_ext_object_cache() && wp_cache_supports('flush_group')`, `wp_cache_set($cache_key, $template, "acf_block_$real_post_id", HOUR_IN_SECONDS)`.

15. **Print output**
    - `print $template;` (matches `print` in original function — semantically equivalent to `echo`).

### Cache invalidation hook (`BlockRenderer::registerInvalidation()`)

```php
public static function registerInvalidation(): void
{
    add_action('acf/save_post', static function ($post_id): void {
        if (is_numeric($post_id) && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
            && function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group('acf_block_' . $post_id);
        }
    }, 20);
}
```

Priority 20 matches the original function's hook.

---

## Extensibility surface (4 WordPress filters)

Layered on top of the faithful port — none of these change default behavior, they only enable customization.

```php
/**
 * Override the cache key composition.
 * Default: 'acf_block_' . md5(wp_json_encode($cache_data))
 */
apply_filters('timber_kit/block_renderer/cache_key', string $key, array $cache_data, string $block_name);

/**
 * Override the cache-enabled decision. Default is the faithful port's gate:
 * !$has_dynamic_filter && wp_using_ext_object_cache() && wp_cache_supports('flush_group').
 */
apply_filters('timber_kit/block_renderer/use_cache', bool $enabled, string $block_name, array $attributes);

/**
 * Override the empty-render alert HTML entirely (theme can return its own
 * Twig template result, e.g. compile('@component/alert/alert.twig', …)).
 * Default: compiled @timber-kit/empty-alert.twig with block_name / block_label / message.
 */
apply_filters('timber_kit/block_renderer/empty_alert_html', string $html, string $block_name, array $attributes);

/**
 * Pre-compile Twig context modification. Last chance to inject or override
 * context values before Timber::compile() runs.
 */
apply_filters('timber_kit/block_renderer/context', array $context, string $block_name, bool $is_preview);
```

---

## Empty-alert template contract

`src/templates/empty-alert.twig`:

```twig
{#
  Empty-block warning. Uses Gutenberg's `.block-editor-warning` classes for
  native editor styling. Frontend (logged-in admin) degrades to bare HTML —
  themes can style `.timber-kit-block-empty` or override the whole HTML via
  the empty_alert_html filter.

  Stable contract (semver-protected):
    - .timber-kit-block-empty class
    - data-block attribute

  Best-effort (Gutenberg internals, filter-overridable if WP renames):
    - .block-editor-warning, .block-editor-warning__contents, .block-editor-warning__message
#}
<div class="block-editor-warning timber-kit-block-empty" data-block="{{ block_name }}">
	<div class="block-editor-warning__contents">
		<p class="block-editor-warning__message">
			{% if block_label %}<strong>{{ block_label }}:</strong> {% endif %}{{ message }}
		</p>
	</div>
</div>
```

The `block_label` prefix is rendered only when provided — preserves the original function's UX where the alert reads e.g. *"Article — Featured: Pro zobrazení vyplňte…"*.

---

## Backwards-compat wrapper in `portadesign/wordpress-base`

```php
// starter_theme/functions.php — preserves block.json references to the old function name.
function timber_block_render_callback( ...$args ): void {
    \Parisek\TimberKit\BlockRenderer::render( ...$args );
}

// The acf/save_post invalidation hook can be removed — BlockRenderer::registerInvalidation()
// (called from StarterBase boot) takes over.
```

New `block.json` files can target `Parisek\\TimberKit\\BlockRenderer::render` directly; existing ones keep working unchanged.

---

## Testing strategy

### `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` (pure unit, 5 cases)

```php
test_returns_true_when_preview_and_empty_fields_and_has_data()
test_returns_false_when_not_preview()
test_returns_false_when_fields_non_empty()
test_returns_false_when_attributes_data_missing()
test_returns_false_when_attributes_data_not_array()
```

### `tests/Unit/BlockRenderer/RenderTest.php` (Brain Monkey integration, ~14 cases)

Each test wires Brain Monkey mocks for the WP functions touched by that branch.

```php
test_inserter_preview_skips_content_filter()                 // NEW behavior (gating)
test_editor_canvas_with_saved_data_runs_content_filter()
test_template_filter_runs_in_all_modes()
test_cache_key_includes_all_seven_fields()
test_cache_key_filter_can_override_default()
test_preview_memo_cache_hit_short_circuits()
test_frontend_cache_skipped_when_block_has_dynamic_filter()
test_frontend_cache_used_when_no_dynamic_filter_and_redis_available()
test_use_cache_filter_can_disable_per_block()
test_side_effecting_block_excluded_from_cache()
test_empty_template_renders_alert_for_logged_in_users()
test_empty_alert_html_filter_replaces_default_output()
test_inserter_preview_wraps_in_16_9_aspect_ratio()
test_real_post_id_resolution_falls_back_to_global_post()
```

### End-to-end (manual, downstream)

Out of scope for automated testing. Post-merge smoke test in `proficio-de`:
- Inserter library hover preview renders example data (no content filter side effects)
- Editor canvas with saved data triggers content filter
- Frontend renders identically to editor
- Cache invalidation: edit + save post → cached blocks for that post regenerated; other posts' caches untouched
- Empty block in editor shows the WP-native warning with block label prefix
- Filter override (replacing `empty_alert_html`) works

---

## Phasing

**Option A — Code + tests in one PR**, as before. Single PR in `parisek/timber-kit`:

1. `src/BlockRenderer.php`
2. `src/templates/empty-alert.twig`
3. `src/StarterBase.php` — namespace registration + `BlockRenderer::registerInvalidation()` boot call
4. `tests/Unit/BlockRenderer/{IsInserterPreviewTest,RenderTest,Fixtures}.php`
5. `CHANGELOG.md` entry
6. Tag `v1.5.0` after green CI

Companion PR in `portadesign/wordpress-base`:

7. Bump composer constraint to `^1.5`
8. Replace `timber_block_render_callback()` body with wrapper
9. Remove the standalone `acf/save_post` hook (BlockRenderer owns it now)

---

## Migration guide (downstream)

`composer update parisek/timber-kit` after the release.

Projects that customized via PHP filters (`block_<name>_content`, `block_<name>_template`) keep working unchanged — the package preserves the exact filter names and signatures.

Projects that want their existing `@component/alert/alert.twig` for the empty-render warning:

```php
add_filter(
    'timber_kit/block_renderer/empty_alert_html',
    static function (string $default, string $block_name, array $attributes): string {
        $block_label = $attributes['title'] ?? $attributes['name'];
        return Timber::compile('@component/alert/alert.twig', [
            'content' => [
                'message'   => '<strong>' . esc_html($block_label) . ':</strong> ' .
                               esc_html(__('Pro zobrazení vyplňte požadované údaje v pravém panelu.', 'starter_theme')),
                'type'      => 'warning',
                'container' => 'container',
            ],
        ]);
    },
    10,
    3
);
```

---

## Out of scope

- Refactoring the per-block PHP filter convention. Acknowledged tech debt.
- Static analysis tooling ("which blocks register an `example`?").
- Block-level performance instrumentation.
- Generic UI components in Twig.

---

## Scope rule for future templates in this package

A Twig template belongs in `parisek/timber-kit` if and only if:

1. The package **renders it itself** from PHP (not via downstream theme code), AND
2. The behaviour is **consistent across all projects**, AND
3. A **filter override** exists so themes can fully replace the output.

---

## References

- Source-of-truth function: `portadesign/wordpress-base` → `wp-content/themes/starter_theme/functions.php:84`
- [`portadesign/wordpress-base` PR #27](https://github.com/portadesign/wordpress-base/pull/27) — introduced empirical `$is_inserter_preview` discriminator
- [Original roadmap](./2026-05-15-block-renderer-roadmap.md) (superseded)
- WordPress `block-editor-warning` component: rendered by [`@wordpress/block-editor`](https://github.com/WordPress/gutenberg/blob/trunk/packages/block-editor/src/components/warning/index.js), stable since Gutenberg 5.x
