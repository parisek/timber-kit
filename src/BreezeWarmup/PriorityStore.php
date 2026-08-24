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
 * The revision counter guards the case that actually happens: a cron refresh
 * reads revision N, spends seconds crawling a sitemap, and by the time it
 * writes, a menu-change rescore has already stored N + 1. The re-read before
 * write() catches that, and the stale ordering is discarded instead of
 * clobbering fresh data.
 *
 * It does not guard two genuinely concurrent writers. Both can read
 * revision N, both can pass the check, and both can call update_option() —
 * the later one wins, both calls return true, and the stored revision reads
 * N + 1 instead of N + 2, so nothing downstream can tell a write was lost.
 * Closing that would need a conditional UPDATE through $wpdb matched against
 * the serialized option value: fragile, and disproportionate to the risk.
 * The two writers here are an hourly cron job and a human saving a menu —
 * overlapping to the microsecond is possible but rare, while the slow-cron
 * case above is routine.
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
	 * Tolerant URL-only read, accepting either the current five-key payload
	 * or the legacy `{urls, fetched_at}` shape a pre-upgrade site left behind.
	 *
	 * {@see self::read()} is deliberately strict: a legacy row must read as
	 * null there so the caller schedules a refresh (that IS the migration —
	 * there is no other conversion step). But the purge-time filter still
	 * needs *some* URL list to hand Breeze in the window between an upgrade
	 * and the first cron refresh — this is the URL source for BOTH branches
	 * of that filter (ordering off and ordering on), not only the legacy
	 * one; `read()` is used there solely to decide staleness and whether the
	 * weights changed. Falling back to nothing here would be a regression:
	 * today's code at least keeps serving the stale list.
	 *
	 * @return array<int, string>
	 */
	public static function readUrls(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$data = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $data ) || ! isset( $data['fetched_at'] ) || ! is_array( $data['urls'] ?? null ) ) {
			return array();
		}

		return array_values( array_filter( $data['urls'], 'is_string' ) );
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
