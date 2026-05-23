# Property Testing Pilot — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce property-based testing to timber-kit via `giorgiosironi/eris`, with two pilot targets — `Resizer::normalizeVariants` (structural invariants) and `Helpers::formatImageFrom` (extracted pure core, contract invariants).

**Architecture:** New `tests/Property/` PHPUnit suite, fully isolated from Brain\Monkey. Shared `PropertyTestCase` abstract base wraps `Eris\TestTrait` with sensible defaults and reflection helper. `Helpers::formatImage` is decomposed: the associative-array shaping branch becomes a public static pure function `formatImageFrom( ?array $raw ): ?array`; `formatImage()` is rewritten as a thin dispatcher preserving its existing API and tests bit-for-bit. `composer test` narrows to the Unit suite; `composer test:property` runs the property suite; CI runs both.

**Tech Stack:** PHP 8.3, PHPUnit 11/12, Brain\Monkey (Unit only), `giorgiosironi/eris` 0.14 (Property only).

**Source spec:** [`docs/superpowers/specs/2026-05-23-property-testing-pilot-design.md`](../specs/2026-05-23-property-testing-pilot-design.md)

---

## File Structure

> **NOTE (post-implementation):** Two structural assumptions in this plan turned out wrong during execution and were corrected in the actual PR. They are flagged inline below; the task steps as written would have produced broken state if followed literally. See the spec doc for the rationale and the final architecture.

| Path | Status | Responsibility |
| --- | --- | --- |
| `composer.json` | modify | add Eris dev dep, retarget `scripts.test`, add `test:property` (using `-c phpunit.property.xml`) + `test:all` (chain via `["@test", "@test:property"]`) |
| `phpunit.xml` | modify | **stays Unit-only** (the plan originally said to register a Property suite here; that won't work — see note) |
| `phpunit.property.xml` | create | dedicated Property-suite config pointing at `tests/bootstrap.property.php` |
| `tests/bootstrap.property.php` | create | chain `tests/bootstrap.php` then define `apply_filters` stub. Lives separate from the shared bootstrap because Patchwork raises `DefinedTooEarly` on Brain\Monkey'ed Unit tests when WP function stubs come from a bootstrap it didn't preprocess |
| `tests/Property/Support/PropertyTestCase.php` | create | abstract base — `Eris\TestTrait` + `callPrivate` reflection helper + `getTestCaseAnnotations()` shim that lets Eris 0.14.1 work on PHPUnit 11 (returns empty annotations; `@eris-repeat` is dead until Eris ships upstream compat) |
| `tests/Property/SmokeTest.php` | create | one trivial property to prove the suite runs in CI |
| `tests/Property/Resizer/NormalizeVariantsPropertyTest.php` | create | four invariants (type stability, ordering, count, determinism) |
| `tests/Property/Helpers/FormatImageFromPropertyTest.php` | create | three invariants (non-throw + no-notice, shape, null propagation) |
| `src/Helpers.php` | modify | extract `formatImageFrom()`; rewrite `formatImage()` array/numeric/URL branches to delegate; cast width/height/id to `int|null` (numeric-string ACF returns) |
| `tests/Unit/Helpers/FormatImageFromTest.php` | create | example tests pinning the documented contract, including numeric-string and non-numeric regression cases |
| `.gitattributes` | modify | `export-ignore` `/phpunit.property.xml` and `/docs` |
| `.github/workflows/tests.yml` | modify | named `Unit tests` + `Property tests` steps; `ERIS_SEED: ${{ github.run_id }}` on the Property step |
| `AGENTS.md` | modify | update Commands section; add Testing notes bullet about the two-bootstrap architecture + `ERIS_SEED` reproduction |
| `CHANGELOG.md` | modify | `### Added` entry + `### Changed` note about SVG-1px guard extension and silent null-coalescing in `formatImage()` |

Boundaries: `tests/Property/*` never imports `Brain\Monkey` and never asserts on WP/ACF function behaviour. If a property test needs a stub, the stub goes into `tests/bootstrap.property.php` (not the shared `tests/bootstrap.php`) as a plain function. PHPStan analyses only `src/`, so test-side type churn does not require new ignores.

---

## Task 1: Infrastructure — Eris dep, suite, composer scripts

> **Correction:** Step 3 as written below adds the Property suite to `phpunit.xml`. In the actual PR this turned out to be wrong (see Task 2 correction). The final state has `phpunit.xml` Unit-only and a separate `phpunit.property.xml`. The composer scripts in Step 2 also evolved — `test:property` ended up as `vendor/bin/phpunit -c phpunit.property.xml`, and `test:all` as `["@test", "@test:property"]`. The literal steps below were what was tried first.

**Files:**
- Modify: `composer.json`
- Modify: `phpunit.xml`

- [ ] **Step 1: Add Eris dev dependency**

Update `composer.json` `require-dev`:

```json
"require-dev": {
    "phpunit/phpunit": "^11.0 || ^12.0",
    "brain/monkey": "^2.7",
    "phpstan/phpstan": "^2.1",
    "szepeviktor/phpstan-wordpress": "^2.0",
    "php-stubs/acf-pro-stubs": "^6.5",
    "giorgiosironi/eris": "^0.14"
}
```

- [ ] **Step 2: Retarget `composer test` to Unit suite and add new scripts**

Replace the `scripts` section in `composer.json`:

```json
"scripts": {
    "phpstan":       "php -d memory_limit=2G vendor/bin/phpstan analyse",
    "test":          "vendor/bin/phpunit --testsuite=Unit",
    "test:property": "vendor/bin/phpunit --testsuite=Property",
    "test:all":      "vendor/bin/phpunit"
}
```

- [ ] **Step 3: Add Property suite to phpunit.xml**

Replace `phpunit.xml` with:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
	bootstrap="tests/bootstrap.php"
	colors="true"
	beStrictAboutTestsThatDoNotTestAnything="true"
>
	<testsuites>
		<testsuite name="Unit">
			<directory suffix="Test.php">tests/Unit</directory>
		</testsuite>
		<testsuite name="Property">
			<directory suffix="Test.php">tests/Property</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 4: Install dependency**

Run: `composer update giorgiosironi/eris --no-interaction`
Expected: `composer.lock` updated, `vendor/giorgiosironi/eris/` populated, no PHP errors.

- [ ] **Step 5: Verify Unit suite still runs and Property suite is empty**

Run: `composer test`
Expected: existing Unit tests all pass (no behaviour change).

Run: `composer test:property`
Expected: PHPUnit reports "No tests executed" (suite exists but is empty) — exit code may be 0 or 1 depending on PHPUnit version; either is acceptable here.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock phpunit.xml
git commit -m "$(cat <<'EOF'
feat(test): add Property test suite scaffolding (#19)

- Eris dev dependency, plus composer scripts for the new suite.
- `composer test` now narrows to Unit; `composer test:property` runs
  Eris-based invariants; `composer test:all` runs both.
- phpunit.xml registers the new <testsuite name="Property">.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Bootstrap stub + PropertyTestCase base

> **Correction:** Step 1 instructs adding the `apply_filters` stub to `tests/bootstrap.php`. Doing that broke 186 Unit tests with Patchwork's `DefinedTooEarly` exception: Brain\Monkey's call interception requires that any function it patches come from a file Patchwork preprocessed, and `tests/bootstrap.php` is not preprocessed. The actual PR creates a new `tests/bootstrap.property.php` that chains the shared bootstrap and adds the stub, paired with a new `phpunit.property.xml`. The `composer test:property` script and `.gitattributes` were updated accordingly. Also: `PropertyTestCase::setUp()`'s env-driven iteration/seed logic in Step 2 was later deleted — Eris's `@before` hook overwrites `$this->iterations` and reads `ERIS_SEED` natively, so the setUp code was dead. A `getTestCaseAnnotations()` override was added instead to make Eris 0.14.1 work on PHPUnit 11 (which removed `parseTestMethodAnnotations`).

**Files:**
- Modify: `tests/bootstrap.php`
- Create: `tests/Property/Support/PropertyTestCase.php`

- [ ] **Step 1: Add plain `apply_filters` stub to bootstrap**

Insert after the `wp_strip_all_tags` block (around line 21–32) in `tests/bootstrap.php`:

```php
// Plain pass-through stub for `apply_filters` so Property tests (which
// don't set up Brain\Monkey) can instantiate Resizer without a fatal.
// Brain\Monkey's Patchwork-based interception still hooks calls in Unit
// tests that wrap `Functions\when('apply_filters')->alias(...)`.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}
```

- [ ] **Step 2: Create PropertyTestCase base**

Write `tests/Property/Support/PropertyTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Property\Support;

use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Base class for property tests.
 *
 * - Wires up `Eris\TestTrait` (provides `forAll`, `limitTo`, `withSeed`).
 * - Pins iteration count from `ERIS_ITERATIONS` env (default 100).
 * - Pins seed from `ERIS_SEED` env when present (CI sets this to the run id
 *   so a failing build can be reproduced locally with the same seed).
 * - Brings a `callPrivate` helper so private targets like
 *   `Resizer::normalizeVariants` can be exercised without changing visibility.
 *
 * NOT a Brain\Monkey consumer. Property tests must run against pure functions
 * (or near-pure with bootstrap stubs only). Any need for per-iteration Monkey
 * state means the target is in the wrong test suite.
 */
abstract class PropertyTestCase extends TestCase {
	use TestTrait;

	protected function setUp(): void {
		parent::setUp();
		// Per-test override: child can re-apply limitTo() inside forAll() chain.
		$envIterations = getenv( 'ERIS_ITERATIONS' );
		if ( is_string( $envIterations ) && ctype_digit( $envIterations ) ) {
			$this->minimumIterations = (int) $envIterations;
		}
		$envSeed = getenv( 'ERIS_SEED' );
		if ( is_string( $envSeed ) && '' !== $envSeed ) {
			$this->seed = (int) $envSeed;
		}
	}

	/**
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	protected function callPrivate( object $obj, string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( $obj, $method );
		return $ref->invoke( $obj, ...$args );
	}
}
```

- [ ] **Step 3: Verify the file parses**

Run: `php -l tests/Property/Support/PropertyTestCase.php`
Expected: `No syntax errors detected in tests/Property/Support/PropertyTestCase.php`

- [ ] **Step 4: Run unit suite to confirm bootstrap change is harmless**

Run: `composer test`
Expected: all existing Unit tests still pass — adding `apply_filters` as a plain bootstrap function should not affect any test that calls `Functions\when('apply_filters')` (Patchwork still intercepts).

- [ ] **Step 5: Commit**

```bash
git add tests/bootstrap.php tests/Property/Support/PropertyTestCase.php
git commit -m "$(cat <<'EOF'
feat(test): add PropertyTestCase base + bootstrap stub (#19)

PropertyTestCase wraps Eris\TestTrait with env-driven iteration count and
seed handling, plus a callPrivate reflection helper for targets that
remain private in production. apply_filters bootstrap stub lets Property
tests instantiate Resizer without Brain\Monkey.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Smoke test — prove the Property suite runs

**Files:**
- Create: `tests/Property/SmokeTest.php`

- [ ] **Step 1: Write smoke test**

Write `tests/Property/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Property;

use Eris\Generator;
use Tests\Property\Support\PropertyTestCase;

/**
 * Minimal property to verify the Property suite is wired up:
 * doubling a non-negative int always yields a non-negative int >= the input.
 */
class SmokeTest extends PropertyTestCase {

	public function test_doubling_a_nat_is_at_least_the_nat(): void {
		$this->forAll( Generator\nat() )
			->then( function ( int $n ): void {
				$doubled = $n * 2;
				$this->assertGreaterThanOrEqual( $n, $doubled );
			} );
	}
}
```

- [ ] **Step 2: Run Property suite, verify it passes**

Run: `composer test:property`
Expected: 1 test, passes, Eris does ~100 iterations silently.

- [ ] **Step 3: Verify Unit suite untouched**

Run: `composer test`
Expected: same pass count as before this branch started.

- [ ] **Step 4: Commit**

```bash
git add tests/Property/SmokeTest.php
git commit -m "$(cat <<'EOF'
test(property): add smoke test confirming Eris suite runs (#19)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Property tests for `Resizer::normalizeVariants` — type stability + count + determinism

**Files:**
- Create: `tests/Property/Resizer/NormalizeVariantsPropertyTest.php`

These three invariants share a generator. Implement them together; the ordering invariant (which needs a different generator constraint) follows in Task 5.

- [ ] **Step 1: Write the test file with shared generator and three invariants**

Write `tests/Property/Resizer/NormalizeVariantsPropertyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Property\Resizer;

use Eris\Generator;
use Parisek\TimberKit\Resizer;
use Tests\Property\Support\PropertyTestCase;

/**
 * Property tests for the private Resizer::normalizeVariants() transformer.
 *
 * Input domain: list of indexed tuples (raw variant specs).
 * Output domain: list of associative dicts with five typed keys.
 * The domains differ, so classic idempotence (f(f(x))===f(x)) does not apply.
 * What does hold: type stability, ordering, count preservation, determinism.
 */
class NormalizeVariantsPropertyTest extends PropertyTestCase {

	/**
	 * Generates a single raw variant tuple of 0–5 fields, each a string that
	 * either represents a small non-negative integer or is empty.
	 */
	private function rawVariantGenerator(): Generator\Generator {
		$numericString = Generator\oneOf(
			Generator\constant( '' ),
			Generator\map(
				fn ( int $n ) => (string) $n,
				Generator\choose( 0, 4000 )
			)
		);
		$styleString = Generator\elements( 'center', 'crop', 'scale', '' );

		return Generator\bind(
			Generator\choose( 0, 5 ),
			function ( int $arity ) use ( $numericString, $styleString ) {
				$fields = [ $numericString, $numericString, $numericString, $styleString, $numericString ];
				$slice  = array_slice( $fields, 0, $arity );
				if ( [] === $slice ) {
					return Generator\constant( [] );
				}
				return Generator\tuple( ...$slice );
			}
		);
	}

	/**
	 * Generates a bounded list (0–8 elements) of raw variant tuples.
	 */
	private function variantsGenerator(): Generator\Generator {
		return Generator\bind(
			Generator\choose( 0, 8 ),
			fn ( int $n ) => 0 === $n
				? Generator\constant( [] )
				: Generator\vector( $n, $this->rawVariantGenerator() )
		);
	}

	public function test_output_keys_and_types_are_stable(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$result  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				$this->assertIsArray( $result );
				foreach ( $result as $row ) {
					$this->assertIsArray( $row );
					$this->assertEqualsCanonicalizing(
						[ 'width', 'height', 'media', 'image_style', 'quality' ],
						array_keys( $row )
					);
					$this->assertIsInt( $row['width'] );
					$this->assertIsInt( $row['height'] );
					$this->assertIsInt( $row['media'] );
					$this->assertIsString( $row['image_style'] );
					$this->assertIsInt( $row['quality'] );
				}
			} );
	}

	public function test_count_is_preserved(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$result  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				$this->assertCount( count( $variants ), $result );
			} );
	}

	public function test_result_is_deterministic(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$first   = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );
				$second  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				$this->assertSame( $first, $second );
			} );
	}
}
```

- [ ] **Step 2: Run the test, verify all three properties hold**

Run: `composer test:property`
Expected: 4 tests (3 here + smoke), all pass, ~400 iterations executed silently.

If any property fails: Eris prints the shrunken minimal input and the seed. Do **not** modify the property to make it pass — record the failing input as a regression in `tests/Unit/Resizer/NormalizeVariantsTest.php`, then fix the bug in `src/Resizer.php` in a separate commit. See the spec's Failure handling section.

- [ ] **Step 3: Commit**

```bash
git add tests/Property/Resizer/NormalizeVariantsPropertyTest.php
git commit -m "$(cat <<'EOF'
test(property): assert type/count/determinism for normalizeVariants (#19)

Three invariants over a bounded generator of 0–8 variant tuples of 0–5
string fields each. Idempotence is intentionally omitted (input domain
differs from output domain). Ordering invariant follows separately.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Property test for `Resizer::normalizeVariants` — ordering

**Files:**
- Modify: `tests/Property/Resizer/NormalizeVariantsPropertyTest.php`

Separate task because the ordering property requires a generator that produces inputs with multiple distinct `media` values.

- [ ] **Step 1: Add the ordering test method to the class**

Insert into the class body of `NormalizeVariantsPropertyTest`, after `test_result_is_deterministic`:

```php
public function test_output_is_sorted_by_media_descending(): void {
	$this->forAll( $this->variantsGenerator() )
		->then( function ( array $variants ): void {
			$resizer = new Resizer();
			$result  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

			$mediaValues = array_map( fn ( array $row ) => $row['media'], $result );

			$sorted = $mediaValues;
			rsort( $sorted, SORT_NUMERIC );

			$this->assertSame(
				$sorted,
				$mediaValues,
				'normalizeVariants must sort by media DESC'
			);
		} );
}
```

- [ ] **Step 2: Run the test, verify ordering holds**

Run: `composer test:property -- --filter NormalizeVariants`
Expected: 4 tests in the file, all pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Property/Resizer/NormalizeVariantsPropertyTest.php
git commit -m "$(cat <<'EOF'
test(property): assert media DESC ordering for normalizeVariants (#19)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Extract `Helpers::formatImageFrom` (TDD: example test first)

**Files:**
- Create: `tests/Unit/Helpers/FormatImageFromTest.php`
- Modify: `src/Helpers.php`

- [ ] **Step 1: Write the failing example test**

Write `tests/Unit/Helpers/FormatImageFromTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Example tests for the pure-core formatter extracted from
 * Helpers::formatImage()'s associative-array branch. Locks the documented
 * contract independently of property-based testing.
 */
class FormatImageFromTest extends HelpersTestCase {

	public function test_null_input_returns_null(): void {
		$this->assertNull( Helpers::formatImageFrom( null ) );
	}

	public function test_empty_array_returns_null(): void {
		$this->assertNull( Helpers::formatImageFrom( [] ) );
	}

	public function test_well_formed_acf_array_maps_to_documented_shape(): void {
		$raw = [
			'ID'          => 42,
			'url'         => 'https://example.com/image.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => 800,
			'height'      => 600,
			'alt'         => 'Test image',
			'caption'     => 'A caption',
			'description' => 'A description',
		];

		$this->assertSame(
			[
				'id'          => 42,
				'src'         => 'https://example.com/image.jpg',
				'type'        => 'image/jpeg',
				'width'       => 800,
				'height'      => 600,
				'alt'         => 'Test image',
				'caption'     => 'A caption',
				'description' => 'A description',
			],
			Helpers::formatImageFrom( $raw )
		);
	}

	public function test_svg_1px_dimensions_are_coerced_to_null(): void {
		$raw = [
			'ID'          => 1,
			'url'         => 'https://example.com/icon.svg',
			'mime_type'   => 'image/svg+xml',
			'width'       => 1,
			'height'      => 1,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatImageFrom( $raw );
		$this->assertNull( $result['width'] );
		$this->assertNull( $result['height'] );
	}

	public function test_missing_keys_default_to_null_without_warning(): void {
		$prevLevel = error_reporting( E_ALL );
		try {
			set_error_handler( static function ( int $errno, string $errstr ): bool {
				throw new \RuntimeException( "Unexpected PHP error: $errstr" );
			} );
			$result = Helpers::formatImageFrom( [ 'ID' => 7 ] );
			restore_error_handler();
		} finally {
			error_reporting( $prevLevel );
		}

		$this->assertSame( 7,    $result['id'] );
		$this->assertNull( $result['src'] );
		$this->assertNull( $result['type'] );
		$this->assertNull( $result['alt'] );
	}
}
```

- [ ] **Step 2: Verify the test fails because the method does not exist**

Run: `vendor/bin/phpunit --testsuite=Unit --filter FormatImageFromTest`
Expected: FATAL or `Error: Call to undefined method Parisek\TimberKit\Helpers::formatImageFrom()` on every test method.

- [ ] **Step 3: Implement `formatImageFrom` in `src/Helpers.php`**

Insert directly **before** the existing `public static function formatImage(` (currently at `src/Helpers.php:37`):

```php
/**
 * Pure-core shaping for a single ACF "array" return-format attachment.
 *
 * Extracted from {@see formatImage()}'s associative-array branch so it can
 * be exercised in isolation by property tests. No WP/ACF calls, no global
 * state. Returns null for degenerate input (null, empty array) instead of
 * an empty dict, so callers can decide whether to skip the item.
 *
 * Missing keys yield null silently via null-coalescing; this fixes a real
 * source of `Undefined index` notices the in-line array branch used to emit
 * for malformed ACF arrays. Well-formed inputs are unaffected.
 *
 * @param array<string,mixed>|null $raw  ACF attachment array as returned by
 *                                       `acf_get_attachment()` or stored in an
 *                                       array-return-format ACF field.
 * @return array{id:int|null,src:string|null,type:string|null,width:int|null,height:int|null,alt:string|null,caption:string|null,description:string|null}|null
 */
public static function formatImageFrom( ?array $raw ): ?array {
	if ( null === $raw || [] === $raw ) {
		return null;
	}
	// SVG width/height-1px guard preserved from the original array branch:
	// https://core.trac.wordpress.org/ticket/26256
	$width  = ( ! empty( $raw['width'] )  && $raw['width']  > 1 ) ? $raw['width']  : null;
	$height = ( ! empty( $raw['height'] ) && $raw['height'] > 1 ) ? $raw['height'] : null;

	return [
		'id'          => $raw['ID']          ?? null,
		'src'         => $raw['url']         ?? null,
		'type'        => $raw['mime_type']   ?? null,
		'width'       => $width,
		'height'      => $height,
		'alt'         => $raw['alt']         ?? null,
		'caption'     => $raw['caption']     ?? null,
		'description' => $raw['description'] ?? null,
	];
}
```

- [ ] **Step 4: Verify the example tests pass**

Run: `vendor/bin/phpunit --testsuite=Unit --filter FormatImageFromTest`
Expected: 5 tests, all pass.

- [ ] **Step 5: Verify existing FormatImageTest still passes (no behaviour change yet)**

Run: `vendor/bin/phpunit --testsuite=Unit --filter FormatImageTest`
Expected: existing pass count unchanged. `formatImage()` is untouched in this task; only `formatImageFrom()` was added.

- [ ] **Step 6: Run full Unit + PHPStan to confirm no regressions**

Run: `composer test && composer phpstan`
Expected: all Unit tests pass, PHPStan reports no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Helpers.php tests/Unit/Helpers/FormatImageFromTest.php
git commit -m "$(cat <<'EOF'
refactor(helpers): extract formatImageFrom pure core (#19)

Adds Helpers::formatImageFrom( ?array \$raw ): ?array, the pure shaping
core of formatImage()'s associative-array branch. Public + static so it
can be property-tested without a Brain\Monkey harness.

formatImage() is unchanged in this commit; the wrapper rewrite follows
in a separate commit to keep behavioural diffs reviewable.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Rewrite `Helpers::formatImage` to delegate to `formatImageFrom`

**Files:**
- Modify: `src/Helpers.php`

- [ ] **Step 1: Replace the body of `formatImage()`**

Replace `src/Helpers.php` lines 37–115 (the entire current `public static function formatImage(` body) with:

```php
public static function formatImage( $image, $post_id = null, $field = null ) {

	$data = [];

	// Gallery / multi-value field: recurse for each item and collect non-empty results.
	if ( is_countable( $image ) && ! Helpers::isAssoc( $image ) ) {
		$items = [];
		foreach ( $image as $item ) {
			$resolved = Helpers::formatImage( $item );
			if ( $resolved ) {
				$items[] = $resolved;
			}
		}
		return $items;
	}

	if ( is_object( $image ) ) {
		// Object branch (typically a Timber image): different property names
		// (`ID`, `src`, `post_mime_type`) so we shape it inline rather than
		// going through formatImageFrom().
		// fixed weird bug when image/svg+xml is sometimes width 1px / height 1px
		// https://core.trac.wordpress.org/ticket/26256
		$width  = ( ! empty( $image->width )  && $image->width  > 1 ) ? $image->width  : null;
		$height = ( ! empty( $image->height ) && $image->height > 1 ) ? $image->height : null;
		$data[] = [
			'id'          => $image->ID,
			'src'         => $image->src,
			'type'        => $image->post_mime_type,
			'width'       => $width,
			'height'      => $height,
			'alt'         => $image->alt,
			'caption'     => $image->caption,
			'description' => $image->description,
		];
	} elseif ( is_array( $image ) ) {
		$item = self::formatImageFrom( $image );
		if ( null !== $item ) {
			$data[] = $item;
		}
	} elseif ( is_numeric( $image ) ) {
		$resolved = acf_get_attachment( $image );
		if ( $resolved ) {
			$item = self::formatImageFrom( $resolved );
			if ( null !== $item ) {
				$data[] = $item;
			}
		}
	} elseif ( filter_var( $image, FILTER_VALIDATE_URL ) ) {
		$attachment_id = attachment_url_to_postid( $image );
		$resolved      = acf_get_attachment( $attachment_id );
		if ( $resolved ) {
			$item = self::formatImageFrom( $resolved );
			if ( null !== $item ) {
				$data[] = $item;
			}
		}
	}

	return $data;
}
```

- [ ] **Step 2: Run the existing FormatImageTest, verify all branches still pass**

Run: `vendor/bin/phpunit --testsuite=Unit --filter FormatImageTest`
Expected: same test methods, same pass count as before this task. The wrapper preserves observable behaviour for every branch the existing tests cover.

- [ ] **Step 3: Run the entire Unit suite to catch downstream callers**

Run: `composer test`
Expected: all Unit tests pass. Several other formatters (`formatFile`, `formatVideo`, `fieldFormatter`) call `Helpers::formatImage()` internally — they must continue to work unchanged.

- [ ] **Step 4: Run PHPStan**

Run: `composer phpstan`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Helpers.php
git commit -m "$(cat <<'EOF'
refactor(helpers): formatImage delegates to formatImageFrom (#19)

Array, numeric, and URL branches now route through the extracted pure
core. Object branch stays inline (different property names). Observable
behaviour preserved; existing FormatImageTest continues to pass without
modification.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Property tests for `Helpers::formatImageFrom`

**Files:**
- Create: `tests/Property/Helpers/FormatImageFromPropertyTest.php`

- [ ] **Step 1: Write the property test file**

Write `tests/Property/Helpers/FormatImageFromPropertyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Property\Helpers;

use Eris\Generator;
use Parisek\TimberKit\Helpers;
use Tests\Property\Support\PropertyTestCase;

/**
 * Property tests for the extracted pure-core ACF-array image formatter.
 *
 * Three invariants:
 *  - Non-throw + no PHP warnings/notices for any ?array input.
 *  - Shape contract: output is null or a dict with exactly the documented keys.
 *  - Null propagation for the two degenerate inputs.
 *
 * The generator deliberately produces realistically dirty input (missing
 * keys, wrong types, null values) — not "valid ACF". The point is to find
 * implicit assumptions in the formatter that fail on real-world data.
 */
class FormatImageFromPropertyTest extends PropertyTestCase {

	private const EXPECTED_KEYS = [
		'id', 'src', 'type', 'width', 'height', 'alt', 'caption', 'description',
	];

	private function rawAcfImageGenerator(): Generator\Generator {
		$maybeNullStr = Generator\oneOf(
			Generator\string(),
			Generator\constant( '' ),
			Generator\constant( null )
		);
		$maybeNullNat = Generator\oneOf(
			Generator\nat(),
			Generator\constant( '' ),
			Generator\constant( null )
		);

		$arrayShape = Generator\associative( [
			'ID'          => Generator\oneOf( Generator\nat(), Generator\constant( null ) ),
			'url'         => $maybeNullStr,
			'mime_type'   => $maybeNullStr,
			'width'       => $maybeNullNat,
			'height'      => $maybeNullNat,
			'alt'         => $maybeNullStr,
			'caption'     => $maybeNullStr,
			'description' => $maybeNullStr,
		] );

		return Generator\oneOf(
			Generator\constant( null ),
			Generator\constant( [] ),
			$arrayShape
		);
	}

	public function test_never_throws_and_emits_no_php_warnings(): void {
		$this->forAll( $this->rawAcfImageGenerator() )
			->then( function ( $raw ): void {
				set_error_handler( static function ( int $errno, string $errstr ): bool {
					throw new \RuntimeException( "Unexpected PHP error ($errno): $errstr" );
				} );
				try {
					Helpers::formatImageFrom( $raw );
				} finally {
					restore_error_handler();
				}
				$this->addToAssertionCount( 1 );
			} );
	}

	public function test_output_is_null_or_matches_documented_shape(): void {
		$this->forAll( $this->rawAcfImageGenerator() )
			->then( function ( $raw ): void {
				$result = Helpers::formatImageFrom( $raw );

				if ( null === $result ) {
					$this->assertTrue( true );
					return;
				}

				$this->assertIsArray( $result );
				$this->assertEqualsCanonicalizing(
					self::EXPECTED_KEYS,
					array_keys( $result )
				);
			} );
	}

	public function test_null_and_empty_array_propagate_to_null(): void {
		$this->forAll( Generator\elements( null, [] ) )
			->then( function ( $degenerate ): void {
				$this->assertNull( Helpers::formatImageFrom( $degenerate ) );
			} );
	}
}
```

- [ ] **Step 2: Run the Property suite**

Run: `composer test:property`
Expected: all property tests pass (smoke + normalizeVariants ×4 + formatImageFrom ×3).

If `test_never_throws_and_emits_no_php_warnings` fails: Eris prints the shrunken input. Add it as a regression case to `tests/Unit/Helpers/FormatImageFromTest.php`, then fix the bug in `src/Helpers.php`. Do not silence the property.

If `test_output_is_null_or_matches_documented_shape` fails: most likely a key was added to or removed from `formatImageFrom`'s output without updating `EXPECTED_KEYS` and the spec's shape contract — sync them and the spec together.

- [ ] **Step 3: Run all tests + PHPStan**

Run: `composer test:all && composer phpstan`
Expected: green.

- [ ] **Step 4: Commit**

```bash
git add tests/Property/Helpers/FormatImageFromPropertyTest.php
git commit -m "$(cat <<'EOF'
test(property): assert non-throw + shape + null propagation for formatImageFrom (#19)

Generator produces realistically dirty ACF arrays (missing keys, null
values, wrong types) plus the two degenerate inputs. Three invariants
exercise the contract documented in src/Helpers.php.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: CI wiring + AGENTS.md + CHANGELOG

**Files:**
- Modify: `.github/workflows/tests.yml`
- Modify: `AGENTS.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add Property tests step to CI**

In `.github/workflows/tests.yml`, replace the `tests` job's `steps:` block with:

```yaml
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - name: Unit tests
        run: composer test
      - name: Property tests
        env:
          ERIS_SEED: ${{ github.run_id }}
        run: composer test:property
```

The `ERIS_SEED` env makes any failing CI run reproducible locally via `ERIS_SEED=<run-id> composer test:property`.

- [ ] **Step 2: Update AGENTS.md Commands section**

In `AGENTS.md`, replace the Commands code block with:

```bash
composer test           # Unit suite (Brain\Monkey, fast — default)
composer test:property  # Eris property suite (invariant-based, ~100 iterations/test)
composer test:all       # both suites
composer phpstan        # vendor/bin/phpstan analyse
```

Also add one bullet to the "Testing notes" section:

```
- Property tests (`tests/Property/`) are isolated from Brain\Monkey by convention — they target pure functions only. If a property test needs a WP/ACF stub, add it as a plain `function_exists`-guarded function to `tests/bootstrap.php` rather than reaching for `Functions\when()`. CI pins `ERIS_SEED=${{ github.run_id }}`; reproduce a failing build with `ERIS_SEED=<run-id> composer test:property`.
```

- [ ] **Step 3: Update CHANGELOG.md**

Under the `## [Unreleased]` heading, add an `### Added` subsection (preserving any existing subsections):

```markdown
### Added

- Property-based test suite (`tests/Property/`, runnable via `composer test:property`) powered by `giorgiosironi/eris`. Pilot covers structural invariants of `Resizer::normalizeVariants` (type stability, ordering, count preservation, determinism) and contract invariants of the new `Helpers::formatImageFrom()` pure core (non-throw, shape contract, null propagation). See [#19](https://github.com/parisek/timber-kit/issues/19).
- `Helpers::formatImageFrom( ?array $raw ): ?array` — public static pure-core formatter extracted from `Helpers::formatImage()`'s associative-array branch. Behaviour preserved for well-formed inputs; missing keys now resolve to `null` silently instead of emitting `Undefined index` notices.
```

- [ ] **Step 4: Verify CI workflow syntax locally**

Run: `cat .github/workflows/tests.yml`
Expected: visually consistent YAML, two-space indentation throughout, `steps:` block intact.

- [ ] **Step 5: Final green check**

Run: `composer test:all && composer phpstan`
Expected: green on both suites, no PHPStan errors.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/tests.yml AGENTS.md CHANGELOG.md
git commit -m "$(cat <<'EOF'
ci+docs: run property suite in CI, document split (#19)

- tests.yml runs composer test then composer test:property in the PHP
  matrix, with ERIS_SEED pinned to the run id for reproducibility.
- AGENTS.md Commands section reflects the new suite split.
- CHANGELOG records the new suite and formatImageFrom export.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final verification (not a commit step)

After Task 9:

- [ ] `composer test:all` green locally.
- [ ] `composer phpstan` green locally.
- [ ] Push branch, open PR targeting `main`, verify GitHub Actions matrix (PHP 8.3 + 8.4) goes green.
- [ ] PR title closes `#19`. Squash-merge so the merge commit subject ends with `(#NN)` (per AGENTS.md release automation requirement).

---

## Self-review notes

**Spec coverage check:**
- Tests/Property/ suite + composer scripts → Tasks 1, 2, 3 ✓
- Resizer invariants (type, ordering, count, determinism) → Tasks 4, 5 ✓
- formatImageFrom extraction → Task 6 ✓
- formatImage wrapper rewrite preserving behaviour → Task 7 ✓
- formatImageFrom property tests (non-throw, shape, null) → Task 8 ✓
- CI + AGENTS.md + CHANGELOG → Task 9 ✓
- `ErisCase` trait renamed to `PropertyTestCase` abstract class for simpler composition with reflection helper — noted in File Structure table ✓

**Placeholder scan:** No TBDs, no "add error handling", no "similar to Task N". Every code step contains the actual code.

**Type/name consistency:** `formatImageFrom`, `formatImage`, `normalizeVariants`, `PropertyTestCase`, `EXPECTED_KEYS`, `rawVariantGenerator`, `variantsGenerator`, `rawAcfImageGenerator` — used consistently across Tasks 4–8.

**Scope:** focused on the pilot. Defers the rest of `Helpers::format*` family to follow-up issues per the spec.
