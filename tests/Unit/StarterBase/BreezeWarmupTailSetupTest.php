<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * Covers the tail flag matrix.
 *
 * Tail without priority wires nothing: there is no ordering to drain, and a
 * half-enabled feature is worse than a disabled one.
 */
class BreezeWarmupTailSetupTest extends TestCase {

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
	 * @param array<int, array{string, int}> $actions
	 * @return void
	 */
	private function captureActions( array &$actions ): void {
		Functions\when( 'add_action' )->alias(
			function ( string $tag, $callback = null, int $priority = 10 ) use ( &$actions ) {
				$actions[] = array( $tag, $priority );

				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
	}

	public function test_tail_on_wires_the_tick_and_the_purge_hook(): void {
		$actions = array();
		$this->captureActions( $actions );

		WarmupSitemap::register( true, null, true, 100 );

		$this->assertContains( array( WarmupSitemap::TAIL_HOOK, 10 ), $actions );
		$this->assertContains( array( 'breeze_clear_all_cache', 1000 ), $actions );
	}

	public function test_tail_off_wires_neither(): void {
		$actions = array();
		$this->captureActions( $actions );

		WarmupSitemap::register( true, null, false, 100 );

		$tags = array_column( $actions, 0 );
		$this->assertNotContains( WarmupSitemap::TAIL_HOOK, $tags );
		$this->assertNotContains( 'breeze_clear_all_cache', $tags );
	}

	public function test_tail_without_priority_wires_neither(): void {
		$actions = array();
		$this->captureActions( $actions );

		WarmupSitemap::register( false, null, true, 100 );

		$tags = array_column( $actions, 0 );
		$this->assertNotContains( WarmupSitemap::TAIL_HOOK, $tags );
		$this->assertNotContains( 'breeze_clear_all_cache', $tags );
	}
}
