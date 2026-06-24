<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerPerformanceHooks() registers the speculation-rules
 * filter and Site Health test under the right feature-flag gates, and that
 * the public callbacks return the expected payloads.
 */
class RegisterPerformanceHooksTest extends StarterBaseTestCase {

	private function invokeRegisterPerformanceHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerPerformanceHooks' );
		$method->invoke( $instance );
	}

	private function setProperty( StarterBase $instance, string $name, mixed $value ): void {
		$prop = ( new \ReflectionClass( StarterBase::class ) )->getProperty( $name );
		$prop->setValue( $instance, $value );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_speculation_rules_filter_skipped_when_property_null(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', null );
		$this->setProperty( $instance, 'warn_speculation_rules_plugin_redundant', false );

		$this->invokeRegisterPerformanceHooks( $instance );

		$this->assertNotContains( 'wp_speculation_rules_configuration', $filters );
	}

	public function test_speculation_rules_filter_registered_when_property_set(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'warn_speculation_rules_plugin_redundant', false );

		$this->invokeRegisterPerformanceHooks( $instance );

		$this->assertContains( 'wp_speculation_rules_configuration', $filters );
	}

	public function test_site_health_filter_skipped_when_both_warnings_disabled(): void {
		// Both site-health features register the same `site_status_tests` hook, so the
		// hook is only absent when BOTH are off.
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', null );
		$this->setProperty( $instance, 'warn_speculation_rules_plugin_redundant', false );
		$this->setProperty( $instance, 'resizer_format_health', false );

		$this->invokeRegisterPerformanceHooks( $instance );

		$this->assertNotContains( 'site_status_tests', $filters );
		$this->assertNotContains( 'debug_information', $filters );
	}

	public function test_resizer_format_health_registers_hooks_by_default(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		// Isolate the resizer feature from the speculation one.
		$this->setProperty( $instance, 'speculation_rules', null );
		$this->setProperty( $instance, 'warn_speculation_rules_plugin_redundant', false );

		$this->invokeRegisterPerformanceHooks( $instance );

		$this->assertContains( 'site_status_tests', $filters );
		$this->assertContains( 'debug_information', $filters );
	}

	public function test_resizer_format_health_debug_hook_skipped_when_disabled(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'resizer_format_health', false );

		$this->invokeRegisterPerformanceHooks( $instance );

		// debug_information is unique to the resizer feature, so it must be gone.
		$this->assertNotContains( 'debug_information', $filters );
	}

	public function test_resizer_skip_animated_filter_not_registered_by_default(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', null );
		$this->setProperty( $instance, 'warn_speculation_rules_plugin_redundant', false );
		$this->setProperty( $instance, 'resizer_format_health', false );

		$this->invokeRegisterPerformanceHooks( $instance );

		// Default off → the resizer keeps re-encoding animated sources (no flag wired).
		$this->assertNotContains( 'timber_kit_resizer_skip_animated', $filters );
	}

	public function test_resizer_skip_animated_filter_registered_when_enabled(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', null );
		$this->setProperty( $instance, 'warn_speculation_rules_plugin_redundant', false );
		$this->setProperty( $instance, 'resizer_format_health', false );
		$this->setProperty( $instance, 'resizer_skip_animated', true );

		$this->invokeRegisterPerformanceHooks( $instance );

		$this->assertContains( 'timber_kit_resizer_skip_animated', $filters );
	}

	public function test_site_health_register_resizer_formats_test_adds_direct_entry(): void {
		$instance = $this->bareInstance();
		Functions\when( '__' )->returnArg( 1 );

		$tests = $instance->site_health_register_resizer_formats_test( [] );

		$this->assertArrayHasKey( 'timber_kit_resizer_formats', $tests['direct'] );
		$this->assertSame(
			[ $instance, 'site_health_test_resizer_formats' ],
			$tests['direct']['timber_kit_resizer_formats']['test']
		);
	}

	public function test_site_health_filter_registered_by_default(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();

		$this->invokeRegisterPerformanceHooks( $instance );

		$this->assertContains( 'site_status_tests', $filters );
	}

	public function test_configure_speculation_rules_returns_mode_and_eagerness_for_anonymous(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$instance = $this->bareInstance();

		$result = $instance->configure_speculation_rules( null );

		$this->assertSame(
			[ 'mode' => 'prerender', 'eagerness' => 'moderate' ],
			$result
		);
	}

	public function test_configure_speculation_rules_returns_null_for_logged_in_when_gate_is_logged_out(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$instance = $this->bareInstance();

		$this->assertNull( $instance->configure_speculation_rules( null ) );
	}

	public function test_configure_speculation_rules_emits_rules_for_logged_in_when_gate_is_any(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', [
			'mode'           => 'prefetch',
			'eagerness'      => 'conservative',
			'authentication' => 'any',
		] );

		$result = $instance->configure_speculation_rules( null );

		$this->assertSame(
			[ 'mode' => 'prefetch', 'eagerness' => 'conservative' ],
			$result
		);
	}

	public function test_configure_speculation_rules_merges_partial_override_with_defaults(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', [ 'mode' => 'prefetch' ] );

		// Convert PHP notices/warnings to exceptions so the test fails if any key
		// access on the partial array silently emits "Undefined array key …".
		set_error_handler( static function ( int $errno, string $message ): bool {
			throw new \ErrorException( $message, 0, $errno );
		}, E_NOTICE | E_WARNING );
		try {
			$result = $instance->configure_speculation_rules( null );
		} finally {
			restore_error_handler();
		}

		// `mode` honoured from the override, `eagerness` filled from defaults.
		$this->assertSame(
			[ 'mode' => 'prefetch', 'eagerness' => 'moderate' ],
			$result
		);
	}

	public function test_configure_speculation_rules_partial_override_respects_authentication_default(): void {
		// Partial override without `authentication` must still apply the `logged_out` default gate.
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', [ 'eagerness' => 'eager' ] );

		$this->assertNull( $instance->configure_speculation_rules( null ) );
	}

	public function test_configure_speculation_rules_passes_through_when_property_null(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'speculation_rules', null );

		$passed = [ 'mode' => 'prefetch', 'eagerness' => 'conservative' ];
		$this->assertSame( $passed, $instance->configure_speculation_rules( $passed ) );
		$this->assertNull( $instance->configure_speculation_rules( 'not-an-array' ) );
	}

	public function test_site_health_register_speculation_rules_test_adds_direct_entry(): void {
		$instance = $this->bareInstance();

		Functions\when( '__' )->returnArg( 1 );

		$tests = $instance->site_health_register_speculation_rules_test( [] );

		$this->assertArrayHasKey( 'direct', $tests );
		$this->assertArrayHasKey( 'timber_kit_speculation_rules_redundant', $tests['direct'] );
		$this->assertSame(
			[ $instance, 'site_health_test_speculation_rules' ],
			$tests['direct']['timber_kit_speculation_rules_redundant']['test']
		);
	}

	public function test_site_health_test_speculation_rules_returns_good_when_plugin_inactive(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		$instance = $this->bareInstance();

		$result = $instance->site_health_test_speculation_rules();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'timber_kit_speculation_rules_redundant', $result['test'] );
	}

	public function test_site_health_test_speculation_rules_returns_recommended_when_plugin_active(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );

		$instance = $this->bareInstance();

		$result = $instance->site_health_test_speculation_rules();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'speculation-rules', $result['actions'] );
	}
}
