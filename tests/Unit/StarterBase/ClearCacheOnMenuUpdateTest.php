<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class ClearCacheOnMenuUpdateTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_clears_cache_on_menu_update(): void {
		Functions\when( 'has_action' )->justReturn( true );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_menu_update( 5 );

		$this->assertContains( 'breeze_clear_all_cache', $dispatched );
	}

	public function test_skips_when_breeze_not_active(): void {
		Functions\when( 'has_action' )->justReturn( false );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_menu_update( 5 );

		$this->assertEmpty( $dispatched );
	}
}
