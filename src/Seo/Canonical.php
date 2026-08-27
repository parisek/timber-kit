<?php

declare(strict_types=1);

/**
 * Self-referencing canonical on paginated routes.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Makes a paginated route's canonical point at itself.
 *
 * Every SEO plugin resolves a singular page's canonical to `get_permalink()`,
 * which carries no pagination. A listing rendered by a block on an ordinary
 * page is therefore singular as far as the plugin is concerned, and every page
 * of it claims to be page one — telling a crawler that nine tenths of the
 * listing is a duplicate of its first page.
 *
 * The rule is applied blind: any singular request past page 1 gets the segment,
 * with no check for which block is on the page. A registry of paginating blocks
 * would drift the moment a second one was written, and could not see a block
 * nested inside a pattern or a reusable block. The tempting alternative — let
 * the block announce itself while rendering — is impossible by ordering:
 * `wp_head` has already run by the time content renders.
 */
final class Canonical {

	/**
	 * Filter callback shared by every adapter.
	 *
	 * Takes `mixed` rather than `string` because Yoast's filter may carry
	 * `false`, meaning "emit no canonical at all". Anything that is not a
	 * string is somebody else's decision and passes through untouched.
	 *
	 * @param mixed $canonical Canonical as the plugin resolved it.
	 *
	 * @return mixed Canonical carrying the pagination segment, or the input.
	 */
	public static function filter( mixed $canonical ): mixed {
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return $canonical;
		}

		$page = PagedRequest::current();
		$base = PaginationBase::current();

		// A manual canonical (Yoast's and AIOSEO's editors both offer the
		// field) is a deliberate override -- an editor pointing `/blog/` at
		// `/campaign/` means every page of the listing to point at
		// `/campaign/`, and appending pagination to it would produce a URL
		// that usually does not exist. This package does not read either
		// plugin's storage to tell a manual value from a derived one -- it
		// does not own that schema, and guessing at it can only fail in the
		// direction that breaks the page. Instead: append only when the
		// canonical, pagination stripped, still describes the current
		// request. A canonical that matches was either left alone by the
		// plugin or manually set to the page's own URL, and both deserve the
		// segment; anything else -- cross-domain, cross-page -- passes
		// through untouched.
		if ( $page > 1 && ! self::describesCurrentRequest( $canonical, $base ) ) {
			return $canonical;
		}

		return Pagination::append( $canonical, $page, $base );
	}

	/**
	 * Whether a canonical, pagination stripped, points at the page WordPress
	 * is currently serving.
	 *
	 * @param string $canonical Canonical as the plugin resolved it.
	 * @param string $base      The site's pagination segment.
	 */
	private static function describesCurrentRequest( string $canonical, string $base ): bool {
		$current = self::currentUrl();

		if ( null === $current ) {
			// Can't tell -- fail safe by not touching the canonical, same as
			// a genuine mismatch. A missing/odd value here must never look
			// like a match.
			return false;
		}

		$canonical_key = self::normalise( $canonical, $base );
		$current_key   = self::normalise( $current, $base );

		return null !== $canonical_key && null !== $current_key && $canonical_key === $current_key;
	}

	/**
	 * The current request, built from WordPress rather than `$_SERVER` where
	 * possible.
	 *
	 * `home_url()` is used because it is already language-aware on a WPML
	 * domain-per-language install -- it returns the current language's own
	 * domain, so a language domain compares equal to itself instead of
	 * looking like a cross-domain override. `$wp->request` is the path
	 * WordPress matched for this request, unprefixed by the language segment
	 * a directory-per-language install adds to the front, which `home_url()`
	 * restores.
	 *
	 * @return string|null Current URL, or null if the global isn't usable.
	 */
	private static function currentUrl(): ?string {
		global $wp;

		if ( ! is_object( $wp ) || ! isset( $wp->request ) || ! is_string( $wp->request ) ) {
			return null;
		}

		return home_url( '/' . ltrim( $wp->request, '/' ) );
	}

	/**
	 * Reduce a URL to a comparison key: lower-cased host, plus path with
	 * pagination and trailing slash stripped.
	 *
	 * Trailing slash and host case are the two variations every SEO plugin
	 * and every `home_url()` call already agree on tolerating, so normalising
	 * them cannot turn a real mismatch into a false match; nothing else is
	 * normalised, so a genuine cross-domain or cross-page override -- WPML's
	 * per-language domain included -- still compares unequal.
	 *
	 * @param string $url  URL to reduce.
	 * @param string $base The site's pagination segment.
	 *
	 * @return string|null Comparison key, or null if the URL has no host.
	 */
	private static function normalise( string $url, string $base ): ?string {
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$path = $parts['path'] ?? '/';
		$path = (string) preg_replace( '#/' . preg_quote( $base, '#' ) . '/\d+/?$#', '/', $path );
		$path = rtrim( $path, '/' );

		return strtolower( $parts['host'] ) . $path;
	}

	/**
	 * Hang {@see self::filter()} on whichever plugin is running.
	 *
	 * No plugin, nothing to hang on: this returns without registering rather
	 * than warning, because a site with no SEO plugin has no canonical for this
	 * to correct.
	 */
	public static function register(): void {
		match ( Plugin::active() ) {
			'aioseo' => Aioseo::register( array( self::class, 'filter' ) ),
			'yoast'  => Yoast::register( array( self::class, 'filter' ) ),
			default  => null,
		};
	}
}
