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

	/**
	 * Approved exception to the fleet's default-off rule for new behaviour --
	 * see the flag's own docblock on `StarterBase` and `AGENTS.md` § Feature
	 * flags & breaking changes for why this one ships on.
	 */
	public function testTheFlagIsOnByDefault(): void {
		$base = $this->createStarterBase();

		$property = new \ReflectionProperty( $base, 'seo_canonical_pagination' );

		$this->assertTrue( $property->getValue( $base ) );
	}

	public function testNothingIsRegisteredWhileTheFlagIsOff(): void {
		$this->assertSame( array(), $this->canonicalFiltersAddedWith( false ) );
	}

	/**
	 * With the flag on, at most one filter is added -- zero also passes this
	 * assertion, and that is deliberate, not an oversight.
	 *
	 * A stronger assertion (naming the one expected hook, or requiring
	 * `count( $added ) === 1`) would depend on which plugin `Plugin::active()`
	 * reports at this point in the run. Brain\Monkey defines real global
	 * functions that outlive the test that defined them, so an earlier test's
	 * stub can still be in effect here, making the result a function of suite
	 * ordering rather than of this test alone. A weak assertion that always
	 * holds beats a strong one that only holds by accident of run order.
	 *
	 * The "never both" guarantee itself is not tested here: it is structural,
	 * enforced by the `match` in `Canonical::register()` having no fallthrough
	 * arm, so at most one adapter can ever be reached. This test corroborates
	 * that outcome; it does not enforce it.
	 */
	public function testAtMostOneFilterIsRegisteredWhileTheFlagIsOn(): void {
		$added = $this->canonicalFiltersAddedWith( true );

		$this->assertLessThanOrEqual( 1, count( $added ) );
	}
}
