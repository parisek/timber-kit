<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\TailStore;

/**
 * Covers the two option rows and the conditional cursor write.
 *
 * The conditional write is the whole point: a tick that started before a purge
 * and finished after it must not overwrite the fresh reset, or the URLs the
 * purge invalidated would never be warmed in that run.
 */
class TailStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -- tail ---------------------------------------------------------------

	public function test_reads_a_stored_tail(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'urls' => array( 'https://example.test/a/' ), 'hash' => 'h' )
		);

		$tail = TailStore::readTail();

		$this->assertSame( array( 'https://example.test/a/' ), $tail['urls'] );
		$this->assertSame( 'h', $tail['hash'] );
	}

	public function test_missing_tail_reads_as_empty(): void {
		Functions\when( 'get_option' )->justReturn( null );

		$this->assertSame( array( 'urls' => array(), 'hash' => '' ), TailStore::readTail() );
	}

	public function test_malformed_tail_reads_as_empty(): void {
		Functions\when( 'get_option' )->justReturn( array( 'urls' => 'oops' ) );

		$this->assertSame( array( 'urls' => array(), 'hash' => '' ), TailStore::readTail() );
	}

	public function test_writing_a_tail_stamps_its_hash_and_disables_autoload(): void {
		$written = null;
		$autoload = null;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $auto = null ) use ( &$written, &$autoload ): bool {
				$written  = $value;
				$autoload = $auto;

				return true;
			}
		);

		TailStore::writeTail( array( 'https://example.test/a/' ) );

		$this->assertSame( array( 'https://example.test/a/' ), $written['urls'] );
		$this->assertNotSame( '', $written['hash'] );
		$this->assertFalse( $autoload, 'the tail must never autoload; it can hold thousands of URLs' );
	}

	// -- cursor -------------------------------------------------------------

	public function test_missing_cursor_reads_as_zero(): void {
		Functions\when( 'get_option' )->justReturn( null );

		$this->assertSame( array( 'index' => 0, 'hash' => '' ), TailStore::readCursor() );
	}

	public function test_reset_writes_index_zero_without_reading_the_tail(): void {
		// The purge path calls this. Reading the tail here would put a
		// multi-thousand-URL payload in the request an editor is waiting on.
		Functions\expect( 'get_option' )->never();
		$written = null;
		$autoload = null;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $auto = null ) use ( &$written, &$autoload ): bool {
				$written  = $value;
				$autoload = $auto;

				return true;
			}
		);

		TailStore::resetCursor();

		$this->assertSame( 0, $written['index'] );
		$this->assertSame( '', $written['hash'], 'the tick stamps the hash; the purge must not read the tail' );
		$this->assertFalse( $autoload, 'the cursor must never autoload; every request would otherwise load it' );
	}

	public function test_advance_writes_when_the_cursor_is_unchanged(): void {
		$current = array( 'index' => 100, 'hash' => 'h' );
		Functions\when( 'get_option' )->justReturn( $current );
		$written = null;
		$autoload = null;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $auto = null ) use ( &$written, &$autoload ): bool {
				$written  = $value;
				$autoload = $auto;

				return true;
			}
		);

		$this->assertTrue( TailStore::advanceCursor( $current, 200, 'h' ) );
		$this->assertSame( 200, $written['index'] );
		$this->assertSame( 'h', $written['hash'] );
		$this->assertFalse( $autoload, 'the cursor must never autoload; every request would otherwise load it' );
	}

	public function test_advance_is_discarded_when_a_purge_reset_the_cursor(): void {
		// The tick read index 100, a purge reset to 0 mid-flight. Writing 200
		// would undo the reset and strand everything below it.
		Functions\when( 'get_option' )->justReturn( array( 'index' => 0, 'hash' => '' ) );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( TailStore::advanceCursor( array( 'index' => 100, 'hash' => 'h' ), 200, 'h' ) );
	}

	public function test_advance_is_discarded_when_another_tick_moved_the_cursor(): void {
		Functions\when( 'get_option' )->justReturn( array( 'index' => 300, 'hash' => 'h' ) );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( TailStore::advanceCursor( array( 'index' => 100, 'hash' => 'h' ), 200, 'h' ) );
	}
}
