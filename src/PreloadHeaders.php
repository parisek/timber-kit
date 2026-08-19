<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Formats the `Link:` response header that carries a page's preload and
 * preconnect hints.
 *
 * WordPress core already collects preload resources through the
 * `wp_preload_resources` filter, but `wp_preload_resources()` renders them in
 * exactly one place: `<link rel="preload">` tags inside `wp_head`. A tag is
 * found only once the browser parses the document, which is after the server
 * finished producing it. The same list expressed as a response header arrives
 * before the body, and every browser acts on it.
 *
 * That header is also the whole of an origin's part in HTTP 103 Early Hints.
 * PHP cannot emit an informational response — `header()` composes the final
 * one — so a 103 is always synthesised by an edge (Kinsta's, Cloudflare's)
 * from the `Link:` headers it saw on a previous 200. Nothing here changes for
 * a site whose edge does not do that: the header still stands on the 200, and
 * the browser still reads it early. Early Hints only makes it arrive earlier
 * still.
 *
 * This class does the formatting and nothing else — no hooks, no globals — so
 * the rules below are testable without a request. {@see StarterBase} owns the
 * registration and the guards.
 *
 * Two limits are deliberate:
 *
 * - **A relative URL is refused.** Preload hints are resolved against the
 *   document, so a relative href works, but an intermediary replaying the
 *   header as a 103 has no document to resolve it against yet.
 * - **An entry without `href` is refused**, even the `imagesrcset`-only shape
 *   core accepts for `as=image`. A link header states its target between angle
 *   brackets; there is no header form of that entry, so it stays a tag.
 * - **The value is capped** ({@see self::MAX_BYTES}). Header size limits live
 *   in proxies, not in the standard, and a proxy that finds the block too
 *   large drops all of it. Losing the tail beats losing the head.
 */
final class PreloadHeaders {

	/**
	 * Byte cap for the assembled header value. Entries past it are dropped.
	 *
	 * Common proxy limits are 8 KB for the whole header block, which this one
	 * header shares with every other. Half of that for the hints alone is
	 * already far above any honest list — the fonts and origins of a real
	 * site run to a few hundred bytes.
	 */
	private const MAX_BYTES = 4096;

	/**
	 * Attributes copied from a preload entry, in the order they are written.
	 *
	 * `as` is mandatory and handled separately. `crossorigin` is a token, not
	 * a quoted string, and `imagesrcset` carries commas, which would be read
	 * as the separator between two links — both are special-cased below.
	 */
	private const ATTRIBUTES = [ 'type', 'media', 'fetchpriority', 'imagesrcset', 'imagesizes' ];

	/**
	 * Build the `Link:` header value from preload resources and preconnect
	 * origins.
	 *
	 * @param array<int, mixed> $resources Entries in the shape `wp_preload_resources` uses. A malformed one is skipped, not fatal.
	 * @param array<int, string>                $preconnect Absolute origins.
	 * @return string The header value, or '' when nothing survives validation.
	 */
	public static function format( array $resources, array $preconnect = [] ): string {
		$links = [];

		foreach ( $preconnect as $origin ) {
			$url = self::originUrl( $origin );
			if ( '' !== $url ) {
				$links[ $url . '|preconnect' ] = '<' . $url . '>; rel=preconnect';
			}
		}

		foreach ( $resources as $resource ) {
			// Core's own consumer skips a non-array entry rather than
			// failing on it, and third-party callbacks have been written
			// against that. Fataling where core shrugs would make this class
			// the visible cause of someone else's malformed entry.
			if ( ! is_array( $resource ) ) {
				continue;
			}

			$link = self::preloadLink( $resource );
			if ( '' !== $link ) {
				// Keyed by URL so a resource listed twice is sent once. A
				// duplicated hint is not an error, but it spends the byte
				// budget the cap below is defending.
				$links[ self::scalar( $resource, 'href' ) . '|preload' ] = $link;
			}
		}

		return self::join( array_values( $links ) );
	}

	/**
	 * One `rel=preload` link, or '' when the entry cannot produce a safe one.
	 *
	 * @param array<string, mixed> $resource
	 */
	private static function preloadLink( array $resource ): string {
		$href = self::absoluteUrl( self::scalar( $resource, 'href' ) );
		$as   = self::token( self::scalar( $resource, 'as' ) );

		// `as` decides the request's destination, priority and CORS mode. A
		// preload without it is fetched with no destination and is usually
		// downloaded a second time by whoever actually needed it, which is
		// slower than not having preloaded at all.
		if ( '' === $href || '' === $as ) {
			return '';
		}

		$link = '<' . $href . '>; rel=preload; as=' . $as;

		foreach ( self::ATTRIBUTES as $name ) {
			$value = self::quotable( self::scalar( $resource, $name ) );
			if ( '' !== $value ) {
				$link .= '; ' . $name . '="' . $value . '"';
			}
		}

		// A font is fetched in CORS mode whatever its origin, so a preload
		// that omits this fetches the file a second time -- the cost this
		// whole class exists to avoid.
		//
		// The keyword is written out. In markup, `crossorigin` with no value
		// is an empty attribute and means anonymous, but a header parameter
		// with no value is not the same thing to every parser, and one that
		// reads it as absent gives the preload no-CORS mode -- which is the
		// double download again, now harder to see. `anonymous` costs ten
		// bytes and means one thing everywhere.
		$crossorigin = self::scalar( $resource, 'crossorigin' );
		if ( '' !== $crossorigin || 'font' === $as ) {
			$link .= '; crossorigin=' . ( 'use-credentials' === $crossorigin ? 'use-credentials' : 'anonymous' );
		}

		return $link;
	}

	/**
	 * Join links until the cap, dropping whole entries rather than truncating.
	 *
	 * @param array<int, string> $links
	 */
	private static function join( array $links ): string {
		$value = '';

		foreach ( $links as $link ) {
			$candidate = '' === $value ? $link : $value . ', ' . $link;
			if ( strlen( $candidate ) > self::MAX_BYTES ) {
				break;
			}
			$value = $candidate;
		}

		return $value;
	}

	/**
	 * An absolute http(s) URL safe to place between angle brackets, or ''.
	 */
	private static function absoluteUrl( string $url ): string {
		$url = trim( $url );

		// A newline here writes a second header. Everything else on this list
		// ends the link or starts the next attribute, so a URL carrying one
		// produces a different link than the caller wrote. The control-byte
		// class covers the rest, including NUL, which some parsers treat as
		// the end of the string and others do not.
		if ( '' === $url || strcspn( $url, "<>;, \t" ) !== strlen( $url ) ) {
			return '';
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/', $url ) === 1 ) {
			return '';
		}

		// The authority must look like a host: no empty one, and nothing that
		// starts the path, query or fragment inside it. `https://?x` parses as
		// a URL and preconnects to nothing.
		return preg_match( '#^https?://[^/?\#@\[\]]+#i', $url ) === 1 ? $url : '';
	}

	/**
	 * An origin for `rel=preconnect` — scheme and host, no path, or ''.
	 */
	private static function originUrl( string $origin ): string {
		$origin = self::absoluteUrl( $origin );
		if ( '' === $origin ) {
			return '';
		}

		// A preconnect opens a connection to a host; the path is read by
		// nobody and only tells a log reader that the author expected it to
		// fetch something.
		$matches = [];
		preg_match( '#^https?://[^/?\#]+#i', $origin, $matches );

		return $matches[0] ?? '';
	}

	/**
	 * One key of an entry as a string, or '' when it is missing or is not a
	 * scalar. Core's HTML side skips a non-scalar value the same way; an array
	 * here would be a PHP notice and a header saying `Array`.
	 *
	 * @param array<string, mixed> $resource
	 */
	private static function scalar( array $resource, string $key ): string {
		$value = $resource[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A bare token — letters, digits and dashes, as every `as` value is.
	 */
	private static function token( string $value ): string {
		return preg_match( '/^[A-Za-z][A-Za-z0-9-]*$/', $value ) === 1 ? $value : '';
	}

	/**
	 * A value safe inside a quoted attribute: no quote, no backslash, no
	 * newline. Rejected rather than escaped — every legitimate value of these
	 * attributes (a MIME type, a media query, a srcset) contains none of them,
	 * so a value that does is a mistake worth losing rather than repairing.
	 */
	private static function quotable( string $value ): string {
		$value = trim( $value );

		return strcspn( $value, "\"\\\r\n" ) === strlen( $value ) ? $value : '';
	}
}
