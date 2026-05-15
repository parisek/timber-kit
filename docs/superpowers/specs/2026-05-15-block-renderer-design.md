# Design — `BlockRenderer` class

**Date**: 2026-05-15
**Status**: Design (decisions locked; ready for implementation plan)
**Supersedes**: [`docs/superpowers/specs/2026-05-15-block-renderer-roadmap.md`](./2026-05-15-block-renderer-roadmap.md) (the Open Questions section is closed by this document)
**Predecessor PR**: [`portadesign/wordpress-base` PR #27](https://github.com/portadesign/wordpress-base/pull/27) — landed the `WP_Block_Type::$example` identity-check discriminator
**Audience**: timber-kit maintainers + reviewers of the implementation PR

---

## Summary

Move `timber_block_render_callback()` (~110 lines of orchestration carried verbatim in ~109 WordPress themes) from per-theme `functions.php` into `parisek/timber-kit` as `Parisek\TimberKit\BlockRenderer`. The package gains its first own Twig template (`src/templates/empty-alert.twig`), registered under a `@timber-kit/` namespace. Extensibility is exposed exclusively through a small set of WordPress filters; the class stays `final`, no inheritance, no DI, no interfaces for hypothetical use cases.

---

## Decisions (resolved Open Questions)

| # | Question | Decision | Rationale |
|---|----------|----------|-----------|
| 1 | Extensibility hooks | **WordPress filters as the sole channel.** `final class`, no static registries, no inheritance. | WP-native idiom, no API breaking changes when filters evolve, downstream doesn't need to know internals. |
| 2 | `Helpers::formatFields()` coupling | **Direct call. No `BlockDataLoader` interface.** | YAGNI — only ACF blocks exist today. If a second data source appears, add an additive `?BlockDataLoader $loader = null` parameter in a minor version. |
| 3 | Empty-render alert | **Twig template in package** (`src/templates/empty-alert.twig`) using **WordPress native `.block-editor-warning` classes**. Translation domain `'timber-kit'`. Filter `timber_kit/block_renderer/empty_alert_html` for full override. | Package owns what it renders. Native classes mean zero package CSS while looking like a first-class Gutenberg warning in the editor. |
| 4 | Cache backend abstraction | **Keep existing detection** (`wp_using_ext_object_cache()` + `wp_cache_supports('flush_group')`). Document Redis as recommended. | The detection logic already lives in the function being migrated. No need for a new abstraction layer with one implementation. |
| 5 | Cache key composition | **Private method + filter** `timber_kit/block_renderer/cache_key`. | Internal detail stays free to evolve; customization through filter (consistent with Decision #1). |

---

## Target architecture

```
parisek/timber-kit/
├── src/
│   ├── DevMediaProxy.php          (existing)
│   ├── Helpers.php                (existing)
│   ├── Resizer.php                (existing)
│   ├── StarterBase.php            (existing — adds @timber-kit/ namespace registration)
│   ├── WPFormsConfigBridge.php    (existing)
│   ├── BlockRenderer.php          (NEW)
│   └── templates/                 (NEW)
│       └── empty-alert.twig
├── tests/
│   └── Unit/
│       ├── Helpers/               (existing)
│       ├── StarterBase/           (existing)
│       ├── Resizer/               (existing)
│       ├── DevMediaProxy/         (existing)
│       ├── WPFormsConfigBridge/   (existing)
│       └── BlockRenderer/         (NEW)
│           ├── IsInserterPreviewTest.php
│           ├── RenderTest.php
│           └── Fixtures.php       (if reuse warrants)
├── composer.json
└── phpunit.xml
```

`.gitattributes` already ships everything under `src/` — templates ride along automatically, no `composer.json` change needed.

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
     * Or via the backwards-compat wrapper in downstream themes (see § BC wrapper).
     */
    public static function render(
        array $attributes,
        string $content = '',
        bool $is_preview = false,
        int $post_id = 0,
        ?\WP_Block $wp_block = null
    ): void;

    /**
     * Pure discriminator — public so tests + downstream customisations can verify
     * "is this an inserter-library example render?" in isolation. No side effects.
     */
    public static function isInserterPreview(
        array $attributes,
        bool $is_preview,
        ?\WP_Block $wp_block
    ): bool;
}
```

### Twig namespace registration

`StarterBase::__construct()` (or a dedicated boot hook called from it) registers the `@timber-kit/` Twig namespace at priority 5 (so downstream themes at default priority 10 can override individual templates by registering their own path under the same namespace later).

```php
// In StarterBase boot (StarterBase.php lives in src/, so __DIR__ resolves to src/)
add_filter('timber/locations', static function (array $paths): array {
    $paths['timber-kit'] = [__DIR__ . '/templates'];
    return $paths;
}, 5);
```

### Defensive fallback in `BlockRenderer::render()`

If `BlockRenderer::render()` is invoked outside a context where the namespace was registered (e.g. a project that bypasses `StarterBase`), the empty-alert path falls back to inline HTML so the package never throws on a missing template:

```php
private static function renderEmptyAlert(string $block_name): string
{
    $message = __(
        'Pro zobrazení vyplňte požadované údaje v pravém panelu.',
        'timber-kit'
    );

    if (\class_exists(\Timber\Timber::class)) {
        $compiled = \Timber\Timber::compile('@timber-kit/empty-alert.twig', [
            'block_name' => $block_name,
            'message'    => $message,
        ]);

        if (\is_string($compiled) && '' !== $compiled) {
            return $compiled;
        }
    }

    // Inline fallback — preserves the same DOM contract.
    return \sprintf(
        '<div class="block-editor-warning timber-kit-block-empty" data-block="%s">'
        . '<div class="block-editor-warning__contents">'
        . '<p class="block-editor-warning__message">%s</p>'
        . '</div></div>',
        \esc_attr($block_name),
        \esc_html($message)
    );
}
```

The inline fallback intentionally matches the Twig template's DOM so theme CSS targeting `.timber-kit-block-empty` works identically in both paths.

### Backwards-compat wrapper in `portadesign/wordpress-base`

```php
// starter_theme/functions.php — preserves block.json references to the old function name.
function timber_block_render_callback( ...$args ): void {
    \Parisek\TimberKit\BlockRenderer::render( ...$args );
}
```

New `block.json` files can target `Parisek\\TimberKit\\BlockRenderer::render` directly; existing ones keep working unchanged.

---

## Render flow

```
1. Discriminator    → BlockRenderer::isInserterPreview() [pure, no side effects]
2. Schema resolve   → ACF block.json → Twig template path
3. Cache lookup     → preview memo (in-request) + Redis (per-block, key = filter-able md5)
4. Data hydration   → Helpers::formatFields($post_id, $is_preview)
5. Filters          → block_<name>_content (gated on discriminator)
                      block_<name>_template (always)
6. Render           → Timber::compile($template, $context) [context passes through context filter]
7. Side-effects     → form-plugin asset enqueue tracking; if any → skip cache write
                      empty render + logged-in user → renderEmptyAlert() via empty_alert_html filter
```

Step 1 (discriminator) is the only step exposed as a separate public method because it's pure — no WP calls, no I/O — and downstream code may want to ask the same question independently.

---

## Extensibility surface (WordPress filters)

These are the **only** customization channels. Every filter has a default that ships out-of-box.

```php
/**
 * Override the cache key composition.
 * Default = md5(wp_json_encode($cache_data)).
 */
apply_filters(
    'timber_kit/block_renderer/cache_key',
    string $key,
    array $cache_data,
    string $block_name
);

/**
 * Disable cache for a specific block at runtime.
 * Default = true (use cache when available).
 */
apply_filters(
    'timber_kit/block_renderer/use_cache',
    bool $enabled,
    string $block_name,
    array $attributes
);

/**
 * Override the empty-render alert HTML entirely. Theme can return its own
 * Twig template render (e.g. compile('@component/alert/alert.twig', ...))
 * without modifying the package.
 *
 * Default = compiled @timber-kit/empty-alert.twig (or inline fallback).
 */
apply_filters(
    'timber_kit/block_renderer/empty_alert_html',
    string $html,
    string $block_name,
    array $attributes
);

/**
 * Pre-compile Twig context modification. Last chance for downstream code
 * to inject or override context values before Timber::compile() runs.
 */
apply_filters(
    'timber_kit/block_renderer/context',
    array $context,
    string $block_name,
    bool $is_preview
);
```

Four filters cover all realistic customization. A fifth (e.g. pre-render lifecycle hook) is added only when a real use case appears.

---

## Empty-alert template contract

`src/templates/empty-alert.twig`:

```twig
{#
  Empty-block warning — rendered when BlockRenderer::render() produces empty
  output for a logged-in user.

  Uses WordPress Gutenberg's `.block-editor-warning` classes so the editor
  styles it natively without any package-shipped CSS. On the frontend
  (logged-in admin viewing live site) the WP styles aren't loaded, so it
  degrades to bare HTML — themes can style `.timber-kit-block-empty` to
  taste, or override the entire HTML via the empty_alert_html filter.
#}
<div class="block-editor-warning timber-kit-block-empty"
     data-block="{{ block_name }}">
  <div class="block-editor-warning__contents">
    <p class="block-editor-warning__message">{{ message }}</p>
  </div>
</div>
```

**Stable contract (semver-protected):**

- `.timber-kit-block-empty` — primary CSS hook for theme styling
- `data-block` — block name for advanced selector / diagnostic use
- `.block-editor-warning` family — Gutenberg-internal classes; documented as "best-effort native editor styling, may need theme fallback if Gutenberg renames them"

Themes that want guaranteed visual styling regardless of Gutenberg internals should override the entire HTML via `timber_kit/block_renderer/empty_alert_html`.

---

## Testing strategy

### Pure unit — `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

No WP, no ACF, no Timber. PHPUnit + `stdClass` mocks for `WP_Block`. Six cases mirroring the discriminator matrix from PR #27:

```php
test_returns_true_when_data_matches_registered_example()
test_returns_false_when_wp_block_is_null()
test_returns_false_when_example_not_registered()
test_returns_false_when_is_preview_is_false()
test_returns_false_when_data_differs_from_example()
test_handles_acf_serialised_data_with_field_refs()
```

### Brain Monkey integration — `tests/Unit/BlockRenderer/RenderTest.php`

Follows the existing `tests/Unit/Helpers/FieldFormatterTest.php` pattern:

```php
test_inserter_preview_skips_content_filter()
test_editor_canvas_runs_filter_with_saved_data()
test_preview_memo_cache_hit_short_circuits()
test_redis_cache_skips_for_dynamic_filter_blocks()
test_side_effecting_block_excluded_from_cache()
test_empty_render_shows_alert_for_logged_in_users()
test_inserter_preview_wrapped_in_16_9_aspect_ratio()
test_template_filter_runs_even_in_inserter_preview()
test_cache_key_filter_can_override_default()
test_empty_alert_html_filter_replaces_default_output()
test_inline_fallback_used_when_timber_namespace_missing()
```

~11 cases covering major branches + each filter's override path + the defensive fallback.

### End-to-end (manual, downstream)

Out of scope for automated testing in this package. Post-merge smoke test in a downstream project (e.g. `proficio-de`), validating:

- Inserter library hover preview shows example data
- Editor canvas renders with saved data
- Frontend renders identically to editor
- Empty block in editor shows the WP-native warning
- Theme filter override (replacing `empty_alert_html`) takes effect

---

## Phasing

**Option A — Code + tests in a single PR.** Selected (per the predecessor roadmap's recommendation; reaffirmed here).

Single PR in `parisek/timber-kit`:

1. `src/BlockRenderer.php` with `render()` + `isInserterPreview()`
2. `src/templates/empty-alert.twig`
3. `src/StarterBase.php` — add `@timber-kit/` namespace registration
4. `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` (~6 cases)
5. `tests/Unit/BlockRenderer/RenderTest.php` (~11 cases)
6. `CHANGELOG.md` entry under `## [Unreleased]` — additive, no breaking changes
7. Tag `v1.5.0` after green CI via the existing Stamp Release workflow

Companion PR in `portadesign/wordpress-base`:

8. Bump composer constraint to `^1.5`
9. Replace function body in `starter_theme/functions.php` with the wrapper
10. Open after `timber-kit` release is tagged

---

## Migration guide (for downstream WordPress projects)

After the `timber-kit` release and the `wordpress-base` skeleton update:

```bash
composer update parisek/timber-kit
```

That's it for projects that use `timber_block_render_callback` and the standard alert behaviour. For projects that want to keep their own Tailwind-styled alert:

```php
// In theme functions.php
add_filter(
    'timber_kit/block_renderer/empty_alert_html',
    static function (string $default, string $block_name, array $attributes): string {
        return \Timber\Timber::compile('@component/alert/alert.twig', [
            'message'  => __('Pro zobrazení vyplňte požadované údaje v pravém panelu.', 'starter_theme'),
            'severity' => 'info',
        ]);
    },
    10,
    3
);
```

The dependency direction is now correct: theme calls its own template through a filter; the package never reaches into theme territory.

---

## Out of scope

- **Refactoring the per-block PHP filter convention** (`block_<name>_content` / `block_<name>_template`). Acknowledged tech debt; separate design space.
- **Static analysis tooling** ("which blocks register an `example`?"). Belongs in the downstream `twig-cs-fixer` layer.
- **Block-level performance instrumentation** (render-time logging, slow-block detection). Future feature, not required for the move.
- **Documenting the `block_<name>_content` filter author contract**. Lives in `portadesign/tailwind-base` rules; this package owns only the renderer's runtime behaviour.
- **Generic UI components in Twig** (button, card, modal). Out of scope and out of philosophy — see scope rule below.

---

## Scope rule for future templates in this package

A Twig template belongs in `parisek/timber-kit` if and only if:

1. The package **renders it itself** from PHP (not via downstream theme code), AND
2. The behaviour is **consistent across all projects** that use the package, AND
3. A **filter override** exists so themes can fully replace the output.

Negative test: if a theme would reasonably call the template directly from `single.twig` or `page.twig`, it doesn't belong in this package.

Realistic future candidates that pass this test: `block-error.twig` (render boundary for thrown exceptions), `dev-mode-badge.twig` (development-only indicator), `comments-disabled.twig` (notice rendered by `WPFormsConfigBridge`).

Things that don't pass: any generic UI component (buttons, cards, modals), any theme-level layout (`header.twig`, `footer.twig`), any block-specific template.

---

## Next steps

1. **User reviews this design.** Comments → revise inline → re-review.
2. **Write implementation plan.** Step-by-step plan via `superpowers:writing-plans`, broken into milestones (template + namespace registration → core render → cache layer → side-effects + alert → tests → CHANGELOG).
3. **Open implementation PR** in `parisek/timber-kit` per Option A.
4. **Open companion PR** in `portadesign/wordpress-base` (wrapper + composer bump) after `v1.5.0` is tagged.
5. **Canary rollout** — pick one downstream project (e.g. `proficio-de`) to validate the full upgrade flow before broad rollout across ~109 projects.

---

## References

- [`portadesign/wordpress-base` PR #27 — discriminator refactor (predecessor)](https://github.com/portadesign/wordpress-base/pull/27)
- [`portadesign/wordpress-base` PR #28 — initial roadmap draft (will be closed by the implementation PR)](https://github.com/portadesign/wordpress-base/pull/28)
- [`./2026-05-15-block-renderer-roadmap.md`](./2026-05-15-block-renderer-roadmap.md) — the roadmap document this design supersedes
- [`portadesign/tailwind-base` `.claude/rules/wordpress/gutenberg.md`](https://github.com/portadesign/tailwind-base/blob/main/.claude/rules/wordpress/gutenberg.md) — § Filters Don't Run in Inserter Preview, the consumer-side doctrine the renderer enforces
- WordPress `block-editor-warning` component reference: rendered by [`@wordpress/block-editor`](https://github.com/WordPress/gutenberg/blob/trunk/packages/block-editor/src/components/warning/index.js) since Gutenberg 5.x
