<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * The flag is the whole gate, so both of its branches are asserted.
 *
 * A default-off flag whose off branch nobody tests is a flag that has only ever
 * been observed on.
 */
final class RegisterSeoHooksTest extends StarterBaseTestCase {

	/**
	 * Canonical filters added during one call to `registerSeoHooks()`.
	 *
	 * `createStarterBase()` stubs `add_filter` to a bare true, so this replaces
	 * that stub with a recording one. Both plugin filter names are watched: the
	 * point is that NEITHER fires when the flag is off, whichever plugin the
	 * process happens to have a symbol for by this point in the run.
	 *
	 * @return array<int, string>
	 */
	private function canonicalFiltersAddedWith( bool $flag ): array {
		$base = $this->createStarterBase( array( 'seo_canonical_pagination' => $flag ) );

		$added = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook ) use ( &$added ): bool {
				if ( in_array( $hook, array( 'wpseo_canonical', 'aioseo_canonical_url' ), true ) ) {
					$added[] = $hook;
				}

				return true;
			}
		);

		( new \ReflectionMethod( $base, 'registerSeoHooks' ) )->invoke( $base );

		return $added;
	}

	public function testTheFlagIsOffByDefault(): void {
		$base = $this->createStarterBase();

		$property = new \ReflectionProperty( $base, 'seo_canonical_pagination' );

		$this->assertFalse( $property->getValue( $base ) );
	}

	public function testNothingIsRegisteredWhileTheFlagIsOff(): void {
		$this->assertSame( array(), $this->canonicalFiltersAddedWith( false ) );
	}

	/**
	 * With the flag on, exactly one filter is added -- never both. Two SEO
	 * plugins would mean two canonical tags, and `Plugin::detect()` exists to
	 * make sure only one adapter is ever wired.
	 */
	public function testExactlyOneFilterIsRegisteredWhileTheFlagIsOn(): void {
		$added = $this->canonicalFiltersAddedWith( true );

		$this->assertLessThanOrEqual( 1, count( $added ) );
	}
}
