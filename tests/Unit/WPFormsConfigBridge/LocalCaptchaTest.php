<?php

declare(strict_types=1);

namespace Tests\Unit\WPFormsConfigBridge;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\WPFormsConfigBridge;

/**
 * `$wpforms_local_captcha_off`: on a local install the bridge sets the CAPTCHA
 * provider to `none` unless `WPFORMS_CAPTCHA_PROVIDER` says otherwise.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class LocalCaptchaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WPFormsConfigBridge::reset_for_tests();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
	}

	protected function tearDown(): void {
		WPFormsConfigBridge::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_local_install_turns_the_captcha_off(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		WPFormsConfigBridge::register( true );

		$result = WPFormsConfigBridge::applyOverrides( array( 'captcha-provider' => 'turnstile' ) );

		$this->assertSame( 'none', $result['captcha-provider'] );
	}

	public function test_local_install_without_saved_settings_turns_the_captcha_off(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		WPFormsConfigBridge::register( true );

		$result = WPFormsConfigBridge::applyOverrides( false );

		$this->assertSame( 'none', $result['captcha-provider'] );
	}

	public function test_constant_wins_over_the_local_default(): void {
		define( 'WPFORMS_CAPTCHA_PROVIDER', 'turnstile' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		WPFormsConfigBridge::register( true );

		$result = WPFormsConfigBridge::applyOverrides( array( 'captcha-provider' => 'hcaptcha' ) );

		$this->assertSame( 'turnstile', $result['captcha-provider'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function nonLocalEnvironments(): array {
		return array(
			'production'  => array( 'production' ),
			'staging'     => array( 'staging' ),
			'development' => array( 'development' ),
		);
	}

	#[DataProvider( 'nonLocalEnvironments' )]
	public function test_other_environments_keep_the_saved_provider( string $environment ): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( $environment );
		WPFormsConfigBridge::register( true );

		$result = WPFormsConfigBridge::applyOverrides( array( 'captcha-provider' => 'turnstile' ) );

		$this->assertSame( 'turnstile', $result['captcha-provider'] );
	}

	public function test_flag_off_keeps_the_saved_provider_on_a_local_install(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		WPFormsConfigBridge::register( false );

		$result = WPFormsConfigBridge::applyOverrides( array( 'captcha-provider' => 'turnstile' ) );

		$this->assertSame( 'turnstile', $result['captcha-provider'] );
	}

	public function test_register_without_argument_keeps_the_saved_provider(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		WPFormsConfigBridge::register();

		$result = WPFormsConfigBridge::applyOverrides( array( 'captcha-provider' => 'turnstile' ) );

		$this->assertSame( 'turnstile', $result['captcha-provider'] );
	}

	public function test_admin_notice_names_the_local_default(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Functions\when( 'get_option' )->justReturn( array( 'captcha-provider' => 'turnstile' ) );
		WPFormsConfigBridge::register( true );

		$this->assertSame(
			array( 'captcha-provider' => 'WP_ENVIRONMENT_TYPE=local' ),
			WPFormsConfigBridge::collectActiveOverrides()
		);
	}

	public function test_admin_notice_names_the_constant_when_it_wins(): void {
		define( 'WPFORMS_CAPTCHA_PROVIDER', 'turnstile' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Functions\when( 'get_option' )->justReturn( array() );
		WPFormsConfigBridge::register( true );

		$this->assertSame(
			array( 'captcha-provider' => 'WPFORMS_CAPTCHA_PROVIDER' ),
			WPFormsConfigBridge::collectActiveOverrides()
		);
	}
}
