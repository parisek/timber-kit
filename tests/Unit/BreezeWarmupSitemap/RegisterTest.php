<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers hook registration: idempotency and the
 * `timberkit_warmup_sitemap_enabled` opt-out filter.
 */
class RegisterTest extends TestCase {

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

	public function test_register_hooks_breeze_preload_urls_filter(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		BreezeWarmupSitemap::register();

		$this->assertSame( array( 'breeze_preload_urls' ), $filters );
	}

	public function test_register_is_idempotent(): void {
		$calls = 0;
		Functions\when( 'add_filter' )->alias(
			function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);

		BreezeWarmupSitemap::register();
		BreezeWarmupSitemap::register();

		$this->assertSame( 1, $calls );
	}

	public function test_register_skips_hooking_when_opted_out(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timberkit_warmup_sitemap_enabled' === $filter ? false : $default;
			}
		);
		Functions\expect( 'add_filter' )->never();

		BreezeWarmupSitemap::register();

		$this->assertFalse( BreezeWarmupSitemap::isEnabled() );
	}
}
