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
	 * In-request memo of compiled block output for PREVIEW renders only,
	 * keyed by cache key. Frontend renders use `wp_cache_set()` (external
	 * object cache) instead — see `render()`. The two cache layers exist
	 * because the in-request memo would never survive between requests on
	 * the frontend anyway, and the external cache adds latency that's not
	 * worth paying for editor/inserter previews that already short-circuit
	 * within a single request.
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

	/**
	 * Render callback for ACF Gutenberg blocks defined via block.json.
	 *
	 * Wire as:
	 *   "acf": { "renderCallback": "Parisek\\TimberKit\\BlockRenderer::render" }
	 *
	 * @param array<string, mixed> $attributes The block's saved or preview attributes.
	 * @param string               $content    Block-supplied content (unused for ACF blocks).
	 * @param bool                 $is_preview True in any editor / inserter preview context.
	 * @param int|string           $post_id    Containing post ID (may be 0 or a "block_*" string in some contexts).
	 * @param \WP_Block|null       $wp_block   The WP_Block instance, null in legacy contexts.
	 */
	public static function render(
		array $attributes,
		string $content = '',
		bool $is_preview = false,
		int|string $post_id = 0,
		?\WP_Block $wp_block = null
	): void {
		$block_name = isset( $attributes['name'] ) && is_string( $attributes['name'] )
			? $attributes['name']
			: 'unknown';

		$use_cache = false; // Enabled below for non-preview renders meeting cache criteria.

		// Slug derivation matches the source function:
		//   "acf/article-featured" → "article-featured"
		//   filter base "block_article_featured" (dashes → underscores)
		$slug        = str_replace( 'acf/', '', $block_name );
		$filter_base = 'block_' . str_replace( '-', '_', $slug );

		// Real post ID resolution for cache group:
		//   callback $post_id → acf_get_valid_post_id() → global $post (when "block_*")
		$callback_post_id = $post_id;
		$post_id          = acf_get_valid_post_id();

		$real_post_id = is_numeric( $callback_post_id ) && (int) $callback_post_id > 0
			? (int) $callback_post_id
			: $post_id;
		if ( str_starts_with( (string) $real_post_id, 'block_' ) ) {
			global $post;
			if ( isset( $post ) && isset( $post->ID ) ) {
				$real_post_id = (int) $post->ID;
			}
		}

		$has_dynamic_filter = has_filter( "{$filter_base}_content" );

		// Cache key + group composition
		$cache_data = [
			'name'      => $block_name,
			'data'      => $attributes['data'] ?? [],
			'anchor'    => $attributes['anchor'] ?? '',
			'className' => $attributes['className'] ?? '',
			'post_id'   => $post_id,
			'lang'      => apply_filters( 'wpml_current_language', '' ),
			'paged'     => get_query_var( 'paged', 0 ),
		];
		$default_key = 'acf_block_' . md5( wp_json_encode( $cache_data ) );
		$cache_key   = apply_filters( 'timber_kit/block_renderer/cache_key', $default_key, $cache_data, $block_name );
		$cache_group = 'acf_block_' . ( is_numeric( $real_post_id ) ? $real_post_id : 0 );

		// Cache lookup (preview memo + frontend Redis)
		if ( $is_preview ) {
			if ( isset( self::$preview_memo[ $cache_key ] ) ) {
				print self::$preview_memo[ $cache_key ];
				return;
			}
		} else {
			$use_cache_default = ! $has_dynamic_filter
				&& function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
				&& function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' );
			$use_cache = apply_filters( 'timber_kit/block_renderer/use_cache', $use_cache_default, $block_name, $attributes );

			if ( $use_cache ) {
				$cached = wp_cache_get( $cache_key, $cache_group );
				if ( false !== $cached ) {
					print $cached;
					return;
				}
			}
		}

		// Pre-render side-effect snapshot. Form plugins (CF7, WPForms) enqueue
		// their CSS/JS during shortcode processing — when their output is served
		// from cache, the shortcode never executes and assets are never enqueued,
		// breaking form styling/JS. By comparing the queue before/after render,
		// blocks with asset side effects are automatically excluded from cache.
		$scripts_before = function_exists( 'wp_scripts' ) ? wp_scripts()->queue : [];
		$styles_before  = function_exists( 'wp_styles' ) ? wp_styles()->queue : [];

		// Data hydration (Helpers::formatFields walks ACF fields for the resolved post).
		$content_data = Helpers::formatFields( $post_id, $is_preview );

		// Discriminator + inserter-preview content fallback. When ACF returned
		// nothing AND attributes carry example data, treat the example data as
		// content (matches block.json's `example.attributes.data` shape).
		$is_inserter_preview = self::isInserterPreview( $is_preview, $content_data, $attributes );
		if ( $is_inserter_preview ) {
			$content_data = array_filter(
				$attributes['data'],
				static fn( $key ) => is_string( $key ) && '' !== $key && '_' !== $key[0],
				ARRAY_FILTER_USE_KEY
			);
		}

		// Wrapper context (always added before content filter / context filter run).
		$content_data['is_preview']      = $is_preview;
		$content_data['wrapper_id']      = $attributes['anchor'] ?? '';
		$content_data['wrapper_classes'] = $attributes['className'] ?? '';

		// Content filter — gated on the discriminator. Inserter-library previews
		// use fake example data; running block_<name>_content callbacks against
		// it would enrich that data with derived values and distort inserter
		// thumbnails.
		if ( ! $is_inserter_preview ) {
			$content_data = apply_filters( "{$filter_base}_content", $content_data );
		}

		// Template filter — always runs (block_<name>_template lets themes swap the Twig path).
		$default_template_path = '@component/' . $slug . '/' . $slug . '.twig';
		$template_path         = apply_filters( "{$filter_base}_template", $default_template_path, $content_data );

		// Twig context assembly + context filter.
		$context             = class_exists( \Timber\Timber::class ) ? \Timber\Timber::context() : [];
		$context['content']  = $content_data;
		$context             = apply_filters( 'timber_kit/block_renderer/context', $context, $block_name, $is_preview );

		// Compile.
		$template_output = '';
		if ( class_exists( \Timber\Timber::class ) ) {
			$compiled = \Timber\Timber::compile( $template_path, $context );
			if ( is_string( $compiled ) ) {
				$template_output = $compiled;
			}
		}

		// Empty render → editor alert.
		if ( '' === trim( $template_output ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$template_output = self::renderEmptyAlert( $block_name, $attributes );
		}

		// Inserter-preview aspect-ratio wrap. Inserter library thumbnails benefit
		// from a fixed aspect so they're consistent regardless of the block's
		// natural height. Wrap with overflow:hidden so taller content crops.
		if ( $is_inserter_preview && '' !== $template_output ) {
			$template_output = '<div style="aspect-ratio: 16/9; overflow: hidden;">' . $template_output . '</div>';
		}

		// Side-effect detection (post-render). Form-plugin shortcodes enqueue
		// assets when they execute — if those queues grew during render, this
		// block's output isn't safe to cache.
		$has_side_effects = function_exists( 'wp_scripts' ) && function_exists( 'wp_styles' )
			&& ( array_diff( wp_scripts()->queue, $scripts_before ) || array_diff( wp_styles()->queue, $styles_before ) );

		// Cache write.
		if ( '' !== $template_output ) {
			if ( $is_preview ) {
				self::$preview_memo[ $cache_key ] = $template_output;
			} elseif ( $use_cache && ! $has_side_effects ) {
				wp_cache_set( $cache_key, $template_output, $cache_group, HOUR_IN_SECONDS );
			}
		}

		print $template_output;
	}

	/**
	 * Register the per-post cache invalidation hook.
	 *
	 * Called from StarterBase boot. When ACF saves a post, the cache group
	 * `acf_block_{$post_id}` is flushed — invalidating exactly the cached
	 * blocks tied to that post without touching others. Priority 20 matches
	 * the original `functions.php` registration so themes' downstream hooks
	 * that depended on this firing at 20 still see the same ordering.
	 */
	public static function registerInvalidation(): void {
		add_action( 'acf/save_post', static function ( $post_id ): void {
			if ( is_numeric( $post_id )
				&& function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
				&& function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
				wp_cache_flush_group( 'acf_block_' . $post_id );
			}
		}, 20 );
	}

	/**
	 * Render the empty-block warning shown to logged-in users when render
	 * produced no output. Tries the bundled Twig template first; falls back
	 * to inline HTML that preserves the same DOM contract.
	 *
	 * Block label prefix comes from `$attributes['title']` (falls back to
	 * `$attributes['name']`) so the editor sees e.g. "Article — Featured:
	 * Pro zobrazení vyplňte...".
	 */
	private static function renderEmptyAlert( string $block_name, array $attributes ): string {
		$block_label = $attributes['title'] ?? $attributes['name'] ?? '';
		$message     = __(
			'Pro zobrazení vyplňte požadované údaje v pravém panelu.',
			'timber-kit'
		);

		$html = '';
		if ( class_exists( \Timber\Timber::class ) ) {
			$compiled = \Timber\Timber::compile(
				'@timber-kit/empty-alert.twig',
				[
					'block_name'  => $block_name,
					'block_label' => $block_label,
					'message'     => $message,
				]
			);
			if ( is_string( $compiled ) && '' !== $compiled ) {
				$html = $compiled;
			}
		}

		if ( '' === $html ) {
			// Inline fallback — preserves the Twig template's DOM exactly so
			// theme CSS targeting `.timber-kit-block-empty` works regardless
			// of whether the namespace was registered.
			$label_prefix = '' !== $block_label
				? '<strong>' . esc_html( (string) $block_label ) . ':</strong> '
				: '';
			$html         = sprintf(
				'<div class="block-editor-warning timber-kit-block-empty" data-block="%s">'
					. '<div class="block-editor-warning__contents">'
						. '<p class="block-editor-warning__message">%s%s</p>'
					. '</div>'
				. '</div>',
				esc_attr( $block_name ),
				$label_prefix,
				esc_html( $message )
			);
		}

		return apply_filters(
			'timber_kit/block_renderer/empty_alert_html',
			$html,
			$block_name,
			$attributes
		);
	}
}
