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
	 * @return array<string, bool> Canonical key => whether it still needs a
	 *                             reachability probe.
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

			$keys[ UrlCanonicalizer::canonicalize( $url[0] ) ] = $url[1];
		}

		return $keys;
	}

	/**
	 * One entry to a URL on this site, or null when it names nothing here.
	 *
	 * @param string $entry Relative path or absolute URL.
	 * @return array{0: string, 1: bool}|null URL, and whether existence still
	 *                                        needs proving with a request.
	 */
	public static function resolve( string $entry ): ?array {
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
				// A post ID settles existence. Probing anyway would spend a
				// request per entry per refresh and, worse, drop a page that
				// answers 405 to HEAD while serving GET perfectly well.
				return array( $permalink, false );
			}
		}

		// Term archives and anything else url_to_postid() cannot see. Kept only
		// if it is on a host this site actually serves.
		return \Parisek\TimberKit\Helpers::isSiteUrl( $absolute ) ? array( $absolute, true ) : null;
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
	 * @param array<string, bool> $keys Canonical key => needs a probe.
	 * @return array<string, true>
	 */
	public static function filterReachable( array $keys ): array {
		if ( ! function_exists( 'wp_remote_head' ) ) {
			return array_fill_keys( array_keys( $keys ), true );
		}

		$kept = array();
		foreach ( $keys as $key => $needs_probe ) {
			if ( ! $needs_probe ) {
				$kept[ $key ] = true;
				continue;
			}
			$response = wp_remote_head(
				$key,
				array(
					'timeout'     => self::PROBE_TIMEOUT,
					'redirection' => 0,
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
}
