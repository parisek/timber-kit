<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * The tail and the cursor into it, in two option rows the purge-time filter
 * never reads.
 *
 * Keeping them out of the priority row is not tidiness: that row is
 * deserialized inside the request that emptied the cache, and a tail can hold
 * thousands of URLs.
 *
 * The cursor has two writers — the purge resets it, the tick advances it — so
 * advancing is conditional. A tick that started before a purge and finished
 * after it must not overwrite the fresh reset, or every URL the purge
 * invalidated would sit unwarmed behind a cursor claiming otherwise. That is
 * the race this guards against, and it is the one that actually happens.
 *
 * It does not guard two genuinely concurrent ticks. Both can read the same
 * cursor, both can pass the check in advanceCursor(), and both can call
 * update_option() — the later one wins, and both calls return true. Closing
 * that would need a conditional UPDATE through $wpdb matched against the
 * serialized option value: fragile, and disproportionate to the risk. The
 * tail advance is driven by one scheduled tick, not by concurrent actors, so
 * overlapping ticks are rare; when they do overlap, the result is a repeated
 * batch of a handful of extra warm requests, not corrupted state.
 */
final class TailStore {

	/** @var string wp_options key holding the ordered tail, autoload off. */
	public const TAIL_OPTION = 'timber_kit_breeze_warmup_tail';

	/** @var string wp_options key holding the cursor into it, autoload off. */
	public const CURSOR_OPTION = 'timber_kit_breeze_warmup_tail_cursor';

	/**
	 * @return array{urls: array<int, string>, hash: string}
	 */
	public static function readTail(): array {
		$empty = array( 'urls' => array(), 'hash' => '' );

		if ( ! function_exists( 'get_option' ) ) {
			return $empty;
		}

		$data = get_option( self::TAIL_OPTION, null );
		if ( ! is_array( $data ) || ! isset( $data['urls'], $data['hash'] ) || ! is_array( $data['urls'] ) ) {
			return $empty;
		}

		return array(
			'urls' => array_values( array_filter( $data['urls'], 'is_string' ) ),
			'hash' => (string) $data['hash'],
		);
	}

	/**
	 * @param array<int, string> $urls
	 * @return void
	 */
	public static function writeTail( array $urls ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$urls = array_values( $urls );

		update_option(
			self::TAIL_OPTION,
			array(
				'urls' => $urls,
				'hash' => TailPlanner::hash( $urls ),
			),
			false
		);
	}

	/**
	 * @return array{index: int, hash: string}
	 */
	public static function readCursor(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array( 'index' => 0, 'hash' => '' );
		}

		$data = get_option( self::CURSOR_OPTION, null );
		if ( ! is_array( $data ) || ! isset( $data['index'], $data['hash'] ) ) {
			return array( 'index' => 0, 'hash' => '' );
		}

		return array(
			'index' => (int) $data['index'],
			'hash'  => (string) $data['hash'],
		);
	}

	/**
	 * Start the tail over.
	 *
	 * Writes an empty hash on purpose: the tick stamps the real one when it
	 * reads the tail anyway. Reading the tail here would drag a payload of
	 * thousands of URLs into the request an editor is waiting on.
	 *
	 * @return void
	 */
	public static function resetCursor(): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		update_option( self::CURSOR_OPTION, array( 'index' => 0, 'hash' => '' ), false );
	}

	/**
	 * Advance the cursor, but only if nobody moved it since it was read.
	 *
	 * @param array{index: int, hash: string} $expected Cursor as the caller read it.
	 * @param int                             $newIndex
	 * @param string                          $hash     Hash of the tail actually used.
	 * @return bool True when written, false when a concurrent write won.
	 */
	public static function advanceCursor( array $expected, int $newIndex, string $hash ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		$current = self::readCursor();
		if ( $current['index'] !== (int) $expected['index'] || $current['hash'] !== (string) $expected['hash'] ) {
			return false;
		}

		update_option( self::CURSOR_OPTION, array( 'index' => max( 0, $newIndex ), 'hash' => $hash ), false );

		return true;
	}
}
