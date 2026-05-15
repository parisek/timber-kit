# Roadmap — Add `BlockRenderer` class

**Date**: 2026-05-15
**Status**: Draft for discussion (no implementation yet)
**Predecessor**: [`portadesign/wordpress-base` PR #27](https://github.com/portadesign/wordpress-base/pull/27) — landed the `WP_Block_Type::$example` identity-check discriminator in the in-theme `timber_block_render_callback()` function
**Sibling roadmap (will close)**: [`portadesign/wordpress-base` PR #28](https://github.com/portadesign/wordpress-base/pull/28) — initial roadmap draft, moved to this repo per workspace doctrine ("changes to a shared package belong in the package, not in a downstream consumer")
**Audience**: timber-kit maintainers reviewing the migration direction before opening the actual implementation PR(s)

---

## Why move it here

`timber_block_render_callback()` is ~110 lines of orchestration logic that every WordPress theme derived from `portadesign/wordpress-base` carries verbatim in `wp-content/themes/<theme>/functions.php`. It handles:

- ACF block schema → Twig template resolution
- Render-time cache (per-preview memo + Redis object cache for static blocks)
- Side-effect detection (form-plugin asset enqueue tracking; blocks with side-effects skip cache)
- Inserter-library preview discriminator via `WP_Block_Type::$example` identity check
- `block_<name>_content` filter dispatch (gated on the discriminator)
- `block_<name>_template` filter dispatch (always runs)
- Empty-render alert for logged-in editors

It's the natural third side of the triangle to existing public classes in this package:

- `Parisek\TimberKit\StarterBase` — theme bootstrap (registers menus, fonts, ACF JSON paths, runs `gutenberg_blocks()` autoloader, exposes `timber_context()` for downstream override)
- `Parisek\TimberKit\Helpers` — formatter funnel (`formatFields()`, `formatMenu()`, `formatLink()`, `formatTerms()`, `resizeImage()`, …)

Block rendering consumes both (`Helpers::formatFields()` for ACF hydration, `Timber` for compile), so co-locating the renderer here removes a cross-repo dependency on theme-level code that's actually package-level concern.

### Benefits

- **Centralised maintenance**: fix a bug once in `timber-kit`, all ~109 downstream WordPress projects get it via `composer update`. Today the same fix is duplicated across project `functions.php` files and propagated only on opportunistic skeleton-sync.
- **Semver-controlled**: Composer constraints (`^1.4`, `^2.0`) make changes opt-in per project. Breaking changes can ship in major-version bumps with explicit migration guidance.
- **Test coverage**: this package **already has PHPUnit 11 + Brain Monkey + PHPStan + the `tests/Unit/<Class>/` convention** with full coverage for `Helpers`, `StarterBase`, `Resizer`, `DevMediaProxy`, `WPFormsConfigBridge`. Adding `tests/Unit/BlockRenderer/` follows the established pattern — zero infrastructure investment, just add tests.
- **Smaller `starter_theme`**: downstream theme `functions.php` shrinks from ~210 lines to ~110 lines after the wrapper migration. Less skeleton drift surface.
- **Type-safe API**: package can expose typed interfaces (e.g. `BlockDataProvider`) for downstream extension that survive refactors.

### Costs / risks

- **Migration of all ~109 downstream WordPress projects**: `functions.php` change is small (delete function body, leave wrapper). Doable via the existing `ddev-sync`-style propagation in `portadesign/wordpress-base`.
- **Backwards-compat surface**: existing `block.json` files reference `"renderCallback": "timber_block_render_callback"`. A thin wrapper in `portadesign/wordpress-base`'s `starter_theme/functions.php` calling `\Parisek\TimberKit\BlockRenderer::render()` preserves the contract without per-project block.json rewrites.
- **Customisation extensibility**: some projects may want renderer behaviour overrides (different cache strategy, custom alert template). The package needs hooks/options/decorators to support that — TBD design (see § Open Questions).

---

## Target architecture

Follow existing package conventions:

```
parisek/timber-kit/
├── src/
│   ├── DevMediaProxy.php          (existing)
│   ├── Helpers.php                (existing)
│   ├── Resizer.php                (existing)
│   ├── StarterBase.php            (existing)
│   ├── WPFormsConfigBridge.php    (existing)
│   └── BlockRenderer.php          (NEW — host for the render callback)
├── tests/
│   └── Unit/
│       ├── Helpers/               (existing — pattern: one file per public method)
│       ├── StarterBase/           (existing)
│       ├── Resizer/               (existing)
│       ├── DevMediaProxy/         (existing)
│       ├── WPFormsConfigBridge/   (existing)
│       └── BlockRenderer/         (NEW — match pattern)
│           ├── IsInserterPreviewTest.php
│           └── RenderTest.php
├── composer.json
└── phpunit.xml                    (existing)
```

### Public API surface

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
     * Or via a wrapper function in the downstream theme for backwards compat
     * with block.json files that reference "timber_block_render_callback".
     */
    public static function render(
        array $attributes,
        string $content = '',
        bool $is_preview = false,
        int $post_id = 0,
        ?\WP_Block $wp_block = null
    ): void;

    /**
     * Pure discriminator — public so tests + downstream customisations can
     * verify "is this an inserter-library example render?" in isolation.
     * No side effects, no WP calls, no I/O.
     */
    public static function isInserterPreview(
        array $attributes,
        bool $is_preview,
        ?\WP_Block $wp_block
    ): bool;
}
```

### Backwards-compat wrapper in `portadesign/wordpress-base`

```php
// starter_theme/functions.php — preserves block.json references to the old function name.
function timber_block_render_callback( ...$args ): void {
    \Parisek\TimberKit\BlockRenderer::render( ...$args );
}
```

New block.json files can point at the namespaced callable directly; existing ones keep working.

---

## Testing strategy

### Tests for `BlockRenderer::isInserterPreview()` (pure unit)

`tests/Unit/BlockRenderer/IsInserterPreviewTest.php` — no WP, no ACF, no Timber. Just PHPUnit + a couple of stdClass mocks for `WP_Block`. Six representative cases covering the matrix from the predecessor design doc:

```php
class IsInserterPreviewTest extends TestCase
{
    public function test_returns_true_when_data_matches_registered_example(): void { … }
    public function test_returns_false_when_wp_block_is_null(): void { … }
    public function test_returns_false_when_example_not_registered(): void { … }
    public function test_returns_false_when_is_preview_is_false(): void { … }
    public function test_returns_false_when_data_differs_from_example(): void { … }
    public function test_handles_acf_serialised_data_with_field_refs(): void { … }
}
```

Each test is a few lines, runs in milliseconds. No mocking overhead.

### Tests for `BlockRenderer::render()` (Brain Monkey integration)

`tests/Unit/BlockRenderer/RenderTest.php` — Brain Monkey already in `require-dev`, follow `tests/Unit/Helpers/FieldFormatterTest.php` pattern.

```php
class RenderTest extends TestCase
{
    public function test_inserter_preview_skips_content_filter(): void
    {
        Filters\expectApplied('block_article_featured_content')->never();
        BlockRenderer::render(/* example attrs */, '', true, 0, $this->wp_block_with_example());
    }

    public function test_editor_canvas_runs_filter_with_saved_data(): void { … }
    public function test_preview_memo_cache_hit_short_circuits(): void { … }
    public function test_redis_cache_skips_for_dynamic_filter_blocks(): void { … }
    public function test_side_effecting_block_excluded_from_cache(): void { … }
    public function test_empty_render_shows_alert_for_logged_in_users(): void { … }
    public function test_inserter_preview_wrapped_in_16_9_aspect_ratio(): void { … }
    public function test_template_filter_runs_even_in_inserter_preview(): void { … }
}
```

~8 test cases covering the major branches. Most fixtures (WP_Block mocks, attributes shapes) can be extracted into `tests/Unit/BlockRenderer/Fixtures.php` if reuse warrants.

### End-to-end (manual, in downstream project)

Out of scope for automation in this package. Post-merge smoke matches the predecessor PR's 5-bullet plan: inserter library hover + editor canvas + frontend, validated against a real WP install with `Article — Featured` block as the canary, in a downstream WordPress project (e.g. `proficio-de`).

---

## Phasing — two rollout options

Existing test infrastructure makes Option B (tests with code) genuinely easy, so the three-option set from the predecessor roadmap collapses to two practical choices.

### Option A — Code + tests in one PR (recommended)

Single PR in this repo:

1. Add `src/BlockRenderer.php` with `render()` + `isInserterPreview()` static methods.
2. Add `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` (~6 cases) + `tests/Unit/BlockRenderer/RenderTest.php` (~8 cases).
3. Add `CHANGELOG.md` entry. Bump version to `^1.5.0` (additive, no breaking changes).
4. Tag release after green CI.

Then companion PR in `portadesign/wordpress-base`:

5. Bump composer constraint to `^1.5`.
6. Replace `functions.php` body with the wrapper function.

**Plus**: clean semver story (tested public API from launch). No "untested public API" gap. Reviewers see the full picture in one PR.
**Minus**: PR is larger (~400 lines including tests). Mitigated by `tests/Unit/BlockRenderer/` being a separate directory — reviewer can review code + tests as separate concerns.

### Option B — Code first, tests follow

1. PR in this repo: add `src/BlockRenderer.php`, no tests. Semver `1.5.0`.
2. Companion PR in `portadesign/wordpress-base`: wrapper + composer bump.
3. Follow-up PR in this repo: add `tests/Unit/BlockRenderer/`. Semver `1.5.1` (patch, no API change).

**Plus**: smaller PRs, faster individual review cycles.
**Minus**: shipping untested public class for a short window contradicts the package's existing test-coverage convention (every other class here has tests). Not aligned with package culture.

### Recommended

**Option A**. Test infrastructure already exists, the package culture already mandates tests for every public class, the additional review cost is small (the test files follow the established pattern reviewers already know). One PR, one merge, one release. Companion `wordpress-base` PR opened in parallel and merged after green release.

---

## Open questions

These need discussion before opening the implementation PR.

1. **Customisation extensibility** — should `BlockRenderer::render()` expose hooks for:
   - Custom cache backend (some projects don't use Redis, some use Cloudflare KV)
   - Custom alert template (current uses `@component/alert/alert.twig`)
   - Disabling inserter-preview discriminator entirely (theoretical: projects that don't use block.json examples)

   Options: a) static callable registry on the class, b) WordPress filters (`apply_filters('timber_kit/block_renderer/cache_backend', …)`), c) inheritance (`BlockRenderer` extendable, downstream overrides `render()`). Need to pick before locking the public API.

2. **`Helpers::formatFields()` coupling** — `BlockRenderer::render()` would call `Helpers::formatFields($post_id, $is_preview)` directly. If we want to support non-ACF blocks in the same renderer one day (mentioned as a bonus of the WP-core-API discriminator), the formatter call has to be parameterised. Should we introduce a `BlockDataLoader` interface that `Helpers` implements by default, and downstream projects can swap?

3. **`is_user_logged_in()` alert** — the empty-render alert that warns editors with "Pro zobrazení vyplňte požadované údaje v pravém panelu" uses a hardcoded translation domain (`'starter_theme'`) in the current source. When moved here, what's the translation domain? Options: a) hardcode `'timber-kit'` (this package owns the string), b) accept a domain parameter on package init, c) drop the translation and emit the string raw with format `e.g. "Block <name>: please fill in the required fields"` so the English fallback is decent on its own. Decision affects downstream `.pot` files.

4. **Cache backend abstraction** — current code calls `wp_using_ext_object_cache()` + `wp_cache_supports('flush_group')` to decide whether to use Redis. Hard-codes the assumption "external object cache = Redis with flush_group support". Some projects use plain `wp_cache_*` without group flushing. Should the package detect this and gracefully degrade, or require Redis as a documented prerequisite? Couples to Open Question #1.

5. **Cache key composition stability** — current `md5(wp_json_encode($cache_data))` includes `$attributes['data']`, `anchor`, `className`, `post_id`, `lang`, `paged`. Should the cache-key composition be a public protected method (`protected static function cacheKey(array $cache_data): string`) so projects can extend? Or keep private and stable across `1.x`? Affects backwards-compat surface if downstream code reaches into the cache for invalidation.

---

## Out of scope (for both this roadmap and the eventual implementation PR)

- **Refactoring the per-block PHP filter convention** (`block_<name>_content` / `block_<name>_template`). Separate design space, separate migration cost across all dynamic blocks. Acknowledged as tech debt in the predecessor PR design doc.
- **Static analysis tooling** ("which blocks register an `example`?"). Belongs as a `twig-cs-fixer` rule in the downstream static-analysis layer, not in this package.
- **Adding block-level performance instrumentation** (render-time logging, slow-block detection). Conceivable future feature; not required for the move.
- **Documenting the entire `block_<name>_content` filter author contract**. That doctrine lives in `portadesign/tailwind-base` rules — this package only owns the renderer's runtime behaviour, not the consumer-side contract.

---

## Next steps (after this draft PR is reviewed)

1. Resolve the 5 Open Questions above. Each becomes a one-line decision in the implementation PR.
2. Open the implementation PR per chosen phasing option (default: Option A — code + tests together).
3. Open the companion PR in `portadesign/wordpress-base` (wrapper + composer bump) once this PR is mergeable.
4. Plan downstream rollout — pick a canary project (e.g. `proficio-de`) to validate the full upgrade flow before broad rollout across all ~109 WordPress projects in the workspace.

---

## References

- [`portadesign/wordpress-base` PR #27 — discriminator refactor (predecessor)](https://github.com/portadesign/wordpress-base/pull/27)
- [`portadesign/wordpress-base` PR #28 — initial roadmap draft (will be closed in favour of this PR)](https://github.com/portadesign/wordpress-base/pull/28)
- Predecessor design doc: `docs/superpowers/specs/2026-05-15-block-preview-discriminator-design.md` in `portadesign/wordpress-base` on branch `feat/block-preview-skip-content-filter`
- Existing siblings in this package (`StarterBase`, `Helpers`, `Resizer`) and their test layout in `tests/Unit/<Class>/`
- [`portadesign/tailwind-base` `.claude/rules/wordpress/gutenberg.md`](https://github.com/portadesign/tailwind-base/blob/main/.claude/rules/wordpress/gutenberg.md) — § Filters Don't Run in Inserter Preview, the consumer-side doctrine the renderer enforces
