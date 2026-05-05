<?php

declare(strict_types=1);

namespace Tests\Unit\WPFormsConfigBridge;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\WPFormsConfigBridge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ApplyOverridesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WPFormsConfigBridge::reset_for_tests();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_array_when_option_is_missing(): void {
		$result = WPFormsConfigBridge::applyOverrides( false );

		$this->assertSame( array(), $result );
	}

	public function test_existing_setting_is_overridden_by_constant(): void {
		define( 'WPFORMS_TURNSTILE_SITE_KEY', 'overridden-site-key' );

		$result = WPFormsConfigBridge::applyOverrides(
			array(
				'turnstile-site-key'   => 'saved-site-key',
				'turnstile-secret-key' => 'saved-secret',
			)
		);

		$this->assertSame( 'overridden-site-key', $result['turnstile-site-key'] );
		$this->assertSame( 'saved-secret', $result['turnstile-secret-key'] );
	}

	public function test_known_captcha_key_is_added_when_missing_from_settings(): void {
		define( 'WPFORMS_TURNSTILE_SECRET_KEY', 'fresh-install-secret' );

		$result = WPFormsConfigBridge::applyOverrides( array() );

		$this->assertSame( 'fresh-install-secret', $result['turnstile-secret-key'] );
	}

	public function test_arbitrary_setting_is_bridged_when_present_in_option(): void {
		define( 'WPFORMS_DISABLE_CSS', '1' );

		$result = WPFormsConfigBridge::applyOverrides(
			array(
				'disable-css' => '2',
			)
		);

		$this->assertSame( '1', $result['disable-css'] );
	}

	public function test_arbitrary_setting_is_not_added_without_existing_key(): void {
		define( 'WPFORMS_SOME_UNKNOWN_KEY', 'value' );

		$result = WPFormsConfigBridge::applyOverrides( array() );

		$this->assertArrayNotHasKey( 'some-unknown-key', $result );
	}

	public function test_no_constants_leaves_settings_intact(): void {
		$input = array(
			'turnstile-site-key' => 'saved',
			'email-template'     => 'default',
		);

		$result = WPFormsConfigBridge::applyOverrides( $input );

		$this->assertSame( $input, $result );
	}

	public function test_collect_active_overrides_returns_map_of_overridden_keys(): void {
		define( 'WPFORMS_TURNSTILE_SITE_KEY', 'overridden' );
		define( 'WPFORMS_DISABLE_CSS', '1' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'turnstile-site-key' => 'saved',
				'disable-css'        => '2',
			)
		);

		$result = WPFormsConfigBridge::collectActiveOverrides();

		$this->assertSame(
			array(
				'disable-css'        => 'WPFORMS_DISABLE_CSS',
				'turnstile-site-key' => 'WPFORMS_TURNSTILE_SITE_KEY',
			),
			$result
		);
	}

	public function test_collect_active_overrides_includes_always_bridged_keys_on_fresh_install(): void {
		define( 'WPFORMS_TURNSTILE_SECRET_KEY', 'fresh' );
		Functions\when( 'get_option' )->justReturn( array() );

		$result = WPFormsConfigBridge::collectActiveOverrides();

		$this->assertSame(
			array( 'turnstile-secret-key' => 'WPFORMS_TURNSTILE_SECRET_KEY' ),
			$result
		);
	}

	public function test_collect_active_overrides_returns_empty_when_no_constants_defined(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'turnstile-site-key' => 'saved' )
		);

		$this->assertSame( array(), WPFormsConfigBridge::collectActiveOverrides() );
	}

	public function test_register_hooks_both_option_and_default_option_filters(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );

		WPFormsConfigBridge::register();

		$this->assertContains( 'option_wpforms_settings', $filters );
		$this->assertContains( 'default_option_wpforms_settings', $filters );
	}

	public function test_default_option_path_injects_constants_when_settings_row_missing(): void {
		define( 'WPFORMS_TURNSTILE_SITE_KEY', 'fresh-install-site-key' );

		$result = WPFormsConfigBridge::applyOverrides( false );

		$this->assertSame( 'fresh-install-site-key', $result['turnstile-site-key'] );
	}

	public function test_admin_notice_is_registered_late_to_survive_wpforms_strip(): void {
		$actions = array();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			function ( string $tag, mixed $callback, int $priority = 10 ) use ( &$actions ) {
				$actions[] = array(
					'tag'      => $tag,
					'priority' => $priority,
				);
				return true;
			}
		);
		Functions\when( 'is_admin' )->justReturn( true );

		WPFormsConfigBridge::register();

		$this->assertContains(
			array(
				'tag'      => 'admin_print_styles',
				'priority' => 1,
			),
			$actions,
			'Admin notice must be re-registered on admin_print_styles priority 1 to survive WPForms removing all admin_notices actions on its own pages.'
		);
	}
}
