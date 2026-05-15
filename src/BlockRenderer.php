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
 * Filters exposed (package-owned, stable across versions):
 *   - timber_kit/block_renderer/cache_key        (string $key, array $cache_data, string $block_name)
 *   - timber_kit/block_renderer/use_cache        (bool $enabled, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/empty_alert_html (string $html, string $block_name, array $attributes)
 *   - timber_kit/block_renderer/context          (array $context, string $block_name, bool $is_preview)
 *
 * Per-block filters dispatched during render (preserved from the original
 * timber_block_render_callback for backwards compatibility — slug is the
 * block name with 'acf/' stripped and dashes converted to underscores):
 *   - block_<slug>_content   (array $content_data) — skipped when isInserterPreview() returns true
 *   - block_<slug>_template  (string $template_path, array $content_data)
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
		[ $block_name, $slug, $filter_base ] = self::resolveBlockIdentity( $attributes );

		$callback_post_id = $post_id;
		$post_id          = acf_get_valid_post_id();
		$real_post_id     = self::resolveRealPostId( $callback_post_id, $post_id );

		$has_dynamic_filter = has_filter( "{$filter_base}_content" );
		$cache_key          = self::buildCacheKey( $block_name, $attributes, $post_id );
		$cache_group        = 'acf_block_' . ( is_numeric( $real_post_id ) ? $real_post_id : 0 );

		$use_cache_default = ! $has_dynamic_filter
			&& function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' );
		$use_cache = ! $is_preview && apply_filters(
			'timber_kit/block_renderer/use_cache',
			$use_cache_default,
			$block_name,
			$attributes
		);

		$cached = self::readFromCache( $cache_key, $cache_group, $is_preview, $use_cache );
		if ( null !== $cached ) {
			print $cached;
			return;
		}

		$scripts_before = function_exists( 'wp_scripts' ) ? wp_scripts()->queue : [];
		$styles_before  = function_exists( 'wp_styles' ) ? wp_styles()->queue : [];

		[ $content_data, $is_inserter_preview ] = self::buildContent( $post_id, $is_preview, $attributes );

		if ( ! $is_inserter_preview ) {
			$content_data = apply_filters( "{$filter_base}_content", $content_data );
		}
		$template_path = apply_filters( "{$filter_base}_template", "@component/{$slug}/{$slug}.twig", $content_data );

		$template_output = self::compile( $template_path, $content_data, $block_name, $is_preview );

		$rendered_empty_alert = false;
		if ( '' === trim( $template_output ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$template_output      = self::renderEmptyAlert( $block_name, $attributes );
			$rendered_empty_alert = true;
		}

		if ( $is_inserter_preview && '' !== $template_output ) {
			$template_output = '<div style="aspect-ratio: 16/9; overflow: hidden;">' . $template_output . '</div>';
		}

		$has_side_effects = function_exists( 'wp_scripts' ) && function_exists( 'wp_styles' )
			&& ( array_diff( wp_scripts()->queue, $scripts_before ) || array_diff( wp_styles()->queue, $styles_before ) );

		self::writeToCache(
			$template_output,
			$cache_key,
			$cache_group,
			$is_preview,
			$use_cache,
			$has_side_effects,
			$rendered_empty_alert
		);

		print $template_output;
	}

	/**
	 * Derive the block's name, slug, and filter base from its attributes.
	 *
	 * - block_name: `$attributes['name']` (falls back to 'unknown' for malformed input)
	 * - slug: block_name with 'acf/' prefix stripped
	 * - filter_base: 'block_' + slug with dashes converted to underscores (matches the
	 *   filter-naming convention used by both `block_<name>_content` and `block_<name>_template`)
	 *
	 * @param array<string, mixed> $attributes
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function resolveBlockIdentity( array $attributes ): array {
		$block_name = isset( $attributes['name'] ) && is_string( $attributes['name'] )
			? $attributes['name']
			: 'unknown';
		$slug        = str_replace( 'acf/', '', $block_name );
		$filter_base = 'block_' . str_replace( '-', '_', $slug );

		return [ $block_name, $slug, $filter_base ];
	}

	/**
	 * Resolve the real post ID used to scope the per-post cache group.
	 *
	 * Priority chain:
	 *   1. callback `$post_id` (the raw value WP passed to render()) when it's numeric > 0
	 *   2. `acf_get_valid_post_id()` result
	 *   3. global `$post->ID` fallback (only when the above is a 'block_*' opaque id —
	 *      typical when rendering inside an inner-block context)
	 *
	 * @param mixed              $callback_post_id   Raw post id WP passed to render().
	 * @param int|string         $acf_resolved_post_id  Result of acf_get_valid_post_id().
	 * @return int|string
	 */
	private static function resolveRealPostId( mixed $callback_post_id, int|string $acf_resolved_post_id ): int|string {
		$real_post_id = is_numeric( $callback_post_id ) && (int) $callback_post_id > 0
			? (int) $callback_post_id
			: $acf_resolved_post_id;

		if ( str_starts_with( (string) $real_post_id, 'block_' ) ) {
			global $post;
			if ( isset( $post ) && isset( $post->ID ) ) {
				$real_post_id = (int) $post->ID;
			}
		}

		return $real_post_id;
	}

	/**
	 * Compose the cache key for a single block render.
	 *
	 * Cache data shape matches the original `timber_block_render_callback()`:
	 * [name, data, anchor, className, post_id, lang (WPML), paged] — captures
	 * everything that can vary the rendered output. The 'acf_block_' prefix
	 * keeps keys readable in cache dashboards. Final key passes through the
	 * `timber_kit/block_renderer/cache_key` filter for projects that need
	 * extra variation vectors (e.g. user role, A/B segment).
	 *
	 * @param array<string, mixed> $attributes
	 */
	private static function buildCacheKey( string $block_name, array $attributes, int|string $post_id ): string {
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

		return apply_filters( 'timber_kit/block_renderer/cache_key', $default_key, $cache_data, $block_name );
	}

	/**
	 * Look up the cached block output. Returns null on miss.
	 *
	 * Two cache layers:
	 *   - Preview mode (editor / inserter context): in-request static memo
	 *     keyed by `$cache_key`. Safe because preview renders are per-request.
	 *   - Frontend mode: external object cache (Redis with flush_group support)
	 *     read via wp_cache_get. Gated by `$use_cache` which already factors in
	 *     has_filter() detection, the use_cache filter, and ext-cache availability.
	 */
	private static function readFromCache( string $cache_key, string $cache_group, bool $is_preview, bool $use_cache ): ?string {
		if ( $is_preview ) {
			return self::$preview_memo[ $cache_key ] ?? null;
		}

		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached && is_string( $cached ) ) {
				return $cached;
			}
		}

		return null;
	}

	/**
	 * Hydrate the block's content data from ACF + apply the inserter-preview
	 * fallback + assemble wrapper context.
	 *
	 * Returns a tuple [content_data, is_inserter_preview]. The boolean is
	 * carried because downstream steps (content-filter gating, aspect-ratio
	 * wrap, etc.) need to know whether this is an inserter-library render.
	 *
	 * @param array<string, mixed> $attributes
	 * @return array{0: array<string, mixed>, 1: bool}
	 */
	private static function buildContent( int|string $post_id, bool $is_preview, array $attributes ): array {
		$content_data = Helpers::formatFields( $post_id, $is_preview );

		$is_inserter_preview = self::isInserterPreview( $is_preview, $content_data, $attributes );
		if ( $is_inserter_preview ) {
			$content_data = array_filter(
				$attributes['data'],
				static fn( $key ) => is_string( $key ) && '' !== $key && '_' !== $key[0],
				ARRAY_FILTER_USE_KEY
			);
		}

		$content_data['is_preview']      = $is_preview;
		$content_data['wrapper_id']      = $attributes['anchor'] ?? '';
		$content_data['wrapper_classes'] = $attributes['className'] ?? '';

		return [ $content_data, $is_inserter_preview ];
	}

	/**
	 * Assemble the Twig context, pass it through the context filter, and compile
	 * the resolved template. Returns the compiled string (or empty when Timber
	 * isn't loaded or compile fails — caller decides what to do with empty output).
	 *
	 * @param array<string, mixed> $content_data
	 */
	private static function compile( string $template_path, array $content_data, string $block_name, bool $is_preview ): string {
		$context             = class_exists( \Timber\Timber::class ) ? \Timber\Timber::context() : [];
		$context['content']  = $content_data;
		$context             = apply_filters( 'timber_kit/block_renderer/context', $context, $block_name, $is_preview );

		if ( ! class_exists( \Timber\Timber::class ) ) {
			return '';
		}

		$compiled = \Timber\Timber::compile( $template_path, $context );
		return is_string( $compiled ) ? $compiled : '';
	}

	/**
	 * Write the compiled block output to the appropriate cache layer.
	 *
	 * Preview mode: in-request memo (safe — per-request, never seen across users).
	 * Frontend mode: external object cache with all guards:
	 *   - has_side_effects: form-plugin enqueues happened during compile, caching
	 *     would skip the enqueues on next request and break the form
	 *   - rendered_empty_alert: the alert is logged-in-only enrichment; caching
	 *     it would poison the shared cache for anonymous visitors
	 *   - use_cache: combines has_filter() detection, wp_using_ext_object_cache,
	 *     wp_cache_supports('flush_group'), and the use_cache filter override
	 */
	private static function writeToCache(
		string $template_output,
		string $cache_key,
		string $cache_group,
		bool $is_preview,
		bool $use_cache,
		bool $has_side_effects,
		bool $rendered_empty_alert
	): void {
		if ( '' === $template_output ) {
			return;
		}

		if ( $is_preview ) {
			self::$preview_memo[ $cache_key ] = $template_output;
			return;
		}

		if ( $use_cache && ! $has_side_effects && ! $rendered_empty_alert ) {
			wp_cache_set( $cache_key, $template_output, $cache_group, HOUR_IN_SECONDS );
		}
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
			try {
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
			} catch ( \Throwable $e ) {
				// Twig loader error (missing namespace), syntax error, or runtime error.
				// Fall through to the inline-HTML fallback below — the alert is a
				// nice-to-have for editors, never worth fatalling the request over.
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
