<?php

declare(strict_types=1);

/**
 * Pagination segment handling for canonical URLs.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Adds or removes the `/page/N/` segment on a canonical URL.
 *
 * Takes the value the SEO plugin already produced and edits it. It never
 * rebuilds a URL from `get_permalink()`: the plugin's string already carries
 * the language domain, the per-language slug and the site's trailing-slash
 * policy, and re-deriving those would get at least one of them wrong on a
 * multilingual install.
 *
 * Query string and fragment are preserved because a canonical that drops them
 * points somewhere the request did not go.
 */
final class Pagination {

	/**
	 * @param string $canonical Canonical URL as the SEO plugin resolved it.
	 * @param int    $page      Requested page; 1 means unpaginated.
	 * @param string $base      The site's pagination segment. Defaults to
	 *                          WordPress's own default of `page`; a caller
	 *                          reading `$wp_rewrite->pagination_base` (see
	 *                          {@see PaginationBase}) supplies the site's real
	 *                          value where it differs. Kept a plain parameter,
	 *                          not a global read, so this method stays pure
	 *                          and testable without WordPress.
	 *
	 * @return string Canonical carrying the right pagination segment.
	 */
	public static function append( string $canonical, int $page, string $base = 'page' ): string {
		if ( '' === $canonical ) {
			return $canonical;
		}

		$suffix = '';
		$path   = $canonical;

		// Split off query and fragment, cutting at whichever comes first.
		$cut = strcspn( $canonical, '?#' );
		if ( $cut < strlen( $canonical ) ) {
			$path   = substr( $canonical, 0, $cut );
			$suffix = substr( $canonical, $cut );
		}

		// preg_quote() so a base containing a regex metacharacter (a literal
		// dot, say) cannot corrupt the pattern.
		$quoted_base = preg_quote( $base, '#' );

		// Strip any pagination the plugin already emitted, so this is
		// idempotent and so page 1 cannot keep a `/page/1/` it should not have.
		$path = (string) preg_replace( '#/' . $quoted_base . '/\d+/?$#', '/', $path );

		if ( $page > 1 ) {
			$path = user_trailingslashit( rtrim( $path, '/' ) . '/' . $base . '/' . $page );
		}

		return $path . $suffix;
	}
}
