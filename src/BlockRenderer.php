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
 *   - timber_kit/block_renderer/content_data     (?array $content_data, int|string $post_id, bool $is_preview, array $attributes)
 *   - timber_kit/block_renderer/context          (array $context, string $block_name, bool $is_preview)
 *   - timber_kit/block_renderer/empty_alert_html (string $html, string $block_name, array $attributes)
 *
 * Per-block filters dispatched during render (preserved from the original
 * timber_block_render_callback for backwards compatibility — slug is the
 * block name with 'acf/' stripped and dashes converted to underscores):
 *   - block_<slug>_content   (array $content_data) — skipped when isInserterPreview() returns true
 *   - block_<slug>_template  (string $template_path, array $content_data)
 */
final class BlockRenderer {

	/**
	 * Cache key + group prefix shared between cache writes (render()),
	 * default cache-key composition (buildCacheKey()), and per-post
	 * invalidation (flushPostBlockCache()). Single source of truth so
	 * the writer and invalidator can't drift.
	 */
	private const CACHE_GROUP_PREFIX = 'acf_block_';

	/**
	 * Suffixes appended to `$filter_base` to form the legacy per-block
	 * filter names (e.g. `block_article_featured_content`).
	 */
	private const FILTER_SUFFIX_CONTENT  = '_content';
	private const FILTER_SUFFIX_TEMPLATE = '_template';

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

		$has_dynamic_filter = has_filter( $filter_base . self::FILTER_SUFFIX_CONTENT );
		$cache_key          = self::buildCacheKey( $block_name, $attributes, $post_id );
		$cache_group        = self::CACHE_GROUP_PREFIX . ( is_numeric( $real_post_id ) ? $real_post_id : 0 );

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
		$dynamic_before = Helpers::dynamicFormatCount();

		// Core runs this filter for every shortcode tag it is about to execute
		// (wp-includes/shortcodes.php, do_shortcode_tag()). It catches the
		// expansions Helpers never sees — above all ACF's own, which runs inside
		// get_field_objects() before any value reaches us.
		//
		// `pre_do_shortcode_tag` and not `do_shortcode_tag`: core fires this one
		// first and unconditionally, then returns early when a plugin answers it
		// with anything but false. A shortcode-caching plugin answering there
		// would keep `do_shortcode_tag` from ever running, and the expansion would
		// pass unseen. The probe must return $return untouched — any other value
		// short-circuits that shortcode for the whole site.
		$shortcode_expanded = false;
		$shortcode_probe    = static function ( $return ) use ( &$shortcode_expanded ) {
			$shortcode_expanded = true;

			return $return;
		};

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'pre_do_shortcode_tag', $shortcode_probe, PHP_INT_MAX );
		}

		// try/finally, because the probe outlives this method if a template throws.
		// Twig exceptions propagate — compile() has no catch, and neither does
		// core's render_block() nor ACF's block renderer — so a leaked closure
		// would sit on the global hook for the rest of the request and flip the
		// verdict for every later render.
		try {
			[ $content_data, $is_inserter_preview ] = self::buildContent( $post_id, $is_preview, $attributes );

			if ( ! $is_inserter_preview ) {
				$content_data = apply_filters( $filter_base . self::FILTER_SUFFIX_CONTENT, $content_data );
			}
			$template_path = apply_filters( $filter_base . self::FILTER_SUFFIX_TEMPLATE, "@component/{$slug}/{$slug}.twig", $content_data );

			$template_output = self::compile( $template_path, $content_data, $block_name, $is_preview );

			$rendered_empty_alert = false;
			if ( '' === trim( $template_output ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				$template_output      = self::renderEmptyAlert( $block_name, $attributes );
				$rendered_empty_alert = true;
			}

			if ( $is_inserter_preview && '' !== $template_output ) {
				$template_output = '<div style="aspect-ratio: 16/9; overflow: hidden;">' . $template_output . '</div>';
			}
		} finally {
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'pre_do_shortcode_tag', $shortcode_probe, PHP_INT_MAX );
			}
		}

		$enqueued_during_render = function_exists( 'wp_scripts' ) && function_exists( 'wp_styles' )
			&& ( array_diff( wp_scripts()->queue, $scripts_before ) || array_diff( wp_styles()->queue, $styles_before ) );

		// A render that expanded a shortcode is not a pure function of its inputs,
		// even when it enqueued nothing. Two probes, because neither sees
		// everything: the counter reads the INPUT Helpers was handed, the filter
		// observes what core was about to execute. See writeToCache().
		$formatted_dynamically = Helpers::dynamicFormatCount() !== $dynamic_before || $shortcode_expanded;

		$has_side_effects = $enqueued_during_render || $formatted_dynamically;

		/**
		 * Filters the side-effect verdict for one block render.
		 *
		 * `timber_kit/block_renderer/use_cache` cannot reach this: writeToCache()
		 * requires `$use_cache && ! $has_side_effects`, so forcing use_cache on
		 * does not restore caching for a block this guard rejected. This filter is
		 * the escape hatch for a project that knows a given block is safe.
		 *
		 * @param bool                 $has_side_effects Whether the render was impure.
		 * @param string               $block_name       Block name, e.g. `acf/contact-form`.
		 * @param array<string, mixed> $attributes       The block's attributes.
		 */
		$has_side_effects = (bool) apply_filters(
			'timber_kit/block_renderer/has_side_effects',
			$has_side_effects,
			$block_name,
			$attributes
		);

		self::writeToCache(
			template_output:      $template_output,
			cache_key:            $cache_key,
			cache_group:          $cache_group,
			is_preview:           $is_preview,
			use_cache:            $use_cache,
			has_side_effects:     $has_side_effects,
			rendered_empty_alert: $rendered_empty_alert,
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
		// Rendered blocks carry resizer URLs, so a cache version bump must miss
		// here too. Added only when set, so existing keys stay byte-identical.
		$resizer_cache_version = trim( (string) apply_filters( 'timber_kit_resizer_cache_version', '' ) );
		if ( '' !== $resizer_cache_version ) {
			$cache_data['resizer_cache_version'] = $resizer_cache_version;
		}
		$default_key = self::CACHE_GROUP_PREFIX . md5( wp_json_encode( $cache_data ) );

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
		// Allow downstream code to inject content data without going through
		// Helpers::formatFields (e.g. non-ACF data sources, test fixtures,
		// storybook-style block previews). Falls back to ACF when no filter
		// registered (default: null → use formatFields()).
		$content_data = apply_filters(
			'timber_kit/block_renderer/content_data',
			null,
			$post_id,
			$is_preview,
			$attributes
		);
		if ( null === $content_data || ! is_array( $content_data ) ) {
			$content_data = Helpers::formatFields( $post_id, $is_preview );
		}

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
	 *   - has_side_effects: the render was not a pure function of its inputs, so
	 *     replaying its output would drop whatever the render also did. Three
	 *     tests, because no one of them sees everything:
	 *
	 *     1. The script/style queues grew during compile. Caching would skip
	 *        those enqueues on the next request and break the form.
	 *     2. `Helpers::dynamicFormatCount()` moved, so a field value Helpers was
	 *        handed still held a registered shortcode when it expanded it.
	 *     3. Core reached `pre_do_shortcode_tag` during the render, so it was
	 *        about to execute a shortcode somewhere Helpers never saw it.
	 *
	 *     Test 1 alone was the original guard, and it cannot see WPForms at all.
	 *     WPForms enqueues nothing while it renders: `WPForms_Frontend::output()`
	 *     only appends the form to its own `$forms` array, and the enqueue
	 *     happens later, on `wp_footer` priority 15, where `assets_footer()`
	 *     returns early while that array is empty. So a cached WPForms block left
	 *     the queues untouched, passed test 1, got stored, and from the next
	 *     request on served the form markup with none of its CSS or JS. The form
	 *     then looked right and did nothing. Measured on a live site: after
	 *     flushing one page's block-cache group, the first request carried 12
	 *     WPForms scripts and every request after it carried zero, with the form
	 *     markup unchanged throughout.
	 *
	 *     Test 2 asks the counter Helpers already keeps, and that counter decides
	 *     from the INPUT — whether a registered shortcode was present — never
	 *     from whether the output differed. `MenuFieldsCache` gates its own
	 *     writes the same way.
	 *
	 *     Test 3 exists because test 2 has a blind spot that covers a common
	 *     case. `formatFields()` calls `get_field_objects()` with ACF's default
	 *     `$format_value = true`, and ACF's WYSIWYG `format_value()` applies
	 *     `acf_the_content`, which carries `do_shortcode` at priority 11
	 *     (`class-acf-field-wysiwyg.php`). A `[wpforms id="…"]` an editor typed
	 *     into a WYSIWYG field is therefore already expanded before any value
	 *     reaches `expandShortcodes()`: no bracket is left, the counter never
	 *     moves, and the block was cached. Core's own `pre_do_shortcode_tag`
	 *     filter fires for every shortcode tag it is about to execute, so it sees
	 *     that expansion and every other one — Twig-side `do_shortcode()`, a
	 *     `field_formatter_<type>` callback — without naming a single plugin. It
	 *     is the earlier of core's two shortcode hooks on purpose: a plugin that
	 *     answers `pre_do_shortcode_tag` keeps `do_shortcode_tag` from running at
	 *     all, so probing the later one would miss exactly the shortcodes another
	 *     cache is already handling.
	 *
	 *     Cost of tests 2 and 3: a block whose render executed any registered
	 *     shortcode stops being cached — its own, or another post's, since a
	 *     listing block that renders a listed post's `[caption]` is impure by the
	 *     same argument. Harmless shortcodes count. That is the safe direction,
	 *     and skipping a cache write is cheaper than serving a dead form. A
	 *     project that knows a given block is safe overrides the verdict through
	 *     `timber_kit/block_renderer/has_side_effects`; `use_cache` cannot do it,
	 *     because the write needs both.
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
	 * Flush the per-post block cache group.
	 *
	 * Wire as the `acf/save_post` callback (StarterBase does this at priority
	 * 20). Flushes the cache group `acf_block_{$post_id}` so cached blocks
	 * tied to that specific post regenerate on next render, while blocks on
	 * other posts keep their cached output.
	 *
	 * Guards against non-numeric post ids (e.g. `'option'` for ACF options
	 * pages) and against environments without an external object cache that
	 * supports `flush_group`.
	 *
	 * @param mixed $post_id The post id passed by the `acf/save_post` action.
	 */
	public static function flushPostBlockCache( mixed $post_id ): void {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}

		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return;
		}

		if ( ! function_exists( 'wp_cache_supports' ) || ! wp_cache_supports( 'flush_group' ) ) {
			return;
		}

		wp_cache_flush_group( self::CACHE_GROUP_PREFIX . $post_id );
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
