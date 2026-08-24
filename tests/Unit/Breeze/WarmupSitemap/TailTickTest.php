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
 * Covers one tail tick: the brake, the batch, and the successor.
 *
 * The successor is scheduled directly rather than through scheduleTailTick(),
 * because that helper reports a RUNNING action as scheduled — a tick asking it
 * would see itself and end the chain after one run.
 */
class TailTickTest extends TestCase {

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

	/**
	 * Enable the tail feature the way registration would.
	 */
	private function enableTail( int $batch = 2 ): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		WarmupSitemap::register( true, null, true, $batch );
	}

	public function test_skips_the_tick_while_breeze_is_still_warming(): void {
		// Piling our batch on top of Breeze's own queue would hit the origin
		// exactly when it is busiest — right after a purge.
		$this->enableTail();
		Functions\when( 'get_option' )->alias(
			static fn( string $key ) => 'breeze_preload_queue' === $key ? array( 'https://example.test/x/' ) : null
		);
		$scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $when, string $hook ) use ( &$scheduled ): int {
				$scheduled[] = $hook;

				return 1;
			}
		);
		Functions\expect( 'update_option' )->never();

		WarmupSitemap::runTailTick();

		$this->assertContains( WarmupSitemap::TAIL_HOOK, $scheduled, 'a skipped tick still schedules its successor' );
	}

	public function test_dispatches_a_batch_and_advances_the_cursor(): void {
		$this->enableTail( 2 );

		$tail = array( 'urls' => array( 'a', 'b', 'c' ), 'hash' => 'H' );
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( $tail ) {
				if ( 'breeze_preload_queue' === $key ) {
					return array();
				}
				if ( WarmupSitemap::TAIL_HOOK === $key ) {
					return null;
				}

				return str_contains( $key, 'cursor' )
					? array( 'index' => 0, 'hash' => 'H' )
					: $tail;
			}
		);
		$written = null;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): bool {
				if ( str_contains( $key, 'cursor' ) ) {
					$written = $value;
				}

				return true;
			}
		);
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		WarmupSitemap::runTailTick();

		$this->assertSame( 2, $written['index'] );
		$this->assertSame( 'H', $written['hash'] );
	}

	public function test_a_changed_tail_restarts_from_the_beginning(): void {
		// The refresh rewrote the tail mid-drain; the old index points into a
		// different plan and must not be trusted.
		$this->enableTail( 1 );
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( 'breeze_preload_queue' === $key ) {
					return array();
				}

				return str_contains( $key, 'cursor' )
					? array( 'index' => 99, 'hash' => 'OLD' )
					: array( 'urls' => array( 'a', 'b' ), 'hash' => 'NEW' );
			}
		);
		$written = null;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): bool {
				if ( str_contains( $key, 'cursor' ) ) {
					$written = $value;
				}

				return true;
			}
		);
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		WarmupSitemap::runTailTick();

		$this->assertSame( 1, $written['index'], 'restarted at 0, advanced by one' );
		$this->assertSame( 'NEW', $written['hash'] );
	}

	public function test_exhausted_tail_ends_the_chain(): void {
		$this->enableTail( 10 );
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( 'breeze_preload_queue' === $key ) {
					return array();
				}

				return str_contains( $key, 'cursor' )
					? array( 'index' => 2, 'hash' => 'H' )
					: array( 'urls' => array( 'a', 'b' ), 'hash' => 'H' );
			}
		);
		$scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $when, string $hook ) use ( &$scheduled ): int {
				$scheduled[] = $hook;

				return 1;
			}
		);

		WarmupSitemap::runTailTick();

		$this->assertSame( array(), $scheduled, 'an exhausted tail must end the chain, not schedule a successor.' );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_does_nothing_when_the_flag_is_off(): void {
		$scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $when, string $hook ) use ( &$scheduled ): int {
				$scheduled[] = $hook;

				return 1;
			}
		);
		$written = array();
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$written ): bool {
				$written[] = $key;

				return true;
			}
		);

		WarmupSitemap::runTailTick();

		$this->assertSame( array(), $scheduled, 'with the flag off, nothing gets scheduled.' );
		$this->assertSame( array(), $written, 'with the flag off, nothing gets written.' );
	}
}
