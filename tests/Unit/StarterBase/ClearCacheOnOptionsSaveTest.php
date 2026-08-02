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
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'options' ] );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 'options' );

		$this->assertContains( 'breeze_clear_all_cache', $dispatched );
	}

	/**
	 * A page registered with a custom `post_id` (see $options_pages) fires
	 * `acf/save_post` with that id, not with 'options'. An equality check
	 * against the literal would leave the cache stale after every save on
	 * exactly the projects that namespace their storage.
	 */
	public function test_clears_cache_for_a_custom_options_namespace(): void {
		Functions\when( 'has_action' )->justReturn( true );
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'mytheme_settings' ] );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 'mytheme_settings' );

		$this->assertContains( 'breeze_clear_all_cache', $dispatched );
	}

	/** A real post save must still not trigger a site-wide flush. */
	public function test_skips_a_post_id_acf_does_not_classify_as_options(): void {
		Functions\when( 'has_action' )->justReturn( true );
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'post', 'id' => 42 ] );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 'post_42' );

		$this->assertEmpty( $dispatched );
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
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'options' ] );
		$dispatched = [];
		Functions\when( 'do_action' )->alias( function ( $action ) use ( &$dispatched ) {
			$dispatched[] = $action;
		} );

		$this->base->clear_cache_on_options_save( 'options' );

		$this->assertEmpty( $dispatched );
	}
}
