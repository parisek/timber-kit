<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class ClearCacheOnOptionsSaveTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_clears_cache_on_options_save(): void {
		Functions\when( 'has_action' )->justReturn( true );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 'options' );

		$this->assertContains( 'breeze_clear_all_cache', $dispatched );
	}

	public function test_skips_non_options_post_id(): void {
		$dispatched = [];
		Functions\when( 'has_action' )->justReturn( true );
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 123 );

		$this->assertEmpty( $dispatched );
	}

	public function test_skips_when_breeze_not_active(): void {
		Functions\when( 'has_action' )->justReturn( false );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 'options' );

		$this->assertEmpty( $dispatched );
	}
}
