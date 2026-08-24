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
 * Covers the multisite guard on tail draining: the brake reads Breeze's
 * `breeze_preload_queue`, which Breeze scopes per blog on multisite, so on
 * multisite the brake would always read idle and the drain would pile onto
 * the origin unthrottled. register() must refuse to wire the tail hooks
 * there rather than run without a working brake.
 */
class TailMultisiteGuardTest extends TestCase {

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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_does_not_wire_tail_hooks_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$actions = array();
		Functions\when( 'add_action' )->alias(
			function ( string $tag ) use ( &$actions ) {
				$actions[] = $tag;
				return true;
			}
		);

		WarmupSitemap::register( true, null, true, 100 );

		$this->assertNotContains( WarmupSitemap::TAIL_HOOK, $actions );
		$this->assertNotContains( 'breeze_clear_all_cache', $actions );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wires_tail_hooks_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );

		$actions = array();
		Functions\when( 'add_action' )->alias(
			function ( string $tag ) use ( &$actions ) {
				$actions[] = $tag;
				return true;
			}
		);

		WarmupSitemap::register( true, null, true, 100 );

		$this->assertContains( WarmupSitemap::TAIL_HOOK, $actions );
		$this->assertContains( 'breeze_clear_all_cache', $actions );
	}
}
