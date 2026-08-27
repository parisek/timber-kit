<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

use Parisek\TimberKit\Breeze\TailPlanner;
use Parisek\TimberKit\Seo\Plugin;

/**
 * Feeds Breeze's Cache Warmup preloader with every URL from the site's XML
 * sitemap, via the `breeze_preload_urls` filter.
 *
 * Breeze 2.5 re-warms the cache after every full purge, but its own URL
 * sources are limited to the homepage, a handful of auto-detected pages, and
 * a manually maintained list capped at 30 entries. This class closes that
 * gap fleet-wide by discovering the sitemap URL set and merging it in.
 *
 * Sitemap source order: AIOSEO (`/sitemap.xml`), then Yoast
 * (`/sitemap_index.xml`), then core (`/wp-sitemap.xml`) — see
 * {@see self::SITEMAP_PROVIDERS}. A project overrides the resolved address
 * with the `timberkit_warmup_sitemap_url` filter. Every format may be a
 * sitemap index that points at
 * per-post-type sub-sitemaps — those are followed recursively, bounded by
 * {@see self::MAX_SUBSITEMAPS} and {@see self::MAX_DEPTH} so a pathological
 * or malicious sitemap can never cause a runaway fetch chain. Every URL
 * dereferenced (root, sub-sitemap, or page URL) is validated as an
 * absolute http(s) URL on the site's own host before it is fetched or
 * returned — see {@see self::isFetchableSameHostUrl()} — closing off SSRF
 * via a sitemap index pointing at an external host, and redirects are
 * disabled on every fetch so a same-host URL can't bounce the request
 * off-site afterwards.
 *
 * Crucially, the `breeze_preload_urls` filter fires synchronously inside the
 * purge request, so it must never perform a live fetch itself. Instead this
 * class follows a "last known good + deferred refresh" model:
 * - The filter callback ({@see self::filterPreloadUrls()}) only reads a
 *   previously stored URL list (a `wp_options` row, `autoload` off) and
 *   returns it — it never touches the network.
 * - When that stored list is missing or older than {@see self::CACHE_TTL},
 *   the callback schedules a one-off `wp_schedule_single_event()` job
 *   ({@see self::CRON_HOOK}) to refresh it in the background, guarded by a
 *   short transient lock ({@see self::LOCK_TTL}) so concurrent purges don't
 *   pile up duplicate refresh jobs.
 * - The refresh job ({@see self::runRefresh()}) does the actual crawl. A
 *   failed or empty crawl **never** overwrites the last known good list —
 *   stale data always beats no data, and the lock's TTL naturally rate-limits
 *   retries.
 *
 * The whole pipeline is best-effort: network failures, malformed XML, or an
 * absent sitemap all degrade silently — this must never throw, and the
 * purge-time filter callback must never block on I/O.
 *
 * Activation is opt-in: `StarterBase::$breeze_warmup_sitemap` (default
 * `false`) plus Breeze being active. Per-project runtime kill switch, even
 * when the flag is on: `add_filter( 'timberkit_warmup_sitemap_enabled', '__return_false' )`.
 */
final class WarmupSitemap {

	/** @var bool Prevent duplicate hook registration. */
	private static bool $registered = false;

	/** @var array<int, string> Project's curated warmup entries, unresolved. */
	private static array $curated = array();

	/** @var bool Whether ordering is enabled for this project. */
	private static bool $priority_enabled = false;

	/** @var string Fingerprint of the effective weights, computed once at registration. */
	private static string $weights_hash = '';

	/** @var array<string, mixed>|null Effective weight map for this project, set at registration. */
	private static ?array $weights = null;

	/** @var bool Whether tail draining is enabled for this project. */
	private static bool $tail_enabled = false;

	/** @var int URLs dispatched per tail tick, filterable at registration via `timberkit_warmup_tail_batch`. */
	private static int $tail_batch = 100;

	/** @var string Action Scheduler hook the tail drain ticks on. */
	public const TAIL_HOOK = 'timber_kit_breeze_warmup_tail_tick';

	/** @var int Seconds between tail ticks. Fixed, not configurable: the batch size is the knob. */
	public const TAIL_INTERVAL = 300;

	/** @var string Transient key for the short refresh lock. */
	private const LOCK_KEY = 'timber_kit_breeze_warmup_sitemap_refresh_lock';

	/** @var string wp_schedule_single_event() hook the deferred refresh job runs on. */
	private const CRON_HOOK = 'timber_kit_breeze_warmup_sitemap_refresh';

	// Literal seconds instead of `HOUR_IN_SECONDS`/`MINUTE_IN_SECONDS` — class
	// constants are evaluated at autoload time, before WordPress bootstrap is
	// guaranteed to have defined the global constants. Using literals keeps
	// the class self-contained for unit tests and any non-WP load context.

	/** @var int Age (seconds) after which the stored URL list is considered stale and a refresh is scheduled. */
	private const CACHE_TTL = 3600;

	/** @var int Refresh-lock TTL (seconds) — also the effective minimum retry interval after a failed refresh. */
	private const LOCK_TTL = 60;

	/** @var int Default safety cap on sitemap-sourced URLs, filterable via `timberkit_warmup_sitemap_max_urls`. */
	private const DEFAULT_MAX_URLS = 200;

	/** @var int Maximum number of sub-sitemaps followed from one sitemap index. */
	private const MAX_SUBSITEMAPS = 50;

	/** @var int Maximum recursion depth into nested sitemap indexes. */
	private const MAX_DEPTH = 2;

	/** @var int Remote fetch timeout in seconds. */
	private const FETCH_TIMEOUT = 5;

	/** @var int Size cap (bytes) on a gzip-compressed sitemap body, checked before decompression. */
	private const MAX_GZIP_BYTES = 10485760; // 10 MB.

	/**
	 * Register the `breeze_preload_urls` filter and the deferred-refresh cron
	 * hook.
	 *
	 * Re-checks the opt-out filter here (not just in the `StarterBase` caller)
	 * so the class stays self-guarding when used directly, e.g. from tests or
	 * a project that wires it without going through `StarterBase`.
	 *
	 * @param array<string, mixed>|null $weights
	 * @param array<int, string>        $curated   Project's curated warmup entries.
	 * @param bool                      $tail      Drain the URLs the cap excluded, a batch at a time.
	 * @return void
	 */
	public static function register( bool $priority = false, ?array $weights = null, array $curated = array(), bool $tail = false, int $tailBatch = 100 ): void {
		if ( self::$registered ) {
			return;
		}

		if ( ! self::isEnabled() ) {
			return;
		}

		self::$registered       = true;
		self::$priority_enabled = $priority;
		self::$weights          = $weights ?? Scorer::DEFAULT_WEIGHTS;
		self::$curated          = $curated;

		// Tail draining requires the ordering — without it there is nothing
		// to drain — so $tail alone must enable nothing. It also refuses to
		// wire on multisite: the brake reads Breeze's `breeze_preload_queue`,
		// which Breeze scopes per blog, so on multisite the brake would
		// always read idle and the drain would pile onto the origin
		// unthrottled. Refusing to wire is safer than running without a
		// working brake.
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();

		if ( $tail && $priority && ! $is_multisite ) {
			self::$tail_enabled = true;
			self::$tail_batch   = (int) apply_filters( 'timberkit_warmup_tail_batch', $tailBatch );

			add_action( self::TAIL_HOOK, array( self::class, 'runTailTick' ) );
			add_action( 'breeze_clear_all_cache', array( self::class, 'onPurgeScheduleTail' ), 1000 );
		}

		add_filter( 'breeze_preload_urls', array( self::class, 'filterPreloadUrls' ) );
		add_action( self::CRON_HOOK, array( self::class, 'runRefresh' ) );

		if ( $priority ) {
			// Computed once here, never per purge — the hot path may only
			// afford a string comparison against the stored hash. Must be
			// built from the FILTERED weights (self::weights()), not the
			// raw self::$weights: the hash a write stores (runRefresh(),
			// rescoreOnMenuUpdate()) is built the same way, and the two must
			// agree — otherwise a project using the
			// `timberkit_warmup_priority_weights` filter would see
			// weightsChanged() report a mismatch on every single purge,
			// scheduling a needless refresh forever.
			self::$weights_hash = Scorer::weightsHash( self::weights() );

			// Priority 5: Breeze's own menu purge and the kit's both sit at
			// 10, so the rescore must land before them — the purge they
			// trigger then reads an ordering that already reflects the new
			// menu.
			add_action( 'wp_update_nav_menu', array( self::class, 'rescoreOnMenuUpdate' ), 5 );
		}
	}

	/**
	 * Recompute the ordering from stored signals after a menu changed.
	 *
	 * No network: menu membership is the only signal that changed, and
	 * everything else is already stored. With no stored signals this does
	 * nothing but schedule a refresh — writing a partial list would be worse
	 * than leaving the stale one in place.
	 *
	 * @return void
	 */
	public static function rescoreOnMenuUpdate(): void {
		if ( ! self::isEnabled() || ! self::$priority_enabled ) {
			return;
		}

		try {
			$stored = PriorityStore::read();
			if ( null === $stored || array() === $stored['signals'] ) {
				self::maybeScheduleRefresh();

				return;
			}

			$menu    = SignalCollector::menuKeys();
			$weights = self::weights();
			$records = array();

			foreach ( $stored['signals'] as $key => $signal ) {
				if ( ! is_array( $signal ) || ! isset( $signal['url'] ) ) {
					continue;
				}

				$records[] = array(
					'url'        => (string) $signal['url'],
					'key'        => (string) $key,
					'lastmod'    => isset( $signal['lastmod'] ) ? $signal['lastmod'] : null,
					'type'       => (string) ( $signal['type'] ?? '' ),
					'lang'       => (string) ( $signal['lang'] ?? '' ),
					'menu'       => isset( $menu[ (string) $key ] ),
					'front_page' => (bool) ( $signal['front_page'] ?? false ),
					'manual'     => (bool) ( $signal['manual'] ?? false ),
				);
			}

			if ( array() === $records ) {
				return;
			}

			$built = self::buildOrderedUrls( $records, $weights, time(), self::maxUrls() );

			PriorityStore::write(
				$built['urls'],
				$built['signals'],
				Scorer::weightsHash( $weights ),
				$stored['revision']
			);
		} catch ( \Throwable $e ) {
			// Best-effort by contract: this runs synchronously inside the
			// editor's Save request, and a failure here must never surface
			// as a fatal in that request.
		}
	}

	/**
	 * Whether the module is enabled for this project (runtime kill switch on
	 * top of the `StarterBase::$breeze_warmup_sitemap` opt-in flag).
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool {
		return (bool) apply_filters( 'timberkit_warmup_sitemap_enabled', true );
	}

	/**
	 * `breeze_preload_urls` filter callback — merge the last known good
	 * sitemap URL list into Breeze's own preload list.
	 *
	 * Read-only with respect to the network: never fetches. When the stored
	 * list is missing or stale it schedules a background refresh and still
	 * returns whatever is currently stored (possibly stale, possibly empty)
	 * so the purge is never delayed.
	 *
	 * @param mixed $urls Breeze's own preload URL list (homepage + auto-detected + user list).
	 * @return array<int, string>
	 */
	public static function filterPreloadUrls( mixed $urls ): array {
		$existing = is_array( $urls ) ? array_values( array_filter( $urls, 'is_string' ) ) : array();

		if ( ! self::isEnabled() ) {
			return $existing;
		}

		$stored = PriorityStore::read();
		if ( null === $stored || self::isStale( $stored ) || self::weightsChanged( $stored ) ) {
			self::maybeScheduleRefresh();
		}

		if ( ! self::$priority_enabled ) {
			return self::legacyMerge( $existing, self::getStoredUrls() );
		}

		return self::mergeUrls( $existing, self::getStoredUrls(), function_exists( 'home_url' ) ? (string) home_url( '/' ) : '' );
	}

	/**
	 * Whether the stored ordering was built with a different weight map.
	 *
	 * The hash is computed once at registration and compared here, so the
	 * purge path pays one string comparison — not a filter call and not a
	 * hash. Recording *what the config was* is cheaper than tracking *when it
	 * changed*.
	 *
	 * @param array{weights_hash: string} $stored
	 * @return bool
	 */
	private static function weightsChanged( array $stored ): bool {
		return self::$priority_enabled && '' !== self::$weights_hash && $stored['weights_hash'] !== self::$weights_hash;
	}

	/**
	 * Current last-known-good sitemap URL list, for inspection/testing.
	 * Never triggers a fetch or a refresh.
	 *
	 * Tolerant of the legacy `{urls, fetched_at}` payload as well as the
	 * current one — see {@see PriorityStore::readUrls()} for why.
	 *
	 * @return array<int, string>
	 */
	public static function getStoredUrls(): array {
		return PriorityStore::readUrls();
	}

	/**
	 * @param array{urls: array<int, string>, signals: array<string, mixed>, fetched_at: int, weights_hash: string, revision: int} $data
	 * @return bool
	 */
	private static function isStale( array $data ): bool {
		return ( time() - $data['fetched_at'] ) > self::CACHE_TTL;
	}

	/**
	 * Schedule the deferred refresh job, guarded by a short lock so
	 * concurrent purges (or repeated calls within the lock TTL) don't queue
	 * duplicate jobs. A pending job already on the cron schedule short-circuits
	 * without even attempting the lock.
	 *
	 * @return void
	 */
	private static function maybeScheduleRefresh(): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		if ( ! self::acquireRefreshLock() ) {
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK );
	}

	/**
	 * Best-effort refresh lock via a transient. Not perfectly atomic under
	 * concurrent requests without an external object cache, but sufficient to
	 * collapse the common case (several purges in quick succession) down to
	 * one scheduled job, and its TTL doubles as a retry backoff after a
	 * failed refresh.
	 *
	 * @return bool True if the lock was acquired (caller should proceed).
	 */
	private static function acquireRefreshLock(): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return false;
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		return true;
	}

	/**
	 * @return void
	 */
	private static function releaseRefreshLock(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Deferred-refresh cron callback. A failed or empty crawl never overwrites
	 * the last known good list — stale data always beats no data.
	 *
	 * The body wraps in try/finally because it now does far more than fetch
	 * and store: any throw between here and the release used to hold the lock
	 * until its TTL expired, which silently blocked retries for a minute.
	 *
	 * @return void
	 */
	public static function runRefresh(): void {
		try {
			$revision = PriorityStore::revision();
			$records  = self::fetchSitemapRecords();

			// No early return on an empty sitemap: enrichRecords() adds the
			// curated entries, and a project that lists them explicitly should
			// still get them warmed when the sitemap is missing or broken --
			// which is exactly when it matters most.
			$records = self::enrichRecords( $records );

			if ( array() === $records ) {
				return;
			}
			$weights = self::weights();
			$built   = self::buildOrderedUrls( $records, $weights, time(), self::maxUrls() );

			PriorityStore::write( $built['urls'], $built['signals'], Scorer::weightsHash( $weights ), $revision );

			if ( self::$tail_enabled ) {
				TailStore::writeTail( $built['tail'] );

				// Cold-start rescue: the purge scheduled a tick before this
				// refresh had written anything, so that tick found an empty
				// tail and ended the chain. Nothing else would ever restart it.
				if ( array() !== $built['tail'] ) {
					self::scheduleTailTick();
				}
			}
		} catch ( \Throwable $e ) {
			// Best-effort by contract: a sitemap outage must never surface as
			// a fatal in a cron job.
		} finally {
			self::releaseRefreshLock();
		}
	}

	/**
	 * Schedule the next tail tick, unless one is already pending or running.
	 *
	 * Called by the purge and by the refresh — never by the tick itself.
	 * `as_next_scheduled_action()` reports a RUNNING action as scheduled, so a
	 * tick using this to decide about its own successor would see itself and
	 * end the chain after one run.
	 *
	 * @return void
	 */
	public static function scheduleTailTick(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( as_next_scheduled_action( self::TAIL_HOOK ) ) {
			return;
		}

		as_schedule_single_action( time() + self::TAIL_INTERVAL, self::TAIL_HOOK );
	}

	/**
	 * Purge handler: start the tail over and kick the chain.
	 *
	 * Priority 1000 so Breeze has already filled its own queue at 999 — the
	 * tick's brake can then see it and stand aside.
	 *
	 * @return void
	 */
	public static function onPurgeScheduleTail(): void {
		if ( ! self::isEnabled() || ! self::$tail_enabled ) {
			return;
		}

		TailStore::resetCursor();
		self::scheduleTailTick();
	}

	/**
	 * One tail tick: dispatch a batch, advance, schedule the successor.
	 *
	 * A skipped tick (brake engaged) still schedules its successor — only an
	 * exhausted tail ends the chain, never a busy Breeze.
	 *
	 * @return void
	 */
	public static function runTailTick(): void {
		if ( ! self::isEnabled() || ! self::$tail_enabled ) {
			return;
		}

		if ( self::breezeIsWarming() ) {
			self::scheduleNextTailTick();

			return;
		}

		$tail = TailStore::readTail();
		if ( array() === $tail['urls'] ) {
			return;
		}

		$cursor = TailStore::readCursor();
		$index  = $cursor['hash'] === $tail['hash'] ? $cursor['index'] : 0;

		if ( $index >= count( $tail['urls'] ) ) {
			return;
		}

		$batch = TailPlanner::nextBatch( $tail['urls'], $index, self::$tail_batch );
		if ( array() === $batch ) {
			return;
		}

		foreach ( $batch as $url ) {
			// Breeze's own primitive: it carries the local-URL check, the
			// circuit breaker and the fire-and-forget fetch. It returns void,
			// so the cursor counts dispatches, not confirmed warms.
			\Breeze_Cache_Preloader::preload_url( $url );
		}

		TailStore::advanceCursor( $cursor, $index + count( $batch ), $tail['hash'] );

		self::scheduleNextTailTick();
	}

	/**
	 * Whether Breeze is draining its own preload queue right now.
	 *
	 * Reads a foreign option, read-only and tolerantly: anything other than a
	 * non-empty array counts as idle. Breeze splices the batch off the queue
	 * BEFORE dispatching it, so the final batch leaves this looking idle while
	 * three URLs are still in flight — about a second at the end of a run.
	 * Accepted: closing that window would mean guessing from timestamps.
	 *
	 * @return bool
	 */
	private static function breezeIsWarming(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$queue = get_option( 'breeze_preload_queue', array() );

		return is_array( $queue ) && array() !== $queue;
	}

	/**
	 * Schedule the successor directly.
	 *
	 * Deliberately NOT scheduleTailTick(): that one asks
	 * `as_next_scheduled_action()`, which reports a RUNNING action as
	 * scheduled — the tick would see itself and end its own chain.
	 *
	 * @return void
	 */
	private static function scheduleNextTailTick(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Deliberately NOT guarded by as_next_scheduled_action(), and not
		// scheduled with $unique either, even though scheduleTailTick() uses
		// the guard a few lines up. The difference is where each one is called
		// from: that one runs on a purge, outside any tick; this one runs
		// INSIDE the tick whose own action is still on the schedule.
		//
		// Measured against a live Action Scheduler rather than assumed. With
		// the action marked in-progress, as_next_scheduled_action() still
		// answers true and as_schedule_single_action( …, $unique = true )
		// refuses and returns 0. So either form would make a tick decline to
		// schedule its own successor, and the drain would stop after one batch
		// -- a silent stop, since nothing reports a chain that simply ends.
		//
		// The cost of leaving it unguarded is that two overlapping ticks start
		// two chains, which then run in parallel at double the intended pace.
		// Closing that needs a successor key that can exclude the running
		// action; it is not closed here, and the asymmetry above is the reason
		// the obvious fix is worse than the defect.
		as_schedule_single_action( time() + self::TAIL_INTERVAL, self::TAIL_HOOK );
	}

	/**
	 * Score, budget and order a set of sitemap records.
	 *
	 * Split out from {@see self::runRefresh()} so the ordering rules can be
	 * tested without mocking the network: everything here is deterministic
	 * given its arguments.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @param array<string, mixed>             $weights
	 * @param int                              $now
	 * @param int                              $max
	 * @return array{urls: array<int, string>, signals: array<string, mixed>, tail: array<int, string>}
	 */
	public static function buildOrderedUrls( array $records, array $weights, int $now, int $max ): array {
		$scored = Scorer::scoreAll( $records, $weights, $now );

		// Sort the FULL set before splitting. Everything upstream preserves
		// input order — scoreAll() only attaches scores, and LanguageQuota
		// selects without reordering — so a tail taken from them directly
		// would come out in sitemap order, which is precisely the ordering
		// this module exists to replace.
		$sorted = Scorer::sort( $scored );

		$kept    = LanguageQuota::apply( $sorted, $max );
		$ordered = Scorer::sort( $kept );

		$signals = array();
		foreach ( $ordered as $record ) {
			$signals[ (string) $record['key'] ] = array(
				'lastmod'    => $record['lastmod'],
				'type'       => (string) $record['type'],
				'lang'       => (string) $record['lang'],
				'menu'       => (bool) $record['menu'],
				'front_page' => (bool) $record['front_page'],
				'manual'     => (bool) $record['manual'],
				'url'        => (string) $record['url'],
			);
		}

		$urls = array_column( $ordered, 'url' );

		return array(
			'urls'    => $urls,
			'signals' => $signals,
			'tail'    => TailPlanner::split( $sorted, $urls, self::maxTailUrls() ),
		);
	}

	/**
	 * Safety cap on stored tail URLs.
	 */
	private static function maxTailUrls(): int {
		$max = function_exists( 'apply_filters' )
			? apply_filters( 'timberkit_warmup_tail_max_urls', TailPlanner::DEFAULT_MAX_TAIL_URLS )
			: TailPlanner::DEFAULT_MAX_TAIL_URLS;

		return is_numeric( $max ) ? max( 0, (int) $max ) : TailPlanner::DEFAULT_MAX_TAIL_URLS;
	}

	/**
	 * Attach the signals a sitemap cannot carry, and resolve each record's
	 * language.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	private static function enrichRecords( array $records ): array {
		$menu       = SignalCollector::menuKeys();
		$frontPages = SignalCollector::frontPages();
		// Breeze's own row plus the project's committed list. Either may be
		// empty; a key present in both is one key.
		$manual     = SignalCollector::manualKeys() + CuratedUrls::filterReachable( CuratedUrls::keys( self::$curated ) );
		$languages  = SignalCollector::activeLanguages();

		// A curated entry that the sitemap does not carry has to become a
		// record, not merely a flag on one. Flagging alone would mean the whole
		// point of a curated list -- naming a page the sitemap ranks badly or
		// omits entirely -- silently did nothing.
		$records = self::appendMissingManual( $records, $manual );

		foreach ( $records as $i => $record ) {
			$key = (string) $record['key'];

			$records[ $i ]['menu']       = isset( $menu[ $key ] );
			$records[ $i ]['front_page'] = isset( $frontPages[ $key ] );
			$records[ $i ]['manual']     = isset( $manual[ $key ] );
			$records[ $i ]['lang']       = isset( $frontPages[ $key ] ) && '' !== $frontPages[ $key ]
				? $frontPages[ $key ]
				: SourceNaming::deriveLanguage(
					(string) $record['url'],
					(string) ( $record['source'] ?? '' ),
					$languages['codes'],
					$languages['default']
				);
		}

		return $records;
	}

	/**
	 * Add records for curated keys the sitemap did not supply.
	 *
	 * `lastmod` is null on purpose: nothing is known about when the page
	 * changed, and inventing a date would hand it a freshness score it has not
	 * earned. `type` is empty for a different reason -- the post type is
	 * knowable for anything that resolved to an ID, it is simply not carried
	 * this far yet -- see #148.
	 *
	 * Both gaps cost score, and the arithmetic is worth stating rather than
	 * assuming. A curated entry earns the `manual` weight (800) plus whatever
	 * `menu` and `front_page` the enrichment below finds, and nothing else. An
	 * ordinary menu page edited yesterday earns menu (500) plus freshness
	 * (300) -- a tie on the default weights, and a win for the sitemap page as
	 * soon as a project sets any `types` weight. So a curated entry does NOT
	 * reliably outrank the sitemap; it is competitive with it. Entries pushed
	 * past the URL cap are picked up by the tail drain, which is why the tail
	 * must never exclude them.
	 *
	 * @param array<int, array<string, mixed>> $records Records from the sitemap.
	 * @param array<string, bool>              $manual  Curated + Breeze keys. Only
	 *                                                  the keys are read.
	 * @return array<int, array<string, mixed>>
	 */
	private static function appendMissingManual( array $records, array $manual ): array {
		if ( array() === $manual ) {
			return $records;
		}

		$known = array();
		foreach ( $records as $record ) {
			$known[ (string) $record['key'] ] = true;
		}

		foreach ( $manual as $key => $flag ) {
			if ( isset( $known[ $key ] ) ) {
				continue;
			}

			$records[] = array(
				'url'     => (string) $key,
				'key'     => (string) $key,
				'lastmod' => null,
				'type'    => '',
				'source'  => 'curated',
			);
		}

		return $records;
	}

	/**
	 * Effective weight map: the defaults, filterable per project.
	 *
	 * The `timberkit_warmup_priority_weights` filter must be a pure function
	 * of its input: its result is fingerprinted and that fingerprint is
	 * compared across requests to decide whether the stored ordering is
	 * stale. A callback that varies between requests (reading mutable state
	 * such as an option that changes) will schedule a refresh on every purge.
	 *
	 * @return array<string, mixed>
	 */
	public static function weights(): array {
		$weights = self::$weights ?? Scorer::DEFAULT_WEIGHTS;

		$filtered = function_exists( 'apply_filters' )
			? apply_filters( 'timberkit_warmup_priority_weights', $weights )
			: $weights;

		return is_array( $filtered ) ? $filtered : $weights;
	}

	/**
	 * @return int
	 */
	private static function maxUrls(): int {
		$max = function_exists( 'apply_filters' )
			? apply_filters( 'timberkit_warmup_sitemap_max_urls', self::DEFAULT_MAX_URLS )
			: self::DEFAULT_MAX_URLS;

		return is_numeric( $max ) ? max( 0, (int) $max ) : self::DEFAULT_MAX_URLS;
	}

	/**
	 * Structured sitemap crawl. Never throws — any failure degrades to an
	 * empty array. Only ever called from the deferred refresh job.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetchSitemapRecords(): array {
		try {
			$root = self::resolveSitemapRootUrl();
			if ( '' === $root ) {
				return array();
			}

			$seen    = array();
			$records = self::fetchAndParseSitemap( $root, 0, $seen );

			return self::dedupeByKey( $records );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Backwards-compatible string view of {@see self::fetchSitemapRecords()}.
	 *
	 * Entries are deduplicated by canonical URL form, not by exact string —
	 * two spellings of the same page (differing only in trailing slash,
	 * scheme case, default port, or fragment) collapse to one, and the
	 * first-seen spelling wins. Warming the same page twice wastes a slot of
	 * the URL cap, and the canonical key is what joins this list with the
	 * signals coming from menus and Breeze's own preload list.
	 *
	 * @return array<int, string>
	 */
	public static function fetchSitemapUrls(): array {
		return array_column( self::fetchSitemapRecords(), 'url' );
	}

	/**
	 * First-seen-wins: when two records share a canonical key, later ones
	 * are dropped rather than overwriting the first.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupeByKey( array $records ): array {
		$seen   = array();
		$result = array();

		foreach ( $records as $record ) {
			$key = (string) $record['key'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = $record;
		}

		return $result;
	}

	/**
	 * Sitemap root path per provider, in detection order.
	 *
	 * The order is the contract, not an accident of layout: a site can carry
	 * more than one SEO plugin, and the first provider that answers wins.
	 * AIOSEO stays first so an existing AIOSEO site resolves exactly the path
	 * it resolved before this map existed. `core` is last and always matches.
	 *
	 * @var array<string, string>
	 */
	private const SITEMAP_PROVIDERS = array(
		'aioseo' => '/sitemap.xml',
		'yoast'  => '/sitemap_index.xml',
		'core'   => '/wp-sitemap.xml',
	);

	/**
	 * Sitemap root URL for whichever provider this site runs.
	 *
	 * Yoast is named explicitly rather than left to the core fallback. Yoast
	 * redirects `/wp-sitemap.xml` to its own index with a 301, and
	 * {@see self::fetchBody()} sends `redirection => 0` on purpose — following
	 * a redirect is the SSRF surface this module closes. So on a Yoast site the
	 * core path does not degrade to a slower answer, it degrades to no answer:
	 * the response code lands outside 200-299 and the body is discarded. The
	 * refresh then stores an empty list and says nothing, because an empty
	 * `fetchSitemapRecords()` is a normal return value and `runRefresh()`
	 * swallows throwables by contract.
	 *
	 * The filter is the escape hatch for the provider this list does not know
	 * yet, and for the site that serves its sitemap from a non-default path.
	 * Returning a non-string, or a URL off this site's own host, falls back to
	 * the detected path — {@see self::isFetchableSameHostUrl()} would reject it
	 * on fetch anyway, and failing here says so while the caller can still act.
	 *
	 * @return string Empty string when `home_url()` is unavailable.
	 */
	private static function resolveSitemapRootUrl(): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}

		$provider = self::detectSitemapProvider();
		$resolved = (string) home_url( self::SITEMAP_PROVIDERS[ $provider ] );

		if ( ! function_exists( 'apply_filters' ) ) {
			return $resolved;
		}

		$filtered = apply_filters( 'timberkit_warmup_sitemap_url', $resolved, $provider );

		return ( is_string( $filtered ) && self::isFetchableSameHostUrl( $filtered ) )
			? $filtered
			: $resolved;
	}

	/**
	 * Which sitemap provider is active, without a hard dependency on any of
	 * them.
	 *
	 * Each plugin is detected by a symbol it defines, never by reading the
	 * active-plugin list: a must-use load, a renamed directory or a bundled
	 * copy all keep the symbol and lose the list entry.
	 *
	 * @return string A key of {@see self::SITEMAP_PROVIDERS}; `core` when no
	 *                SEO plugin answers.
	 */
	private static function detectSitemapProvider(): string {
		if ( 'aioseo' === Plugin::active() ) {
			return 'aioseo';
		}

		if ( self::isYoastSitemapActive() ) {
			return 'yoast';
		}

		return 'core';
	}

	/**
	 * Whether Yoast is loaded *and* serving its own sitemap.
	 *
	 * The symbol alone is not enough. Yoast carries a switch that turns its
	 * XML sitemap off, and when it is off WordPress core serves
	 * `/wp-sitemap.xml` again -- so a site in that state answers on the core
	 * path and 404s on `/sitemap_index.xml`. Detecting on the symbol alone
	 * would send exactly those sites to the address that does not exist,
	 * breaking a configuration that worked before Yoast was recognised here.
	 *
	 * A symbol proves the plugin is loaded. It does not prove the plugin does
	 * the thing being asked about.
	 *
	 * The option is read directly rather than through `WPSEO_Options`, to keep
	 * this a soft dependency. An absent or unreadable value counts as on,
	 * which is Yoast's own default -- decided by {@see Plugin::supportsYoastSitemap()}.
	 *
	 * @return bool
	 */
	private static function isYoastSitemapActive(): bool {
		if ( ! defined( 'WPSEO_VERSION' ) && ! class_exists( '\WPSEO_Sitemaps' ) ) {
			return false;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}

		$options = get_option( 'wpseo' );

		return Plugin::supportsYoastSitemap( is_array( $options ) ? $options : null );
	}

	/**
	 * Fetch one sitemap document and recursively follow index entries.
	 *
	 * Every URL passed here — the root sitemap and every subsequent
	 * `<sitemap><loc>` entry — is validated as an absolute http(s) URL on the
	 * site's own host before it is fetched (SSRF guard: an index entry
	 * pointing off-host is silently skipped rather than dereferenced).
	 *
	 * @param string               $url   Sitemap (or sub-sitemap) URL.
	 * @param int                  $depth Current recursion depth.
	 * @param array<string, bool>  $seen  URLs already fetched, by reference — guards against index cycles.
	 * @return array<int, array<string, mixed>> Records collected from `<url><loc>` entries.
	 */
	private static function fetchAndParseSitemap( string $url, int $depth, array &$seen ): array {
		if ( ! self::isFetchableSameHostUrl( $url ) ) {
			return array();
		}

		if ( isset( $seen[ $url ] ) ) {
			return array();
		}
		$seen[ $url ] = true;

		$body = self::fetchBody( $url );
		if ( '' === $body ) {
			return array();
		}

		$body = self::maybeDecompress( $body, $url );
		if ( '' === $body ) {
			return array();
		}

		$xml = self::parseXml( $body );
		if ( null === $xml ) {
			return array();
		}

		$root_name = $xml->getName();

		if ( 'sitemapindex' === $root_name ) {
			return self::collectFromIndex( $xml, $depth, $seen );
		}

		if ( 'urlset' === $root_name ) {
			return self::collectFromUrlset( $xml, $url );
		}

		return array();
	}

	/**
	 * Follow a `<sitemapindex>` document's `<sitemap><loc>` entries.
	 *
	 * @param \SimpleXMLElement   $xml   Parsed `<sitemapindex>` root.
	 * @param int                 $depth Current recursion depth.
	 * @param array<string, bool> $seen  URLs already fetched, by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collectFromIndex( \SimpleXMLElement $xml, int $depth, array &$seen ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return array();
		}

		$records = array();
		$count   = 0;

		foreach ( $xml->sitemap as $sitemap ) {
			if ( $count >= self::MAX_SUBSITEMAPS ) {
				break;
			}

			$loc = isset( $sitemap->loc ) ? trim( (string) $sitemap->loc ) : '';
			if ( '' === $loc ) {
				continue;
			}

			++$count;
			// fetchAndParseSitemap() re-validates $loc as an absolute
			// same-host http(s) URL before fetching it — this counts a
			// rejected off-host entry against MAX_SUBSITEMAPS too, which is
			// fine: it's still one <sitemap> entry consumed either way.
			$records = array( ...$records, ...self::fetchAndParseSitemap( $loc, $depth + 1, $seen ) );
		}

		return $records;
	}

	/**
	 * Collect `<url><loc>` entries from a `<urlset>` document, keeping only
	 * same-host URLs.
	 *
	 * @param \SimpleXMLElement $xml       Parsed `<urlset>` root.
	 * @param string            $sourceUrl The document this urlset came from.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collectFromUrlset( \SimpleXMLElement $xml, string $sourceUrl ): array {
		$type    = SourceNaming::derivePostType( $sourceUrl );
		$records = array();

		foreach ( $xml->url as $entry ) {
			$loc = isset( $entry->loc ) ? trim( (string) $entry->loc ) : '';
			if ( '' === $loc || ! self::isFetchableSameHostUrl( $loc ) ) {
				continue;
			}

			$records[] = array(
				'url'        => $loc,
				'key'        => UrlCanonicalizer::canonicalize( $loc ),
				'lastmod'    => self::parseLastmod( isset( $entry->lastmod ) ? trim( (string) $entry->lastmod ) : '' ),
				'type'       => $type,
				'source'     => $sourceUrl,
				'lang'       => '',
				'menu'       => false,
				'front_page' => false,
				'manual'     => false,
			);
		}

		return $records;
	}

	/**
	 * `<lastmod>` to a unix timestamp. Anything unparseable is null rather
	 * than "now" — a broken timestamp must not read as fresh content.
	 *
	 * @param string $raw
	 * @return int|null
	 */
	private static function parseLastmod( string $raw ): ?int {
		if ( '' === $raw ) {
			return null;
		}

		$ts = strtotime( $raw );

		return false === $ts ? null : $ts;
	}

	/**
	 * Remote-fetch a URL's response body. Any non-2xx response or transport
	 * error resolves to an empty string. Redirects are disabled
	 * (`redirection => 0`) — a same-host sitemap URL could still 30x to an
	 * external host, and a followed redirect would bypass the host check
	 * that only runs on the request URL, not the final one.
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
				'timeout'     => self::FETCH_TIMEOUT,
				'sslverify'   => true,
				'redirection' => 0,
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
	 * Transparently gzip-decompress a fetched body when it looks compressed
	 * (gzip magic bytes, or a `.gz`-suffixed URL) — AIOSEO and core both may
	 * serve `sitemap.xml.gz` sub-sitemaps. Bounded by
	 * {@see self::MAX_GZIP_BYTES} on the compressed size, checked before
	 * decompression ever runs.
	 *
	 * @param string $body Raw fetched body.
	 * @param string $url  Source URL, used only for the `.gz` suffix check.
	 * @return string Decompressed body, the original body when not gzipped,
	 *                or an empty string when decompression is refused/fails.
	 */
	private static function maybeDecompress( string $body, string $url ): string {
		if ( '' === $body ) {
			return '';
		}

		$looks_gzip = str_starts_with( $body, "\x1f\x8b" ) || str_ends_with( strtolower( $url ), '.gz' );
		if ( ! $looks_gzip ) {
			return $body;
		}

		if ( strlen( $body ) > self::MAX_GZIP_BYTES ) {
			return '';
		}

		if ( ! function_exists( 'gzdecode' ) ) {
			return '';
		}

		$decoded = @gzdecode( $body );

		return is_string( $decoded ) ? $decoded : '';
	}

	/**
	 * Parse an XML string, tolerating malformed input.
	 *
	 * `LIBXML_NONET` blocks any network-based entity resolution as
	 * defense-in-depth; `LIBXML_NOENT` is deliberately never passed (it is
	 * the flag that turns on internal entity *substitution* — the other half
	 * of a classic XXE payload — so leaving it off means declared entities
	 * are never expanded into the parsed tree in the first place).
	 *
	 * @param string $body
	 * @return \SimpleXMLElement|null
	 */
	private static function parseXml( string $body ): ?\SimpleXMLElement {
		$internal_errors = libxml_use_internal_errors( true );

		try {
			$xml = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		} catch ( \Throwable $e ) {
			$xml = false;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );

		return ( $xml instanceof \SimpleXMLElement ) ? $xml : null;
	}

	/**
	 * Whether a URL is an absolute http(s) URL on the site's own host —
	 * sitemap entries (both sub-sitemap `<loc>` and page `<loc>`) are always
	 * expected to be local; this is what stops a sitemap index (or a
	 * compromised/misconfigured one) from making this class fetch or surface
	 * an arbitrary external or non-http(s) (e.g. `file://`, `gopher://`) URL.
	 *
	 * Uses PHP's native `parse_url()` rather than `wp_parse_url()` — the two
	 * behave identically for a plain scheme/host lookup, and the native
	 * function keeps this check dependency-free (no WordPress bootstrap needed).
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function isFetchableSameHostUrl( string $url ): bool {
		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		$home      = (string) home_url();
		$home_host = strtolower( (string) ( parse_url( $home, PHP_URL_HOST ) ?: '' ) );

		if ( '' === $home_host || strtolower( $parts['host'] ) !== $home_host ) {
			return false;
		}

		// Host alone is not the same origin. Without this, a URL on the site's
		// own hostname but a different port passes the guard and is fetched --
		// an internal service bound to :8080 or :9200 on that host becomes
		// reachable through a sitemap index entry or a filter callback. Ports
		// are normalised by scheme first, so one origin written two ways
		// (`https://site` and `https://site:443`) still compares equal.
		$home_port = parse_url( $home, PHP_URL_PORT );
		$url_port  = self::effectivePort( $scheme, isset( $parts['port'] ) ? (int) $parts['port'] : null );
		$site_port = self::effectivePort(
			strtolower( (string) ( parse_url( $home, PHP_URL_SCHEME ) ?: '' ) ),
			is_int( $home_port ) ? $home_port : null
		);

		return $url_port === $site_port;
	}

	/**
	 * Port a URL actually connects on, with the scheme's default filled in.
	 *
	 * @param string   $scheme Lowercased URL scheme.
	 * @param int|null $port   Explicit port, or null when the URL omits one.
	 * @return int 0 when no port is given and the scheme is neither http nor https.
	 */
	private static function effectivePort( string $scheme, ?int $port ): int {
		if ( null !== $port ) {
			return $port;
		}

		return match ( $scheme ) {
			'https' => 443,
			'http'  => 80,
			default => 0,
		};
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
	private static function legacyMerge( array $existing, array $sitemap_urls ): array {
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
	 * Positional merge of Breeze's own list with our ordered one.
	 *
	 * Sorting is not allowed here — this runs synchronously inside the purge
	 * request, so the cost must not grow with the size of the sitemap. The
	 * rule is therefore positional:
	 *
	 *   homepage, then Breeze entries we cannot score, then our ordering.
	 *
	 * Entries Breeze supplied that *are* in the sitemap already carry the
	 * `manual` weight and sorted themselves; the ones that are not have no
	 * signals at all, so they go right behind the homepage, matching
	 * `manual` being the second highest weight.
	 *
	 * Membership is tested on canonical keys. Breeze builds the homepage with
	 * `trailingslashit()` while a sitemap may emit it bare — on raw strings
	 * those are two URLs and the homepage would be warmed twice.
	 *
	 * @param array<int, string> $existing Breeze's own preload URL list.
	 * @param array<int, string> $ordered  Our stored, already ordered list.
	 * @param string             $homeUrl  Current language homepage.
	 * @return array<int, string>
	 */
	public static function mergeUrls( array $existing, array $ordered, string $homeUrl ): array {
		$homeKey = UrlCanonicalizer::canonicalize( $homeUrl );

		// This runs synchronously inside the purge request, and $ordered can
		// hold as many URLs as the store's cap allows — up to 1000 on one
		// site in this fleet. Canonicalize each distinct input URL exactly
		// once here and read the memoized key everywhere below, instead of
		// re-canonicalizing on every membership check and dedup lookup.
		$existingKeyed = array();
		foreach ( $existing as $url ) {
			$existingKeyed[] = array( $url, UrlCanonicalizer::canonicalize( $url ) );
		}

		$orderedKeyed = array();
		$orderedMap   = array();
		foreach ( $ordered as $url ) {
			$key                = UrlCanonicalizer::canonicalize( $url );
			$orderedKeyed[]     = array( $url, $key );
			$orderedMap[ $key ] = true;
		}

		// When a URL appears in both lists, Breeze's own spelling wins — the
		// sitemap only supplies ordering, and Breeze must warm exactly what
		// it was already going to warm.
		$existingByKey = array();
		foreach ( $existingKeyed as [ $url, $key ] ) {
			if ( ! isset( $existingByKey[ $key ] ) ) {
				$existingByKey[ $key ] = $url;
			}
		}

		$result = array();
		$seen   = array();

		$push = static function ( string $url, string $key ) use ( &$result, &$seen ): void {
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$result[]     = $url;
		};

		foreach ( $existingKeyed as [ $url, $key ] ) {
			if ( $key === $homeKey ) {
				$push( $url, $key );
				break;
			}
		}

		foreach ( $existingKeyed as [ $url, $key ] ) {
			if ( ! isset( $orderedMap[ $key ] ) ) {
				$push( $url, $key );
			}
		}

		foreach ( $orderedKeyed as [ $url, $key ] ) {
			$push( $existingByKey[ $key ] ?? $url, $key );
		}

		return $result;
	}

	/**
	 * Reset internal state so tests can re-register the module.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$registered       = false;
		self::$priority_enabled = false;
		self::$weights_hash     = '';
		self::$weights          = null;
		self::$tail_enabled     = false;
		self::$tail_batch       = 100;
	}
}
