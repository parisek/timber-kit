<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Wpml;

use Parisek\TimberKit\Acfml\LoadReferenceGuard;

/**
 * Pushes Copy-marked custom fields from a source post to its translations,
 * without the two traps of doing it by hand.
 *
 * **Never call `wpml_sync_all_custom_fields` in a loop.** It walks the whole
 * `custom_fields_translation` dictionary per post, and that dictionary is
 * site-wide and monotonic: ACFML registers one entry per *flattened* meta key,
 * so a repeater contributes one entry per row index that has ever existed. A
 * site with 23 declared fields measured 807 Copy entries. Two costs follow:
 *
 * 1. `WPML_Sync_Custom_Fields::sync_all_custom_fields()` loops the N keys and
 *    each `sync_to_translations()` does an `in_array()` over the same N-element
 *    list — quadratic. Measured on the `in_array` alone, per post: 2 000 keys
 *    0.004s, 10 000 keys 0.094s, 50 000 keys 2.617s.
 * 2. Every synced key leaks a filter callback ({@see LoadReferenceGuard}), so
 *    the cost also climbs with each post already processed.
 *
 * Syncing only the keys actually written avoids the first; the guard avoids the
 * second. Both matter — on the site above, the two together took a 147-post
 * import from 26 minutes to a few.
 *
 * ```php
 * $sync = new CopyFieldSync();
 * foreach ( $rows as $row ) {
 *     $post_id = $this->write( $row );
 *     $sync->push( $post_id, [ 'price', 'status', 'floor' ] );
 * }
 * ```
 *
 * Only fields the caller actually wrote should be passed. Listing every field
 * of the post reintroduces cost 1 in miniature and syncs values nothing
 * touched.
 */
final class CopyFieldSync {

	private readonly LoadReferenceGuard $guard;

	public function __construct( ?LoadReferenceGuard $guard = null ) {
		$this->guard = $guard ?? new LoadReferenceGuard();
	}

	/**
	 * Syncs the named meta keys from `$post_id` to its translations.
	 *
	 * Whether a key is copied, translated or ignored stays WPML's decision —
	 * this only narrows *which* keys are offered. A key WPML has marked
	 * Translate is left alone by `wpml_sync_custom_field` itself.
	 *
	 * ACF stores a `_<name>` companion holding the field key, and WPML treats it
	 * as a separate dictionary entry. Syncing the value without its companion
	 * leaves the translation's field reference pointing at whatever was there
	 * before, so companions are synced too unless the caller opts out.
	 *
	 * @param int          $post_id    Source post — must be in the default language.
	 * @param list<string> $meta_keys  Meta keys (ACF field *names*, not `field_…` keys).
	 * @param bool         $companions Also sync each key's `_<name>` companion.
	 * @return int Callbacks swept afterwards; 0 once upstream fixes the leak.
	 */
	public function push( int $post_id, array $meta_keys, bool $companions = true ): int {
		foreach ( $meta_keys as $meta_key ) {
			do_action( 'wpml_sync_custom_field', $post_id, $meta_key );

			if ( $companions ) {
				do_action( 'wpml_sync_custom_field', $post_id, '_' . $meta_key );
			}
		}

		return $this->guard->sweep();
	}

	/**
	 * Total callbacks swept across every {@see push()} — worth logging at the
	 * end of a long import, since a non-zero figure is the leak still being
	 * present in the installed ACFML.
	 */
	public function sweptTotal(): int {
		return $this->guard->sweptTotal();
	}
}
