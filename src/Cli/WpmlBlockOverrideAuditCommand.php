<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\Wpml\BlockOverrideAudit;
use Parisek\TimberKit\WpmlBlockOverride;

/**
 * `wp timber-kit wpml-block-override-audit` — list the published pages that
 * `$wpml_block_override_skip_wp_editor` would change. Read-only.
 *
 * `$wpml_block_override` copies source-language Copy values into translated
 * ACF blocks. On a page translated in the WordPress editor that overwrites
 * values the editor chose, and the skip flag stops it. Turning the flag on
 * for a running site therefore changes what some pages render: each
 * one goes from the source value to its own stored value. Only a person can
 * tell whether that stored value is intended or stale, so this command lists
 * the pages to look at.
 *
 * It measures the override with the skip veto removed, so the answer is the
 * same before and after the flag is on. It restores the veto, the global
 * `$post` and the WPML language before it returns.
 *
 * Thin adapter over {@see BlockOverrideAudit}; the WP_CLI I/O here is not
 * unit-tested (same doctrine as the other `Cli/*` commands).
 */
class WpmlBlockOverrideAuditCommand {

	private const VETO_FILTER = 'timber_kit/wpml_block_override/should_override';

	private const FIELDS = array( 'original', 'post', 'language', 'blocks', 'url' );

	/**
	 * List published pages that $wpml_block_override_skip_wp_editor would change.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit wpml-block-override-audit
	 *     wp timber-kit wpml-block-override-audit --format=csv > audit.csv
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( ! \defined( 'ICL_SITEPRESS_VERSION' ) || ! \class_exists( 'WPML_TM_Post_Edit_TM_Editor_Mode' ) ) {
			\WP_CLI::error( 'This command needs WPML with Translation Management.' );
			return;
		}

		if ( false === \has_filter( 'render_block_data', array( WpmlBlockOverride::class, 'filter' ) ) ) {
			\WP_CLI::success( '$wpml_block_override is off on this site. The skip flag changes nothing.' );
			return;
		}

		global $wpdb, $post;

		$default_language = (string) \apply_filters( 'wpml_default_language', null );
		$current_language = \apply_filters( 'wpml_current_language', null );
		$saved_post       = $post;

		$veto          = array( WpmlBlockOverride::class, 'skipWordPressEditorPages' );
		$veto_priority = \has_filter( self::VETO_FILTER, $veto );
		if ( false !== $veto_priority ) {
			\remove_filter( self::VETO_FILTER, $veto, $veto_priority );
		}

		// Originals only: WPML marks them with no source language. The query
		// does not filter on the original's content. On a site whose originals
		// are not in the default language, the override reads its source from
		// the default-language version, so an original without ACF blocks can
		// still head a group whose translations get overridden.
		$originals = $wpdb->get_results(
			"SELECT p.ID, p.post_type FROM {$wpdb->posts} p
			 JOIN {$wpdb->prefix}icl_translations t
			   ON t.element_id = p.ID AND t.element_type = CONCAT( 'post_', p.post_type )
			 WHERE p.post_status = 'publish'
			   AND t.source_language_code IS NULL
			 ORDER BY p.ID"
		);

		$filter = static fn( array $block ): array => WpmlBlockOverride::filter( $block, $block );

		$rows         = array();
		$checked      = 0;
		$total_blocks = 0;

		try {
			foreach ( (array) $originals as $original ) {
				$original_id  = (int) $original->ID;
				$element_type = 'post_' . $original->post_type;
				$trid         = \apply_filters( 'wpml_element_trid', null, $original_id, $element_type );
				$translations = \apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );

				foreach ( (array) $translations as $language => $translation ) {
					// Skip the default language only: the override never runs there.
					// The original itself stays in the loop, because on a site whose
					// originals are not in the default language the override copies
					// the default-language version into the original.
					$translation_id = (int) ( $translation->element_id ?? 0 );
					if ( $language === $default_language || 0 === $translation_id ) {
						continue;
					}
					$translated = \get_post( $translation_id );
					if ( ! $translated || 'publish' !== $translated->post_status || ! \str_contains( $translated->post_content, '<!-- wp:acf/' ) ) {
						continue;
					}

					// Same call, same ID as skipWordPressEditorPages(): the page
					// being rendered, not its original.
					$settings = (array) \WPML_TM_Post_Edit_TM_Editor_Mode::get_editor_settings( null, $translation_id );
					if ( ! BlockOverrideAudit::usesWordPressEditor( $settings ) ) {
						continue;
					}
					++$checked;

					\do_action( 'wpml_switch_language', $language );
					$post = $translated; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the override reads the global post; restored below.
					WpmlBlockOverride::resetBlockOrdinals( '' );

					$blocks = BlockOverrideAudit::countChangedBlocks( \parse_blocks( $translated->post_content ), $filter );
					if ( 0 === $blocks ) {
						continue;
					}

					$total_blocks += $blocks;
					$rows[]        = array(
						'original' => $original_id,
						'post'     => $translation_id,
						'language' => (string) $language,
						'blocks'   => $blocks,
						'url'      => (string) \get_permalink( $translation_id ),
					);
				}
			}
		} finally {
			$post = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the value saved above.
			\do_action( 'wpml_switch_language', $current_language );
			if ( false !== $veto_priority ) {
				\add_filter( self::VETO_FILTER, $veto, $veto_priority, 2 );
			}
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );

		if ( 'table' === $format ) {
			\WP_CLI::log( BlockOverrideAudit::summary( $checked, \count( $rows ), $total_blocks ) );
		}
	}
}
