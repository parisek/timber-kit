<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Breeze\ServerHeaders;
use Parisek\TimberKit\StarterBase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class BreezeServerHeadersSetupStarterBaseStub extends StarterBase {

	public function __construct( ?bool $skip = null, bool $security_headers = false ) {
		if ( null !== $skip ) {
			$this->breeze_skip_server_headers = $skip;
		}

		$this->security_headers = $security_headers;
	}

	public function flag(): bool {
		return $this->breeze_skip_server_headers;
	}

	public function run_setup(): void {
		$this->setup_breeze_server_headers();
	}

	/** @return string[] */
	public function resolved_names(): array {
		return $this->resolve_breeze_server_headers();
	}
}

/**
 * Covers `StarterBase::setup_breeze_server_headers()`: on by default (a named
 * exception in AGENTS.md), wired only when the flag is on and Breeze is
 * loaded, and the header names follow `$security_headers`.
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

	/** @return string[] The filter tags registered while running the setup. */
	private function filtersFor( BreezeServerHeadersSetupStarterBaseStub $stub ): array {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		$stub->run_setup();

		return $filters;
	}

	public function test_the_flag_defaults_to_on(): void {
		$this->assertTrue( ( new BreezeServerHeadersSetupStarterBaseStub() )->flag() );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_wires_nothing_when_the_flag_is_off_even_with_breeze(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$this->assertNotContains( 'breeze_custom_headers_allow', $this->filtersFor( new BreezeServerHeadersSetupStarterBaseStub( false ) ) );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_wires_nothing_when_breeze_is_absent(): void {
		$this->assertNotContains( 'breeze_custom_headers_allow', $this->filtersFor( new BreezeServerHeadersSetupStarterBaseStub( true ) ) );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_wires_the_filter_when_the_theme_sends_no_security_headers(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$this->assertContains( 'breeze_custom_headers_allow', $this->filtersFor( new BreezeServerHeadersSetupStarterBaseStub( true, false ) ) );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_drops_nothing_when_the_theme_sends_the_security_headers(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$this->assertNotContains( 'breeze_custom_headers_allow', $this->filtersFor( new BreezeServerHeadersSetupStarterBaseStub( true, true ) ) );
	}

	public function test_the_names_are_the_managed_security_headers_without_permissions_policy(): void {
		Functions\when( 'is_ssl' )->justReturn( true );

		$managed = array_map( 'strtolower', array_keys( ( new BreezeServerHeadersSetupStarterBaseStub( true, false ) )->security_headers( array() ) ) );
		$managed = array_values( array_diff( $managed, array( 'permissions-policy' ) ) );
		$names   = ( new BreezeServerHeadersSetupStarterBaseStub( true, false ) )->resolved_names();

		sort( $managed );
		sort( $names );

		$this->assertSame( $managed, $names );
	}

	public function test_there_are_no_names_when_the_theme_sends_the_security_headers(): void {
		$this->assertSame( array(), ( new BreezeServerHeadersSetupStarterBaseStub( true, true ) )->resolved_names() );
	}

	public function test_the_filter_can_change_the_names(): void {
		Filters\expectApplied( 'timber_kit_breeze_server_headers' )->once()->andReturn( array( 'x-frame-options' ) );

		$this->assertSame( array( 'x-frame-options' ), ( new BreezeServerHeadersSetupStarterBaseStub( true, false ) )->resolved_names() );
	}
}
