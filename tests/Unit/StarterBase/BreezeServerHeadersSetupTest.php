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

	/** @param string[]|null $names */
	public function __construct( ?array $names = null, bool $security_headers = false ) {
		$this->breeze_server_headers = $names;
		$this->security_headers      = $security_headers;
	}

	/** @return string[] */
	public function resolved_names(): array {
		return $this->resolve_breeze_server_headers();
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
	public function test_wires_nothing_when_the_list_is_set_empty_even_with_breeze(): void {
		define( 'BREEZE_VERSION', '2.5.0' );
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeServerHeadersSetupStarterBaseStub( array() ) )->run_setup();

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

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_the_default_wires_the_filter_when_the_theme_sends_no_security_headers(): void {
		define( 'BREEZE_VERSION', '2.5.0' );
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeServerHeadersSetupStarterBaseStub( null, false ) )->run_setup();

		$this->assertContains( 'breeze_custom_headers_allow', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_the_default_drops_nothing_when_the_theme_sends_the_security_headers(): void {
		define( 'BREEZE_VERSION', '2.5.0' );
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeServerHeadersSetupStarterBaseStub( null, true ) )->run_setup();

		$this->assertNotContains( 'breeze_custom_headers_allow', $filters );
	}

	public function test_the_default_names_are_the_managed_security_headers_without_permissions_policy(): void {
		Functions\when( 'is_ssl' )->justReturn( true );

		$managed = array_map( 'strtolower', array_keys( ( new BreezeServerHeadersSetupStarterBaseStub( null, false ) )->security_headers( array() ) ) );
		$managed = array_values( array_diff( $managed, array( 'permissions-policy' ) ) );
		$names   = ( new BreezeServerHeadersSetupStarterBaseStub( null, false ) )->resolved_names();

		sort( $managed );
		sort( $names );

		$this->assertSame( $managed, $names );
	}

	public function test_an_explicit_list_wins_over_the_default(): void {
		$this->assertSame( array( 'x-frame-options' ), ( new BreezeServerHeadersSetupStarterBaseStub( array( 'x-frame-options' ), false ) )->resolved_names() );
	}

	public function test_an_empty_list_turns_the_default_off(): void {
		$this->assertSame( array(), ( new BreezeServerHeadersSetupStarterBaseStub( array(), false ) )->resolved_names() );
	}
}
