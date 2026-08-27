<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\StarterBaseTestCase;

/**
 * Covers the ACF hook wiring that keeps the field-group memo honest.
 *
 * `Helpers::fieldGroupsForScreen()` memoizes per screen for the whole process.
 * This hook is the only thing that invalidates it automatically, so an admin
 * save is not answered from the list read before it. Deleting the line, or
 * pointing it at a different hook, otherwise changes no test.
 */
class AcfHookRegistrationTest extends StarterBaseTestCase {

	public function test_field_group_flush_is_wired_to_acf_update_field_group(): void {
		$base = $this->createStarterBase();

		$actions = [];
		Functions\when( 'add_action' )->alias(
			function ( string $tag, $callback = null ) use ( &$actions ) {
				$actions[ $tag ] = $callback;
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		( new \ReflectionMethod( $base, 'registerAcfHooks' ) )->invoke( $base );

		$this->assertArrayHasKey(
			'acf/update_field_group',
			$actions,
			'The field-group memo has no other automatic invalidation.'
		);
		$this->assertSame(
			[ Helpers::class, 'flushFieldGroups' ],
			$actions['acf/update_field_group']
		);
	}
}
