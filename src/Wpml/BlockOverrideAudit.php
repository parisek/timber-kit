<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Wpml;

/**
 * Pure parts of `wp timber-kit wpml-block-override-audit`.
 *
 * The command answers one question before a site turns on
 * `$wpml_block_override_skip_wp_editor`: which translations would render
 * differently? Those are the translations of pages translated in the
 * WordPress editor where `$wpml_block_override` changes at least one block
 * today. This class holds the two decisions that need no database: whether
 * WPML's editor settings mean "WordPress editor", and how many blocks a filter
 * changes in a parsed block tree.
 *
 * All I/O (SQL, language switching, the global `$post`) happens in
 * {@see \Parisek\TimberKit\Cli\WpmlBlockOverrideAuditCommand}; same split as
 * {@see ThemeDomainCleanupPlan}.
 */
final class BlockOverrideAudit {

	/**
	 * Does WPML resolve this page to the WordPress editor?
	 *
	 * Reads the result of
	 * `WPML_TM_Post_Edit_TM_Editor_Mode::get_editor_settings( null, $id )`,
	 * which WPML 4.9 and 5 both return as `[ use_tm, blocked, reason ]`. WPML
	 * itself treats a page as native when it does not use the translation
	 * editor or when that editor is blocked for it. `uses_native_editor()`
	 * says the same, but exists only in WPML 5.
	 *
	 * @param array<int|string, mixed> $settings
	 */
	public static function usesWordPressEditor( array $settings ): bool {
		return ! ( $settings[0] ?? true ) || ! empty( $settings[1] );
	}

	/**
	 * Count the ACF blocks whose `attrs.data` the filter changes.
	 *
	 * Walks the tree in render order (parent before its inner blocks), the
	 * order `render_block_data` sees, because the override pairs blocks by
	 * ordinal. Non-ACF blocks still recurse: an ACF block can sit inside a
	 * core group.
	 *
	 * @param array<int|string, array<string, mixed>>              $blocks parse_blocks() output.
	 * @param callable(array<string, mixed>): array<string, mixed> $filter
	 */
	public static function countChangedBlocks( array $blocks, callable $filter ): int {
		$changed = 0;
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( \is_string( $name ) && \str_starts_with( $name, 'acf/' ) ) {
				$after = $filter( $block );
				if ( ( $after['attrs']['data'] ?? null ) !== ( $block['attrs']['data'] ?? null ) ) {
					++$changed;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && \is_array( $block['innerBlocks'] ) ) {
				$changed += self::countChangedBlocks( $block['innerBlocks'], $filter );
			}
		}
		return $changed;
	}

	/**
	 * One line for the end of the report.
	 *
	 * @param int $checked Published non-default-language pages with ACF blocks
	 *                     that WPML resolves to the WordPress editor.
	 */
	public static function summary( int $checked, int $pages, int $blocks ): string {
		return \sprintf(
			'%d published page(s) with ACF blocks are translated in the WordPress editor. '
			. '$wpml_block_override_skip_wp_editor would change %d of them, %d block(s).',
			$checked,
			$pages,
			$blocks
		);
	}
}
