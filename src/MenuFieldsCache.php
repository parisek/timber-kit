<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * The page-independent half of a formatted menu, across requests.
 *
 * A menu's ACF fields are the same on every URL; `is_active` and
 * `in_active_trail` are not. Only the first half is stored here, which is what
 * keeps this to one entry per menu instead of one per page — and what keeps the
 * highlighted item right by construction rather than by a key that remembers to
 * carry the URL.
 *
 * Why the proof is shaped this way, and the two designs that failed before it:
 * `docs/adr/0007-prove-cache-purity-from-inputs.md`.
 *
 * **It stores what it can prove is storable, and nothing else.** Formatting a
 * field can run `do_shortcode()` and any `field_formatter_{$type}` filter, so
 * the rendered result is not always a function of the stored value. It can also
 * return objects that have no business in a shared cache. Both are detected
 * rather than assumed, and either one makes the walk store nothing at all —
 * a menu that cannot be cached correctly is a menu that is not cached.
 */
final class MenuFieldsCache {

	private const GROUP = 'timber-kit-menu';

	/**
	 * Reserved slot for the menu's own fields among integer item ids.
	 */
	private const MENU_SLOT = '#menu';

	/**
	 * Default lifetime, in seconds.
	 *
	 * The key already carries a content version, so an entry goes unreachable
	 * on the next content change rather than stale. The lifetime is not there
	 * to bound staleness — it is there to bound **accumulation**: every content
	 * change orphans the previous generation, and under a non-evicting cache
	 * policy those generations pile up until writes start failing.
	 */
	private const DEFAULT_TTL = 43200;

	/**
	 * Open depth per menu id. A menu is written only when its outermost walk
	 * finishes.
	 *
	 * @var array<int, int>
	 */
	private static array $depth = [];

	/** @var array<int, array<int|string, mixed>> */
	private static array $payload = [];

	/** @var array<int, bool> */
	private static array $dirty = [];

	/** @var array<int, bool> */
	private static array $unstorable = [];

	/**
	 * Whether the walk of this menu may store anything at all.
	 *
	 * Recorded at `open()` rather than asked again per slot: `isCacheable()`
	 * scans the filter registry, and the answer must not change halfway through
	 * one menu — a walk that started storable and finished unstorable would
	 * write a payload with a hole in it.
	 *
	 * @var array<int, bool>
	 */
	private static array $active = [];

	/**
	 * What was decided for each menu this request, and why.
	 *
	 * The reason was already computed and thrown away, which made "why is my
	 * menu not cached" a source-reading exercise. Keeping it costs one string
	 * per menu and turns the question into a call.
	 *
	 * @var array<int, string>
	 */
	private static array $decisions = [];

	/**
	 * The cache decision per menu for this request, keyed by menu term id.
	 *
	 * Values: 'cache' when it was cached, 'filtered-off' when a project said
	 * no, else the objection — 'no-object-cache', 'formatter-filter',
	 * 'untrusted-value-load'.
	 *
	 * @return array<int, string>
	 */
	public static function decisions(): array {
		return self::$decisions;
	}

	/**
	 * Begin a walk of one menu, replaying a stored payload if there is one.
	 *
	 * Re-entrant on purpose. Formatting a field can reach code that asks for the
	 * same menu again, and an inner call that reset the outer walk's state would
	 * make the outer walk silently record nothing. Depth is counted; only the
	 * outermost open reads, and only the outermost close writes.
	 *
	 * @param int $menu_id Menu term id.
	 * @return void
	 */
	public static function open( int $menu_id ): void {
		$depth = ( self::$depth[ $menu_id ] ?? 0 ) + 1;
		self::$depth[ $menu_id ] = $depth;

		if ( $depth > 1 ) {
			return;
		}

		self::$payload[ $menu_id ]    = [];
		self::$dirty[ $menu_id ]      = false;
		self::$unstorable[ $menu_id ] = false;
		self::$active[ $menu_id ]     = self::isCacheable( $menu_id );

		if ( ! self::$active[ $menu_id ] ) {
			return;
		}

		$stored = wp_cache_get( self::key( $menu_id ), self::GROUP );
		if ( is_array( $stored ) ) {
			self::$payload[ $menu_id ] = $stored;
		}
	}

	/**
	 * End a walk, storing the payload if the outermost one produced something
	 * new and provably storable.
	 *
	 * Call it from a `finally`. An exception mid-walk must not leave the state
	 * resident, and must not store a payload whose walk never finished.
	 *
	 * @param int  $menu_id  Menu term id.
	 * @param bool $complete Whether the walk it closes ran to the end.
	 * @return void
	 */
	public static function close( int $menu_id, bool $complete = true ): void {
		$depth = ( self::$depth[ $menu_id ] ?? 1 ) - 1;

		if ( $depth > 0 ) {
			self::$depth[ $menu_id ] = $depth;
			return;
		}

		$dirty      = self::$dirty[ $menu_id ] ?? false;
		$unstorable = self::$unstorable[ $menu_id ] ?? true;
		$payload    = self::$payload[ $menu_id ] ?? [];

		$active = self::$active[ $menu_id ] ?? false;

		unset(
			self::$depth[ $menu_id ],
			self::$payload[ $menu_id ],
			self::$dirty[ $menu_id ],
			self::$unstorable[ $menu_id ],
			self::$active[ $menu_id ]
		);

		if ( ! $active || ! $complete || ! $dirty || $unstorable || [] === $payload ) {
			return;
		}

		wp_cache_set( self::key( $menu_id ), $payload, self::GROUP, self::ttl() );
	}

	/**
	 * One menu item's formatted fields, replayed or built.
	 *
	 * @param int      $menu_id Menu term id.
	 * @param int      $item_id Menu item post id.
	 * @param callable $build   Produces the fields when they are not stored.
	 * @return array<string, mixed>
	 */
	public static function itemFields( int $menu_id, int $item_id, callable $build ): array {
		return self::remember( $menu_id, $item_id, $build );
	}

	/**
	 * The menu's own formatted fields, replayed or built.
	 *
	 * @param int      $menu_id Menu term id.
	 * @param callable $build   Produces the fields when they are not stored.
	 * @return array<string, mixed>
	 */
	public static function menuFields( int $menu_id, callable $build ): array {
		return self::remember( $menu_id, self::MENU_SLOT, $build );
	}

	/**
	 * Delete every stored menu payload, now.
	 *
	 * The key carries a content version, so ordinary staleness needs no flush —
	 * a saved post makes the old key unreachable by itself. This is for the case
	 * that is not ordinary: an entry is believed wrong and has to be gone before
	 * the next content change, not after it.
	 *
	 * Without it the only lever is `wp_cache_flush()`, which empties the whole
	 * object cache — every group, and on shared infrastructure every site.
	 * {@see BlockRenderer} already takes this narrower route; so does this.
	 *
	 * A backend with no `flush_group` support leaves the entries in place, and
	 * says so by returning false rather than by looking successful.
	 *
	 * @return bool Whether the group was flushed.
	 */
	public static function flushStored(): bool {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return false;
		}

		if ( ! function_exists( 'wp_cache_supports' ) || ! wp_cache_supports( 'flush_group' ) ) {
			return false;
		}

		if ( ! function_exists( 'wp_cache_flush_group' ) ) {
			return false;
		}

		return (bool) wp_cache_flush_group( self::GROUP );
	}

	/**
	 * Drop every in-flight walk.
	 *
	 * Stored entries need no flushing: {@see CacheSignature} keys them by a
	 * content version, so a change makes the old key unreachable rather than
	 * wrong. This resets the per-request assembly state, which a long-running
	 * process and a test both outlive.
	 *
	 * @internal
	 * @return void
	 */
	public static function flush(): void {
		self::$depth      = [];
		self::$payload    = [];
		self::$dirty      = [];
		self::$unstorable = [];
		self::$active     = [];
		self::$decisions  = [];
	}

	/**
	 * @param int        $menu_id Menu term id.
	 * @param int|string $slot    Item id, or the reserved menu slot.
	 * @param callable   $build   Produces the value when it is not stored.
	 * @return array<string, mixed>
	 */
	private static function remember( int $menu_id, int|string $slot, callable $build ): array {
		if ( array_key_exists( $slot, self::$payload[ $menu_id ] ?? [] ) ) {
			return (array) self::$payload[ $menu_id ][ $slot ];
		}

		$before = Helpers::dynamicFormatCount();
		$fields = (array) $build();

		// Raised while the fields were built, by whichever surface could not be
		// cleared in advance: a value that reached `do_shortcode()` carrying a
		// bracket, or a rendered CF7/WPForms embed whose markup holds a nonce.
		// Both are decided from the INPUT, before the dynamic call runs — never
		// from whether its output differed.
		$dynamic = Helpers::dynamicFormatCount() !== $before;

		if ( ! isset( self::$payload[ $menu_id ] ) ) {
			return $fields;
		}

		if ( $dynamic || ! self::isStorable( $fields ) ) {
			// One unstorable slot condemns the whole menu rather than storing a
			// payload with a hole in it: a partial payload would be replayed as
			// complete, and the missing fields would simply not render.
			self::$unstorable[ $menu_id ] = true;
			return $fields;
		}

		self::$payload[ $menu_id ][ $slot ] = $fields;
		self::$dirty[ $menu_id ]            = true;

		return $fields;
	}

	/**
	 * Whether a value survives a round trip through a shared cache unchanged.
	 *
	 * Scalars and arrays of scalars do. Objects may serialize and come back
	 * carrying state that was true when they were stored; resources and
	 * closures do not survive at all. None of them belongs in an entry shared
	 * between visitors.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private static function isStorable( mixed $value ): bool {
		if ( null === $value || is_scalar( $value ) ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! self::isStorable( $item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this menu's payload may cross requests at all.
	 *
	 * @param int $menu_id Menu term id.
	 * @return bool
	 */
	private static function isCacheable( int $menu_id ): bool {
		if ( $menu_id <= 0 || ! function_exists( 'wp_cache_get' ) ) {
			return false;
		}

		// A `field_formatter_{$type}` callback may read anything, and nothing
		// about the stored value says which. There is no static proof of purity
		// to be had, so one registered callback ends it for every menu.
		//
		// It is a default, not a verdict: it goes through the filter with
		// everything else, so a project that knows its own formatter is pure can
		// say so — and a project that does not, does not have to know the rule
		// exists.
		$reason = self::refusalReason();

		/**
		 * Filters whether one menu's fields may be cached across requests.
		 *
		 * The reason is passed so a project can answer the specific objection
		 * rather than the verdict — "that formatter is mine and it is pure" is
		 * a different claim from "cache this whatever you found", and only the
		 * first is one an author can honestly make.
		 *
		 * @param bool   $default Whether the kit would cache it.
		 * @param int    $menu_id Menu term id.
		 * @param string $reason  Why not: '' when the default is to cache, else
		 *                        one of 'no-object-cache', 'formatter-filter',
		 *                        'untrusted-value-load'.
		 */
		$allowed = (bool) apply_filters( 'timber_kit_cache_menu_fields', '' === $reason, $menu_id, $reason );

		self::$decisions[ $menu_id ] = $allowed ? 'cache' : ( '' !== $reason ? $reason : 'filtered-off' );

		return $allowed;
	}

	/**
	 * Why this request would not cache, or '' when it would.
	 *
	 * Ordered cheapest first, and it stops at the first objection — a site
	 * with no object cache is not asked to walk the filter registry.
	 *
	 * @return string
	 */
	private static function refusalReason(): string {
		if ( ! CacheSignature::isAvailable() ) {
			return 'no-object-cache';
		}

		if ( Helpers::hasFieldFormatterFilters() ) {
			return 'formatter-filter';
		}

		// The surface the shortcode check cannot see: by the time a value
		// exists, a callback on these has already run.
		if ( ! Helpers::valueLoadHooksAreTrusted() ) {
			return 'untrusted-value-load';
		}

		return '';
	}

	/**
	 * @return int
	 */
	private static function ttl(): int {
		$ttl = apply_filters( 'timber_kit_menu_fields_ttl', self::DEFAULT_TTL );

		return is_int( $ttl ) && $ttl > 0 ? $ttl : self::DEFAULT_TTL;
	}

	/**
	 * @param int $menu_id Menu term id.
	 * @return string
	 */
	private static function key( int $menu_id ): string {
		// The field-config version rides in the key rather than being flushed on
		// a hook, because the change that needs catching is a file deploy and no
		// hook fires for one.
		return 'menu-fields:' . $menu_id
			. '|' . Helpers::menuFieldConfigVersion( $menu_id )
			// Not decoration. The shortcode check answers "can do_shortcode()
			// change this", which is a question about the value AND the
			// registry. Store the answer without the registry and activating a
			// plugin turns a stored literal into a permanently wrong render,
			// with no content change to make the entry unreachable.
			. '|s' . Helpers::shortcodeTagsVersion()
			. '|' . CacheSignature::shared();
	}
}
