<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerSecurityHardeningHooks() respects each feature flag
 * and registers (or skips) hooks accordingly.
 */
class RegisterSecurityHardeningHooksTest extends StarterBaseTestCase {

	private function invokeRegisterSecurityHardeningHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerSecurityHardeningHooks' );
		$method->invoke( $instance );
	}

	private function bareInstanceWithAllFlagsOff(): StarterBase {
		$instance = ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
		foreach ( [
			'cleanup_wp_head',
			'disable_xmlrpc',
			'disable_emojis',
			'disable_feeds',
			'disable_search',
			'cleanup_dashboard',
			'cleanup_admin_bar',
			'editor_role_enhancements',
			'disable_self_pingbacks',
			'restrict_rest_users',
			'disable_application_passwords',
			'block_author_enumeration',
			'disable_file_editing',
			'remove_wp_generator',
			'disable_author_sitemap',
			'security_headers',
		] as $flag ) {
			$this->setProperty( $instance, $flag, false );
		}
		return $instance;
	}

	private function setProperty( StarterBase $instance, string $name, mixed $value ): void {
		$prop = ( new \ReflectionClass( StarterBase::class ) )->getProperty( $name );
		$prop->setValue( $instance, $value );
	}

	public function test_no_hooks_registered_when_all_flags_disabled(): void {
		$actions = [];
		$filters = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterSecurityHardeningHooks( $this->bareInstanceWithAllFlagsOff() );

		$this->assertEmpty( $actions );
		$this->assertEmpty( $filters );
	}

	public function test_cleanup_wp_head_registers_init_when_enabled(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'cleanup_wp_head', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertContains( 'init', $actions );
	}

	public function test_disable_xmlrpc_registers_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'disable_xmlrpc', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertContains( 'xmlrpc_enabled', $filters );
		$this->assertContains( 'wp_headers', $filters );
	}

	public function test_restrict_rest_users_registers_filter(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'restrict_rest_users', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertContains( 'rest_authentication_errors', $filters );
	}

	public function test_block_author_enumeration_registers_template_redirect_at_priority_9(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'priority' => $priority ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'block_author_enumeration', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$redirect = array_filter( $actions, fn( $a ) => $a['hook'] === 'template_redirect' );
		$this->assertNotEmpty( $redirect );
		$this->assertSame( 9, array_values( $redirect )[0]['priority'] );
	}

	public function test_remove_wp_generator_registers_the_generator_filter(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, ...$rest ) use ( &$filters ) {
			$filters[$hook] = $callback;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'remove_wp_generator', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertArrayHasKey( 'the_generator', $filters );
		$this->assertSame( '__return_empty_string', $filters['the_generator'] );
	}

	public function test_disable_author_sitemap_registers_provider_filter(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) use ( &$filters ) {
			$filters[ $hook ] = [ 'priority' => $priority, 'accepted_args' => $accepted_args ];
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'disable_author_sitemap', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertArrayHasKey( 'wp_sitemaps_add_provider', $filters );
		// The handler needs the 2nd arg ($name) to tell the users provider apart —
		// regress if the `, 10, 2` is ever dropped from the registration.
		$this->assertSame( 2, $filters['wp_sitemaps_add_provider']['accepted_args'] );
	}

	public function test_security_headers_registers_wp_headers_filter_when_enabled(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'security_headers', true );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertContains( 'wp_headers', $filters );
	}

	public function test_security_headers_registers_duplicate_headers_site_health_test_by_default(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'security_headers', true );
		// $warn_duplicate_security_headers defaults to true.

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		$this->assertContains( 'site_status_tests', $filters );
	}

	public function test_duplicate_headers_site_health_test_skipped_when_flag_off(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstanceWithAllFlagsOff();
		$this->setProperty( $instance, 'security_headers', true );
		$this->setProperty( $instance, 'warn_duplicate_security_headers', false );

		$this->invokeRegisterSecurityHardeningHooks( $instance );

		// wp_headers still registers; the Site Health test does not.
		$this->assertContains( 'wp_headers', $filters );
		$this->assertNotContains( 'site_status_tests', $filters );
	}
}
