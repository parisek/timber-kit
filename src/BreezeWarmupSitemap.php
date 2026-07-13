<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Feeds Breeze's Cache Warmup preloader with every URL from the site's XML
 * sitemap, via the `breeze_preload_urls` filter.
 *
 * Breeze 2.5 re-warms the cache after every full purge, but its own URL
 * sources are limited to the homepage, a handful of auto-detected pages, and
 * a manually maintained list capped at 30 entries. This class closes that
 * gap fleet-wide by discovering the sitemap URL set once per purge cycle and
 * merging it in.
 *
 * Sitemap source order: AIOSEO (`/sitemap.xml`) when active, otherwise core
 * (`/wp-sitemap.xml`). Both formats may be a sitemap index that points at
 * per-post-type sub-sitemaps — those are followed recursively, bounded by
 * {@see self::MAX_SUBSITEMAPS} and {@see self::MAX_DEPTH} so a pathological
 * or malicious sitemap can never cause a runaway fetch chain.
 *
 * The whole pipeline is best-effort: network failures, malformed XML, or an
 * absent sitemap all degrade silently to an empty result — this must never
 * throw, and must never block or delay the cache purge it hooks into. The
 * resolved URL list is cached in a transient (see {@see self::CACHE_TTL}) so
 * repeated purges do not re-fetch and re-parse the sitemap every time.
 *
 * Activation is gated by `StarterBase` to projects where Breeze is active;
 * the filter is otherwise inert because Breeze never fires it. Per-project
 * opt-out: `add_filter( 'timberkit_warmup_sitemap_enabled', '__return_false' )`.
 */
final class BreezeWarmupSitemap {

	/** @var bool Prevent duplicate hook registration. */
	private static bool $registered = false;

	private const CACHE_KEY = 'timber_kit_breeze_warmup_sitemap_urls';

	// Literal seconds-per-hour instead of `HOUR_IN_SECONDS` — class constants
	// are evaluated at autoload time, before WordPress bootstrap is guaranteed
	// to have defined the global constant. Using the literal keeps the class
	// self-contained for unit tests and any non-WP load context.
	private const CACHE_TTL = 3600;

	/** @var int Default safety cap on sitemap-sourced URLs, filterable via `timberkit_warmup_sitemap_max_urls`. */
	private const DEFAULT_MAX_URLS = 200;

	/** @var int Maximum number of sub-sitemaps followed from one sitemap index. */
	private const MAX_SUBSITEMAPS = 50;

	/** @var int Maximum recursion depth into nested sitemap indexes. */
	private const MAX_DEPTH = 2;

	/** @var int Remote fetch timeout in seconds. */
	private const FETCH_TIMEOUT = 5;

	/**
	 * Register the `breeze_preload_urls` filter.
	 *
	 * Re-checks the opt-out filter here (not just in the `StarterBase` caller)
	 * so the class stays self-guarding when used directly, e.g. from tests or
	 * a project that wires it without going through `StarterBase`.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		if ( ! self::isEnabled() ) {
			return;
		}

		self::$registered = true;

		add_filter( 'breeze_preload_urls', array( self::class, 'filterPreloadUrls' ) );
	}

	/**
	 * Whether the module is enabled for this project.
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool {
		return (bool) apply_filters( 'timberkit_warmup_sitemap_enabled', true );
	}

	/**
	 * `breeze_preload_urls` filter callback — merge sitemap URLs into
	 * Breeze's own preload list.
	 *
	 * @param mixed $urls Breeze's own preload URL list (homepage + auto-detected + user list).
	 * @return array<int, string>
	 */
	public static function filterPreloadUrls( mixed $urls ): array {
		$existing = is_array( $urls ) ? array_values( array_filter( $urls, 'is_string' ) ) : array();

		if ( ! self::isEnabled() ) {
			return $existing;
		}

		return self::mergeUrls( $existing, self::getSitemapUrls() );
	}

	/**
	 * Resolve the cached (or freshly fetched) list of same-host sitemap URLs.
	 *
	 * @return array<int, string>
	 */
	public static function getSitemapUrls(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, 'is_string' ) );
		}

		$urls = self::fetchSitemapUrls();

		set_transient( self::CACHE_KEY, $urls, self::CACHE_TTL );

		return $urls;
	}

	/**
	 * Fetch and parse the site's sitemap into a flat, deduped, same-host URL
	 * list. Never throws — any failure along the way degrades to an empty
	 * array so the caller always has something safe to merge.
	 *
	 * @return array<int, string>
	 */
	public static function fetchSitemapUrls(): array {
		try {
			$root = self::resolveSitemapRootUrl();
			if ( '' === $root ) {
				return array();
			}

			$seen = array();
			$urls = self::fetchAndParseSitemap( $root, 0, $seen );

			return array_values( array_unique( $urls ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * AIOSEO-first sitemap root URL resolution, falling back to WordPress core.
	 *
	 * @return string Empty string when `home_url()` is unavailable.
	 */
	private static function resolveSitemapRootUrl(): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}

		$path = self::isAioseoActive() ? '/sitemap.xml' : '/wp-sitemap.xml';

		return (string) home_url( $path );
	}

	/**
	 * Detect an active AIOSEO installation without a hard dependency on it.
	 *
	 * @return bool
	 */
	private static function isAioseoActive(): bool {
		return function_exists( 'aioseo' ) || class_exists( '\AIOSEO\Plugin\AIOSEO' );
	}

	/**
	 * Fetch one sitemap document and recursively follow index entries.
	 *
	 * @param string               $url   Sitemap (or sub-sitemap) URL.
	 * @param int                  $depth Current recursion depth.
	 * @param array<string, bool>  $seen  URLs already fetched, by reference — guards against index cycles.
	 * @return array<int, string> URLs collected from `<url><loc>` entries.
	 */
	private static function fetchAndParseSitemap( string $url, int $depth, array &$seen ): array {
		if ( isset( $seen[ $url ] ) ) {
			return array();
		}
		$seen[ $url ] = true;

		$body = self::fetchBody( $url );
		if ( '' === $body ) {
			return array();
		}

		$xml = self::parseXml( $body );
		if ( null === $xml ) {
			return array();
		}

		if ( isset( $xml->sitemap ) ) {
			return self::collectFromIndex( $xml, $depth, $seen );
		}

		if ( isset( $xml->url ) ) {
			return self::collectFromUrlset( $xml );
		}

		return array();
	}

	/**
	 * Follow a `<sitemapindex>` document's `<sitemap><loc>` entries.
	 *
	 * @param \SimpleXMLElement   $xml   Parsed `<sitemapindex>` root.
	 * @param int                 $depth Current recursion depth.
	 * @param array<string, bool> $seen  URLs already fetched, by reference.
	 * @return array<int, string>
	 */
	private static function collectFromIndex( \SimpleXMLElement $xml, int $depth, array &$seen ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return array();
		}

		$urls  = array();
		$count = 0;

		foreach ( $xml->sitemap as $sitemap ) {
			if ( $count >= self::MAX_SUBSITEMAPS ) {
				break;
			}

			$loc = isset( $sitemap->loc ) ? trim( (string) $sitemap->loc ) : '';
			if ( '' === $loc ) {
				continue;
			}

			++$count;
			$urls = array( ...$urls, ...self::fetchAndParseSitemap( $loc, $depth + 1, $seen ) );
		}

		return $urls;
	}

	/**
	 * Collect `<url><loc>` entries from a `<urlset>` document, keeping only
	 * same-host URLs.
	 *
	 * @param \SimpleXMLElement $xml Parsed `<urlset>` root.
	 * @return array<int, string>
	 */
	private static function collectFromUrlset( \SimpleXMLElement $xml ): array {
		$urls = array();

		foreach ( $xml->url as $entry ) {
			$loc = isset( $entry->loc ) ? trim( (string) $entry->loc ) : '';
			if ( '' === $loc || ! self::isSameHost( $loc ) ) {
				continue;
			}

			$urls[] = $loc;
		}

		return $urls;
	}

	/**
	 * Remote-fetch a URL's response body. Any non-2xx response or transport
	 * error resolves to an empty string.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function fetchBody( string $url ): string {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => self::FETCH_TIMEOUT,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Parse an XML string, tolerating malformed input.
	 *
	 * @param string $body
	 * @return \SimpleXMLElement|null
	 */
	private static function parseXml( string $body ): ?\SimpleXMLElement {
		$internal_errors = libxml_use_internal_errors( true );

		try {
			$xml = simplexml_load_string( $body );
		} catch ( \Throwable $e ) {
			$xml = false;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );

		return ( $xml instanceof \SimpleXMLElement ) ? $xml : null;
	}

	/**
	 * Whether a URL's host matches the site's own host — sitemap entries are
	 * always expected to be local, but a defensive check keeps a misbehaving
	 * or spoofed sitemap from feeding external URLs into the warmup queue.
	 *
	 * Uses PHP's native `parse_url()` rather than `wp_parse_url()` — the two
	 * behave identically for a plain host lookup, and the native function
	 * keeps this check dependency-free (no WordPress bootstrap needed).
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function isSameHost( string $url ): bool {
		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}

		$target_host = strtolower( (string) ( parse_url( $url, PHP_URL_HOST ) ?: '' ) );
		$home_host   = strtolower( (string) ( parse_url( (string) home_url(), PHP_URL_HOST ) ?: '' ) );

		return '' !== $target_host && $target_host === $home_host;
	}

	/**
	 * Merge sitemap URLs into Breeze's own preload list — dedup against the
	 * existing entries and cap the number of sitemap-sourced URLs added.
	 *
	 * The safety cap applies only to sitemap-sourced URLs; entries Breeze
	 * already carries (homepage, auto-detected pages, the user's own manual
	 * list) are always preserved in full.
	 *
	 * @param array<int, string> $existing     Breeze's own preload URL list.
	 * @param array<int, string> $sitemap_urls Same-host URLs collected from the sitemap.
	 * @return array<int, string>
	 */
	private static function mergeUrls( array $existing, array $sitemap_urls ): array {
		$max = apply_filters( 'timberkit_warmup_sitemap_max_urls', self::DEFAULT_MAX_URLS );
		$max = is_numeric( $max ) ? max( 0, (int) $max ) : self::DEFAULT_MAX_URLS;

		$merged = $existing;
		$known  = array_fill_keys( $existing, true );
		$added  = 0;

		foreach ( $sitemap_urls as $url ) {
			if ( $added >= $max ) {
				break;
			}
			if ( isset( $known[ $url ] ) ) {
				continue;
			}

			$known[ $url ] = true;
			$merged[]      = $url;
			++$added;
		}

		return $merged;
	}

	/**
	 * Reset internal state so tests can re-register the module.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}
}
