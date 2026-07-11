<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerSiteHealthHooks() respects the $site_health flag
 * (off = default = no hooks; on = site_status_tests filter registered).
 */
class RegisterSiteHealthHooksTest extends StarterBaseTestCase {

	private function invokeRegisterSiteHealthHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerSiteHealthHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance( bool $site_health ): StarterBase {
		$instance = ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
		$prop     = ( new \ReflectionClass( StarterBase::class ) )->getProperty( 'site_health' );
		$prop->setValue( $instance, $site_health );
		return $instance;
	}

	public function test_no_hooks_when_flag_off(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterSiteHealthHooks( $this->bareInstance( false ) );

		$this->assertEmpty( $filters );
	}

	public function test_site_status_tests_filter_registered_when_flag_on(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterSiteHealthHooks( $this->bareInstance( true ) );

		$this->assertSame( [ 'site_status_tests' ], $filters );
	}
}
