# BlockRenderer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the ~110-line `timber_block_render_callback()` from per-theme `functions.php` into `parisek/timber-kit` as `Parisek\TimberKit\BlockRenderer`, with WP-filter-based extensibility and a package-owned empty-alert Twig template.

**Architecture:** New `final class BlockRenderer` with two public static methods (`render()`, `isInserterPreview()`). Package ships its first Twig template under a new `@timber-kit/` namespace registered by `StarterBase`. Extensibility exclusively through 4 WordPress filters. Existing cache-backend detection (`wp_using_ext_object_cache` + `wp_cache_supports('flush_group')`) is preserved. Defensive inline-HTML fallback prevents hard failure when the Twig namespace isn't registered.

**Tech Stack:** PHP 8.3+, Timber 2.x, WordPress filter API, PHPUnit 11/12, Brain\Monkey for WP function mocking. PSR-4 autoload `Parisek\TimberKit\` → `src/`. Tabs for indentation.

---

## Source-of-truth references

- **Spec:** [`docs/superpowers/specs/2026-05-15-block-renderer-design.md`](../specs/2026-05-15-block-renderer-design.md) — locked decisions, public API, filter signatures, DOM contract.
- **Original `timber_block_render_callback()` function:** lives in `portadesign/wordpress-base`, `starter_theme/functions.php`. The implementer **must open that file in parallel** while porting — every behavior we test against has to match what the original function does. The predecessor PR ([`portadesign/wordpress-base#27`](https://github.com/portadesign/wordpress-base/pull/27)) landed the `WP_Block_Type::$example` identity-check discriminator already; that's the version to port.
- **Existing test patterns to copy:** `tests/Unit/Helpers/FieldFormatterTest.php` (Brain Monkey setup + `Functions\when()` mocks), `tests/Unit/HelpersTestCase.php` (base case with `Monkey\setUp()` / `tearDown()`).
- **Brain Monkey gotcha** (from `AGENTS.md`): function definitions persist across tests in the same run. Once any test mocks `xxx`, `function_exists('xxx')` returns true for the rest of the suite. Don't write tests that rely on `function_exists`-fail paths.

---

## File map

**Create:**
- `src/BlockRenderer.php` — the new class
- `src/templates/empty-alert.twig` — empty-alert template using WP-native `.block-editor-warning` classes
- `tests/Unit/BlockRendererTestCase.php` — base test case with Brain Monkey setup
- `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` — 6 pure unit tests for the discriminator
- `tests/Unit/BlockRenderer/RenderTest.php` — 11 Brain Monkey integration tests
- `tests/Unit/BlockRenderer/Fixtures.php` — shared `WP_Block` mock factory

**Modify:**
- `src/StarterBase.php` — add `timber/locations` filter registration in `__construct()` near the other `timber/*` filters (around line 178-180)
- `CHANGELOG.md` — entry under `## [Unreleased]`

---

## Task 1: Scaffold the test base class

**Files:**
- Create: `tests/Unit/BlockRendererTestCase.php`

- [ ] **Step 1: Create the test base class**

`tests/Unit/BlockRendererTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

abstract class BlockRendererTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Unit/BlockRendererTestCase.php
git commit -m "test(BlockRenderer): scaffold test base case with Brain Monkey setup"
```

---

## Task 2: Create the `WP_Block` fixture factory

**Files:**
- Create: `tests/Unit/BlockRenderer/Fixtures.php`

Used by both test files. The factory creates a `WP_Block`-like stdClass with the `block_type->example` chain that the discriminator inspects.

- [ ] **Step 1: Create the fixtures helper**

`tests/Unit/BlockRenderer/Fixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

/**
 * Test fixtures for BlockRenderer tests.
 *
 * The real `WP_Block` class isn't available in unit tests (no WordPress boot).
 * These factories produce stdClass mocks that mirror the shape the renderer
 * inspects: `$wp_block->block_type->example`.
 */
final class Fixtures {

	/**
	 * Build a stdClass that quacks like WP_Block with an `example` registered
	 * on its block_type.
	 *
	 * @param array<string, mixed>|null $example The block_type->example payload.
	 *                                            null = no example registered.
	 */
	public static function wpBlock( ?array $example ): \stdClass {
		$block = new \stdClass();
		$block->block_type = new \stdClass();
		$block->block_type->example = $example;
		return $block;
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Unit/BlockRenderer/Fixtures.php
git commit -m "test(BlockRenderer): add WP_Block fixture factory"
```

---

## Task 3: `isInserterPreview()` — test 1 (returns true when data matches example)

**Files:**
- Create: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`
- Create: `src/BlockRenderer.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/BlockRenderer/IsInserterPreviewTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class IsInserterPreviewTest extends BlockRendererTestCase {

	public function test_returns_true_when_data_matches_registered_example(): void {
		$example_data = [ 'title' => 'Example title', 'subtitle' => 'Example sub' ];
		$attributes   = [ 'data' => $example_data ];
		$wp_block     = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $example_data ] ] );

		$this->assertTrue(
			BlockRenderer::isInserterPreview( $attributes, true, $wp_block )
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_returns_true_when_data_matches_registered_example
```

Expected: FAIL with `Class "Parisek\TimberKit\BlockRenderer" not found` or equivalent.

- [ ] **Step 3: Create the class with minimal implementation**

`src/BlockRenderer.php`:

```php
<?php

declare(strict_types=1);

/**
 * BlockRenderer — render callback for ACF Gutenberg blocks.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

/**
 * Render callback orchestration for ACF Gutenberg blocks defined via block.json.
 *
 * Migrated from per-theme `timber_block_render_callback()` to provide a single
 * versioned source of truth across all themes derived from
 * `portadesign/wordpress-base`. Extensibility is exposed exclusively through
 * WordPress filters listed in the class docblock below; the class is `final`
 * and uses static methods so it can be wired directly as `renderCallback` in
 * block.json files.
 *
 * Filters exposed:
 *   - timber_kit/block_renderer/cache_key       (string $key, array $cache_data, string $block_name)
 *   - timber_kit/block_renderer/use_cache       (bool $enabled, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/empty_alert_html (string $html, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/context         (array $context, string $block_name, bool $is_preview)
 */
final class BlockRenderer {

	/**
	 * Pure discriminator — answers "is this an inserter-library example render?".
	 *
	 * Identity-check against `WP_Block_Type::$example->attributes->data`. No WP
	 * calls, no I/O, safe to call from anywhere. Public so downstream code can
	 * ask the same question without re-running the full render pipeline.
	 *
	 * @param array<string, mixed> $attributes The block's saved/preview attributes.
	 * @param bool                 $is_preview True when called in any preview context.
	 * @param object|null          $wp_block   The WP_Block instance (typed as object so
	 *                                          unit tests can pass stdClass mocks).
	 */
	public static function isInserterPreview( array $attributes, bool $is_preview, ?object $wp_block ): bool {
		if ( ! $is_preview ) {
			return false;
		}
		if ( null === $wp_block ) {
			return false;
		}
		if ( ! isset( $wp_block->block_type->example ) || ! is_array( $wp_block->block_type->example ) ) {
			return false;
		}

		$example = $wp_block->block_type->example;

		if ( ! isset( $example['attributes']['data'] ) || ! isset( $attributes['data'] ) ) {
			return false;
		}

		return $example['attributes']['data'] === $attributes['data'];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_returns_true_when_data_matches_registered_example
```

Expected: PASS, 1 test.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "feat(BlockRenderer): add isInserterPreview discriminator (TDD: match case)"
```

---

## Task 4: `isInserterPreview()` — test 2 (false when wp_block is null)

**Files:**
- Modify: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

- [ ] **Step 1: Add the failing test**

Append to `IsInserterPreviewTest.php`:

```php
	public function test_returns_false_when_wp_block_is_null(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview( [ 'data' => [ 'foo' => 'bar' ] ], true, null )
		);
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_returns_false_when_wp_block_is_null
```

Expected: PASS (current impl already handles this branch).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "test(BlockRenderer): isInserterPreview returns false for null wp_block"
```

---

## Task 5: `isInserterPreview()` — test 3 (false when example not registered)

**Files:**
- Modify: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_returns_false_when_example_not_registered(): void {
		$wp_block = Fixtures::wpBlock( null );

		$this->assertFalse(
			BlockRenderer::isInserterPreview( [ 'data' => [ 'foo' => 'bar' ] ], true, $wp_block )
		);
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_returns_false_when_example_not_registered
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "test(BlockRenderer): isInserterPreview returns false when no example"
```

---

## Task 6: `isInserterPreview()` — test 4 (false when not preview)

**Files:**
- Modify: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_returns_false_when_is_preview_is_false(): void {
		$example_data = [ 'title' => 'X' ];
		$wp_block     = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $example_data ] ] );

		$this->assertFalse(
			BlockRenderer::isInserterPreview( [ 'data' => $example_data ], false, $wp_block )
		);
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_returns_false_when_is_preview_is_false
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "test(BlockRenderer): isInserterPreview returns false when not preview"
```

---

## Task 7: `isInserterPreview()` — test 5 (false when data differs)

**Files:**
- Modify: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_returns_false_when_data_differs_from_example(): void {
		$example_data = [ 'title' => 'Example title' ];
		$saved_data   = [ 'title' => 'Real user content' ];
		$wp_block     = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $example_data ] ] );

		$this->assertFalse(
			BlockRenderer::isInserterPreview( [ 'data' => $saved_data ], true, $wp_block )
		);
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_returns_false_when_data_differs_from_example
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "test(BlockRenderer): isInserterPreview returns false when data differs"
```

---

## Task 8: `isInserterPreview()` — test 6 (ACF serialized data with field refs)

**Files:**
- Modify: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

ACF stores field references alongside values (e.g. `_title` → field key). The example registration and the saved data both contain these companion entries; identity check has to hold even with them present.

- [ ] **Step 1: Add the failing test**

```php
	public function test_handles_acf_serialised_data_with_field_refs(): void {
		$acf_payload = [
			'title'  => 'Example',
			'_title' => 'field_abc123',
		];
		$wp_block = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $acf_payload ] ] );

		$this->assertTrue(
			BlockRenderer::isInserterPreview( [ 'data' => $acf_payload ], true, $wp_block )
		);
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_handles_acf_serialised_data_with_field_refs
```

Expected: PASS.

- [ ] **Step 3: Run the full `IsInserterPreviewTest` suite to confirm all 6 cases**

```bash
composer test -- --filter IsInserterPreviewTest
```

Expected: 6 tests, 6 passes.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "test(BlockRenderer): isInserterPreview handles ACF field-ref companion keys"
```

---

## Task 9: Create the empty-alert Twig template

**Files:**
- Create: `src/templates/empty-alert.twig`

- [ ] **Step 1: Create the template**

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

  Stable contract (semver-protected):
    - .timber-kit-block-empty class
    - data-block attribute carrying the block name

  Best-effort (Gutenberg internals, may need filter override if WP renames):
    - .block-editor-warning, .block-editor-warning__contents, .block-editor-warning__message
#}
<div class="block-editor-warning timber-kit-block-empty" data-block="{{ block_name }}">
	<div class="block-editor-warning__contents">
		<p class="block-editor-warning__message">{{ message }}</p>
	</div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add src/templates/empty-alert.twig
git commit -m "feat(BlockRenderer): add empty-alert Twig template with WP-native classes"
```

---

## Task 10: Register `@timber-kit/` Twig namespace in StarterBase

**Files:**
- Modify: `src/StarterBase.php` — append one filter line in `__construct()` near the other `timber/*` filter registrations (around line 178-181)

- [ ] **Step 1: Read the current `__construct()` block**

```bash
grep -n "timber/twig\|timber/context\|timber/loader" src/StarterBase.php
```

Confirm the three existing `timber/*` filter registrations on lines ~177-179. The new line goes immediately after them.

- [ ] **Step 2: Add the namespace registration**

In `src/StarterBase.php`, find the line:

```php
add_filter( 'timber/loader/loader', array( $this, 'timber_twig_loader' ) );
```

Insert immediately after it:

```php
add_filter( 'timber/locations', array( $this, 'register_timber_kit_namespace' ), 5 );
```

Priority `5` ensures downstream themes registering at the default priority `10` can override individual templates by adding their own path under the same `timber-kit` namespace.

- [ ] **Step 3: Add the handler method**

Find a place near other small Timber-related methods (e.g. after `timber_twig_loader()`). Add:

```php
	/**
	 * Register the `@timber-kit/` Twig namespace pointing at this package's
	 * shipped templates directory.
	 *
	 * Priority 5 (vs WP default 10) so downstream themes registering at the
	 * default priority can override individual templates by adding their own
	 * path under the same namespace later in the chain.
	 *
	 * @param array<string, array<int, string>> $paths Existing namespace map.
	 * @return array<string, array<int, string>>
	 */
	public function register_timber_kit_namespace( array $paths ): array {
		$paths['timber-kit'] = [ __DIR__ . '/templates' ];
		return $paths;
	}
```

- [ ] **Step 4: Run the full StarterBase test suite to confirm no regression**

```bash
composer test -- --filter StarterBase
```

Expected: existing tests still pass. No new test required yet — the namespace registration is exercised indirectly by `RenderTest::test_empty_render_shows_alert_for_logged_in_users` (Task 19).

- [ ] **Step 5: Commit**

```bash
git add src/StarterBase.php
git commit -m "feat(StarterBase): register @timber-kit/ Twig namespace for package templates"
```

---

## Task 11: Begin `RenderTest.php` and start the discriminator integration test

**Files:**
- Create: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php` — add `render()` skeleton

This is the first of 11 render tests. Each subsequent test adds one branch.

- [ ] **Step 1: Write the failing test**

`tests/Unit/BlockRenderer/RenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class RenderTest extends BlockRendererTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Default no-op mocks for WP functions that every render path touches.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
	}

	public function test_inserter_preview_skips_content_filter(): void {
		$example_data = [ 'title' => 'Example' ];
		$wp_block     = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $example_data ], 'name' => 'acf/article-featured' ] );

		// The block_<name>_content filter MUST NOT run during inserter preview.
		Filters\expectApplied( 'block_acf/article-featured_content' )->never();

		ob_start();
		BlockRenderer::render(
			[ 'data' => $example_data, 'name' => 'acf/article-featured' ],
			'',
			true,
			0,
			$wp_block
		);
		ob_end_clean();
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_inserter_preview_skips_content_filter
```

Expected: FAIL with `Call to undefined method ... render` or equivalent.

- [ ] **Step 3: Add the `render()` skeleton to `src/BlockRenderer.php`**

Inside the class, before the closing brace, add:

```php
	/**
	 * Render callback for ACF Gutenberg blocks defined via block.json.
	 *
	 * Wire as:
	 *   "acf": { "renderCallback": "Parisek\\TimberKit\\BlockRenderer::render" }
	 *
	 * Or via the backwards-compat wrapper in downstream themes:
	 *   function timber_block_render_callback( ...$args ) {
	 *       \Parisek\TimberKit\BlockRenderer::render( ...$args );
	 *   }
	 *
	 * @param array<string, mixed> $attributes The block's saved or preview attributes.
	 * @param string               $content    Block-supplied content (unused for ACF blocks).
	 * @param bool                 $is_preview True in any editor / inserter preview context.
	 * @param int                  $post_id    Containing post ID (0 in some contexts).
	 * @param \WP_Block|null       $wp_block   The WP_Block instance, null in legacy contexts.
	 */
	public static function render(
		array $attributes,
		string $content = '',
		bool $is_preview = false,
		int $post_id = 0,
		?\WP_Block $wp_block = null
	): void {
		$block_name = isset( $attributes['name'] ) && is_string( $attributes['name'] )
			? $attributes['name']
			: 'unknown';

		$is_inserter_preview = self::isInserterPreview( $attributes, $is_preview, $wp_block );

		// Step 5 (content filter) — skipped entirely when this is an inserter preview.
		if ( ! $is_inserter_preview ) {
			apply_filters( "block_{$block_name}_content", $content, $attributes );
		}
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_inserter_preview_skips_content_filter
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): add render() skeleton, gate content filter on inserter-preview discriminator"
```

---

## Task 12: Editor canvas runs `block_<name>_content` filter with saved data

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_editor_canvas_runs_filter_with_saved_data(): void {
		$saved_data = [ 'title' => 'Real user content' ];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/article-featured' ] );

		// Saved data differs from example → not inserter preview → filter MUST run.
		Filters\expectApplied( 'block_acf/article-featured_content' )->once();

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/article-featured' ],
			'',
			true,           // is_preview = true (editor canvas)
			123,
			$wp_block
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_editor_canvas_runs_filter_with_saved_data
```

Expected: PASS (current impl already routes correctly).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): editor canvas with saved data triggers content filter"
```

---

## Task 13: `block_<name>_template` filter runs even in inserter preview

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_template_filter_runs_even_in_inserter_preview(): void {
		$example_data = [ 'title' => 'Example' ];
		$wp_block     = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $example_data ], 'name' => 'acf/article-featured' ] );

		// block_<name>_template runs always, including in inserter preview.
		Filters\expectApplied( 'block_acf/article-featured_template' )->once();

		ob_start();
		BlockRenderer::render(
			[ 'data' => $example_data, 'name' => 'acf/article-featured' ],
			'',
			true,
			0,
			$wp_block
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_template_filter_runs_even_in_inserter_preview
```

Expected: FAIL — current `render()` doesn't dispatch `block_<name>_template` yet.

- [ ] **Step 3: Add the template-filter dispatch in `render()`**

In `src/BlockRenderer.php`, inside `render()`, after the `if ( ! $is_inserter_preview )` block, add:

```php
		// Step 5b — block_<name>_template filter ALWAYS runs, including in inserter preview.
		// It resolves the Twig template path for this block (set via ACF block.json or filter).
		$template = apply_filters( "block_{$block_name}_template", '', $attributes );
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_template_filter_runs_even_in_inserter_preview
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): dispatch block_<name>_template filter always"
```

---

## Task 14: Cache key composition (filter override path)

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php` — add private `cacheKey()` method + `timber_kit/block_renderer/cache_key` filter

The cache key is `md5(wp_json_encode($cache_data))` where `$cache_data` includes `data`, `anchor`, `className`, `post_id`, `lang`, `paged`. Behind a filter so downstream can add variation vectors (e.g. user role).

- [ ] **Step 1: Add the failing test**

```php
	public function test_cache_key_filter_can_override_default(): void {
		$saved_data = [ 'title' => 'Some title' ];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/article' ] );

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		$captured_key = null;
		Filters\expectApplied( 'timber_kit/block_renderer/cache_key' )
			->once()
			->andReturnUsing(
				function ( string $key, array $cache_data, string $block_name ) use ( &$captured_key ): string {
					$captured_key = $key;
					return 'custom-key-' . $block_name;
				}
			);

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/article' ],
			'',
			true,
			0,
			$wp_block
		);
		ob_end_clean();

		$this->assertNotNull( $captured_key, 'cache_key filter must be invoked' );
		$this->assertSame( 32, strlen( $captured_key ), 'default key must be md5 (32 hex chars)' );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_cache_key_filter_can_override_default
```

Expected: FAIL — `cacheKey()` method and filter dispatch don't exist yet.

- [ ] **Step 3: Add the private `cacheKey()` method to `src/BlockRenderer.php`**

Inside the class, after `render()`:

```php
	/**
	 * Compose a cache key for a single block render.
	 *
	 * Default formula: md5(wp_json_encode($cache_data)). Downstream projects
	 * can mix in additional variation vectors (locale, user role, paged) via
	 * the `timber_kit/block_renderer/cache_key` filter.
	 *
	 * @param array<string, mixed> $cache_data Composition inputs (attributes data, anchor,
	 *                                          className, post_id, lang, paged).
	 * @param string               $block_name The block's name (e.g. "acf/article-featured").
	 */
	private static function cacheKey( array $cache_data, string $block_name ): string {
		$default = md5( wp_json_encode( $cache_data ) );

		return apply_filters(
			'timber_kit/block_renderer/cache_key',
			$default,
			$cache_data,
			$block_name
		);
	}
```

- [ ] **Step 4: Wire `cacheKey()` into `render()`**

In `render()`, after the `$template = apply_filters(...)` line, add:

```php
		// Step 3 — cache key composition (filter-overridable).
		$cache_data = [
			'data'      => $attributes['data'] ?? null,
			'anchor'    => $attributes['anchor'] ?? null,
			'className' => $attributes['className'] ?? null,
			'post_id'   => $post_id,
		];
		$cache_key = self::cacheKey( $cache_data, $block_name );
```

(This invokes the filter even when no cache backend exists, which matches the test's expectation. The actual cache read/write is added in Task 15.)

- [ ] **Step 5: Run test to verify it passes**

```bash
composer test -- --filter test_cache_key_filter_can_override_default
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): add cache key composition with cache_key filter override"
```

---

## Task 15: Preview memo cache short-circuit

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

In-request preview memo: when the same block is rendered twice in one request (e.g. server-side render API + post content), the second call returns the cached compiled output. Keyed by the same `cacheKey()`.

- [ ] **Step 1: Add the failing test**

```php
	public function test_preview_memo_cache_hit_short_circuits(): void {
		$saved_data = [ 'title' => 'Memo test' ];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/memo' ] );

		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		// First render — block_<name>_content filter runs.
		Filters\expectApplied( 'block_acf/memo_content' )->once();

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/memo' ],
			'',
			true,
			0,
			$wp_block
		);
		$first_output = ob_get_clean();

		// Second identical render — cached, filter MUST NOT run again.
		// (expectApplied above already locks "once" — a second invocation fails the test.)
		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/memo' ],
			'',
			true,
			0,
			$wp_block
		);
		$second_output = ob_get_clean();

		$this->assertSame( $first_output, $second_output, 'memoised render should produce identical output' );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_preview_memo_cache_hit_short_circuits
```

Expected: FAIL — content filter currently runs on both calls.

- [ ] **Step 3: Add the static memo + early return to `render()`**

At the top of the class (before any methods), add a static property:

```php
	/**
	 * In-request memo of compiled block output, keyed by cacheKey().
	 *
	 * @var array<string, string>
	 */
	private static array $preview_memo = [];
```

In `render()`, after `$cache_key = self::cacheKey(...)`, add the memo check:

```php
		// Step 3 — preview memo (in-request cache, no backend required).
		if ( isset( self::$preview_memo[ $cache_key ] ) ) {
			echo self::$preview_memo[ $cache_key ];
			return;
		}
```

At the end of `render()` (currently just after the template filter), capture the output:

```php
		// Capture output for the preview memo. Compose by buffering the filters
		// we've already dispatched + (eventually) the Timber compile result.
		$output = ''; // Real compile path lands in Task 17 — for now memo holds empty.
		self::$preview_memo[ $cache_key ] = $output;
		echo $output;
```

This is intentionally a stub — Task 17 fills in the real Timber compile. For now the memo behavior is correct and the test passes (both calls echo `''` and the filter runs only once thanks to the memo short-circuit).

**Important:** Re-run the previously-passing tests to check the memo isn't breaking them.

```bash
composer test -- --filter RenderTest
```

Expected: all 5 tests still pass. If `test_inserter_preview_skips_content_filter` or `test_editor_canvas_runs_filter_with_saved_data` regress, it's because the memo carries state across tests. **Fix: reset `$preview_memo` in test setUp:**

In `RenderTest::setUp()`, after `parent::setUp()`, add:

```php
		// Reset in-request memo between tests (it's a static class property).
		$reflection = new \ReflectionClass( BlockRenderer::class );
		$reflection->setStaticPropertyValue( 'preview_memo', [] );
```

- [ ] **Step 4: Run the new test + the full RenderTest suite**

```bash
composer test -- --filter RenderTest
```

Expected: all 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): in-request preview memo short-circuits identical renders"
```

---

## Task 16: Redis cache integration with `use_cache` filter

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

When `wp_using_ext_object_cache()` AND `wp_cache_supports('flush_group')` both return true, store the compiled output under the block-specific cache group so theme code can invalidate via `wp_cache_flush_group("timber_kit_block_$block_name")`. Filter `use_cache` can disable this per-block at runtime.

- [ ] **Step 1: Add the failing test**

```php
	public function test_redis_cache_skips_for_dynamic_filter_blocks(): void {
		$saved_data = [ 'title' => 'No cache for me' ];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/dynamic-filter' ] );

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		// wp_cache_set MUST NOT be called when use_cache filter returns false.
		Functions\expect( 'wp_cache_set' )->never();
		Functions\when( 'wp_cache_get' )->justReturn( false );

		Filters\expectApplied( 'timber_kit/block_renderer/use_cache' )
			->once()
			->andReturn( false );

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/dynamic-filter' ],
			'',
			true,
			0,
			$wp_block
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_redis_cache_skips_for_dynamic_filter_blocks
```

Expected: FAIL — Redis cache code path doesn't exist yet.

- [ ] **Step 3: Add the Redis cache code path in `render()`**

After the preview memo check in `render()`, insert before any filter dispatch:

```php
		// Step 3 — external object cache (Redis with flush_group support).
		$use_external_cache = function_exists( 'wp_using_ext_object_cache' )
			&& wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_supports' )
			&& wp_cache_supports( 'flush_group' );

		$use_cache_for_this_block = apply_filters(
			'timber_kit/block_renderer/use_cache',
			$use_external_cache,
			$block_name,
			$attributes
		);

		$cache_group = "timber_kit_block_{$block_name}";
		if ( $use_cache_for_this_block ) {
			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached && is_string( $cached ) ) {
				self::$preview_memo[ $cache_key ] = $cached;
				echo $cached;
				return;
			}
		}
```

And at the bottom of `render()`, replace the temporary `$output = '';` line with a guarded cache write:

```php
		// Write to external cache if enabled for this block.
		if ( $use_cache_for_this_block && ! self::hadSideEffectsDuringRender() ) {
			wp_cache_set( $cache_key, $output, $cache_group );
		}

		self::$preview_memo[ $cache_key ] = $output;
		echo $output;
```

And add a placeholder static method (will be filled in Task 18):

```php
	/**
	 * Did the most recent render call enqueue form-plugin assets via the
	 * `wpcf7_form_class_attr` / WPForms `wpforms_frontend_load` paths? If so,
	 * the output isn't safe to cache (it captured a one-shot side effect).
	 */
	private static function hadSideEffectsDuringRender(): bool {
		return false; // Task 18 wires up the real tracking.
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_redis_cache_skips_for_dynamic_filter_blocks
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): external cache integration with use_cache filter"
```

---

## Task 17: Timber compile with context filter

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

Wire `Timber::compile()` to render the resolved template with the data-hydrated context. The context passes through `timber_kit/block_renderer/context` filter as the last step before compile.

- [ ] **Step 1: Add the failing test**

```php
	public function test_context_filter_runs_before_compile(): void {
		$saved_data = [ 'title' => 'Hello' ];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/ctx' ] );

		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		// Stub Helpers::formatFields by mocking its return path via Brain Monkey
		// is impossible (static method on a real class). Instead the test relies
		// on the Helpers class being autoloaded — the real implementation reads
		// no DB in pure-PHP unit context, so it just returns [] for post_id=0.

		Filters\expectApplied( 'timber_kit/block_renderer/context' )->once();
		// block_<name>_template returns empty string → no Timber compile attempted.
		Filters\expectApplied( 'block_acf/ctx_template' )->once()->andReturn( '' );

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/ctx' ],
			'',
			false,
			0,
			$wp_block
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_context_filter_runs_before_compile
```

Expected: FAIL — context filter not dispatched yet.

- [ ] **Step 3: Add the context filter + Timber compile path in `render()`**

Replace the temporary `$output = '';` stub with:

```php
		// Step 4 — data hydration via Helpers (ACF field walker).
		$fields = \Parisek\TimberKit\Helpers::formatFields( $post_id, $is_preview );

		// Step 6 — assemble context and pass through context filter.
		$context = [
			'block_name' => $block_name,
			'attributes' => $attributes,
			'fields'     => $fields,
			'is_preview' => $is_preview,
			'post_id'    => $post_id,
		];

		$context = apply_filters(
			'timber_kit/block_renderer/context',
			$context,
			$block_name,
			$is_preview
		);

		// Compile only when a template path was resolved by the block_<name>_template filter.
		$output = '';
		if ( is_string( $template ) && '' !== $template && class_exists( \Timber\Timber::class ) ) {
			$compiled = \Timber\Timber::compile( $template, $context );
			if ( is_string( $compiled ) ) {
				$output = $compiled;
			}
		}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_context_filter_runs_before_compile
```

Expected: PASS. Also confirm previous tests still pass:

```bash
composer test -- --filter RenderTest
```

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): Timber compile with context filter passthrough"
```

---

## Task 18: Side-effect tracking — form plugin enqueue detection

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

The original function tracks whether `wpcf7_form_class_attr` / `wpforms_frontend_load` filters fire during the compile — if any do, the block has a side effect (asset enqueue) and the output is **not** safe to cache (caching would skip the enqueue on the next request, breaking forms).

- [ ] **Step 1: Add the failing test**

```php
	public function test_side_effecting_block_excluded_from_cache(): void {
		$saved_data = [ 'form_id' => 42 ];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'form_id' => 1 ] ], 'name' => 'acf/contact-form' ] );

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		// wp_cache_set MUST NOT be called when a side-effect filter fired.
		Functions\expect( 'wp_cache_set' )->never();

		// Simulate the form plugin enqueueing assets during render by triggering its filter.
		Filters\expectApplied( 'block_acf/contact-form_template' )
			->once()
			->andReturnUsing( static function () {
				apply_filters( 'wpcf7_form_class_attr', '' );
				return '';
			} );

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/contact-form' ],
			'',
			false,
			0,
			$wp_block
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_side_effecting_block_excluded_from_cache
```

Expected: FAIL — side-effect tracking returns hardcoded `false`.

- [ ] **Step 3: Replace the placeholder `hadSideEffectsDuringRender()`**

Add the tracking state to the class:

```php
	/**
	 * Per-render flag set by `recordSideEffect()` when a form-plugin filter
	 * fires during compile. When true, the resulting output skips cache write
	 * because it captured a one-shot asset enqueue that wouldn't replay from cache.
	 */
	private static bool $side_effect_in_progress = false;

	/**
	 * Filter names that, when fired during compile, indicate a side effect.
	 *
	 * @var array<int, string>
	 */
	private const SIDE_EFFECT_FILTERS = [
		'wpcf7_form_class_attr',   // Contact Form 7 — fires when shortcode renders
		'wpforms_frontend_load',   // WPForms — fires when frontend assets load
	];
```

Replace `hadSideEffectsDuringRender()` with:

```php
	private static function hadSideEffectsDuringRender(): bool {
		return self::$side_effect_in_progress;
	}
```

In `render()`, before the Timber::compile path, register the side-effect listeners and reset the flag:

```php
		// Step 7a — register side-effect listeners. Form plugins fire these
		// during their compile; if any of them runs, the output captures a
		// one-shot asset enqueue and isn't safe to cache.
		self::$side_effect_in_progress = false;
		foreach ( self::SIDE_EFFECT_FILTERS as $filter_name ) {
			add_filter( $filter_name, [ self::class, 'recordSideEffect' ], PHP_INT_MAX );
		}
```

After the Timber compile, deregister them:

```php
		foreach ( self::SIDE_EFFECT_FILTERS as $filter_name ) {
			remove_filter( $filter_name, [ self::class, 'recordSideEffect' ], PHP_INT_MAX );
		}
```

Add the recorder method:

```php
	/**
	 * Internal — passthrough filter that flags side-effect occurrence during render.
	 *
	 * Registered on form-plugin filters at PHP_INT_MAX priority during `render()`.
	 * Returns its input unchanged so it never alters plugin behavior.
	 *
	 * @internal Public only because WordPress's add_filter requires a callable;
	 *           do not call directly from outside the class.
	 */
	public static function recordSideEffect( mixed $value ): mixed {
		self::$side_effect_in_progress = true;
		return $value;
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_side_effecting_block_excluded_from_cache
```

Expected: PASS.

Also re-run the cache-skip test to confirm no regression:

```bash
composer test -- --filter RenderTest
```

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): track form-plugin side effects; skip cache write when detected"
```

---

## Task 19: Empty-render alert via Twig template path

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

When the compiled output is empty AND user is logged in, render the alert via `@timber-kit/empty-alert.twig`. The `empty_alert_html` filter lets themes replace the entire HTML.

- [ ] **Step 1: Add the failing test (Twig path)**

```php
	public function test_empty_render_shows_alert_for_logged_in_users(): void {
		$saved_data = [];
		$wp_block   = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/empty-block' ] );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		// No template resolved → Timber compile returns nothing → empty output.
		Filters\expectApplied( 'block_acf/empty-block_template' )->andReturn( '' );

		// empty_alert_html MUST be dispatched.
		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )->once();

		ob_start();
		BlockRenderer::render(
			[ 'data' => $saved_data, 'name' => 'acf/empty-block' ],
			'',
			true,
			0,
			$wp_block
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'timber-kit-block-empty', $output, 'output must carry stable CSS hook' );
		$this->assertStringContainsString( 'data-block="acf/empty-block"', $output );
		$this->assertStringContainsString( 'block-editor-warning', $output );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_empty_render_shows_alert_for_logged_in_users
```

Expected: FAIL — alert rendering not implemented yet.

- [ ] **Step 3: Add `renderEmptyAlert()` and wire into `render()`**

Add the method to `src/BlockRenderer.php`:

```php
	/**
	 * Render the empty-block warning shown to logged-in users when `render()`
	 * produced no output.
	 *
	 * Tries the bundled Twig template first (`@timber-kit/empty-alert.twig`,
	 * registered by StarterBase). If Timber isn't loaded or the namespace
	 * isn't registered, falls back to an inline HTML string that preserves
	 * the same DOM contract (`.block-editor-warning` + `.timber-kit-block-empty`).
	 *
	 * @param array<string, mixed> $attributes Block attributes (passed to filter for context).
	 */
	private static function renderEmptyAlert( string $block_name, array $attributes ): string {
		$message = __(
			'Pro zobrazení vyplňte požadované údaje v pravém panelu.',
			'timber-kit'
		);

		$html = '';
		if ( class_exists( \Timber\Timber::class ) ) {
			$compiled = \Timber\Timber::compile(
				'@timber-kit/empty-alert.twig',
				[
					'block_name' => $block_name,
					'message'    => $message,
				]
			);
			if ( is_string( $compiled ) && '' !== $compiled ) {
				$html = $compiled;
			}
		}

		if ( '' === $html ) {
			// Inline fallback — preserves the same DOM contract as the Twig template.
			$html = sprintf(
				'<div class="block-editor-warning timber-kit-block-empty" data-block="%s">'
					. '<div class="block-editor-warning__contents">'
						. '<p class="block-editor-warning__message">%s</p>'
					. '</div>'
				. '</div>',
				esc_attr( $block_name ),
				esc_html( $message )
			);
		}

		return apply_filters(
			'timber_kit/block_renderer/empty_alert_html',
			$html,
			$block_name,
			$attributes
		);
	}
```

In `render()`, after computing `$output` from the Timber compile, before the cache-write block, add:

```php
		// Step 7b — empty-render alert (logged-in users only, never in inserter preview).
		if ( '' === trim( $output ) && ! $is_inserter_preview && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$output = self::renderEmptyAlert( $block_name, $attributes );
		}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_empty_render_shows_alert_for_logged_in_users
```

Expected: PASS — the inline fallback path runs (Timber isn't really loaded in unit tests; `class_exists(Timber::class)` returns false unless previously triggered) so we see the inline HTML which carries all three required substrings.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): render empty-block alert via Twig with inline fallback"
```

---

## Task 20: Test — `empty_alert_html` filter replaces default output

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

Confirms the filter has full control — theme can swap the entire HTML.

- [ ] **Step 1: Add the failing test**

```php
	public function test_empty_alert_html_filter_replaces_default_output(): void {
		$wp_block = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/custom-alert' ] );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		Filters\expectApplied( 'block_acf/custom-alert_template' )->andReturn( '' );
		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )
			->once()
			->andReturn( '<custom-theme-alert>OVERRIDE</custom-theme-alert>' );

		ob_start();
		BlockRenderer::render(
			[ 'data' => [], 'name' => 'acf/custom-alert' ],
			'',
			true,
			0,
			$wp_block
		);
		$output = ob_get_clean();

		$this->assertSame( '<custom-theme-alert>OVERRIDE</custom-theme-alert>', $output );
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_empty_alert_html_filter_replaces_default_output
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): empty_alert_html filter replaces default output"
```

---

## Task 21: Test — inserter preview wrapped in 16:9 aspect ratio

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

The original function wraps the inserter preview output in `<div style="aspect-ratio: 16/9">` so the inserter library shows blocks at a consistent thumbnail aspect.

- [ ] **Step 1: Add the failing test**

```php
	public function test_inserter_preview_wrapped_in_16_9_aspect_ratio(): void {
		$example_data = [ 'title' => 'Example' ];
		$wp_block     = Fixtures::wpBlock( [ 'attributes' => [ 'data' => $example_data ], 'name' => 'acf/wrapped' ] );

		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		// block_<name>_template filter returns a path → simulate compile returning a marker.
		Filters\expectApplied( 'block_acf/wrapped_template' )->andReturn( 'simulated-template.twig' );

		// Without a real Timber compile we synthesize the output via the context filter.
		Filters\expectApplied( 'timber_kit/block_renderer/context' )
			->andReturnUsing( static function ( array $context ): array {
				return $context;
			} );

		// Mock Timber::compile via class_exists short-circuit — by not autoloading Timber,
		// $output stays '', so we test the wrapping logic by injecting via empty_alert path
		// disabled (user not logged in).
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		ob_start();
		BlockRenderer::render(
			[ 'data' => $example_data, 'name' => 'acf/wrapped' ],
			'',
			true,
			0,
			$wp_block
		);
		$output = ob_get_clean();

		// With no Timber and no alert, output is empty — the wrap should be skipped on empty.
		// But the wrap CSS rule should still appear when output is non-empty in a preview.
		// To exercise the wrap branch we use the empty_alert_html filter to inject content.
		// Re-run with injected content:

		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )
			->andReturn( '<p>Synthetic preview body</p>' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );

		ob_start();
		BlockRenderer::render(
			[ 'data' => $example_data, 'name' => 'acf/wrapped' ],
			'',
			true,
			0,
			$wp_block
		);
		$wrapped_output = ob_get_clean();

		$this->assertStringContainsString( 'aspect-ratio', $wrapped_output );
		$this->assertStringContainsString( '16 / 9', $wrapped_output );
		$this->assertStringContainsString( 'Synthetic preview body', $wrapped_output );
	}
```

**Note:** this test exercises both the no-wrap-on-empty and wrap-on-non-empty paths in one run. The double `ob_start`/`ob_get_clean` is intentional.

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_inserter_preview_wrapped_in_16_9_aspect_ratio
```

Expected: FAIL — wrapping not implemented.

- [ ] **Step 3: Add the wrap step in `render()`**

After the empty-alert path, before the cache-write block, add:

```php
		// Step 6b — wrap inserter-preview output in a 16/9 aspect-ratio box so the
		// inserter library shows blocks at a consistent thumbnail aspect.
		if ( $is_inserter_preview && '' !== $output ) {
			$output = '<div style="aspect-ratio: 16 / 9; overflow: hidden;">' . $output . '</div>';
		}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_inserter_preview_wrapped_in_16_9_aspect_ratio
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): wrap inserter-preview output in 16/9 aspect-ratio box"
```

---

## Task 22: Test — inline fallback when Timber namespace missing

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

This validates the defensive fallback documented in the spec.

- [ ] **Step 1: Add the failing test**

```php
	public function test_inline_fallback_used_when_timber_namespace_missing(): void {
		// Simulate a project that uses BlockRenderer without StarterBase — Timber
		// class might not even be autoloaded. The renderEmptyAlert inline fallback
		// must still produce well-formed HTML carrying the stable contract classes.

		$wp_block = Fixtures::wpBlock( [ 'attributes' => [ 'data' => [ 'title' => 'Example' ] ], 'name' => 'acf/no-base' ] );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);

		Filters\expectApplied( 'block_acf/no-base_template' )->andReturn( '' );
		// empty_alert_html filter passes the inline-fallback HTML through unchanged.

		ob_start();
		BlockRenderer::render(
			[ 'data' => [], 'name' => 'acf/no-base' ],
			'',
			false,
			0,
			$wp_block
		);
		$output = ob_get_clean();

		// All three stable-contract markers present.
		$this->assertStringContainsString( 'block-editor-warning', $output );
		$this->assertStringContainsString( 'timber-kit-block-empty', $output );
		$this->assertStringContainsString( 'data-block="acf/no-base"', $output );
		// Translated message present.
		$this->assertStringContainsString( 'Pro zobrazení vyplňte', $output );
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_inline_fallback_used_when_timber_namespace_missing
```

Expected: PASS (inline-fallback path runs in unit tests since Timber isn't really loaded).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): inline fallback used when Timber namespace missing"
```

---

## Task 23: Run the full test suite + PHPStan

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHPUnit suite**

```bash
composer test
```

Expected: all previous tests + 17 new BlockRenderer tests, all passing.

- [ ] **Step 2: Run PHPStan**

```bash
composer phpstan
```

Expected: no new errors at level 5. If errors surface in `BlockRenderer.php`, fix them inline (likely candidates: missing `@param` types, mixed types in cache_data array). Re-run until clean.

- [ ] **Step 3: Commit any PHPStan fixes**

If any fixes were needed:

```bash
git add src/BlockRenderer.php
git commit -m "chore(BlockRenderer): satisfy PHPStan level 5"
```

---

## Task 24: CHANGELOG entry

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add the Unreleased entry**

Open `CHANGELOG.md`. Under `## [Unreleased]`, add:

```markdown
### Added
- `Parisek\TimberKit\BlockRenderer` — new class hosting the ACF Gutenberg block render callback that themes derived from `portadesign/wordpress-base` previously carried inline in `functions.php`. Two static methods: `render()` (full pipeline — discriminator, cache, data hydration, filter dispatch, Timber compile, side-effect tracking, empty-render alert) and `isInserterPreview()` (pure discriminator, identity-check against `WP_Block_Type::$example`). Extensibility through four WordPress filters: `timber_kit/block_renderer/cache_key`, `…/use_cache`, `…/empty_alert_html`, `…/context`. Wire it in `block.json` as `"renderCallback": "Parisek\\TimberKit\\BlockRenderer::render"`, or call from a wrapper function for backwards-compat with existing `block.json` files referencing `timber_block_render_callback`.
- `src/templates/empty-alert.twig` — first package-shipped Twig template, rendered by `BlockRenderer::render()` when output is empty for a logged-in user. Uses WordPress Gutenberg's native `.block-editor-warning` classes so the editor styles it without any package-shipped CSS. Stable contract: `.timber-kit-block-empty` class + `data-block` attribute as theme styling hooks; full HTML override via the `empty_alert_html` filter. Defensive inline-HTML fallback in PHP when the `@timber-kit/` Twig namespace isn't registered (e.g. projects using `BlockRenderer` without `StarterBase`).
- `StarterBase::register_timber_kit_namespace()` — registers the `@timber-kit/` Twig namespace pointing at the package's templates directory. Hooked on `timber/locations` at priority 5 (below WP default 10) so downstream themes can override individual templates by registering their own path under the same namespace.

### Changed
- `StarterBase::__construct()` — adds one `add_filter('timber/locations', …, 5)` line near the existing `timber/*` filter registrations to wire the new `@timber-kit/` namespace.
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): document BlockRenderer migration under [Unreleased]"
```

---

## Task 25: Final verification — full suite green

**Files:** none

- [ ] **Step 1: Run the full test suite**

```bash
composer test
```

Expected: green across the board. Existing tests (Helpers, StarterBase, Resizer, DevMediaProxy, WPFormsConfigBridge) unchanged + 17 new BlockRenderer tests passing.

- [ ] **Step 2: Run PHPStan one more time**

```bash
composer phpstan
```

Expected: no errors.

- [ ] **Step 3: Inspect git log**

```bash
git log --oneline feat/block-renderer-roadmap...HEAD
```

Verify each commit message starts with `feat(BlockRenderer)`, `test(BlockRenderer)`, `feat(StarterBase)`, `docs(changelog)`, or similar conventional-commit prefix.

- [ ] **Step 4: Open the implementation PR**

```bash
gh pr create \
  --title "feat: add BlockRenderer class hosting the WP block render callback" \
  --body "$(cat <<'EOF'
## Summary
- Migrates `timber_block_render_callback()` (~110 lines) from per-theme `functions.php` into `Parisek\TimberKit\BlockRenderer` — one versioned source of truth across ~109 downstream WordPress themes.
- First package-shipped Twig template (`src/templates/empty-alert.twig`) under a new `@timber-kit/` namespace registered by `StarterBase`. Uses WordPress-native `.block-editor-warning` classes so the editor styles the empty-block alert without any package CSS.
- Four WordPress filters expose all extensibility points: `cache_key`, `use_cache`, `empty_alert_html`, `context`. No DI, no interfaces, no inheritance — `final class`, static methods.

Design spec: [`docs/superpowers/specs/2026-05-15-block-renderer-design.md`](docs/superpowers/specs/2026-05-15-block-renderer-design.md)
Implementation plan: [`docs/superpowers/plans/2026-05-15-block-renderer-implementation.md`](docs/superpowers/plans/2026-05-15-block-renderer-implementation.md)

## Test plan
- [x] `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` — 6 pure-unit cases covering the discriminator matrix
- [x] `tests/Unit/BlockRenderer/RenderTest.php` — 11 Brain Monkey integration cases (filter routing, cache memo, cache backend gating, side-effect tracking, empty-render alert, inserter wrapping, filter overrides, defensive fallback)
- [x] `composer test` — full suite green
- [x] `composer phpstan` — level 5 clean
- [ ] Manual smoke in downstream project (`proficio-de`): inserter library hover + editor canvas + frontend
- [ ] Companion PR in `portadesign/wordpress-base` (wrapper + composer bump) opened after `v1.5.0` tagged
EOF
)"
```

(The companion PR in `portadesign/wordpress-base` is **out of scope** for this plan — it's opened separately after `v1.5.0` of this package is tagged.)

---

## Self-review checklist (run inline after writing the plan)

**Spec coverage:**
- ✓ Decision #1 (filters) → Tasks 14 (cache_key), 16 (use_cache), 19/20 (empty_alert_html), 17 (context)
- ✓ Decision #2 (direct Helpers::formatFields call) → Task 17 (`Helpers::formatFields()` invocation)
- ✓ Decision #3 (Twig template + native classes + i18n + filter override) → Tasks 9 (template), 10 (namespace), 19 (Twig path + inline fallback), 20 (filter override)
- ✓ Decision #4 (existing cache detection) → Task 16 (`wp_using_ext_object_cache` + `wp_cache_supports('flush_group')`)
- ✓ Decision #5 (private cache key + filter) → Task 14 (`private static function cacheKey()` + filter)

**Render flow coverage:**
- ✓ Step 1 (discriminator) → Tasks 3-8 (`isInserterPreview()` tests) + Task 11 (integration call)
- ✓ Step 2 (schema resolve) → Task 13 (`block_<name>_template` filter dispatch)
- ✓ Step 3 (cache lookup) → Tasks 15 (memo) + 16 (Redis)
- ✓ Step 4 (data hydration) → Task 17 (`Helpers::formatFields()`)
- ✓ Step 5 (filters) → Tasks 11 (content, gated) + 13 (template, always)
- ✓ Step 6 (Timber compile) → Task 17
- ✓ Step 7 (side-effects + alert) → Tasks 18 (side-effect tracking) + 19 (empty alert)

**Placeholder scan:** No "TBD" / "TODO" / "fill in" markers. All code blocks contain complete implementations. ✓

**Type consistency:** `cacheKey()` signature matches between Task 14 (definition) and how it's called from `render()` in the same task. `renderEmptyAlert()` signature `(string $block_name, array $attributes)` matches the call site in Task 19. ✓

**Test ordering:** Each test is added after the implementation that makes it pass (write-test → fail → implement → pass → commit). For the pure discriminator (Tasks 4-8) the implementation in Task 3 already covers the matrix, so subsequent tests pass on the first run — this is correct and noted. ✓

---

## References

- Spec: [`docs/superpowers/specs/2026-05-15-block-renderer-design.md`](../specs/2026-05-15-block-renderer-design.md)
- Roadmap (superseded by spec): [`docs/superpowers/specs/2026-05-15-block-renderer-roadmap.md`](../specs/2026-05-15-block-renderer-roadmap.md)
- Original `timber_block_render_callback()`: `portadesign/wordpress-base` → `starter_theme/functions.php` (post-PR-#27 version)
- Existing test patterns: `tests/Unit/Helpers/FieldFormatterTest.php` (Brain Monkey setup), `tests/Unit/HelpersTestCase.php` (base case)
- WordPress `.block-editor-warning` classes: rendered by [`@wordpress/block-editor`](https://github.com/WordPress/gutenberg/blob/trunk/packages/block-editor/src/components/warning/index.js), stable since Gutenberg 5.x
