<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\WarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * Covers hook registration: both the `breeze_preload_urls` filter and the
 * deferred-refresh cron action, idempotency, and the
 * `timberkit_warmup_sitemap_enabled` opt-out filter.
 */
class RegisterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WarmupSitemap::reset_for_tests();
	}

	protected function tearDown(): void {
		WarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_breeze_preload_urls_filter_and_refresh_cron_action(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);
		$actions = array();
		Functions\when( 'add_action' )->alias(
			function ( string $tag ) use ( &$actions ) {
				$actions[] = $tag;
				return true;
			}
		);

		WarmupSitemap::register();

		$this->assertSame( array( 'breeze_preload_urls' ), $filters );
		$this->assertSame( array( 'timber_kit_breeze_warmup_sitemap_refresh' ), $actions );
	}

	public function test_register_is_idempotent(): void {
		$filterCalls = 0;
		$actionCalls = 0;
		Functions\when( 'add_filter' )->alias(
			function () use ( &$filterCalls ) {
				++$filterCalls;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			function () use ( &$actionCalls ) {
				++$actionCalls;
				return true;
			}
		);

		WarmupSitemap::register();
		WarmupSitemap::register();

		$this->assertSame( 1, $filterCalls );
		$this->assertSame( 1, $actionCalls );
	}

	public function test_register_skips_hooking_when_opted_out(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timberkit_warmup_sitemap_enabled' === $filter ? false : $default;
			}
		);
		Functions\expect( 'add_filter' )->never();
		Functions\expect( 'add_action' )->never();

		WarmupSitemap::register();

		$this->assertFalse( WarmupSitemap::isEnabled() );
	}
}
