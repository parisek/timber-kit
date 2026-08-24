<?php

declare(strict_types=1);

namespace Parisek\TimberKit\BreezeWarmup;

/**
 * The scoring core: turns a sitemap record plus a weight map into one
 * integer, and orders records by it.
 *
 * Deliberately pure — no WordPress, no filters, no I/O. Two things depend on
 * that: the unit tests need no Brain\Monkey, and the property test that
 * asserts sorting is a permutation of its input lives in `tests/Property/`,
 * which is reserved for functions isolated from Brain\Monkey.
 *
 * Score is a **sum**, not a maximum, so a fresh page in a menu outranks a
 * menu page nobody has touched in a year.
 */
final class Scorer {

	/** @var int Seconds in a day; freshness buckets are expressed in days. */
	private const DAY = 86400;

	/**
	 * @var array{front_page: int, manual: int, menu: int, types: array<string, int>, freshness: array<int, int>}
	 */
	public const DEFAULT_WEIGHTS = array(
		'front_page' => 1000,
		'manual'     => 800,
		'menu'       => 500,
		'types'      => array(),
		'freshness'  => array(
			2   => 300,
			7   => 200,
			30  => 100,
			365 => 25,
		),
	);

	/**
	 * Bucketed freshness. Bucketed rather than continuous because the buckets
	 * are trivially testable and immune to clock skew of a few minutes.
	 *
	 * The boundary belongs to the higher bucket (strict `<`). A missing,
	 * unparseable or **future** timestamp scores zero: scheduled content is
	 * not fresh content, and a broken `lastmod` must never be able to shoot a
	 * URL to the front of the queue.
	 *
	 * @param int|null           $lastmod Unix timestamp, or null.
	 * @param int                $now     Unix timestamp to measure against.
	 * @param array<int, int>    $buckets Days => points, ascending by days.
	 * @return int
	 */
	public static function freshness( ?int $lastmod, int $now, array $buckets ): int {
		if ( null === $lastmod || $lastmod > $now ) {
			return 0;
		}

		$ageDays = ( $now - $lastmod ) / self::DAY;

		ksort( $buckets );
		foreach ( $buckets as $days => $points ) {
			if ( $ageDays < $days ) {
				return (int) $points;
			}
		}

		return 0;
	}

	/**
	 * $now is required, not defaulted: a default lets a caller who passes a
	 * real lastmod but forgets $now silently lose the whole freshness
	 * contribution, with no warning and no exception to catch it.
	 *
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $weights
	 * @param int                  $now
	 * @return int
	 */
	public static function score( array $record, array $weights, int $now ): int {
		$score = 0;

		if ( ! empty( $record['front_page'] ) ) {
			$score += (int) ( $weights['front_page'] ?? 0 );
		}
		if ( ! empty( $record['manual'] ) ) {
			$score += (int) ( $weights['manual'] ?? 0 );
		}
		if ( ! empty( $record['menu'] ) ) {
			$score += (int) ( $weights['menu'] ?? 0 );
		}

		$type = isset( $record['type'] ) ? (string) $record['type'] : '';
		if ( '' !== $type ) {
			$types  = is_array( $weights['types'] ?? null ) ? $weights['types'] : array();
			$score += (int) ( $types[ $type ] ?? 0 );
		}

		$buckets = is_array( $weights['freshness'] ?? null ) ? $weights['freshness'] : array();
		$lastmod = isset( $record['lastmod'] ) ? (int) $record['lastmod'] : null;
		$score  += self::freshness( $lastmod, $now, $buckets );

		return $score;
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 * @param array<string, mixed>             $weights
	 * @param int                              $now
	 * @return array<int, array<string, mixed>> Records with a `score` key added.
	 */
	public static function scoreAll( array $records, array $weights, int $now ): array {
		foreach ( $records as $i => $record ) {
			$records[ $i ]['score'] = self::score( $record, $weights, $now );
		}

		return array_values( $records );
	}

	/**
	 * Stable descending sort by score.
	 *
	 * PHP's `usort` has been stable since 8.0, but the tie-break is stated
	 * explicitly here because the whole design leans on it: ties preserve the
	 * sitemap's own ordering, which for AIOSEO is newest-first — free date
	 * ordering as a last resort.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort( array $records ): array {
		$records = array_values( $records );

		usort(
			$records,
			static fn( array $a, array $b ): int => ( (int) ( $b['score'] ?? 0 ) ) <=> ( (int) ( $a['score'] ?? 0 ) )
		);

		return $records;
	}

	/**
	 * Fingerprint of the effective weight map.
	 *
	 * Stored alongside the ordered list so a deploy that changes the weights
	 * invalidates the ordering by itself. Cheaper than tracking *when* the
	 * config changed: record *what it was* and let the mismatch notice.
	 *
	 * Key order must not affect the result, or a harmless reordering in
	 * `StarterBase` would trigger a needless refresh.
	 *
	 * @param array<string, mixed> $weights
	 * @return string
	 */
	public static function weightsHash( array $weights ): string {
		$normalized = self::sortKeysDeep( $weights );

		return md5( (string) json_encode( $normalized ) );
	}

	/**
	 * @param array<string, mixed> $value
	 * @return array<string, mixed>
	 */
	private static function sortKeysDeep( array $value ): array {
		ksort( $value );
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = self::sortKeysDeep( $v );
			}
		}

		return $value;
	}
}
