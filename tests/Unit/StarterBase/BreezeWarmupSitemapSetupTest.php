<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\BreezeWarmupSitemap;
use Parisek\TimberKit\StarterBase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class BreezeWarmupSitemapSetupStarterBaseStub extends StarterBase {

	public function __construct( bool $breeze_warmup_sitemap = false ) {
		$this->breeze_warmup_sitemap = $breeze_warmup_sitemap;
	}

	public function run_setup_breeze_warmup_sitemap(): void {
		$this->setup_breeze_warmup_sitemap();
	}
}

/**
 * Covers `StarterBase::setup_breeze_warmup_sitemap()` wiring: the
 * `$breeze_warmup_sitemap` flag (default off — this is a behavior-changing
 * feature per the repo's feature-flag doctrine, so it never auto-activates
 * just because Breeze is present), the Breeze-active detection, and the
 * runtime opt-out filter still working on top of an enabled flag.
 */
class BreezeWarmupSitemapSetupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_does_not_register_when_flag_is_off_even_if_breeze_is_active(): void {
		// Flag defaults to off. Defining BREEZE_VERSION here (rather than
		// leaving Breeze absent) proves the flag — not Breeze detection — is
		// what's gating registration in this case; isolated to its own
		// process so the constant doesn't leak into the "Breeze absent" test.
		define( 'BREEZE_VERSION', '2.5.0' );

		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeWarmupSitemapSetupStarterBaseStub( false ) )->run_setup_breeze_warmup_sitemap();

		$this->assertNotContains( 'breeze_preload_urls', $filters );
	}

	public function test_does_not_register_when_flag_is_on_but_breeze_is_absent(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeWarmupSitemapSetupStarterBaseStub( true ) )->run_setup_breeze_warmup_sitemap();

		$this->assertNotContains( 'breeze_preload_urls', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_registers_when_flag_is_on_and_breeze_version_constant_is_defined(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		( new BreezeWarmupSitemapSetupStarterBaseStub( true ) )->run_setup_breeze_warmup_sitemap();

		$this->assertContains( 'breeze_preload_urls', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_does_not_register_when_flag_on_and_breeze_active_but_project_opted_out(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timberkit_warmup_sitemap_enabled' === $filter ? false : $default;
			}
		);
		Functions\expect( 'add_filter' )->never();

		( new BreezeWarmupSitemapSetupStarterBaseStub( true ) )->run_setup_breeze_warmup_sitemap();

		$this->assertFalse( BreezeWarmupSitemap::isEnabled() );
	}
}
