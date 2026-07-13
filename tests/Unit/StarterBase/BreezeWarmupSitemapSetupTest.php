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

	public function __construct() {
	}

	public function run_setup_breeze_warmup_sitemap(): void {
		$this->setup_breeze_warmup_sitemap();
	}
}

class BreezeWarmupSitemapSetupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_does_not_register_when_breeze_is_absent(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeWarmupSitemapSetupStarterBaseStub() )->run_setup_breeze_warmup_sitemap();

		$this->assertNotContains( 'breeze_preload_urls', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_registers_when_breeze_version_constant_is_defined(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		( new BreezeWarmupSitemapSetupStarterBaseStub() )->run_setup_breeze_warmup_sitemap();

		$this->assertContains( 'breeze_preload_urls', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_does_not_register_when_breeze_active_but_project_opted_out(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timberkit_warmup_sitemap_enabled' === $filter ? false : $default;
			}
		);
		Functions\expect( 'add_filter' )->never();

		( new BreezeWarmupSitemapSetupStarterBaseStub() )->run_setup_breeze_warmup_sitemap();

		$this->assertFalse( BreezeWarmupSitemap::isEnabled() );
	}
}
