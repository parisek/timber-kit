<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * Covers the flag matrix.
 *
 * Priority without the sitemap module has nothing to order, so it must wire
 * nothing — a project that flips only the second flag should get today's
 * behaviour, not a half-enabled feature.
 */
class BreezeWarmupPrioritySetupTest extends TestCase {

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
	 * Capture every add_action() call as (tag, priority).
	 *
	 * Brain\Monkey does not maintain a real hook registry, so `has_action()`
	 * is not available to assert against — the suite's own convention
	 * (RegisterTest) is to alias the registrar and inspect what it was
	 * handed.
	 *
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
	}

	public function test_priority_off_does_not_wire_the_menu_hook(): void {
		$actions = array();
		$this->captureActions( $actions );

		WarmupSitemap::register( false, null );

		$this->assertNotContains( 'wp_update_nav_menu', array_column( $actions, 0 ) );
	}

	public function test_priority_on_wires_the_menu_hook_at_priority_five(): void {
		// Priority 5 so the rescore lands before both Breeze's own menu purge
		// and the kit's, which sit at 10.
		$actions = array();
		$this->captureActions( $actions );

		WarmupSitemap::register( true, null );

		$this->assertContains( array( 'wp_update_nav_menu', 5 ), $actions );
	}

	public function test_the_filter_wins_over_the_declared_weights(): void {
		// How many times the filter fires is an implementation detail, not
		// part of this contract — only pin what wins.
		Filters\expectApplied( 'timberkit_warmup_priority_weights' )
			->andReturn( array( 'menu' => 42 ) );

		WarmupSitemap::register( true, array( 'menu' => 7 ) );

		$this->assertSame( 42, WarmupSitemap::weights()['menu'] );
	}

	/**
	 * Pins the actual contract behind `weightsChanged()`: the hash computed
	 * once at registration and the hash a refresh write would store both
	 * derive from the FILTERED weights. If registration ever hashed the raw,
	 * unfiltered weights instead, a project using the
	 * `timberkit_warmup_priority_weights` filter would see these two values
	 * permanently disagree — `weightsChanged()` would report a mismatch on
	 * every single purge, scheduling a needless sitemap refresh forever.
	 */
	public function test_registration_hash_agrees_with_what_a_refresh_write_would_store(): void {
		Filters\expectApplied( 'timberkit_warmup_priority_weights' )
			->andReturn( array( 'menu' => 42 ) );

		WarmupSitemap::register( true, array( 'menu' => 7 ) );

		$reflection      = new \ReflectionClass( WarmupSitemap::class );
		$registeredHash  = $reflection->getProperty( 'weights_hash' );

		$writeWouldStore = \Parisek\TimberKit\Breeze\Scorer::weightsHash( WarmupSitemap::weights() );

		$this->assertSame( $writeWouldStore, $registeredHash->getValue() );
	}
}
