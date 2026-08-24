<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmup;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmup\PriorityStore;

/**
 * Covers the option row and its optimistic lock.
 *
 * Two writers share this row: the cron refresh and the menu-change rescore.
 * `update_option()` is last-write-wins, so a slow refresh finishing after a
 * menu edit would restore the stale ordering. The revision guard closes that.
 */
class PriorityStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_reads_a_complete_payload(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'urls'         => array( 'https://example.test/' ),
				'signals'      => array( 'https://example.test/' => array( 'menu' => true ) ),
				'fetched_at'   => 123,
				'weights_hash' => 'abc',
				'revision'     => 4,
			)
		);

		$data = PriorityStore::read();

		$this->assertNotNull( $data );
		$this->assertSame( 4, $data['revision'] );
		$this->assertSame( 'abc', $data['weights_hash'] );
	}

	public function test_legacy_payload_without_signals_reads_as_null(): void {
		// A v1.x row has only urls + fetched_at. Treating it as null makes the
		// caller schedule a refresh, which is exactly the desired migration:
		// none.
		Functions\when( 'get_option' )->justReturn(
			array( 'urls' => array( 'https://example.test/' ), 'fetched_at' => 123 )
		);

		$this->assertNull( PriorityStore::read() );
	}

	public function test_missing_option_reads_as_null(): void {
		Functions\when( 'get_option' )->justReturn( null );

		$this->assertNull( PriorityStore::read() );
	}

	public function test_write_succeeds_when_the_revision_is_unchanged(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'urls'         => array(),
				'signals'      => array(),
				'fetched_at'   => 1,
				'weights_hash' => 'a',
				'revision'     => 2,
			)
		);
		$written = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$written ): bool {
				$written = $value;

				return true;
			}
		);

		$ok = PriorityStore::write( array( 'https://example.test/' ), array(), 'b', 2 );

		$this->assertTrue( $ok );
		$this->assertSame( 3, $written['revision'], 'the stored revision advances' );
	}

	public function test_write_is_discarded_when_the_revision_moved(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'urls'         => array(),
				'signals'      => array(),
				'fetched_at'   => 1,
				'weights_hash' => 'a',
				'revision'     => 9,
			)
		);
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( PriorityStore::write( array( 'x' ), array(), 'b', 2 ) );
	}

	public function test_write_on_an_empty_store_succeeds_from_revision_zero(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );

		$this->assertTrue( PriorityStore::write( array( 'https://example.test/' ), array(), 'b', 0 ) );
	}
}
