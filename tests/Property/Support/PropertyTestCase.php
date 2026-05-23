<?php

declare(strict_types=1);

namespace Tests\Property\Support;

use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Base class for property tests.
 *
 * - Wires up `Eris\TestTrait` (provides `forAll`, `limitTo`, `withSeed`).
 * - Brings a `callPrivate` helper so private targets like
 *   `Resizer::normalizeVariants` can be exercised without changing visibility.
 *
 * NOT a Brain\Monkey consumer. Property tests must run against pure functions
 * (or near-pure with bootstrap stubs only). Any need for per-iteration Monkey
 * state means the target is in the wrong test suite.
 *
 * Iteration count: Eris defaults to 100. Override per-test with the
 * `@eris-repeat N` PHPDoc annotation on the test method.
 *
 * Seed: Eris reads `ERIS_SEED` natively via `seedingRandomNumberGeneration()`.
 * Setting that env var in CI is sufficient to make a failing run reproducible.
 * No setUp() override is needed here.
 *
 * PHPUnit 11 compat: `PHPUnit\Util\Test::parseTestMethodAnnotations()` was
 * removed in PHPUnit 10+. Eris 0.14.1 falls through to that call when
 * `getAnnotations()` is absent. We override `getTestCaseAnnotations()` to
 * return the empty structure Eris expects so it gracefully uses its defaults.
 */
abstract class PropertyTestCase extends TestCase {
	use TestTrait;

	/**
	 * @return array{class: array<string, list<string>>, method: array<string, list<string>>}
	 */
	public function getTestCaseAnnotations(): array {
		return [ 'class' => [], 'method' => [] ];
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
