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
