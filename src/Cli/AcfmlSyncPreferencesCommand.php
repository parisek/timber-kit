<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\Acfml\PreferenceSyncPlan;

/**
 * `wp timber-kit acfml-sync-preferences` — reconcile WPML's
 * `custom_fields_translation` dictionary with ACF field definitions.
 *
 * WPML packs custom fields into translation jobs by exact meta-key lookup;
 * ACFML materialises dictionary entries only on admin field-group save or on
 * value save through the ACF pipeline. Meta written programmatically
 * (importers, WPML duplication, direct writes) therefore never gets entries
 * and is silently excluded from translation jobs. This command walks existing
 * postmeta, resolves each key's field definition via its `_<key>` field-key
 * companion, and registers the exact key with the definition's
 * `wpml_cf_preferences` — the same result an admin re-save of every post
 * would produce. Intended as a deploy step after `wp timber-kit updates`.
 *
 * Thin adapter over {@see PreferenceSyncPlan}: accumulation, conflict
 * detection and patch computation live in a unit-tested pure class; the
 * WP_CLI I/O here is intentionally not unit-tested (same doctrine as
 * ConvertUtf8mb4Command).
 *
 * Scope: postmeta of the current site only. Term meta, options-page meta,
 * user meta and multisite network sweeps are deliberately out of scope for
 * now — run per-site via `wp --url=…` on multisite.
 */
class AcfmlSyncPreferencesCommand {

	private const BATCH_SIZE = 100;

	/**
	 * Register WPML translation preferences for existing ACF postmeta keys.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Explicit alias of the default behavior — print the plan, change nothing.
	 *
	 * [--apply]
	 * : Write the computed entries into WPML's dictionary. Without it the
	 *   command only reports what would be registered. Newly-translatable keys
	 *   trigger WPML's ProcessNewTranslatableFields background task, flagging
	 *   affected translations as needing update — that backlog is the point.
	 *
	 * [--post_type=<csv>]
	 * : Limit the scan to these post types. Default: every WPML-translatable
	 *   post type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit acfml-sync-preferences
	 *     wp timber-kit acfml-sync-preferences --apply
	 *     wp timber-kit acfml-sync-preferences --apply --post_type=room_type
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		global $iclTranslationManagement;

		if ( ! function_exists( 'acf_get_field' ) ) {
			\WP_CLI::error( 'ACF is not active — acf_get_field() unavailable.' );
			return;
		}
		if ( ! class_exists( '\TranslationManagement' ) || ! $iclTranslationManagement instanceof \TranslationManagement ) {
			\WP_CLI::error( 'WPML Translation Management is not active.' );
			return;
		}

		$post_types = isset( $assoc_args['post_type'] )
			? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['post_type'] ) ) ) )
			: $this->translatablePostTypes();

		if ( array() === $post_types ) {
			\WP_CLI::error( 'No translatable post types found — pass --post_type=<csv> explicitly.' );
			return;
		}

		$plan = new PreferenceSyncPlan(
			static function ( string $field_key ): ?array {
				$field = acf_get_field( $field_key );
				return is_array( $field ) ? $field : null;
			}
		);

		$scanned = $this->collectPostmeta( $plan, $post_types );

		$current = isset( $iclTranslationManagement->settings['custom_fields_translation'] )
			&& is_array( $iclTranslationManagement->settings['custom_fields_translation'] )
			? $iclTranslationManagement->settings['custom_fields_translation']
			: array();

		$patch   = $plan->patch( $current );
		$summary = $plan->summary();

		\WP_CLI::log( sprintf( 'Scanned %d post(s) of type(s): %s', $scanned, implode( ', ', $post_types ) ) );
		foreach ( $summary['registered_by_preference'] as $pref => $count ) {
			\WP_CLI::log( sprintf( '  preference %d (%s): %d key(s)', $pref, $this->prefLabel( $pref ), $count ) );
		}
		\WP_CLI::log( sprintf( 'Dictionary entries to write: %d (of %d currently registered)', count( $patch ), count( $current ) ) );

		foreach ( $summary['conflicts'] as $key => $prefs ) {
			\WP_CLI::warning( sprintf(
				'Conflict: meta key "%s" resolves to preferences [%s] across posts — skipped, resolve the field definitions first.',
				$key,
				implode( ', ', array_map( 'strval', $prefs ) )
			) );
		}

		if ( array() !== $summary['unresolvable'] ) {
			$sample = array_slice( $summary['unresolvable'], 0, 20 );
			\WP_CLI::warning( sprintf(
				'%d meta key(s) reference an ACF field without a resolvable wpml_cf_preferences — skipped: %s%s',
				count( $summary['unresolvable'] ),
				implode( ', ', $sample ),
				count( $summary['unresolvable'] ) > count( $sample ) ? ', …' : ''
			) );
		}

		if ( array() === $patch ) {
			\WP_CLI::success( 'Dictionary already in sync — nothing to write.' );
			return;
		}

		if ( ! isset( $assoc_args['apply'] ) ) {
			\WP_CLI::log( 'Dry-run only. Re-run with --apply to write the entries.' );
			return;
		}

		// Patch-only merge: touch exactly the computed keys, never rebuild or
		// prune the dictionary. save_settings() still persists the whole
		// settings snapshot loaded at bootstrap, so a write racing within the
		// same request window can be lost — acceptable for a deploy step.
		foreach ( $patch as $key => $pref ) {
			$iclTranslationManagement->settings['custom_fields_translation'][ $key ] = $pref;
		}
		$iclTranslationManagement->save_settings();

		\WP_CLI::success( sprintf( 'Registered %d dictionary entr(ies).', count( $patch ) ) );
	}

	/**
	 * WPML-translatable post types, from SitePress.
	 *
	 * @return list<string>
	 */
	private function translatablePostTypes(): array {
		global $sitepress;

		if ( ! class_exists( '\SitePress' ) || ! $sitepress instanceof \SitePress ) {
			return array();
		}

		$documents = $sitepress->get_translatable_documents( false );

		return array_values( array_map( 'strval', array_keys( $documents ) ) );
	}

	/**
	 * Feed every matching post's meta into the plan, batched by ID so the
	 * scan never loads all postmeta into memory at once.
	 *
	 * @param list<string> $post_types
	 * @return int Number of posts scanned.
	 */
	private function collectPostmeta( PreferenceSyncPlan $plan, array $post_types ): int {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$scanned      = 0;
		$last_id      = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders string is built from array_fill, values go through prepare().
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) AND post_status != 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d",
				array_merge( $post_types, array( $last_id, self::BATCH_SIZE ) )
			) );

			if ( ! is_array( $ids ) || array() === $ids ) {
				break;
			}

			foreach ( $ids as $id ) {
				$meta = get_post_meta( (int) $id );
				if ( is_array( $meta ) ) {
					$plan->collect( $meta );
				}
				$scanned++;
				$last_id = (int) $id;
			}

			// Meta accumulates in the object cache during the scan — drop it
			// between batches so large sites stay flat on memory.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $ids ) === self::BATCH_SIZE );

		return $scanned;
	}

	private function prefLabel( int $pref ): string {
		return match ( $pref ) {
			PreferenceSyncPlan::PREF_IGNORE    => 'don\'t translate',
			PreferenceSyncPlan::PREF_COPY      => 'copy',
			PreferenceSyncPlan::PREF_TRANSLATE => 'translate',
			PreferenceSyncPlan::PREF_COPY_ONCE => 'copy once',
			default                            => 'unknown',
		};
	}
}
