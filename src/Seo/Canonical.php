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

		// WPML's Yoast glue (`wp-seo-multilingual`) registers its own
		// `wpseo_canonical` callback at the same priority as this one, and
		// PHP breaks same-priority ties by registration order -- so on a
		// WPML + Yoast install its `canonical_filter()` runs first and hands
		// this method a URL-encoded string (`https%3A%2F%2F...`) instead of a
		// real URL. `parse_url()` finds no host in that, so every comparison
		// below would fail and the feature would silently do nothing on
		// exactly the installs it was written for -- something downstream
		// (WordPress's own `wp_head` output buffering, in practice) decodes
		// it again before it reaches the page, so the encoding was never
		// meaningful, only in-transit noise between two plugins. Decode once
		// and operate on the decoded string; if that still has no host, the
		// input was never a URL at all and this falls through to the normal
		// fail-safe path below.
		$resolved = self::decodeIfEncoded( $canonical );

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
		// through untouched, exactly as it arrived (still encoded, if it
		// arrived encoded -- this method never repairs a value it is not
		// going to touch).
		if ( $page > 1 && ! self::describesCurrentRequest( $resolved, $base ) ) {
			return $canonical;
		}

		// From here the canonical is being rewritten regardless, so the
		// return value is the decoded, paginated URL rather than a
		// re-encoded one. A canonical tag must contain a real URL --
		// percent-encoding its scheme and slashes does not produce one -- and
		// the live symptom this fixes (a clean, decoded tag rendering on the
		// page) proves something later in the pipeline decodes it anyway.
		// Handing back the decoded form is therefore not a guess about what
		// that later step wants; it is the value already known to survive
		// the trip.
		return Pagination::append( $resolved, $page, $base );
	}

	/**
	 * Decode a canonical once if it looks like it was URL-encoded whole.
	 *
	 * A real canonical's `parse_url()` always yields a host. One that does
	 * not is either garbage or has been percent-encoded end to end by
	 * something upstream (`urlencode()`/`rawurlencode()` applied to the whole
	 * string, not just a component of it) -- decoding once recovers the host
	 * in the second case. Exactly one decode: a value that still has no host
	 * after it is treated as unusable rather than decoded again, because a
	 * second pass invites a decoding-oracle shape this package has no case
	 * for.
	 *
	 * @param string $canonical Canonical as the plugin resolved it.
	 *
	 * @return string The canonical, decoded once if that recovers a host;
	 *                otherwise the original, untouched string.
	 */
	private static function decodeIfEncoded( string $canonical ): string {
		if ( self::hasHost( $canonical ) ) {
			return $canonical;
		}

		$decoded = urldecode( $canonical );

		return self::hasHost( $decoded ) ? $decoded : $canonical;
	}

	/**
	 * @param string $url URL to check.
	 */
	private static function hasHost( string $url ): bool {
		$parts = parse_url( $url );

		return is_array( $parts ) && ! empty( $parts['host'] );
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
	 * The current request, built from two sources that each answer reliably
	 * on their own axis -- never from `home_url( $path )`, which is not one
	 * of those sources.
	 *
	 * The host comes from `home_url()` called with no path. That keeps it
	 * language-aware on a WPML domain-per-language install -- it returns the
	 * current language's own domain, so a language domain compares equal to
	 * itself instead of looking like a cross-domain override.
	 *
	 * The path comes from the raw `$_SERVER['REQUEST_URI']`, not from
	 * `$wp->request`. `$wp->request` is WordPress's own parsed match, and on
	 * a WPML directory-per-language install it still carries the language
	 * segment -- `cs/blog/page/2`. Passing that through `home_url( $path )`
	 * does not restore anything: WPML's `home_url` filter prepends the
	 * language directory to whatever path it is given, with no way to tell
	 * an already-prefixed path from a bare one, so the segment is added
	 * twice (`/cs/cs/blog/`). That mismatch made every paginated canonical on
	 * such a site fail the comparison in {@see self::describesCurrentRequest()}
	 * and silently disabled pagination tagging for every language but the
	 * one at the domain root. `REQUEST_URI` has no filter standing between it
	 * and the browser's request line, so it carries the segment exactly
	 * once, prefixed or not.
	 *
	 * @return string|null Current URL, or null if either source is unusable.
	 */
	private static function currentUrl(): ?string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}

		$request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

		if ( ! is_string( $request_path ) || '' === $request_path ) {
			return null;
		}

		$home_parts = parse_url( home_url() );

		if ( ! is_array( $home_parts ) || empty( $home_parts['scheme'] ) || empty( $home_parts['host'] ) ) {
			return null;
		}

		return $home_parts['scheme'] . '://' . $home_parts['host'] . $request_path;
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
