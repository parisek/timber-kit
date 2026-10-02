<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Breeze\ServerHeaders;
use Parisek\TimberKit\StarterBase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class BreezeServerHeadersSetupStarterBaseStub extends StarterBase {

	/** @param string[] $names */
	public function __construct( array $names = array() ) {
		$this->breeze_server_headers = $names;
	}

	public function run_setup(): void {
		$this->setup_breeze_server_headers();
	}
}

/**
 * Covers `StarterBase::setup_breeze_server_headers()`: off by default (only
 * the site knows what its server sends), and wired only when Breeze is
 * loaded and the site lists header names.
 */
class BreezeServerHeadersSetupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		ServerHeaders::reset_for_tests();
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		ServerHeaders::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_wires_nothing_when_the_list_is_empty_even_with_breeze(): void {
		define( 'BREEZE_VERSION', '2.5.0' );
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeServerHeadersSetupStarterBaseStub() )->run_setup();

		$this->assertNotContains( 'breeze_custom_headers_allow', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_wires_nothing_when_breeze_is_absent(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeServerHeadersSetupStarterBaseStub( array( 'x-frame-options' ) ) )->run_setup();

		$this->assertNotContains( 'breeze_custom_headers_allow', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_wires_the_filter_when_names_are_listed_and_breeze_is_active(): void {
		define( 'BREEZE_VERSION', '2.5.0' );
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeServerHeadersSetupStarterBaseStub( array( 'x-frame-options' ) ) )->run_setup();

		$this->assertContains( 'breeze_custom_headers_allow', $filters );
	}
}
