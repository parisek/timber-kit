<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\WarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * Covers tail persistence during the refresh, and the cold-start rescue.
 *
 * The rescue exists because the tail is written by the deferred refresh while
 * the tick is scheduled by the purge. On a project's first purge the tick can
 * run first, find nothing, and end the chain — after which the refresh writes
 * a tail that nothing will ever start.
 */
class TailRefreshTest extends TestCase {

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

	public function test_schedules_a_tick_when_none_is_pending(): void {
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		$scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $when, string $hook ) use ( &$scheduled ): int {
				$scheduled[] = $hook;

				return 1;
			}
		);

		WarmupSitemap::scheduleTailTick();

		$this->assertContains( WarmupSitemap::TAIL_HOOK, $scheduled );
	}

	public function test_does_not_schedule_a_second_tick(): void {
		// Two chains draining at once would silently double the configured
		// pace, which is the one thing the batch size is meant to control.
		Functions\when( 'as_next_scheduled_action' )->justReturn( true );
		Functions\expect( 'as_schedule_single_action' )->never();

		WarmupSitemap::scheduleTailTick();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_does_nothing_without_action_scheduler(): void {
		// No fatal, no half-wired state — the module behaves as if switched off.
		Functions\expect( 'as_schedule_single_action' )->never();

		WarmupSitemap::scheduleTailTick();
	}

	public function test_refresh_writes_a_non_empty_tail_and_reschedules_the_tick(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'wp_get_nav_menus' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// Cap the ordered set to one URL so the rest spills into the tail —
		// this is what proves the tail is non-empty, not merely written.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				return 'timberkit_warmup_sitemap_max_urls' === $hook ? 1 : $value;
			}
		);

		$urls = array(
			'https://example.test/one/',
			'https://example.test/two/',
			'https://example.test/three/',
		);
		$urlset = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ( $urls as $url ) {
			$urlset .= '<url><loc>' . $url . '</loc></url>';
		}
		$urlset .= '</urlset>';

		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => $urlset,
			)
		);

		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		$scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $when, string $hook ) use ( &$scheduled ): int {
				$scheduled[] = $hook;

				return 1;
			}
		);

		$writtenTails = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload ) use ( &$writtenTails ) {
				if ( 'timber_kit_breeze_warmup_tail' === $key ) {
					$writtenTails[] = $value['urls'];
				}

				return true;
			}
		);

		WarmupSitemap::register( true, null, true );
		WarmupSitemap::runRefresh();

		$this->assertNotSame( array(), $writtenTails, 'runRefresh() must write a tail while tail draining is on.' );
		$this->assertNotSame( array(), $writtenTails[0], 'the tail must contain the URLs the cap excluded.' );
		$this->assertContains( WarmupSitemap::TAIL_HOOK, $scheduled, 'a non-empty tail written on a cold start must self-schedule the tick.' );
	}

	public function test_tail_alone_without_priority_writes_nothing(): void {
		// $tail alone must enable nothing — draining needs the ordering to
		// drain, and priority is what produces it.
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'wp_get_nav_menus' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'delete_transient' )->justReturn( true );

		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.test/one/</loc></url></urlset>',
			)
		);

		Functions\expect( 'as_schedule_single_action' )->never();

		$tailWritten = false;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload ) use ( &$tailWritten ) {
				if ( 'timber_kit_breeze_warmup_tail' === $key ) {
					$tailWritten = true;
				}

				return true;
			}
		);

		WarmupSitemap::register( false, null, true );
		WarmupSitemap::runRefresh();

		$this->assertFalse( $tailWritten, '$tail alone (without $priority) must not enable tail draining.' );
	}
}
