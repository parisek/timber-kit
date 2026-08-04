<?php

declare(strict_types=1);

namespace Tests\Unit\Wpml;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Acfml\LoadReferenceGuard;
use Parisek\TimberKit\Wpml\CopyFieldSync;
use PHPUnit\Framework\TestCase;

class CopyFieldSyncTest extends TestCase {

	/** @var list<array{int, string}> */
	private array $synced = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->synced         = [];
		$GLOBALS['wp_filter'] = [];

		Functions\when( 'do_action' )->alias(
			function ( string $tag, mixed ...$args ): void {
				if ( 'wpml_sync_custom_field' === $tag ) {
					$this->synced[] = [ (int) $args[0], (string) $args[1] ];
				}
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_filter'] );

		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_syncs_each_key_with_its_companion(): void {
		( new CopyFieldSync() )->push( 42, [ 'price', 'status' ] );

		$this->assertSame(
			[
				[ 42, 'price' ],
				[ 42, '_price' ],
				[ 42, 'status' ],
				[ 42, '_status' ],
			],
			$this->synced
		);
	}

	public function test_companions_can_be_switched_off(): void {
		( new CopyFieldSync() )->push( 42, [ 'price' ], false );

		$this->assertSame( [ [ 42, 'price' ] ], $this->synced );
	}

	public function test_an_empty_key_list_syncs_nothing(): void {
		// A post whose diff came back empty must cost nothing — the whole point
		// of passing only the keys actually written.
		$this->assertSame( 0, ( new CopyFieldSync() )->push( 42, [] ) );
		$this->assertSame( [], $this->synced );
	}

	public function test_sweeps_the_leak_after_pushing(): void {
		// Real guard over a fake hook rather than a doubled guard: what needs
		// asserting is that the leak is gone afterwards, not that a method was
		// called.
		$hook                 = new \WP_Hook();
		$GLOBALS['wp_filter'] = [ LoadReferenceGuard::HOOK => $hook ];

		$sync = new CopyFieldSync();

		Functions\when( 'do_action' )->alias(
			static function ( string $tag ) use ( $hook ): void {
				if ( 'wpml_sync_custom_field' === $tag ) {
					$hook->add_filter( LoadReferenceGuard::HOOK, static fn( $reference ) => $reference );
				}
			}
		);

		$this->assertSame( 2, $sync->push( 42, [ 'price' ] ), 'key + companion each leak one closure' );
		$this->assertSame( 0, $hook->count() );
		$this->assertSame( 2, $sync->sweptTotal() );
	}
}
