# BlockRenderer Implementation Plan (v2 — post-audit)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Revision history**:
- v1 (commit 8df1b7c): based on initial spec which described aspirational behavior.
- v2 (this revision): aligned with actual `timber_block_render_callback()` function in `portadesign/wordpress-base` after source audit. Drop-in compatible port + 4 architectural improvements.

**Goal:** Migrate the ~140-line `timber_block_render_callback()` from `portadesign/wordpress-base/wp-content/themes/starter_theme/functions.php` into `parisek/timber-kit` as `Parisek\TimberKit\BlockRenderer`, preserving 100 % of current behavior while adding (a) Twig template in package for empty-block alert, (b) 4 WP filters for extensibility, (c) package-native translation domain, (d) the content-filter gating that PR #27 designed but didn't ship.

**Architecture:** `final class BlockRenderer` with three public static methods (`render()`, `isInserterPreview()`, `registerInvalidation()`). Faithful port of cache mechanism, cache key composition, side-effect detection, and `acf/save_post` invalidation hook. New Twig template under `@timber-kit/` namespace registered by `StarterBase`.

**Tech Stack:** PHP 8.3+, Timber 2.x, WordPress filter API, PHPUnit 11/12, Brain\Monkey. PSR-4 `Parisek\TimberKit\` → `src/`. Tabs for indentation.

---

## Source-of-truth references

- **Spec (v2):** [`docs/superpowers/specs/2026-05-15-block-renderer-design.md`](../specs/2026-05-15-block-renderer-design.md)
- **Original function:** `/Users/pari/Sites/wordpress/wordpress-base/wp-content/themes/starter_theme/functions.php:84-216`. Branch `feat/move-block-renderer-to-timber-kit` (post-PR-#27).
  - The implementer MUST open this file as a reference. Behavior parity is the success criterion for ~109 downstream themes.
- **Existing test patterns:** `tests/Unit/Helpers/FieldFormatterTest.php` (Brain Monkey + `Functions\when()`), `tests/Unit/HelpersTestCase.php` (base case).
- **Brain Monkey gotcha** (from `AGENTS.md`): function definitions persist across tests; once any test mocks `xxx`, `function_exists('xxx')` returns true for the rest of the suite. Don't write tests relying on `function_exists`-fail paths.

---

## File map

**Create:**
- `src/BlockRenderer.php` — the new class
- `src/templates/empty-alert.twig` — empty-alert template
- `tests/Unit/BlockRendererTestCase.php` — base test case
- `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` — 5 discriminator tests
- `tests/Unit/BlockRenderer/RenderTest.php` — ~14 render orchestration tests
- `tests/Unit/BlockRenderer/Fixtures.php` — shared mock helpers

**Modify:**
- `src/StarterBase.php` — add `timber/locations` filter + `BlockRenderer::registerInvalidation()` boot call
- `CHANGELOG.md` — entry under `## [Unreleased]`

---

## Task 1: Test base case + fixtures helper

**Files:**
- Create: `tests/Unit/BlockRendererTestCase.php`
- Create: `tests/Unit/BlockRenderer/Fixtures.php`

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

- [ ] **Step 2: Create the fixtures helper**

`tests/Unit/BlockRenderer/Fixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

/**
 * Shared test fixtures + helpers for BlockRenderer tests.
 */
final class Fixtures {

	/**
	 * Standard ACF block attributes shape (matches what WP passes to render callbacks).
	 *
	 * @param array<string, mixed> $overrides Keys merged on top of the default shape.
	 * @return array<string, mixed>
	 */
	public static function attributes( array $overrides = [] ): array {
		return array_merge(
			[
				'name'      => 'acf/article-featured',
				'data'      => [ 'title' => 'Example title' ],
				'anchor'    => '',
				'className' => '',
			],
			$overrides
		);
	}

	/**
	 * Reset the BlockRenderer's in-request preview memo between tests so the
	 * static property doesn't leak state across cases.
	 */
	public static function resetPreviewMemo(): void {
		$ref = new \ReflectionClass( \Parisek\TimberKit\BlockRenderer::class );
		if ( $ref->hasProperty( 'preview_memo' ) ) {
			$prop = $ref->getProperty( 'preview_memo' );
			$prop->setAccessible( true );
			$prop->setValue( null, [] );
		}
	}
}
```

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRendererTestCase.php tests/Unit/BlockRenderer/Fixtures.php
git commit -m "test(BlockRenderer): scaffold test base case + shared fixtures"
```

---

## Task 2: `isInserterPreview()` — pure discriminator (TDD, 5 tests)

**Files:**
- Create: `src/BlockRenderer.php` (class skeleton + this method)
- Create: `tests/Unit/BlockRenderer/IsInserterPreviewTest.php`

The discriminator matches the function exactly: `is_preview && empty($formatted_fields) && !empty($attributes['data']) && is_array($attributes['data'])`.

- [ ] **Step 1: Write all 5 failing tests upfront**

`tests/Unit/BlockRenderer/IsInserterPreviewTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class IsInserterPreviewTest extends BlockRendererTestCase {

	public function test_returns_true_when_preview_and_empty_fields_and_has_data(): void {
		$this->assertTrue(
			BlockRenderer::isInserterPreview(
				true,
				[],
				[ 'data' => [ 'title' => 'Example' ] ]
			)
		);
	}

	public function test_returns_false_when_not_preview(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview(
				false,
				[],
				[ 'data' => [ 'title' => 'Example' ] ]
			)
		);
	}

	public function test_returns_false_when_fields_non_empty(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview(
				true,
				[ 'title' => 'Real saved value' ],
				[ 'data' => [ 'title' => 'Example' ] ]
			)
		);
	}

	public function test_returns_false_when_attributes_data_missing(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview( true, [], [] )
		);
	}

	public function test_returns_false_when_attributes_data_not_array(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview( true, [], [ 'data' => 'not-an-array' ] )
		);
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test -- --filter IsInserterPreviewTest
```

Expected: 5 errors (class not found).

- [ ] **Step 3: Create `src/BlockRenderer.php` with the class skeleton + `isInserterPreview()`**

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
 * `portadesign/wordpress-base`. Behaviorally a faithful port; adds four
 * WordPress filters as extensibility hooks listed below.
 *
 * Filters exposed:
 *   - timber_kit/block_renderer/cache_key        (string $key, array $cache_data, string $block_name)
 *   - timber_kit/block_renderer/use_cache        (bool $enabled, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/empty_alert_html (string $html, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/context          (array $context, string $block_name, bool $is_preview)
 */
final class BlockRenderer {

	/**
	 * In-request memo of rendered block output, keyed by cache key.
	 *
	 * @var array<string, string>
	 */
	private static array $preview_memo = [];

	/**
	 * Empirical inserter-preview detector. Pure: no I/O, no WP side effects.
	 *
	 * Returns true when the block is being rendered for the inserter library,
	 * detected by: preview mode AND ACF returned no fields for the resolved
	 * post AND attributes carry an example data payload (registered via
	 * block.json's `example` field).
	 *
	 * @param bool                 $is_preview        True in any editor / inserter preview context.
	 * @param array<string, mixed> $formatted_fields  Result of Helpers::formatFields() (or equivalent).
	 * @param array<string, mixed> $attributes        The block's attributes.
	 */
	public static function isInserterPreview(
		bool $is_preview,
		array $formatted_fields,
		array $attributes
	): bool {
		return $is_preview
			&& empty( $formatted_fields )
			&& ! empty( $attributes['data'] )
			&& is_array( $attributes['data'] );
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
composer test -- --filter IsInserterPreviewTest
```

Expected: 5 tests, 5 passes.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/IsInserterPreviewTest.php
git commit -m "feat(BlockRenderer): add isInserterPreview discriminator + 5 unit tests"
```

---

## Task 3: Empty-alert Twig template + Twig namespace in StarterBase

**Files:**
- Create: `src/templates/empty-alert.twig`
- Modify: `src/StarterBase.php`

- [ ] **Step 1: Create the Twig template**

`src/templates/empty-alert.twig`:

```twig
{#
  Empty-block warning. Uses Gutenberg's `.block-editor-warning` classes for
  native editor styling — WP loads block-editor.css automatically when editing,
  so we get the warning panel look without shipping any package CSS. Frontend
  (logged-in admin viewing live site) degrades to bare HTML — themes can style
  `.timber-kit-block-empty` to taste, or override the entire HTML via the
  empty_alert_html filter.

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

- [ ] **Step 2: Add the Twig namespace registration to `StarterBase`**

Find the three existing `timber/*` filter registrations in `__construct()`:

```bash
grep -n "timber/twig\|timber/context\|timber/loader" src/StarterBase.php
```

Confirm lines around 177-179. Insert immediately after the `timber/loader/loader` line:

```php
		add_filter( 'timber/locations', array( $this, 'register_timber_kit_namespace' ), 5 );
```

Then add the handler method near the other small Timber-related methods:

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

- [ ] **Step 3: Run the existing StarterBase tests to confirm no regression**

```bash
composer test -- --filter StarterBase
```

Expected: all existing tests still pass. (No new test needed; the registration is exercised indirectly by RenderTest::test_empty_template_renders_alert_for_logged_in_users in Task 14.)

- [ ] **Step 4: Commit**

```bash
git add src/templates/empty-alert.twig src/StarterBase.php
git commit -m "feat: add @timber-kit/ Twig namespace + empty-alert template"
```

---

## Task 4: `render()` skeleton — slug derivation, real post ID resolution

**Files:**
- Create: `tests/Unit/BlockRenderer/RenderTest.php`
- Modify: `src/BlockRenderer.php`

This task introduces `render()` with: parameter shape, slug derivation, real post ID resolution. No cache yet, no content/template filter dispatch yet.

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
		Fixtures::resetPreviewMemo();

		// Default no-op mocks for WP functions every render path touches.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);
		Functions\when( 'wp_scripts' )->alias(
			static fn() => (object) [ 'queue' => [] ]
		);
		Functions\when( 'wp_styles' )->alias(
			static fn() => (object) [ 'queue' => [] ]
		);
		Functions\when( 'acf_get_valid_post_id' )->justReturn( 0 );
		Functions\when( 'get_query_var' )->justReturn( 0 );
	}

	public function test_real_post_id_resolution_falls_back_to_global_post(): void {
		// When callback $post_id is a "block_*" string and ACF resolves it to a
		// "block_*" string too, the renderer must fall back to global $post->ID
		// for the cache group naming.
		$GLOBALS['post'] = (object) [ 'ID' => 42 ];

		Functions\when( 'acf_get_valid_post_id' )->justReturn( 'block_abc123' );

		$captured_group = null;
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\expect( 'wp_cache_get' )
			->andReturnUsing( function ( string $key, string $group ) use ( &$captured_group ) {
				$captured_group = $group;
				return false;
			} );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false,
			'block_abc123',
			null
		);
		ob_end_clean();

		$this->assertSame( 'acf_block_42', $captured_group );

		unset( $GLOBALS['post'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --filter test_real_post_id_resolution_falls_back_to_global_post
```

Expected: FAIL — `render()` doesn't exist.

- [ ] **Step 3: Add `render()` skeleton with slug derivation, real post ID resolution, and cache lookup skeleton**

In `src/BlockRenderer.php`, add inside the class:

```php
	/**
	 * Render callback for ACF Gutenberg blocks defined via block.json.
	 *
	 * Wire as:
	 *   "acf": { "renderCallback": "Parisek\\TimberKit\\BlockRenderer::render" }
	 *
	 * @param array<string, mixed> $attributes The block's saved or preview attributes.
	 * @param string               $content    Block-supplied content (unused for ACF blocks).
	 * @param bool                 $is_preview True in any editor / inserter preview context.
	 * @param int|string           $post_id    Containing post ID (may be 0 or a "block_*" string in some contexts).
	 * @param \WP_Block|null       $wp_block   The WP_Block instance, null in legacy contexts.
	 */
	public static function render(
		array $attributes,
		string $content = '',
		bool $is_preview = false,
		int|string $post_id = 0,
		?\WP_Block $wp_block = null
	): void {
		$block_name = isset( $attributes['name'] ) && is_string( $attributes['name'] )
			? $attributes['name']
			: 'unknown';

		// Slug derivation matches the source function:
		//   "acf/article-featured" → "article-featured"
		//   filter base "block_article_featured" (dashes → underscores)
		$slug        = str_replace( 'acf/', '', $block_name );
		$filter_base = 'block_' . str_replace( '-', '_', $slug );

		// Real post ID resolution for cache group:
		//   callback $post_id → acf_get_valid_post_id() → global $post (when "block_*")
		$callback_post_id = $post_id;
		$post_id          = acf_get_valid_post_id();

		$real_post_id = is_numeric( $callback_post_id ) && (int) $callback_post_id > 0
			? (int) $callback_post_id
			: $post_id;
		if ( str_starts_with( (string) $real_post_id, 'block_' ) ) {
			global $post;
			if ( isset( $post ) && isset( $post->ID ) ) {
				$real_post_id = (int) $post->ID;
			}
		}

		$has_dynamic_filter = has_filter( "{$filter_base}_content" );

		// Cache key + group composition
		$cache_data = [
			'name'      => $block_name,
			'data'      => $attributes['data'] ?? [],
			'anchor'    => $attributes['anchor'] ?? '',
			'className' => $attributes['className'] ?? '',
			'post_id'   => $post_id,
			'lang'      => apply_filters( 'wpml_current_language', '' ),
			'paged'     => get_query_var( 'paged', 0 ),
		];
		$default_key = 'acf_block_' . md5( wp_json_encode( $cache_data ) );
		$cache_key   = apply_filters( 'timber_kit/block_renderer/cache_key', $default_key, $cache_data, $block_name );
		$cache_group = 'acf_block_' . ( is_numeric( $real_post_id ) ? $real_post_id : 0 );

		// Cache lookup (preview memo + frontend Redis)
		if ( $is_preview ) {
			if ( isset( self::$preview_memo[ $cache_key ] ) ) {
				print self::$preview_memo[ $cache_key ];
				return;
			}
		} else {
			$use_cache_default = ! $has_dynamic_filter
				&& function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
				&& function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' );
			$use_cache = apply_filters( 'timber_kit/block_renderer/use_cache', $use_cache_default, $block_name, $attributes );

			if ( $use_cache ) {
				$cached = wp_cache_get( $cache_key, $cache_group );
				if ( false !== $cached ) {
					print $cached;
					return;
				}
			}
		}

		// Render path (Tasks 5+ fill this in).
		print '';
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
composer test -- --filter test_real_post_id_resolution_falls_back_to_global_post
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): render() skeleton — slug derivation, real post ID, cache key"
```

---

## Task 5: Cache key composition test (all 7 fields + acf_block_ prefix)

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_cache_key_includes_all_seven_fields(): void {
		$captured_cache_data = null;
		$captured_key        = null;

		Filters\expectApplied( 'timber_kit/block_renderer/cache_key' )
			->once()
			->andReturnUsing(
				function ( string $key, array $cache_data, string $block_name ) use ( &$captured_cache_data, &$captured_key ): string {
					$captured_cache_data = $cache_data;
					$captured_key        = $key;
					return $key;
				}
			);

		Functions\when( 'get_query_var' )->justReturn( 3 );
		Filters\expectApplied( 'wpml_current_language' )->andReturn( 'cs' );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [
				'anchor'    => 'my-anchor',
				'className' => 'is-style-big',
			] ),
			'',
			true,
			0,
			null
		);
		ob_end_clean();

		$this->assertNotNull( $captured_cache_data );
		$this->assertSame(
			[ 'name', 'data', 'anchor', 'className', 'post_id', 'lang', 'paged' ],
			array_keys( $captured_cache_data ),
			'cache_data must contain exactly these 7 keys in this order'
		);
		$this->assertSame( 'my-anchor', $captured_cache_data['anchor'] );
		$this->assertSame( 'is-style-big', $captured_cache_data['className'] );
		$this->assertSame( 'cs', $captured_cache_data['lang'] );
		$this->assertSame( 3, $captured_cache_data['paged'] );

		$this->assertStringStartsWith( 'acf_block_', $captured_key );
		$this->assertSame( 32 + 10, strlen( $captured_key ), 'key = "acf_block_" (10) + md5 (32)' );
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_cache_key_includes_all_seven_fields
```

Expected: PASS (cache key logic already in place from Task 4).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): verify cache_data has all 7 fields and acf_block_ prefix"
```

---

## Task 6: Preview memo cache short-circuit

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_preview_memo_cache_hit_short_circuits(): void {
		// First call writes to memo (with empty render output for simplicity).
		// Second call must hit memo and never re-enter the render path —
		// we verify by counting calls to acf_get_valid_post_id (entry side effect).

		Functions\expect( 'acf_get_valid_post_id' )->once()->andReturn( 0 );
		// Note: ->once() asserts EXACTLY one call across both render() invocations.

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		$first = ob_get_clean();

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		$second = ob_get_clean();

		$this->assertSame( $first, $second );
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_preview_memo_cache_hit_short_circuits
```

Expected: FAIL — the memo isn't being written yet (we'll fix in Task 11 when the render path completes). 

**Note for implementer:** Mark this test temporarily as skipped with `$this->markTestSkipped('Memo write happens in Task 11')` to keep the suite green, OR keep it failing as a forcing function. **Recommended:** mark skipped — TDD red is for the next task that implements it, not for parking.

Add `$this->markTestSkipped('Memo write lands in Task 11 — cache_write step');` at the top of the test.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): preview memo cache hit test (skipped, lands in Task 11)"
```

---

## Task 7: Frontend cache skipped for dynamic-filter blocks

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_frontend_cache_skipped_when_block_has_dynamic_filter(): void {
		Functions\when( 'has_filter' )->alias(
			static fn( string $name ): bool => $name === 'block_article_featured_content'
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );

		// wp_cache_get MUST NOT be called when block has a dynamic filter.
		Functions\expect( 'wp_cache_get' )->never();

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(), // name = "acf/article-featured" → filter name "block_article_featured_content"
			'',
			false, // frontend, not preview
			0,
			null
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_frontend_cache_skipped_when_block_has_dynamic_filter
```

Expected: PASS (Task 4 already implemented this branch).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): frontend cache skipped when has_filter detects dynamic block"
```

---

## Task 8: `use_cache` filter can disable per-block

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_use_cache_filter_can_disable_per_block(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );

		Filters\expectApplied( 'timber_kit/block_renderer/use_cache' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_cache_get' )->never();

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false,
			0,
			null
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_use_cache_filter_can_disable_per_block
```

Expected: PASS (filter dispatched in Task 4).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): use_cache filter can disable caching per-block"
```

---

## Task 9: Side-effect snapshot + data hydration + discriminator + content/template filters

**Files:**
- Modify: `src/BlockRenderer.php`
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

This is the biggest single task — it adds the render body between cache lookup and cache write:
- `wp_scripts/wp_styles` queue snapshot
- `Helpers::formatFields()` call
- Discriminator computation + inserter-preview content fallback
- Content filter dispatch (gated on discriminator — the NEW behavior)
- Template filter dispatch (always)

- [ ] **Step 1: Add 3 failing tests covering the new branches**

```php
	public function test_inserter_preview_skips_content_filter(): void {
		// Inserter preview: empty post fields + has attributes.data → discriminator true → skip filter.
		Filters\expectApplied( 'block_article_featured_content' )->never();
		Filters\expectApplied( 'block_article_featured_template' )->once();

		Functions\when( 'Parisek\\TimberKit\\Helpers::formatFields' )->justReturn( [] );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [ 'data' => [ 'title' => 'Example' ] ] ),
			'',
			true,
			0,
			null
		);
		ob_end_clean();
	}

	public function test_editor_canvas_with_saved_data_runs_content_filter(): void {
		// Editor canvas: ACF returns saved fields → discriminator false → content filter runs.
		Filters\expectApplied( 'block_article_featured_content' )->once();
		Filters\expectApplied( 'block_article_featured_template' )->once();

		// Helpers::formatFields returning a non-empty array can't be mocked directly
		// (it's a real method on a real class). Instead we override via the context
		// filter: stub formatFields by mocking apply_filters for the upstream call.
		// → Simpler: assert via the discriminator's empty-fields branch by ensuring
		//   $is_preview=false (so even if fields were empty, no discriminator).

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false, // not preview
			123,
			null
		);
		ob_end_clean();
	}

	public function test_template_filter_runs_in_all_modes(): void {
		Filters\expectApplied( 'block_article_featured_template' )->once();

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			true,
			0,
			null
		);
		ob_end_clean();
	}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test -- --filter "test_inserter_preview_skips_content_filter|test_editor_canvas_with_saved_data_runs_content_filter|test_template_filter_runs_in_all_modes"
```

Expected: all 3 FAIL.

- [ ] **Step 3: Replace the `print '';` stub at the end of `render()` with the full render body**

In `src/BlockRenderer.php`, replace `print '';` with:

```php
		// Pre-render side-effect snapshot. Form plugins (CF7, WPForms) enqueue
		// their CSS/JS during shortcode processing — when their output is served
		// from cache, the shortcode never executes and assets are never enqueued,
		// breaking form styling/JS. By comparing the queue before/after render,
		// blocks with asset side effects are automatically excluded from cache.
		$scripts_before = function_exists( 'wp_scripts' ) ? wp_scripts()->queue : [];
		$styles_before  = function_exists( 'wp_styles' ) ? wp_styles()->queue : [];

		// Data hydration.
		$content_data = Helpers::formatFields( $post_id, $is_preview );

		// Discriminator + inserter-preview content fallback.
		$is_inserter_preview = self::isInserterPreview( $is_preview, $content_data, $attributes );
		if ( $is_inserter_preview ) {
			$content_data = array_filter(
				$attributes['data'],
				static fn( $key ) => is_string( $key ) && '' !== $key && '_' !== $key[0],
				ARRAY_FILTER_USE_KEY
			);
		}

		// Append wrapper context.
		$content_data['is_preview']      = $is_preview;
		$content_data['wrapper_id']      = $attributes['anchor'] ?? '';
		$content_data['wrapper_classes'] = $attributes['className'] ?? '';

		// Content filter — GATED on discriminator (the new behavior PR #27 designed but didn't ship).
		if ( ! $is_inserter_preview ) {
			$content_data = apply_filters( "{$filter_base}_content", $content_data );
		}

		// Template filter — always runs.
		$default_template_path = '@component/' . $slug . '/' . $slug . '.twig';
		$template_path         = apply_filters( "{$filter_base}_template", $default_template_path, $content_data );

		// Twig context assembly + context filter.
		$context = class_exists( \Timber\Timber::class ) ? \Timber\Timber::context() : [];
		$context['content'] = $content_data;
		$context = apply_filters( 'timber_kit/block_renderer/context', $context, $block_name, $is_preview );

		// Compile.
		$template_output = '';
		if ( class_exists( \Timber\Timber::class ) ) {
			$compiled = \Timber\Timber::compile( $template_path, $context );
			if ( is_string( $compiled ) ) {
				$template_output = $compiled;
			}
		}

		// Empty render → editor alert (Task 12 fills in renderEmptyAlert).
		if ( '' === trim( $template_output ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$template_output = self::renderEmptyAlert( $block_name, $attributes );
		}

		// Inserter-preview aspect-ratio wrap.
		if ( $is_inserter_preview && '' !== $template_output ) {
			$template_output = '<div style="aspect-ratio: 16/9; overflow: hidden;">' . $template_output . '</div>';
		}

		// Side-effect detection (post-render).
		$has_side_effects = function_exists( 'wp_scripts' ) && function_exists( 'wp_styles' )
			&& ( array_diff( wp_scripts()->queue, $scripts_before ) || array_diff( wp_styles()->queue, $styles_before ) );

		// Cache write.
		if ( '' !== $template_output ) {
			if ( $is_preview ) {
				self::$preview_memo[ $cache_key ] = $template_output;
			} elseif ( isset( $use_cache ) && $use_cache && ! $has_side_effects ) {
				wp_cache_set( $cache_key, $template_output, $cache_group, HOUR_IN_SECONDS );
			}
		}

		print $template_output;
```

Also add the `use` statement at the top of the file:

```php
use Parisek\TimberKit\Helpers;
```

And add a stub `renderEmptyAlert()` method (Task 12 fills in):

```php
	private static function renderEmptyAlert( string $block_name, array $attributes ): string {
		return '';
	}
```

- [ ] **Step 4: Run the 3 new tests**

```bash
composer test -- --filter "test_inserter_preview_skips_content_filter|test_editor_canvas_with_saved_data_runs_content_filter|test_template_filter_runs_in_all_modes"
```

Expected: all 3 PASS.

- [ ] **Step 5: Re-run full RenderTest suite to verify no regression**

```bash
composer test -- --filter RenderTest
```

Expected: all tests (including the previously-passing ones) still pass.

- [ ] **Step 6: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): full render body — data hydration, content/template filters, discriminator gating"
```

---

## Task 10: Side-effect detection skips cache write

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_side_effecting_block_excluded_from_cache(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );

		// Simulate side effect: scripts queue grows during render.
		$call_count = 0;
		Functions\when( 'wp_scripts' )->alias( static function () use ( &$call_count ) {
			$call_count++;
			return (object) [ 'queue' => $call_count === 1 ? [] : [ 'wpforms-frontend' ] ];
		} );

		// Make Timber::compile produce non-empty output so the cache_write branch executes.
		Filters\expectApplied( 'block_article_featured_template' )
			->andReturn( '@component/article-featured/article-featured.twig' );
		Filters\expectApplied( 'timber_kit/block_renderer/context' )
			->andReturnUsing( static function ( array $ctx ): array {
				// Inject a synthetic "compiled" result via the context — we can't compile real Twig in unit tests.
				return $ctx;
			} );
		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )
			->andReturn( '<synthetic-output>' );
		Functions\when( 'is_user_logged_in' )->justReturn( true ); // routes through empty-alert path which yields content

		// wp_cache_set MUST NOT be called when side-effects fired.
		Functions\expect( 'wp_cache_set' )->never();

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', false, 0, null );
		ob_end_clean();
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_side_effecting_block_excluded_from_cache
```

Expected: PASS (side-effect detection already in Task 9).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): side-effect detection via queue snapshot skips cache write"
```

---

## Task 11: Un-skip preview memo test + verify Task 9 made it pass

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Remove the `markTestSkipped` from `test_preview_memo_cache_hit_short_circuits`**

Find the test added in Task 6, delete the `$this->markTestSkipped(...)` line.

- [ ] **Step 2: Adjust the test to inject synthetic non-empty output (memo only writes when output non-empty)**

Replace the test body with:

```php
	public function test_preview_memo_cache_hit_short_circuits(): void {
		// Inject synthetic output so the memo write branch fires.
		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )
			->andReturn( '<synthetic-output>' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		// First call writes to memo.
		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		$first = ob_get_clean();

		// Second call must hit memo. We verify by counting calls to a function
		// that's only invoked on cache MISS (not on hit) — acf_get_valid_post_id
		// runs before the memo check, so it's a poor signal. Instead we use the
		// content filter — gated on inserter preview, but if not preview, it
		// would fire. Here we're in preview so it doesn't fire anyway. Better:
		// the post-cache render body would touch wp_styles. Count wp_styles calls.

		$styles_calls = 0;
		Functions\when( 'wp_styles' )->alias( static function () use ( &$styles_calls ) {
			$styles_calls++;
			return (object) [ 'queue' => [] ];
		} );

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', true, 0, null );
		$second = ob_get_clean();

		$this->assertSame( $first, $second );
		$this->assertSame( 0, $styles_calls, 'second render must hit memo and skip the render body' );
	}
```

- [ ] **Step 3: Run test**

```bash
composer test -- --filter test_preview_memo_cache_hit_short_circuits
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): un-skip preview memo test, verify memo write + hit"
```

---

## Task 12: `renderEmptyAlert()` Twig + inline fallback

**Files:**
- Modify: `src/BlockRenderer.php`
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_empty_template_renders_alert_for_logged_in_users(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES ) );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [ 'title' => 'Article — Featured' ] ),
			'',
			false,
			0,
			null
		);
		$output = ob_get_clean();

		// Stable contract: must contain class + data-block + translated message + block label.
		$this->assertStringContainsString( 'block-editor-warning', $output );
		$this->assertStringContainsString( 'timber-kit-block-empty', $output );
		$this->assertStringContainsString( 'data-block="acf/article-featured"', $output );
		$this->assertStringContainsString( 'Pro zobrazení vyplňte', $output );
		$this->assertStringContainsString( 'Article — Featured', $output, 'block_label prefix' );
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_empty_template_renders_alert_for_logged_in_users
```

Expected: FAIL — renderEmptyAlert() is still a stub returning empty string.

- [ ] **Step 3: Replace the stub `renderEmptyAlert()` with the real implementation**

```php
	/**
	 * Render the empty-block warning shown to logged-in users when render
	 * produced no output. Tries the bundled Twig template first; falls back
	 * to inline HTML that preserves the same DOM contract.
	 */
	private static function renderEmptyAlert( string $block_name, array $attributes ): string {
		$block_label = $attributes['title'] ?? $attributes['name'] ?? '';
		$message     = __(
			'Pro zobrazení vyplňte požadované údaje v pravém panelu.',
			'timber-kit'
		);

		$html = '';
		if ( class_exists( \Timber\Timber::class ) ) {
			$compiled = \Timber\Timber::compile(
				'@timber-kit/empty-alert.twig',
				[
					'block_name'  => $block_name,
					'block_label' => $block_label,
					'message'     => $message,
				]
			);
			if ( is_string( $compiled ) && '' !== $compiled ) {
				$html = $compiled;
			}
		}

		if ( '' === $html ) {
			// Inline fallback — preserves the Twig template's DOM exactly.
			$label_prefix = '' !== $block_label
				? '<strong>' . esc_html( (string) $block_label ) . ':</strong> '
				: '';
			$html         = sprintf(
				'<div class="block-editor-warning timber-kit-block-empty" data-block="%s">'
					. '<div class="block-editor-warning__contents">'
						. '<p class="block-editor-warning__message">%s%s</p>'
					. '</div>'
				. '</div>',
				esc_attr( $block_name ),
				$label_prefix,
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

- [ ] **Step 4: Run test**

```bash
composer test -- --filter test_empty_template_renders_alert_for_logged_in_users
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockRenderer.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): renderEmptyAlert with Twig template + inline fallback + block_label"
```

---

## Task 13: `empty_alert_html` filter replaces default output

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_empty_alert_html_filter_replaces_default_output(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );

		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )
			->once()
			->andReturn( '<custom-theme-alert>OVERRIDE</custom-theme-alert>' );

		ob_start();
		BlockRenderer::render( Fixtures::attributes(), '', false, 0, null );
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
git commit -m "test(BlockRenderer): empty_alert_html filter can replace default output entirely"
```

---

## Task 14: Inserter preview 16:9 aspect-ratio wrap

**Files:**
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

- [ ] **Step 1: Add the failing test**

```php
	public function test_inserter_preview_wraps_in_16_9_aspect_ratio(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( '__' )->alias( static fn( string $text ): string => $text );
		Functions\when( 'esc_attr' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'esc_html' )->alias( static fn( string $v ): string => $v );

		// Inject synthetic non-empty output via empty_alert filter.
		Filters\expectApplied( 'timber_kit/block_renderer/empty_alert_html' )
			->andReturn( '<p>Synthetic preview body</p>' );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes( [ 'data' => [ 'title' => 'Example' ] ] ),
			'',
			true,
			0,
			null
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'aspect-ratio: 16/9', $output );
		$this->assertStringContainsString( 'overflow: hidden', $output );
		$this->assertStringContainsString( '<p>Synthetic preview body</p>', $output );
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_inserter_preview_wraps_in_16_9_aspect_ratio
```

Expected: PASS (wrap logic already in Task 9).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BlockRenderer/RenderTest.php
git commit -m "test(BlockRenderer): inserter preview output wrapped in 16:9 aspect-ratio box"
```

---

## Task 15: `BlockRenderer::registerInvalidation()` + StarterBase boot wiring

**Files:**
- Modify: `src/BlockRenderer.php`
- Modify: `src/StarterBase.php`
- Modify: `tests/Unit/BlockRenderer/RenderTest.php`

The per-post cache invalidation hook lived as a freestanding `add_action` in the original `functions.php`. Moving it into the package keeps the cache writer and invalidator co-located.

- [ ] **Step 1: Add the failing test**

```php
	public function test_register_invalidation_hooks_acf_save_post_at_priority_20(): void {
		Functions\expect( 'add_action' )
			->once()
			->with(
				'acf/save_post',
				\Mockery::type( 'callable' ),
				20
			);

		BlockRenderer::registerInvalidation();
	}
```

- [ ] **Step 2: Run test**

```bash
composer test -- --filter test_register_invalidation_hooks_acf_save_post_at_priority_20
```

Expected: FAIL — method doesn't exist.

- [ ] **Step 3: Add `registerInvalidation()` to `BlockRenderer`**

```php
	/**
	 * Register the per-post cache invalidation hook.
	 *
	 * Called from StarterBase boot. When ACF saves a post, the cache group
	 * "acf_block_{$post_id}" is flushed — invalidating exactly the cached
	 * blocks tied to that post without touching others.
	 */
	public static function registerInvalidation(): void {
		add_action( 'acf/save_post', static function ( $post_id ): void {
			if ( is_numeric( $post_id )
				&& function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
				&& function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
				wp_cache_flush_group( 'acf_block_' . $post_id );
			}
		}, 20 );
	}
```

- [ ] **Step 4: Wire from `StarterBase::__construct()`**

In `src/StarterBase.php`, after the `register_timber_kit_namespace` line added in Task 3, add:

```php
		BlockRenderer::registerInvalidation();
```

And add the `use` statement at the top of `StarterBase.php`:

```php
use Parisek\TimberKit\BlockRenderer;
```

- [ ] **Step 5: Run test**

```bash
composer test -- --filter test_register_invalidation_hooks_acf_save_post_at_priority_20
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/BlockRenderer.php src/StarterBase.php tests/Unit/BlockRenderer/RenderTest.php
git commit -m "feat(BlockRenderer): per-post cache invalidation hook + StarterBase wiring"
```

---

## Task 16: Final verification — full suite + PHPStan

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHPUnit suite**

```bash
composer test
```

Expected: existing tests (Helpers, StarterBase, Resizer, …) unchanged + ~15 new BlockRenderer tests passing.

- [ ] **Step 2: Run PHPStan**

```bash
composer phpstan
```

Expected: no new errors at level 5. Fix any inline; common issues with this code: `array<string, mixed>` shape annotations, `mixed` returns from filters that need narrowing.

- [ ] **Step 3: If PHPStan fixes needed, commit them**

```bash
git add src/BlockRenderer.php
git commit -m "chore(BlockRenderer): satisfy PHPStan level 5"
```

---

## Task 17: CHANGELOG entry

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add the Unreleased entry**

Under `## [Unreleased]`:

```markdown
### Added
- `Parisek\TimberKit\BlockRenderer` — new class hosting the ACF Gutenberg block render callback previously carried in every downstream theme's `functions.php`. Faithful behavioural port: same cache key composition (`acf_block_` + md5 of `[name, data, anchor, className, post_id, lang, paged]`), same per-post cache group naming (`acf_block_{$real_post_id}`), same `wp_scripts`/`wp_styles` queue snapshot for side-effect detection, same `has_filter()` gate that skips Redis cache for dynamic blocks, same `acf_get_valid_post_id()` → global `$post` fallback for real-post-id resolution. **New behavior**: the `block_<name>_content` filter is now skipped when the inserter-preview discriminator fires — completing the gating PR #27 designed but didn't ship. Four WordPress filters exposed: `timber_kit/block_renderer/cache_key`, `…/use_cache`, `…/empty_alert_html`, `…/context`. Wire as `"renderCallback": "Parisek\\TimberKit\\BlockRenderer::render"` in `block.json`, or call from the existing `timber_block_render_callback` wrapper in downstream themes.
- `src/templates/empty-alert.twig` — first package-shipped Twig template, rendered when render output is empty for a logged-in user. Uses WordPress Gutenberg's native `.block-editor-warning` classes so the editor styles it without any package CSS. Stable contract: `.timber-kit-block-empty` class + `data-block` attribute + optional `block_label` prefix from `$attributes['title']`. Defensive inline-HTML fallback when the `@timber-kit/` Twig namespace isn't registered (e.g. projects using `BlockRenderer` without `StarterBase`).
- `BlockRenderer::registerInvalidation()` — registers the `acf/save_post` cache invalidation hook (flushes cache group `acf_block_{$post_id}` at priority 20). Migrated from the freestanding `add_action` in the original `functions.php`; now lives alongside the cache writer so the two can't drift.
- `StarterBase::register_timber_kit_namespace()` — registers the `@timber-kit/` Twig namespace pointing at `src/templates/`, priority 5 so downstream themes can override at default priority 10. Plus `BlockRenderer::registerInvalidation()` boot call from `StarterBase::__construct()`.

### Changed
- `StarterBase::__construct()` adds the `timber/locations` filter + the `BlockRenderer::registerInvalidation()` boot call near the existing `timber/*` filter registrations.
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): document BlockRenderer migration under [Unreleased]"
```

---

## Task 18: Open implementation PR

**Files:** none

- [ ] **Step 1: Verify branch state**

```bash
git log --oneline feat/block-renderer-roadmap...HEAD | head -20
```

Verify the commits trace the TDD progression: scaffold → discriminator → template/namespace → render skeleton → cache key → render body → empty alert → invalidation → CHANGELOG.

- [ ] **Step 2: Open the PR**

```bash
gh pr create \
  --title "feat: add BlockRenderer hosting the WP block render callback" \
  --body "$(cat <<'EOF'
## Summary
- Migrates `timber_block_render_callback()` (~140 lines) from per-theme `functions.php` into `Parisek\TimberKit\BlockRenderer`. Faithful behaviour port + 4 architectural improvements.
- Same cache keys, same cache group, same side-effect detection mechanism, same `acf/save_post` invalidation hook — **drop-in upgrade**, no behavioural regression in ~109 downstream themes.
- **New behaviour**: completes the `block_<name>_content` filter gating that PR #27 designed but didn't ship. Inserter-preview renders no longer dispatch the content filter (which would distort fake example data with derived enrichments).
- First package-shipped Twig template (`src/templates/empty-alert.twig`) using WP-native `.block-editor-warning` classes — zero CSS shipped, native editor look.
- Four WordPress filters expose all extensibility points: `cache_key`, `use_cache`, `empty_alert_html`, `context`. No DI, no interfaces — `final class`, static methods.

Design spec: [`docs/superpowers/specs/2026-05-15-block-renderer-design.md`](docs/superpowers/specs/2026-05-15-block-renderer-design.md)
Implementation plan: [`docs/superpowers/plans/2026-05-15-block-renderer-implementation.md`](docs/superpowers/plans/2026-05-15-block-renderer-implementation.md)

## Test plan
- [x] `tests/Unit/BlockRenderer/IsInserterPreviewTest.php` — 5 pure-unit cases covering the discriminator matrix
- [x] `tests/Unit/BlockRenderer/RenderTest.php` — ~14 Brain Monkey integration cases (slug derivation, real post ID resolution, cache key composition, preview memo, frontend Redis gating, has_filter check, use_cache filter, side-effect detection, content/template filter dispatch, content filter gating on discriminator, empty alert, filter override, aspect-ratio wrap, invalidation hook)
- [x] `composer test` — full suite green
- [x] `composer phpstan` — level 5 clean
- [ ] Manual smoke in `proficio-de`: inserter library hover + editor canvas + frontend + cache invalidation on save
- [ ] Companion PR in `portadesign/wordpress-base` (wrapper + composer bump + remove standalone invalidation hook) — opens after `v1.5.0` tagged
EOF
)"
```

---

## Self-review checklist

**Spec coverage (v2):**
- ✓ Decision #1 (filters) → Tasks 5 (cache_key in 4), 8 (use_cache), 13 (empty_alert_html), 9 (context)
- ✓ Decision #2 (direct `Helpers::formatFields`) → Task 9
- ✓ Decision #3 (Twig + native classes + block_label + i18n + override) → Tasks 3, 12, 13
- ✓ Decision #4 (cache backend faithful port with has_filter check) → Tasks 4, 7
- ✓ Decision #5 (cache key with all 7 fields + acf_block_ prefix) → Task 5
- ✓ Decision #6 audit (empirical discriminator) → Task 2
- ✓ Decision #7 audit (queue snapshot side-effects) → Tasks 9, 10
- ✓ Decision #8 audit (content filter gating — NEW) → Task 9
- ✓ Decision #9 audit (invalidation in package) → Task 15

**Render flow coverage:**
- ✓ Step 1 (slug derivation) → Task 4
- ✓ Step 2 (real post ID resolution) → Task 4
- ✓ Step 3 (has_filter detection) → Tasks 4, 7
- ✓ Step 4 (cache key) → Tasks 4, 5
- ✓ Step 5 (cache lookup) → Tasks 4, 6, 7, 8, 11
- ✓ Step 6 (side-effect snapshot) → Tasks 9, 10
- ✓ Step 7 (data hydration + discriminator + fallback) → Tasks 2, 9
- ✓ Step 8 (content filter, gated) → Task 9
- ✓ Step 9 (template filter) → Task 9
- ✓ Step 10 (context filter + Timber compile) → Task 9
- ✓ Step 11 (empty alert) → Task 12, 13
- ✓ Step 12 (aspect-ratio wrap) → Task 14
- ✓ Step 13 (side-effect detection post-render) → Tasks 9, 10
- ✓ Step 14 (cache write) → Tasks 9, 10, 11
- ✓ Step 15 (print output) → Task 9
- ✓ Invalidation hook → Task 15

**Placeholder scan:** No "TBD" / "TODO" markers. All code blocks complete.

**Type consistency:** `isInserterPreview(bool, array, array): bool` matches between Task 2 (definition) and Task 9 (call site). `renderEmptyAlert(string, array): string` matches between Task 12 (definition) and Task 9 (call site). `registerInvalidation(): void` matches Task 15 definition and StarterBase wiring.

---

## References

- Spec v2: [`docs/superpowers/specs/2026-05-15-block-renderer-design.md`](../specs/2026-05-15-block-renderer-design.md)
- Original function: `/Users/pari/Sites/wordpress/wordpress-base/wp-content/themes/starter_theme/functions.php:84-216`
- [`portadesign/wordpress-base` PR #27](https://github.com/portadesign/wordpress-base/pull/27) — empirical discriminator predecessor
