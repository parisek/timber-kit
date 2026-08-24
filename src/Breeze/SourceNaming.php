<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * Derives the two provenance signals a sitemap carries implicitly: which
 * post type a URL belongs to, and which language it is in.
 *
 * Both are read off names rather than looked up in the database, because
 * scoring runs during the deferred refresh over the whole sitemap — a query
 * per URL would turn a cheap job into a slow one for a signal worth a few
 * points.
 *
 * Neither ever throws and neither ever guesses: an unrecognised shape yields
 * an empty post type (weight 0) or the site's default language.
 */
final class SourceNaming {

	/**
	 * AIOSEO archive index names that share the `<name>-sitemap.xml` shape
	 * with a post-type sitemap but are not post types at all. A taxonomy
	 * sitemap cannot be told apart from a post-type one by filename alone;
	 * that case is not solved here and simply falls through to weight 0,
	 * the safe default the rest of the design already relies on.
	 *
	 * @var string[]
	 */
	private const AIOSEO_NON_POST_TYPE_INDEXES = array( 'author', 'date', 'product_attributes', 'rss', 'additional' );

	/**
	 * Post type from a sub-sitemap URL.
	 *
	 * Core emits `wp-sitemap-posts-<type>-<N>.xml`; AIOSEO emits
	 * `<type>-sitemap.xml`. The result is not validated against
	 * `get_post_types()` — it is only a key into the weight map, and an
	 * unknown key scores 0 exactly like an unrecognised name would.
	 *
	 * @param string $sitemapUrl
	 * @return string Post type, or '' when the name is not recognised.
	 */
	public static function derivePostType( string $sitemapUrl ): string {
		$path = (string) ( parse_url( $sitemapUrl, PHP_URL_PATH ) ?: '' );
		$base = basename( $path );
		if ( '' === $base ) {
			return '';
		}

		$base = preg_replace( '/\.gz$/i', '', $base ) ?? $base;

		if ( 1 === preg_match( '/^wp-sitemap-posts-(.+)-\d+\.xml$/i', $base, $m ) ) {
			return strtolower( $m[1] );
		}

		if ( 1 === preg_match( '/^(.+)-sitemap(?:\d+)?\.xml$/i', $base, $m ) ) {
			// "wp-sitemap.xml" itself matches nothing useful; guard the known
			// index names so a root document is not read as a type.
			$candidate = strtolower( $m[1] );

			if ( in_array( $candidate, array( 'wp', '' ), true ) ) {
				return '';
			}

			return in_array( $candidate, self::AIOSEO_NON_POST_TYPE_INDEXES, true ) ? '' : $candidate;
		}

		return '';
	}

	/**
	 * Language for a URL, in falling order of confidence.
	 *
	 * @param string        $url          The page URL.
	 * @param string        $sitemapUrl   The sub-sitemap it came from.
	 * @param array<int, string> $activeCodes Active language codes.
	 * @param string        $defaultCode  Site default language code.
	 * @return string
	 */
	public static function deriveLanguage( string $url, string $sitemapUrl, array $activeCodes, string $defaultCode ): string {
		$codes = array_map( 'strtolower', $activeCodes );

		$fromSitemap = self::firstPathSegment( $sitemapUrl );
		if ( '' !== $fromSitemap && in_array( $fromSitemap, $codes, true ) ) {
			return $fromSitemap;
		}

		$fromPath = self::firstPathSegment( $url );
		if ( '' !== $fromPath && in_array( $fromPath, $codes, true ) ) {
			return $fromPath;
		}

		$query = (string) ( parse_url( $url, PHP_URL_QUERY ) ?: '' );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			$lang = isset( $params['lang'] ) && is_string( $params['lang'] ) ? strtolower( $params['lang'] ) : '';
			if ( '' !== $lang && in_array( $lang, $codes, true ) ) {
				return $lang;
			}
		}

		return strtolower( $defaultCode );
	}

	/**
	 * @param string $url
	 * @return string Lowercased first path segment, or '' when there is none.
	 */
	private static function firstPathSegment( string $url ): string {
		$path     = (string) ( parse_url( $url, PHP_URL_PATH ) ?: '' );
		$segments = array_values( array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s ) );

		return isset( $segments[0] ) ? strtolower( $segments[0] ) : '';
	}
}
