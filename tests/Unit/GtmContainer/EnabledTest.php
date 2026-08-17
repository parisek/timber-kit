<?php

declare(strict_types=1);

namespace Tests\Unit\GtmContainer;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\GtmContainer;

/**
 * The measurement on/off decision.
 *
 * The gate is deliberately its own constant rather than WP_DEBUG: debugging
 * and measuring are separate concerns, and a site that switches one must not
 * silently switch the other.
 */
class EnabledTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_constant_wins_over_the_environment(): void {
		define( 'TIMBERKIT_GTM_ENABLED', FALSE );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$this->assertFalse( GtmContainer::enabled() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_the_constant_can_switch_measurement_on_outside_production(): void {
		define( 'TIMBERKIT_GTM_ENABLED', TRUE );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );

		$this->assertTrue( GtmContainer::enabled() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_a_string_constant_is_read_as_a_boolean(): void {
		define( 'TIMBERKIT_GTM_ENABLED', 'false' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$this->assertFalse( GtmContainer::enabled() );
	}

	public function test_without_the_constant_production_measures(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$this->assertTrue( GtmContainer::enabled() );
	}

	public function test_without_the_constant_every_other_environment_stays_quiet(): void {
		foreach ( array( 'local', 'development', 'staging' ) as $environment ) {
			Functions\when( 'wp_get_environment_type' )->justReturn( $environment );

			$this->assertFalse( GtmContainer::enabled(), $environment . ' must not measure' );
		}
	}
}
