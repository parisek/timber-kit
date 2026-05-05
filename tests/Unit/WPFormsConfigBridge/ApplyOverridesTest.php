<?php

declare(strict_types=1);

namespace Tests\Unit\WPFormsConfigBridge;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\WPFormsConfigBridge;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ApplyOverridesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WPFormsConfigBridge::reset_for_tests();
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
}
