<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Acfml;

/**
 * Undoes ACFML's `acf/load_reference` filter leak.
 *
 * `WPML_ACF_Worker::getFieldObjectWithFilteredReference()` (acfml 2.2.4,
 * `classes/class-wpml-acf-worker.php`) adds a closure to `acf/load_reference`,
 * calls `get_field_object()`, then tries to remove it again:
 *
 * ```php
 * add_filter( 'acf/load_reference', $keepOnlyLastFieldReference );
 * $field = get_field_object( $metaKey, $objectFromId, false, false );
 * remove_filter( 'acf/$metaKey', $keepOnlyLastFieldReference );   // <- single-quoted
 * ```
 *
 * The removal names `'acf/$metaKey'` in single quotes, so `$metaKey` is not
 * interpolated and the call targets a literal hook name that never exists. The
 * closure is therefore never removed. ACFML runs that method once per synced
 * meta key, so a `wpml_sync_all_custom_fields` over a dictionary of N Copy keys
 * leaks N closures — and every later `get_field_object()` walks all of them.
 *
 * The cost is invisible in a single request and severe in a loop. Measured on a
 * site with 807 Copy keys, syncing 24 posts in one process:
 *
 * | | first post | last post | total |
 * |---|---|---|---|
 * | as shipped | 0.060s | 1.327s (22x) | 16.33s |
 * | swept after each post | 0.047s | 0.040s (0.8x) | 0.95s |
 *
 * The per-post cost stops depending on the iteration ordinal — the growth is
 * the leak, not the dictionary size. (Confirmed by measuring the same post in a
 * fresh process: 0.092s alone versus 4.64s at ordinal 40.)
 *
 * Sweeping restores the state ACFML itself intends, which is what makes this
 * safe rather than clever: the closure is scoped to one `get_field_object()`
 * call and has no purpose afterwards. Should upstream fix the typo, sweeping
 * becomes a no-op — nothing leaks, so nothing is removed.
 *
 * ```php
 * $guard = new LoadReferenceGuard();
 * foreach ( $post_ids as $post_id ) {
 *     do_action( 'wpml_sync_custom_field', $post_id, 'price' );
 *     $guard->sweep();
 * }
 * ```
 *
 * Only callbacks registered *after* construction are removed, so filters the
 * theme added at boot survive. Construct the guard immediately before the loop
 * and it needs no allow-list.
 */
final class LoadReferenceGuard {

	public const HOOK = 'acf/load_reference';

	/**
	 * Callback IDs present at construction, per priority: priority => [ id => true ].
	 *
	 * @var array<int|string, array<int|string, true>>
	 */
	private array $baseline;

	private int $swept = 0;

	/**
	 * @param string $hook Filter to guard. Defaults to the one ACFML leaks; injectable for tests.
	 */
	public function __construct( private readonly string $hook = self::HOOK ) {
		$this->baseline = $this->snapshot();
	}

	/**
	 * Removes callbacks added since construction and returns how many went.
	 *
	 * Closures only. A named function or object method appearing mid-loop is far
	 * more likely to be a deliberate registration by application code than a
	 * leak, and dropping it would be a surprise; the leaked callbacks are all
	 * anonymous, so the narrower rule loses nothing.
	 */
	public function sweep(): int {
		$hooks = $this->hookObject();

		if ( null === $hooks ) {
			return 0;
		}

		$removed = 0;

		foreach ( $hooks->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( isset( $this->baseline[ $priority ][ $id ] ) ) {
					continue;
				}

				if ( ! ( ( $callback['function'] ?? null ) instanceof \Closure ) ) {
					continue;
				}

				$hooks->remove_filter( $this->hook, $callback['function'], (int) $priority );
				$removed++;
			}
		}

		$this->swept += $removed;

		return $removed;
	}

	/**
	 * Total removed across every {@see sweep()} on this instance — for logging
	 * and for asserting in tests that the leak was actually present.
	 */
	public function sweptTotal(): int {
		return $this->swept;
	}

	/**
	 * Runs `$work` and sweeps afterwards, even if it throws.
	 *
	 * @template T
	 * @param \Closure(): T $work
	 * @return T
	 */
	public function around( \Closure $work ): mixed {
		try {
			return $work();
		} finally {
			$this->sweep();
		}
	}

	/**
	 * @return array<int|string, array<int|string, true>>
	 */
	private function snapshot(): array {
		$hooks = $this->hookObject();

		if ( null === $hooks ) {
			return [];
		}

		$snapshot = [];

		foreach ( $hooks->callbacks as $priority => $callbacks ) {
			$snapshot[ $priority ] = array_fill_keys( array_keys( $callbacks ), true );
		}

		return $snapshot;
	}

	/**
	 * The `WP_Hook` for the guarded filter, or null when nothing is registered
	 * yet — which is the normal state before ACFML has run even once.
	 */
	private function hookObject(): ?\WP_Hook {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $this->hook ] ) || ! $wp_filter[ $this->hook ] instanceof \WP_Hook ) {
			return null;
		}

		return $wp_filter[ $this->hook ];
	}
}
