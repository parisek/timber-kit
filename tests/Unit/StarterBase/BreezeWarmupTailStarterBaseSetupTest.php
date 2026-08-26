<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Breeze\WarmupSitemap;
use Parisek\TimberKit\StarterBase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class BreezeWarmupTailStarterBaseSetupStub extends StarterBase {

	public function __construct( bool $sitemap, bool $priority, bool $tail, int $tail_batch = 100 ) {
		$this->breeze_warmup_sitemap    = $sitemap;
		$this->breeze_warmup_priority   = $priority;
		$this->breeze_warmup_tail       = $tail;
		$this->breeze_warmup_tail_batch = $tail_batch;
	}

	public function run_setup_breeze_warmup_sitemap(): void {
		$this->setup_breeze_warmup_sitemap();
	}
}

/**
 * Covers `StarterBase::setup_breeze_warmup_sitemap()` actually forwarding the
 * tail flag and batch size into `WarmupSitemap::register()` — the one thing
 * this task adds. `BreezeWarmupTailSetupTest` in this directory drives the
 * same matrix through `WarmupSitemap::register()` directly, which is useful
 * on its own but proves nothing about the `StarterBase` wiring: that call
 * already had all four parameters before this task existed.
 */
class BreezeWarmupTailStarterBaseSetupTest extends TestCase {

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

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_all_three_flags_on_wires_the_tick_and_the_purge_hook(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$actions = array();
		$this->captureActions( $actions );

		( new BreezeWarmupTailStarterBaseSetupStub( true, true, true, 100 ) )->run_setup_breeze_warmup_sitemap();

		$this->assertContains( array( WarmupSitemap::TAIL_HOOK, 10 ), $actions );
		$this->assertContains( array( 'breeze_clear_all_cache', 1000 ), $actions );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_sitemap_and_priority_on_tail_off_wires_neither(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$actions = array();
		$this->captureActions( $actions );

		( new BreezeWarmupTailStarterBaseSetupStub( true, true, false, 100 ) )->run_setup_breeze_warmup_sitemap();

		$tags = array_column( $actions, 0 );
		$this->assertNotContains( WarmupSitemap::TAIL_HOOK, $tags );
		$this->assertNotContains( 'breeze_clear_all_cache', $tags );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_sitemap_and_tail_on_priority_off_wires_neither(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$actions = array();
		$this->captureActions( $actions );

		( new BreezeWarmupTailStarterBaseSetupStub( true, false, true, 100 ) )->run_setup_breeze_warmup_sitemap();

		$tags = array_column( $actions, 0 );
		$this->assertNotContains( WarmupSitemap::TAIL_HOOK, $tags );
		$this->assertNotContains( 'breeze_clear_all_cache', $tags );
	}

	/**
	 * The batch size is the one knob StarterBase exposes on the tail. Prove it
	 * genuinely reaches WarmupSitemap by driving a real tick after going
	 * through setup_breeze_warmup_sitemap(), and reading how far the cursor
	 * advanced off the tail store — the same technique
	 * TailTickTest::test_dispatches_a_batch_and_advances_the_cursor() uses,
	 * since the dispatch target (Breeze_Cache_Preloader::preload_url) is a
	 * static method Brain\Monkey cannot intercept to count calls directly.
	 */
	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_a_non_default_tail_batch_reaches_the_module(): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		( new BreezeWarmupTailStarterBaseSetupStub( true, true, true, 2 ) )->run_setup_breeze_warmup_sitemap();

		$tail = array( 'urls' => array( 'a', 'b', 'c' ), 'hash' => 'H' );
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( $tail ) {
				if ( 'breeze_preload_queue' === $key ) {
					return array();
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

		WarmupSitemap::runTailTick();

		$this->assertSame( 2, $written['index'], 'the batch of 2 set on StarterBase reached WarmupSitemap, not the default of 100' );
	}
}
