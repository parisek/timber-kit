<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * One canonical shape for a URL, used as the key that joins signals coming
 * from three different producers.
 *
 * Breeze builds the homepage with `trailingslashit( home_url() )`, a sitemap
 * may emit it without the slash, and a menu item may carry a fragment. Keyed
 * on the raw string those are three different URLs, so the homepage would be
 * scored once and emitted twice.
 *
 * The query string is deliberately left alone: under WPML's parameter mode
 * `?lang=sk` carries meaning, and reordering parameters would be guessing.
 */
final class UrlCanonicalizer {

	/** @var array<string, int> Default port per scheme. */
	private const DEFAULT_PORTS = array(
		'http'  => 80,
		'https' => 443,
	);

	/**
	 * @param string $url Absolute http(s) URL.
	 * @return string Canonical form, or the input unchanged when it cannot be parsed.
	 */
	public static function canonicalize( string $url ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );

		$port = '';
		if ( isset( $parts['port'] ) && ( self::DEFAULT_PORTS[ $scheme ] ?? null ) !== (int) $parts['port'] ) {
			$port = ':' . (int) $parts['port'];
		}

		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$path  = self::withTrailingSlash( $path );
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Add a trailing slash unless the last segment looks like a file — a dot
	 * in the final segment is the cheapest available signal for that, and
	 * getting it wrong only costs one duplicate entry, never a wrong page.
	 *
	 * @param string $path
	 * @return string
	 */
	private static function withTrailingSlash( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}

		if ( str_ends_with( $path, '/' ) ) {
			return $path;
		}

		$last = substr( $path, (int) strrpos( $path, '/' ) + 1 );

		return str_contains( $last, '.' ) ? $path : $path . '/';
	}
}
