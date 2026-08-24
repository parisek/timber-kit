<?php

declare(strict_types=1);

namespace Parisek\TimberKit\BreezeWarmup;

/**
 * The last-known-good ordering, and the guard that keeps two writers from
 * undoing each other.
 *
 * The cron refresh and the menu-change rescore both write this row. WordPress
 * options are last-write-wins, so a refresh that started before a menu edit
 * and finished after it would silently restore the stale ordering.
 *
 * The revision counter makes that a discarded write instead: read the
 * revision, write with revision + 1, and refuse if somebody moved it in
 * between. Not atomic in the strong sense — no transaction is available here
 * — but it shrinks the window to a single get/update pair and removes the
 * failure that actually happens.
 */
final class PriorityStore {

	/** @var string wp_options key, autoload off. */
	public const OPTION_KEY = 'timber_kit_breeze_warmup_sitemap_urls';

	/**
	 * @return array{urls: array<int, string>, signals: array<string, mixed>, fetched_at: int, weights_hash: string, revision: int}|null
	 */
	public static function read(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$data = get_option( self::OPTION_KEY, null );

		if (
			! is_array( $data )
			|| ! isset( $data['urls'], $data['signals'], $data['fetched_at'], $data['weights_hash'], $data['revision'] )
			|| ! is_array( $data['urls'] )
			|| ! is_array( $data['signals'] )
		) {
			return null;
		}

		return array(
			'urls'         => array_values( array_filter( $data['urls'], 'is_string' ) ),
			'signals'      => $data['signals'],
			'fetched_at'   => (int) $data['fetched_at'],
			'weights_hash' => (string) $data['weights_hash'],
			'revision'     => (int) $data['revision'],
		);
	}

	/**
	 * Current revision, 0 when the row is missing or legacy.
	 *
	 * @return int
	 */
	public static function revision(): int {
		$data = self::read();

		return null === $data ? 0 : $data['revision'];
	}

	/**
	 * @param array<int, string>   $urls             Ordered URLs.
	 * @param array<string, mixed> $signals          Canonical key => signal record.
	 * @param string               $weightsHash
	 * @param int                  $expectedRevision Revision the caller read before computing.
	 * @return bool True when written, false when a concurrent write won.
	 */
	public static function write( array $urls, array $signals, string $weightsHash, int $expectedRevision ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		if ( self::revision() !== $expectedRevision ) {
			return false;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'urls'         => array_values( $urls ),
				'signals'      => $signals,
				'fetched_at'   => function_exists( 'time' ) ? time() : 0,
				'weights_hash' => $weightsHash,
				'revision'     => $expectedRevision + 1,
			),
			false
		);

		return true;
	}
}
