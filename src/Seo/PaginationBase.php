<?php

declare(strict_types=1);

/**
 * The site's own pagination segment.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Reads the `/page/N/`-style segment WordPress actually serves.
 *
 * `page` is only the default. WordPress keeps the real value on
 * `$wp_rewrite->pagination_base`, and WPML or a hand-rolled rewrite can change
 * it — a Czech site commonly serves `/strana/N/` instead. `Pagination::append()`
 * stays pure and takes the base as a parameter; this is the one place in the
 * pagination pair that reaches for the global.
 */
final class PaginationBase {

	private const DEFAULT = 'page';

	/**
	 * @return string The site's pagination segment, never empty.
	 */
	public static function current(): string {
		$rewrite = $GLOBALS['wp_rewrite'] ?? null;

		if ( ! is_object( $rewrite ) || ! isset( $rewrite->pagination_base ) ) {
			return self::DEFAULT;
		}

		$base = $rewrite->pagination_base;

		if ( ! is_string( $base ) || '' === $base ) {
			return self::DEFAULT;
		}

		return $base;
	}
}
