# Property testing pilot — design

**Issue:** [#19](https://github.com/parisek/timber-kit/issues/19)
**Branch:** `19-property-testing-pilot`
**Date:** 2026-05-23

## Context

The repo today has only example-based unit tests (`tests/Unit/`). They enumerate cases by hand: someone thinks of an input, asserts the output. They catch regressions in known cases. They do not catch regressions in cases nobody thought of.

Two parts of the codebase are vulnerable to that gap:

1. **`Helpers::format*` family** (`formatImage`, `formatLink`, `formatField`, `formatFile`, `formatVideo`, `formatTerms`, `formatLanguageSwitcher`) — transforms raw ACF return values into the dicts Twig templates consume. ACF return values have known variance (return-format `array` / `id` / `object`, missing keys, `null` / `false` / `0`, repeater-empty representations). Templates use `{{ image.url }}` without defensive coding — a missing key is silent breakage in render, a fatal in the formatter is a 500.
2. **`Resizer::normalizeVariants`** — shape-and-defaults normalisation of variant config. Existing tests cover several happy paths but a property like `normalize(normalize(x)) === normalize(x)` is currently not enforced.

This spec describes a pilot that introduces property-based testing to the repo (via `giorgiosironi/eris`) on these two targets. The pilot is sized for a single PR.

## Goals

- Establish `tests/Property/` suite, `composer test:property` script, and CI integration as reusable infrastructure for future property tests.
- Apply property testing to two targets that exercise two different invariant styles:
  - `Resizer::normalizeVariants`: idempotence + type stability + ordering + count preservation.
  - `Helpers::formatImageFrom` (pure core extracted from `formatImage`): non-throw + shape contract + null propagation.
- Extract `Helpers::formatImage` into a thin WP/ACF wrapper plus a pure transformation core, so property tests can target the core without Brain\Monkey state per iteration.

## Non-goals

- Round-trip invariant. The codebase has no serializer/parser pair. If one is introduced later (e.g. block-config snapshots, ACF schema cache), round-trip is added then.
- Property testing the rest of the `Helpers::format*` family. Follow-up issues per function.
- Property testing `WPFormsConfigBridge::applyOverrides`. Surface is small, payoff is low — explicitly deferred.
- Property testing Twig templates. Twig is not a pure function; templates are full of side effects. Wrong tool.
- Replacing example-based tests. `tests/Unit/` stays as the regression oracle.

## Architecture overview

### Files touched

| Path | Change |
| --- | --- |
| `composer.json` | add `require-dev: giorgiosironi/eris ^0.14`; tighten `scripts.test` to `--testsuite=Unit`; add `scripts.test:property` and `scripts.test:all` |
| `phpunit.xml` | add `<testsuite name="Property">tests/Property</testsuite>` |
| `src/Helpers.php` | extract pure core `formatImageFrom( ?array $raw ): ?array`; rewrite `formatImage()` as a thin wrapper that resolves WP/ACF inputs and delegates |
| `tests/Property/Support/ErisCase.php` | shared trait — wraps `Eris\TestTrait`, applies default iteration count and CI seed handling |
| `tests/Property/Resizer/NormalizeVariantsPropertyTest.php` | new, four invariants |
| `tests/Property/Helpers/FormatImageFromPropertyTest.php` | new, three invariants |
| `tests/Unit/Helpers/FormatImageTest.php` | retargeted to exercise the boundary (`formatImage()` wrapper); pure cases may shift to a new `FormatImageFromTest.php` example test |
| `.github/workflows/tests.yml` | add `composer test:property` step after `composer test`, with `ERIS_SEED: ${{ github.run_id }}` |
| `AGENTS.md` | update Commands section: list `composer test:property` and `composer test:all` |
| `CHANGELOG.md` | add `### Added` entry under `[Unreleased]` |

No other `src/` files change.

### Boundary between Unit and Property suites

- `tests/Unit/` — example-based, deterministic, the source of regression coverage. May call WP/ACF stubs via Brain\Monkey.
- `tests/Property/` — invariant-based. **Never sets up Brain\Monkey, never calls WP or ACF functions.** Inputs are pure PHP values; targets must be pure functions or the test is structurally wrong.
- If a property test fails and produces a shrunken minimal input, the response is **two commits**: first add that input as a regression case under `tests/Unit/` (oracle for the bug), then fix the production code. The property test stays as the open-ended horizon; the unit test stays as the proof the specific bug does not return.

## Production refactor

Only `Helpers::formatImage` changes.

**Before** (today): `formatImage( int|array|null $image )` accepts an attachment ID, calls `acf_get_attachment()` / `wp_get_attachment_metadata()` / `wp_get_attachment_image_src()` to resolve it, then shapes the result. The resolution and the shaping live in one function, so testing the shaping in isolation requires mocking WP/ACF.

**After:**

```php
public static function formatImage( int|array|null $image ): ?array {
    if ( null === $image ) {
        return null;
    }
    if ( is_int( $image ) ) {
        // Resolve via ACF / WP. Exact call shape preserved from current impl.
        $raw = function_exists( 'acf_get_attachment' )
            ? acf_get_attachment( $image )
            : null;
        if ( ! is_array( $raw ) ) {
            return null;
        }
        return self::formatImageFrom( $raw );
    }
    return self::formatImageFrom( $image );
}

public static function formatImageFrom( ?array $raw ): ?array {
    // Pure: takes the post-ACF-resolution array, returns the Twig-shaped dict
    // or null. No WP calls, no global state, no Brain\Monkey needed in tests.
    // Body mirrors the current array-branch of formatImage().
}
```

The exact set of output keys (`url`, `alt`, `width`, `height`, possibly `sizes`, `caption`, `mime_type`, `ID`) is read from current `formatImage()` implementation when writing the code — this spec does not freeze that list, the existing example tests do.

`tests/Unit/Helpers/FormatImageTest.php` continues to drive `formatImage()` at the boundary. Pure cases that don't need WP/ACF mocking may move to a sibling `FormatImageFromTest.php` for clarity; that's a tidy-up, not a behavioral change.

## Invariants

### `Resizer::normalizeVariants`

1. **Idempotence.** For any input `$v`:
   `$this->callPrivate( $r, 'normalizeVariants', [ $this->callPrivate( $r, 'normalizeVariants', [ $v ] ) ] ) === $this->callPrivate( $r, 'normalizeVariants', [ $v ] )`.

2. **Type stability.** Every element of the output has exactly the keys `width:int`, `height:int`, `media:int`, `image_style:string`, `quality:int`. No extras, no missing keys, types as declared regardless of input string-ness.

3. **Ordering.** When the output has two or more elements with distinct `media` values, the array is sorted by `media DESC`. (Stable for ties is not asserted — current implementation does not guarantee it.)

4. **Count preservation.** `count(normalize($v)) === count($v)`. Normalisation neither drops nor duplicates variants.

### `Helpers::formatImageFrom`

1. **Non-throw.** For any `?array $raw` the call **never throws Throwable**. This is the strongest guarantee — Twig templates do not catch exceptions from formatters.

2. **Shape contract.** Output is either `null` or an associative array containing at minimum the keys `url`, `alt`, `width`, `height`. If output is an array, no required key is missing. (Final required-key list is locked in by reading current implementation.)

3. **Null propagation.** `formatImageFrom( null ) === null`. `formatImageFrom( [] ) === null`. Degenerate input yields `null`, not a dict full of empty values that templates render as broken HTML.

## Generators

### Variant generator (for `normalizeVariants`)

```php
private function rawVariantGenerator(): Generator\Generator {
    $numericString = Generator\oneOf(
        Generator\constant( '' ),
        Generator\map( fn( int $n ) => (string) $n, Generator\choose( 0, 4000 ) )
    );
    $styleString = Generator\elements( 'center', 'crop', 'scale', '' );

    return Generator\bind(
        Generator\choose( 0, 5 ),  // arity: how many fields the variant tuple has
        function ( int $arity ) use ( $numericString, $styleString ) {
            $fields = [ $numericString, $numericString, $numericString, $styleString, $numericString ];
            return Generator\tuple( ...array_slice( $fields, 0, $arity ) );
        }
    );
}

private function variantsGenerator(): Generator\Generator {
    return Generator\suchThat(
        fn( array $v ) => count( $v ) <= 8,  // bound shrink time
        Generator\seq( $this->rawVariantGenerator() )
    );
}
```

### ACF image generator (for `formatImageFrom`)

```php
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
        'ID'     => Generator\oneOf( Generator\nat(), Generator\constant( null ) ),
        'url'    => $maybeNullStr,
        'alt'    => $maybeNullStr,
        'width'  => $maybeNullNat,
        'height' => $maybeNullNat,
        'sizes'  => Generator\oneOf(
            Generator\constant( [] ),
            Generator\constant( null ),
            Generator\associative( [
                'thumbnail'       => Generator\string(),
                'thumbnail-width' => Generator\nat(),
            ] )
        ),
    ] );

    return Generator\oneOf(
        Generator\constant( null ),
        Generator\constant( [] ),
        $arrayShape
    );
}
```

The generator deliberately produces **realistically dirty input**, not "valid ACF return". Property testing finds implicit assumptions in `formatImageFrom` that fail on inputs production actually encounters.

## Suite layout & CI

### `composer.json` scripts

```json
"scripts": {
    "test":          "vendor/bin/phpunit --testsuite=Unit",
    "test:property": "vendor/bin/phpunit --testsuite=Property",
    "test:all":      "vendor/bin/phpunit",
    "phpstan":       "php -d memory_limit=2G vendor/bin/phpstan analyse"
}
```

Note: `composer test` narrows to the Unit suite. With no Property tests yet existing the behaviour is unchanged; after this PR `composer test` stays fast, contributors run `composer test:property` when they want the slower invariant pass.

`AGENTS.md` Commands section is updated to reflect the split.

### `phpunit.xml`

```xml
<testsuites>
    <testsuite name="Unit">
        <directory suffix="Test.php">tests/Unit</directory>
    </testsuite>
    <testsuite name="Property">
        <directory suffix="Test.php">tests/Property</directory>
    </testsuite>
</testsuites>
```

### `ErisCase` trait

Wraps `Eris\TestTrait`. Centralises:
- Default iteration count (200 for cheap properties, 100 for everything else — invoked per test).
- CI seed pinning via `ERIS_SEED` env var. Locally seed is random; in CI seed is `${{ github.run_id }}` so a failing build is reproducible by `ERIS_SEED=<run-id> composer test:property`.
- A `tearDown` hook that prints the shrunken counterexample in `var_export` form when a property fails (Eris already does this; the trait normalises the format).

### CI

`.github/workflows/tests.yml` already runs `composer test` in a PHP 8.3 + 8.4 matrix. Add a step after it:

```yaml
- name: Property tests
  env:
    ERIS_SEED: ${{ github.run_id }}
  run: composer test:property
```

Property tests are mandatory in CI. They are opt-in locally.

## Failure handling

When a property test fails Eris prints the original failing input, the shrunken minimum, and the seed. The response protocol is:

1. Reproduce locally: `ERIS_SEED=<seed-from-ci> composer test:property`.
2. Take the shrunken input. Add it as a regression case under `tests/Unit/` — example-based, deterministic, named after the symptom.
3. Fix the bug in `src/`.
4. Commit both together in the same PR. The unit test guarantees the bug does not return; the property test continues to look for new ones.

The property test itself is never edited to make a failure go away. If a property test produces a false positive (the property is wrong, not the code) the fix is to refine the property in this design and update the test.

## Risks

- **Eris maintenance.** Last release ~2021. Works on PHP 8.3. If it breaks on a future PHP version the fallback is a hand-rolled data-provider loop — the invariants and generators are the asset, the framework is replaceable. Acceptable for a pilot.
- **PHPStan friction.** Eris generators surface as `mixed`/`array` inside `forAll` callbacks. PHPStan level 5 may warn. Mitigation: per-file ignore in `phpstan.neon` (no production `mixed` propagation).
- **Brain\Monkey leakage.** If a property test accidentally pulls in a code path that calls `function_exists` against a function any prior unit test mocked, results become order-dependent. Mitigation: property suite is **fully isolated** — separate phpunit suite, no Brain\Monkey setUp/tearDown in `ErisCase`, generators feed pure functions only. Any accidental WP call inside a property test should be treated as a bug in the test.
- **Generator drift.** If `formatImageFrom` adds a new required output key (e.g. `caption`) but the generator and the shape-contract assertion aren't updated, the test silently keeps passing for the old contract. Mitigation: shape assertion compares the full sorted key set (`assertEqualsCanonicalizing( $expectedKeys, array_keys( $result ) )`), so an added or removed key forces an explicit decision in the spec and test.

## Open follow-ups (NOT this PR)

- Property tests for the rest of `Helpers::format*` family — one issue per function, each its own small spec referencing this one.
- Once two or three more format* functions are pure-cored, evaluate whether the extraction pattern wants a shared base or naming convention.
- If a serialize/parse pair appears (block config cache, ACF schema snapshot), add round-trip invariant against it then.
