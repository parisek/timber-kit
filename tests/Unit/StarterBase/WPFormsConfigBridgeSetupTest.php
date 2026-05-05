<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Parisek\TimberKit\WPFormsConfigBridge;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class WPFormsConfigBridgeSetupStarterBaseStub extends StarterBase {

	public function __construct() {
	}

	public function run_setup_wpforms_config_bridge(): void {
		$this->setup_wpforms_config_bridge();
	}
}

class WPFormsConfigBridgeSetupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WPFormsConfigBridge::reset_for_tests();
	}

	protected function tearDown(): void {
		WPFormsConfigBridge::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_does_not_register_when_wpforms_is_absent(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		( new WPFormsConfigBridgeSetupStarterBaseStub() )->run_setup_wpforms_config_bridge();

		$this->assertNotContains( 'option_wpforms_settings', $filters );
		$this->assertNotContains( 'default_option_wpforms_settings', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_registers_when_wpforms_version_constant_is_defined(): void {
		define( 'WPFORMS_VERSION', '1.0.0' );

		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		( new WPFormsConfigBridgeSetupStarterBaseStub() )->run_setup_wpforms_config_bridge();

		$this->assertContains( 'option_wpforms_settings', $filters );
		$this->assertContains( 'default_option_wpforms_settings', $filters );
	}
}
