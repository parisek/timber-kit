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

	/** @var int Default cap on entries accepted from a project, before resolution. */
	private const DEFAULT_MAX_ENTRIES = 200;

	/** @var int Timeout, in seconds, for one reachability probe. */
	private const PROBE_TIMEOUT = 5;

	/**
	 * @var int Wall-clock budget, in seconds, for all probes in one refresh.
	 *
	 * Entry count alone does not bound the cost: 200 entries pointed at a slow
	 * host is 200 x PROBE_TIMEOUT serially. A max_execution_time fatal is not a
	 * Throwable, so it walks past runRefresh()'s catch AND its finally — the
	 * refresh vanishes mid-run and the lock is left to expire on its own TTL.
	 * Stopping early keeps the entries not yet reached rather than treating
	 * them as dead, which is the same stale-beats-nothing rule as a failed
	 * probe.
	 */
	private const PROBE_BUDGET = 20;

	/**
	 * Canonical keys for a project's curated list.
	 *
	 * @param array<int, mixed> $entries Raw `$breeze_warmup_urls` value.
	 * @return array<string, bool> Canonical key => whether it still needs a
	 *                             reachability probe.
	 */
	public static function keys( array $entries ): array {
		// Filterable for the same reason every other knob in this subsystem is:
		// a project may keep its config in an mu-plugin rather than in the theme
		// subclass, and without this the curated list would be the one warmup
		// setting unreachable from there.
		//
		// Unlike `timberkit_warmup_priority_weights` this does not have to be a
		// pure function — nothing fingerprints it. The cost of that is a delay:
		// a changed list is picked up at the next refresh, within CACHE_TTL,
		// not on the next purge.
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'timberkit_warmup_curated_urls', $entries );
			if ( is_array( $filtered ) ) {
				$entries = $filtered;
			}
		}

		$max = self::DEFAULT_MAX_ENTRIES;
		if ( function_exists( 'apply_filters' ) ) {
			$candidate = apply_filters( 'timberkit_warmup_curated_max_entries', $max );
			$max       = is_numeric( $candidate ) ? max( 0, (int) $candidate ) : $max;
		}

		$keys  = array();
		$count = 0;

		foreach ( $entries as $entry ) {
			if ( $count >= $max ) {
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

		$kept     = array();
		$deadline = time() + self::PROBE_BUDGET;

		foreach ( $keys as $key => $needs_probe ) {
			if ( ! $needs_probe ) {
				$kept[ $key ] = true;
				continue;
			}

			// Out of budget: keep the rest unprobed rather than spend a refresh
			// finding out, or die trying.
			if ( time() >= $deadline ) {
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

			// Drop only what is definitively gone. This probe exists to remove
			// a URL whose page was deleted -- it is not qualified to judge
			// anything else, and every other reading of a status code costs a
			// page the project explicitly asked for.
			//
			// 405 in particular: a term archive behind a WAF or a security
			// plugin that refuses the HEAD verb is a perfectly live page, and
			// dropping it would be the exact failure this file's own comment
			// claims to avoid -- that comment guarded the post-ID path and left
			// this one open. 401/403 are the same shape: authorisation says
			// nothing about existence, and a warmer is not logged in.
			//
			// 5xx is excluded from the drop list too. A server erroring today
			// is not a page deleted, and dropping on it would empty a curated
			// list during an outage -- precisely when nothing should change.
			if ( 404 === $code || 410 === $code ) {
				continue;
			}

			$kept[ $key ] = true;
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
