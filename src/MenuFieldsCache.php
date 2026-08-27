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

		if ( ! self::isCacheable( $menu_id ) ) {
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

		unset(
			self::$depth[ $menu_id ],
			self::$payload[ $menu_id ],
			self::$dirty[ $menu_id ],
			self::$unstorable[ $menu_id ]
		);

		if ( ! $complete || ! $dirty || $unstorable || [] === $payload ) {
			return;
		}

		if ( ! self::isCacheable( $menu_id ) ) {
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
		$moved  = Helpers::dynamicFormatCount() !== $before;

		if ( ! isset( self::$payload[ $menu_id ] ) ) {
			return $fields;
		}

		if ( $moved || ! self::isStorable( $fields ) ) {
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

		return (bool) apply_filters(
			'timber_kit_cache_menu_fields',
			CacheSignature::isAvailable(),
			$menu_id
		);
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
		return 'menu-fields:' . $menu_id . '|' . CacheSignature::shared();
	}
}
