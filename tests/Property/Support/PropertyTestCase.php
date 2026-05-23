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
 *
 * NOTE: Eris 0.14.1 exposes `$iterations` as private and `$seed` as protected
 * on TestTrait. We use `$this->limitTo(int)` (the public API) to set the
 * iteration count, and direct assignment for `$this->seed`. The plan referenced
 * `$minimumIterations` which does not exist in this version of Eris.
 */
abstract class PropertyTestCase extends TestCase {
	use TestTrait;

	protected function setUp(): void {
		parent::setUp();
		// Per-test override: child can re-apply limitTo() inside forAll() chain.
		$envIterations = getenv( 'ERIS_ITERATIONS' );
		if ( is_string( $envIterations ) && ctype_digit( $envIterations ) ) {
			// Eris 0.14.1: $iterations is private; limitTo(int) is the public setter.
			$this->limitTo( (int) $envIterations );
		}
		$envSeed = getenv( 'ERIS_SEED' );
		if ( is_string( $envSeed ) && '' !== $envSeed ) {
			// $seed is protected on TestTrait so direct assignment works.
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
