<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * The tail's pure arithmetic: which URLs the cap excluded, how that list is
 * fingerprinted, and how a batch is sliced off it.
 *
 * Deliberately free of WordPress. The property test that pins the batching
 * invariant lives under `tests/Property/`, which is reserved for functions
 * isolated from Brain\Monkey.
 *
 * This class never sorts. Its caller hands it an already-ordered set, because
 * the tail's order IS the feature's promise: a run cut short by the next purge
 * must still have warmed the most valuable pages first.
 */
final class TailPlanner {

	/** @var int Safety cap on stored tail URLs, filterable by the caller. */
	public const DEFAULT_MAX_TAIL_URLS = 5000;

	/**
	 * URLs the cap excluded, in the order they were given.
	 *
	 * @param array<int, array<string, mixed>> $scored   Already-sorted scored records.
	 * @param array<int, string>               $keptUrls URLs that made it under the cap.
	 * @param int                              $maxTail  Upper bound on the result.
	 * @return array<int, string>
	 */
	public static function split( array $scored, array $keptUrls, int $maxTail ): array {
		$kept = array_fill_keys( $keptUrls, true );
		$tail = array();

		foreach ( $scored as $record ) {
			if ( ! isset( $record['url'] ) || ! is_string( $record['url'] ) || '' === $record['url'] ) {
				continue;
			}
			if ( isset( $kept[ $record['url'] ] ) ) {
				continue;
			}

			$tail[] = $record['url'];
		}

		return array_slice( $tail, 0, max( 0, $maxTail ) );
	}

	/**
	 * Fingerprint of a tail, used to invalidate a cursor that points into a
	 * different plan. Order participates: a reordered tail is a new plan.
	 *
	 * @param array<int, string> $urls
	 * @return string
	 */
	public static function hash( array $urls ): string {
		return md5( (string) json_encode( array_values( $urls ) ) );
	}

	/**
	 * One batch, starting at the cursor.
	 *
	 * A negative index is clamped to zero rather than passed to array_slice(),
	 * which would read from the end of the array — a corrupted cursor must not
	 * silently warm the wrong pages.
	 *
	 * @param array<int, string> $urls
	 * @param int                $index
	 * @param int                $batch
	 * @return array<int, string>
	 */
	public static function nextBatch( array $urls, int $index, int $batch ): array {
		return array_slice( $urls, max( 0, $index ), max( 0, $batch ) );
	}
}
