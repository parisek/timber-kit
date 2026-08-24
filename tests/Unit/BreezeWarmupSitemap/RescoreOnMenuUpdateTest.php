<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers the in-place rescore on `wp_update_nav_menu`.
 *
 * A menu edit changes the very signal the ordering is built on *and* triggers
 * a full purge, so waiting for the hourly refresh would leave the ordering
 * one step behind exactly when it matters most. Recomputing from stored
 * signals avoids a network round trip entirely.
 */
class RescoreOnMenuUpdateTest extends TestCase {

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

	/**
	 * Turns priority ordering on the way a real project would: through
	 * `register( true )`. This also computes `$weights_hash` once, exactly
	 * as production does, rather than poking the private static directly.
	 */
	private function enablePriority(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );

		BreezeWarmupSitemap::register( true );
	}

	public function test_reorders_from_stored_signals_without_touching_the_network(): void {
		$this->enablePriority();

		Functions\when( 'get_option' )->justReturn(
			array(
				'urls'    => array( 'https://example.test/a/', 'https://example.test/b/' ),
				'signals' => array(
					'https://example.test/a/' => array(
						'lastmod' => null, 'type' => '', 'lang' => 'cs',
						'menu' => false, 'front_page' => false, 'manual' => false,
						'url' => 'https://example.test/a/',
					),
					'https://example.test/b/' => array(
						'lastmod' => null, 'type' => '', 'lang' => 'cs',
						'menu' => false, 'front_page' => false, 'manual' => false,
						'url' => 'https://example.test/b/',
					),
				),
				'fetched_at'   => time(),
				'weights_hash' => 'h',
				'revision'     => 1,
			)
		);
		Functions\when( 'wp_get_nav_menus' )->justReturn( array( (object) array( 'term_id' => 3 ) ) );
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			array( (object) array( 'url' => 'https://example.test/b/' ) )
		);
		Functions\expect( 'wp_remote_get' )->never();

		$written = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$written ): bool {
				$written = $value;

				return true;
			}
		);

		BreezeWarmupSitemap::rescoreOnMenuUpdate();

		$this->assertSame( 'https://example.test/b/', $written['urls'][0], 'the new menu page leads' );
	}

	public function test_does_nothing_when_signals_are_missing(): void {
		$this->enablePriority();

		// A v1.x payload, or a refresh that has not run yet. Partial data must
		// never be written: stale data beats no data, same as runRefresh().
		Functions\when( 'get_option' )->justReturn(
			array( 'urls' => array( 'https://example.test/a/' ), 'fetched_at' => time() )
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\expect( 'update_option' )->never();

		BreezeWarmupSitemap::rescoreOnMenuUpdate();

		$this->addToAssertionCount( 1 );
	}

	public function test_register_true_hooks_the_rescore_at_priority_5(): void {
		$actions = array();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			function ( string $tag, $callback, int $priority = 10 ) use ( &$actions ) {
				$actions[] = array( $tag, $priority );

				return true;
			}
		);

		BreezeWarmupSitemap::register( true );

		$this->assertContains( array( 'wp_update_nav_menu', 5 ), $actions );
	}

	public function test_register_false_does_not_hook_the_rescore(): void {
		$actions = array();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			function ( string $tag ) use ( &$actions ) {
				$actions[] = $tag;

				return true;
			}
		);

		BreezeWarmupSitemap::register();

		$this->assertNotContains( 'wp_update_nav_menu', $actions );
	}

	public function test_register_false_leaves_rescore_unreachable_even_with_stored_signals(): void {
		// register() defaults to false: priority_enabled stays false, so
		// rescoreOnMenuUpdate() must no-op regardless of what is stored.
		Functions\when( 'get_option' )->justReturn(
			array(
				'urls'    => array( 'https://example.test/a/' ),
				'signals' => array(
					'https://example.test/a/' => array(
						'lastmod' => null, 'type' => '', 'lang' => 'cs',
						'menu' => false, 'front_page' => false, 'manual' => false,
						'url' => 'https://example.test/a/',
					),
				),
				'fetched_at'   => time(),
				'weights_hash' => 'h',
				'revision'     => 1,
			)
		);
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		BreezeWarmupSitemap::rescoreOnMenuUpdate();

		$this->addToAssertionCount( 1 );
	}
}
