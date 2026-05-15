<?php

declare(strict_types=1);

/**
 * BlockRenderer — render callback for ACF Gutenberg blocks.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

/**
 * Render callback orchestration for ACF Gutenberg blocks defined via block.json.
 *
 * Migrated from per-theme `timber_block_render_callback()` to provide a single
 * versioned source of truth across all themes derived from
 * `portadesign/wordpress-base`. Behaviorally a faithful port; adds four
 * WordPress filters as extensibility hooks listed below.
 *
 * Filters exposed:
 *   - timber_kit/block_renderer/cache_key        (string $key, array $cache_data, string $block_name)
 *   - timber_kit/block_renderer/use_cache        (bool $enabled, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/empty_alert_html (string $html, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/context          (array $context, string $block_name, bool $is_preview)
 */
final class BlockRenderer {

	/**
	 * In-request memo of rendered block output, keyed by cache key.
	 *
	 * @var array<string, string>
	 */
	private static array $preview_memo = [];

	/**
	 * Empirical inserter-preview detector. Pure: no I/O, no WP side effects.
	 *
	 * Returns true when the block is being rendered for the inserter library,
	 * detected by: preview mode AND ACF returned no fields for the resolved
	 * post AND attributes carry an example data payload (registered via
	 * block.json's `example` field).
	 *
	 * @param bool                 $is_preview        True in any editor / inserter preview context.
	 * @param array<string, mixed> $formatted_fields  Result of Helpers::formatFields() (or equivalent).
	 * @param array<string, mixed> $attributes        The block's attributes.
	 */
	public static function isInserterPreview(
		bool $is_preview,
		array $formatted_fields,
		array $attributes
	): bool {
		return $is_preview
			&& empty( $formatted_fields )
			&& ! empty( $attributes['data'] )
			&& is_array( $attributes['data'] );
	}
}
