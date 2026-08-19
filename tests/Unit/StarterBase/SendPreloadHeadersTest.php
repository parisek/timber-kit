<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * The header value itself is covered by {@see \Tests\Unit\PreloadHeaders\FormatTest}.
 * What is asserted here is the decision to send one at all -- a hint sent on a
 * response that renders no document is a wasted byte, and on some of them a
 * leaked one.
 */
class SendPreloadHeadersTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
	}

	/** The resource list is read only when a header will be sent, so its absence proves the guard fired. */
	private function expectNoListRead(): void {
		Functions\expect( 'apply_filters' )
			->andReturnUsing( function ( string $hook, $value ) {
				$this->assertNotSame( 'wp_preload_resources', $hook, 'guard did not fire' );
				return $value;
			} );
	}

	public function test_reads_the_list_on_an_ordinary_front_end_request(): void {
		$seen = [];
		Functions\when( 'apply_filters' )->alias( function ( string $hook, $value ) use ( &$seen ) {
			$seen[] = $hook;
			return 'timber_kit_preload_headers' === $hook ? true : $value;
		} );

		$this->base->send_preload_headers();

		$this->assertContains( 'wp_preload_resources', $seen );
		$this->assertContains( 'timber_kit_preconnect_origins', $seen );
	}

	public function test_silent_in_the_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->expectNoListRead();

		$this->base->send_preload_headers();
	}

	public function test_silent_on_a_feed(): void {
		Functions\when( 'is_feed' )->justReturn( true );
		$this->expectNoListRead();

		$this->base->send_preload_headers();
	}

	public function test_the_property_switches_it_off(): void {
		$base = $this->createStarterBase( [ 'preload_headers' => false ] );
		Functions\when( 'apply_filters' )->alias( function ( string $hook, $value ) {
			$this->assertSame( 'timber_kit_preload_headers', $hook, 'read past the off switch' );
			return $value;
		} );

		$base->send_preload_headers();

		$this->addToAssertionCount( 1 );
	}

	public function test_the_filter_switches_it_off_over_the_property(): void {
		Functions\when( 'apply_filters' )->alias( function ( string $hook, $value ) {
			$this->assertSame( 'timber_kit_preload_headers', $hook, 'read past the off switch' );
			return 'timber_kit_preload_headers' === $hook ? false : $value;
		} );

		$this->base->send_preload_headers();

		$this->addToAssertionCount( 1 );
	}
}
