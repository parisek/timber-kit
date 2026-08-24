<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * Resolves a project's hand-written warmup list into canonical URL keys.
 *
 * The scorer has always had `manual` as a source, but its only input was
 * Breeze's own settings row — so the one curated signal in the model was the one
 * signal a project could not put in git. This turns a `StarterBase` property
 * into the same keys, and the two merge: a project may use either, or both.
 *
 * Entries may be written either way, and the reason is not convenience:
 *
 * - A **relative path** (`/blog/`) is what a project wants most of the time. The
 *   same code runs on ddev, on staging and on production, and an absolute URL
 *   would be silently wrong on two of the three — rejected as off-host, warming
 *   nothing, reporting nothing.
 * - An **absolute URL** is what a project needs when the target is not on
 *   `home_url()`'s host at all, which under WPML's domain-per-language
 *   negotiation is every language but the default one.
 *
 * Both are resolved rather than trusted. An entry that names a post or a page
 * is turned into its ID and back into `get_permalink()`, so what gets stored is
 * this environment's own correct URL in this environment's own language —
 * which is what makes accepting a relative path safe rather than merely
 * convenient. It is the pattern `Breadcrumb::get_global_links()` already uses.
 *
 * An entry that resolves to nothing is dropped. A deleted page stops being
 * warmed without anyone having to remember the list exists, and a typo warms a
 * 404 zero times instead of on every cycle.
 *
 * `url_to_postid()` does not see term archives, so those cannot be resolved this
 * way and are kept as URLs, host-checked. Whether they still exist is settled by
 * {@see self::filterReachable()}, which the refresh calls — off the purge path,
 * where a request is affordable.
 */
final class CuratedUrls {

	/** @var int Cap on entries accepted from a project, before resolution. */
	private const MAX_ENTRIES = 200;

	/** @var int Timeout, in seconds, for one reachability probe. */
	private const PROBE_TIMEOUT = 5;

	/**
	 * Canonical keys for a project's curated list.
	 *
	 * @param array<int, mixed> $entries Raw `$breeze_warmup_urls` value.
	 * @return array<string, true> Canonical keys, keyed for merging.
	 */
	public static function keys( array $entries ): array {
		$keys  = array();
		$count = 0;

		foreach ( $entries as $entry ) {
			if ( $count >= self::MAX_ENTRIES ) {
				break;
			}
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}

			++$count;

			$url = self::resolve( $entry );
			if ( null === $url ) {
				continue;
			}

			$keys[ UrlCanonicalizer::canonicalize( $url ) ] = true;
		}

		return $keys;
	}

	/**
	 * One entry to a URL on this site, or null when it names nothing here.
	 *
	 * @param string $entry Relative path or absolute URL.
	 * @return string|null
	 */
	public static function resolve( string $entry ): ?string {
		$absolute = self::toAbsolute( $entry );
		if ( null === $absolute ) {
			return null;
		}

		// A post or page resolves to an ID, and the ID back to a permalink. The
		// round trip is the point: it returns this environment's URL, in this
		// language, whatever was written.
		$post_id = \Parisek\TimberKit\Helpers::urlToPostId( $absolute );
		if ( $post_id > 0 ) {
			$permalink = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : false;
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		// Term archives and anything else url_to_postid() cannot see. Kept only
		// if it is on a host this site actually serves.
		return self::isOwnHost( $absolute ) ? $absolute : null;
	}

	/**
	 * Drop entries that do not answer, one request each.
	 *
	 * Only for what {@see self::resolve()} could not turn into a permalink — a
	 * post ID is proof enough on its own. Called from the refresh, never from
	 * the purge-time filter: that one runs while an editor waits for the page to
	 * save and must not touch the network.
	 *
	 * A request that fails outright is treated as reachable. The alternative is
	 * worse: one flaky DNS lookup would silently empty a curated list, and
	 * `runRefresh()` is built on stale-beats-nothing everywhere else too.
	 *
	 * @param array<string, true> $keys Canonical keys from {@see self::keys()}.
	 * @return array<string, true>
	 */
	public static function filterReachable( array $keys ): array {
		if ( ! function_exists( 'wp_remote_head' ) ) {
			return $keys;
		}

		$kept = array();
		foreach ( $keys as $key => $flag ) {
			$response = wp_remote_head(
				$key,
				array(
					'timeout'     => self::PROBE_TIMEOUT,
					'redirection' => 0,
					'sslverify'   => false,
				)
			);

			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				$kept[ $key ] = true;
				continue;
			}

			$code = function_exists( 'wp_remote_retrieve_response_code' )
				? (int) wp_remote_retrieve_response_code( $response )
				: 0;

			// 3xx counts: a curated entry pointing at a redirect still warms the
			// hop the visitor takes, and dropping it would punish a trailing
			// slash. 4xx and 5xx are what this exists to remove.
			if ( 0 === $code || ( $code >= 200 && $code < 400 ) ) {
				$kept[ $key ] = true;
			}
		}

		return $kept;
	}

	/**
	 * A written entry as an absolute URL, or null when it is neither.
	 *
	 * @param string $entry Relative path or absolute URL.
	 * @return string|null
	 */
	private static function toAbsolute( string $entry ): ?string {
		if ( preg_match( '#^https?://#i', $entry ) ) {
			return $entry;
		}

		// Anything else is treated as a path. A scheme-relative `//host/path`
		// is refused rather than guessed at: it reads as a path and behaves as
		// a host, and that ambiguity has no safe default here.
		if ( str_starts_with( $entry, '//' ) ) {
			return null;
		}

		if ( ! function_exists( 'home_url' ) ) {
			return null;
		}

		return home_url( '/' . ltrim( $entry, '/' ) );
	}

	/**
	 * Whether a URL is on a host this site serves.
	 *
	 * `home_url()` alone is not the answer under domain-per-language: every
	 * language but the default one lives somewhere else, and treating those as
	 * foreign would refuse exactly the entries an absolute URL exists to write.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function isOwnHost( string $url ): bool {
		$host = strtolower( (string) ( parse_url( $url, PHP_URL_HOST ) ?: '' ) );
		if ( '' === $host ) {
			return false;
		}

		return in_array( $host, self::ownHosts(), true );
	}

	/**
	 * Every host this site answers on: `home_url()` plus each active language's.
	 *
	 * @return array<int, string>
	 */
	private static function ownHosts(): array {
		$hosts = array();

		if ( function_exists( 'home_url' ) ) {
			$host = strtolower( (string) ( parse_url( (string) home_url(), PHP_URL_HOST ) ?: '' ) );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => false ) );
			if ( is_array( $languages ) ) {
				foreach ( $languages as $language ) {
					if ( ! is_array( $language ) || empty( $language['url'] ) ) {
						continue;
					}
					$host = strtolower( (string) ( parse_url( (string) $language['url'], PHP_URL_HOST ) ?: '' ) );
					if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
						$hosts[] = $host;
					}
				}
			}
		}

		return $hosts;
	}
}
