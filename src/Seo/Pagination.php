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
	 *
	 * @return string Canonical carrying the right pagination segment.
	 */
	public static function append( string $canonical, int $page ): string {
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

		// Strip any pagination the plugin already emitted, so this is
		// idempotent and so page 1 cannot keep a `/page/1/` it should not have.
		$path = (string) preg_replace( '#/page/\d+/?$#', '/', $path );

		if ( $page > 1 ) {
			$path = user_trailingslashit( rtrim( $path, '/' ) . '/page/' . $page );
		}

		return $path . $suffix;
	}
}
