<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * Divides the URL budget between languages.
 *
 * The cap is **divided**, never multiplied: without this a trilingual site
 * would quietly triple the number of origin renders one purge costs.
 *
 * Homepages and menu items of every language are guaranteed and may push the
 * total **over** the cap. That is the deliberate trade: a language losing its
 * homepage from the warmup list is a worse outcome than a soft cap, and the
 * overflow is bounded by the number of menu items, not by the sitemap.
 *
 * Selection only — ordering is the Scorer's job and must not change here.
 */
final class LanguageQuota {

	/**
	 * @param array<int, array<string, mixed>> $records Scored records.
	 * @param int                              $max     Soft cap on total URLs.
	 * @return array<int, array<string, mixed>> Selected records, input order preserved.
	 */
	public static function apply( array $records, int $max ): array {
		$max     = max( 0, $max );
		$keep    = array();
		$dropped = array();

		foreach ( $records as $i => $record ) {
			if ( ! empty( $record['front_page'] ) || ! empty( $record['menu'] ) ) {
				$keep[ $i ] = true;
			} else {
				$dropped[] = $i;
			}
		}

		$budget = max( 0, $max - count( $keep ) );
		if ( $budget > 0 && array() !== $dropped ) {
			foreach ( self::selectByLanguage( $records, $dropped, $budget ) as $i ) {
				$keep[ $i ] = true;
			}
		}

		$result = array();
		foreach ( $records as $i => $record ) {
			if ( isset( $keep[ $i ] ) ) {
				$result[] = $record;
			}
		}

		return $result;
	}

	/**
	 * Split the remaining budget across languages in proportion to how many
	 * optional URLs each has, then take that many best-scoring ones.
	 *
	 * Proportional rather than equal: on a site where 90 percent of the
	 * content is Czech, an equal split would hand English a third of the
	 * budget for nothing.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @param array<int, int>                  $candidates Indexes eligible for selection.
	 * @param int                              $budget
	 * @return array<int, int> Selected indexes.
	 */
	private static function selectByLanguage( array $records, array $candidates, int $budget ): array {
		$byLang = array();
		foreach ( $candidates as $i ) {
			$lang            = isset( $records[ $i ]['lang'] ) ? (string) $records[ $i ]['lang'] : '';
			$byLang[ $lang ] = $byLang[ $lang ] ?? array();
			$byLang[ $lang ][] = $i;
		}

		$total    = count( $candidates );
		$selected = array();
		$assigned = 0;

		// Largest-remainder is overkill here; floor each share and hand any
		// leftover slots to the languages with the most candidates, so no
		// slot is wasted to rounding.
		$quotas = array();
		foreach ( $byLang as $lang => $indexes ) {
			$quotas[ $lang ] = (int) floor( $budget * count( $indexes ) / $total );
			$assigned       += $quotas[ $lang ];
		}

		$leftover = $budget - $assigned;
		if ( $leftover > 0 ) {
			uasort( $byLang, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
			foreach ( array_keys( $byLang ) as $lang ) {
				if ( $leftover <= 0 ) {
					break;
				}
				++$quotas[ $lang ];
				--$leftover;
			}
		}

		foreach ( $byLang as $lang => $indexes ) {
			// Guard the read: a record that never went through the Scorer has no
			// 'score' key, and it should sort last rather than warn.
			usort(
				$indexes,
				static fn( int $a, int $b ): int => ( (int) ( $records[ $b ]['score'] ?? 0 ) ) <=> ( (int) ( $records[ $a ]['score'] ?? 0 ) )
			);
			foreach ( array_slice( $indexes, 0, $quotas[ $lang ] ) as $i ) {
				$selected[] = $i;
			}
		}

		return $selected;
	}
}
