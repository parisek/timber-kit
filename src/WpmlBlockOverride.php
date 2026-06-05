<?php

declare(strict_types=1);

/**
 * WpmlBlockOverride — render-time source-value override for Copy fields in ACF
 * Gutenberg blocks under WPML/ACFML.
 *
 * Problem: when an editor changes a Copy field (typically an image) in the
 * source language, WPML never marks the translation `needs_update` — a Copy
 * change isn't a *translatable* change — so the translation's `post_content`
 * keeps the stale value until someone manually re-runs the ATE job. Documented
 * WPML pain since ACFML 1.3 (2019), unsolved in core.
 *
 * Fix: hook `render_block_data` at priority 20 (after WPML's own handlers) and,
 * for ACF blocks rendered in a non-default language, overwrite each Copy field's
 * `attrs.data.<field>` with the value from the *source-language* post — at
 * render time, no DB writes. ACF configuration becomes the single source of
 * truth for Copy fields; the Translate flow stays entirely with ACFML/ATE.
 *
 * @see https://github.com/parisek/timber-kit/issues/29
 */

namespace Parisek\TimberKit;

/**
 * Read-time Copy-field sync for ACF blocks under WPML. Static, no DI — matches
 * the kit's BlockRenderer pattern; no Timber dependency (works on Timber 1 + 2).
 */
final class WpmlBlockOverride {

	/**
	 * `render_block_data` priority. 20 (not the default 10) so this runs AFTER
	 * WPML's own `render_block_data` handlers, whose remaps we must not let
	 * revert our overrides.
	 */
	private const HOOK_PRIORITY = 20;

	/**
	 * ACFML field translation-preference value for "Copy" (the source value is
	 * mirrored into every translation). 0=Don't translate, 1=Copy, 2=Translate,
	 * 3=Copy once.
	 */
	private const ACFML_PREFERENCE_COPY = 1;

	/** Transient key for the per-block Copy-field map (invalidated on field-group save). */
	private const COPY_FIELDS_TRANSIENT = 'timber_kit_wpml_block_override_copy_fields';

	/**
	 * Per-request memo of parsed source-post block trees, keyed by source post id.
	 * Source content can't meaningfully change mid-request, so this is safe.
	 *
	 * @var array<int, array<int|string, array<string, mixed>>>
	 */
	private static array $sourceBlocksMemo = array();

	/**
	 * Register the render-time override + the cache-invalidation hook.
	 */
	public static function register(): void {
		add_filter( 'render_block_data', array( self::class, 'filter' ), self::HOOK_PRIORITY, 1 );
		add_action( 'acf/update_field_group', array( self::class, 'flushCopyFieldsCache' ) );
	}

	/**
	 * `render_block_data` callback — the orchestration entry point.
	 *
	 * @param mixed $block The parsed block array WP is about to render.
	 * @return mixed The block, with Copy fields overridden from source when applicable.
	 */
	public static function filter( $block ) {
		if ( ! \is_array( $block ) ) {
			return $block;
		}

		$current_lang = (string) apply_filters( 'wpml_current_language', null );
		$default_lang = (string) apply_filters( 'wpml_default_language', null );

		if ( ! self::shouldOverride( $block, $current_lang, $default_lang ) ) {
			return $block;
		}

		$block_name  = \is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$copy_fields = self::getCopyFields( $block_name );
		if ( array() === $copy_fields ) {
			return $block;
		}

		$source_post_id = self::getSourcePostId( $default_lang );
		if ( $source_post_id <= 0 ) {
			return $block;
		}

		$source_block = self::findSourceBlock( $block, self::getSourceBlocks( $source_post_id ) );
		if ( null === $source_block ) {
			// Structural drift / no id / pre-ACF-v3 block → safe degrade to the
			// translation's own stored value.
			if ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[timber-kit] WpmlBlockOverride: no source block matched for ' . $block_name );
			}
			return $block;
		}

		return self::applyCopyFields( $block, $source_block, $copy_fields, $current_lang );
	}

	/**
	 * Whether this block is eligible for source-value override.
	 *
	 * Bypassed for: non-ACF blocks, admin / REST contexts (editor-preview
	 * safety — `render_block_data` runs there too), missing language context, and
	 * the source language itself (nothing to override when we *are* the source).
	 *
	 * @param array<string, mixed> $block
	 */
	public static function shouldOverride( array $block, string $current_lang, string $default_lang ): bool {
		if ( is_admin() || ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$block_name = $block['blockName'] ?? null;
		if ( ! \is_string( $block_name ) || ! str_starts_with( $block_name, 'acf/' ) ) {
			return false;
		}

		if ( '' === $current_lang || '' === $default_lang ) {
			return false;
		}

		return $current_lang !== $default_lang;
	}

	/**
	 * Resolve the Copy-field map for a block name: `[field_name => true]`.
	 *
	 * Auto-detected from the block's ACF field groups (fields whose ACFML
	 * preference is "Copy"), with `_`-prefixed WPML system fields skipped, then
	 * passed through the `timber_kit/wpml_block_override/copy_fields` filter so a
	 * project can declare or correct the set explicitly. Cached for a day in a
	 * transient, keyed by block name, flushed on `acf/update_field_group`.
	 *
	 * @param string $block_name e.g. `acf/jumbotron-video`.
	 * @return array<string, true>
	 */
	public static function getCopyFields( string $block_name ): array {
		if ( '' === $block_name ) {
			return array();
		}

		$cache = get_transient( self::COPY_FIELDS_TRANSIENT );
		$cache = \is_array( $cache ) ? $cache : array();
		if ( \array_key_exists( $block_name, $cache ) && \is_array( $cache[ $block_name ] ) ) {
			return $cache[ $block_name ];
		}

		$copy_fields = array();
		if ( \function_exists( 'acf_get_field_groups' ) && \function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups( array( 'block' => $block_name ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					$name = \is_array( $field ) ? ( $field['name'] ?? null ) : null;
					if ( ! \is_string( $name ) || '' === $name || str_starts_with( $name, '_' ) ) {
						continue;
					}
					$preference = apply_filters(
						'acfml_field_group_mode_field_translation_preference',
						null,
						$field,
						$group
					);
					if ( self::ACFML_PREFERENCE_COPY === $preference ) {
						$copy_fields[ $name ] = true;
					}
				}
			}
		}

		/**
		 * Filter the resolved Copy-field map for a block. Projects can declare
		 * Copy fields explicitly here when auto-detection can't see them.
		 *
		 * @param array<string, true> $copy_fields field_name => true.
		 * @param string              $block_name
		 */
		$copy_fields = (array) apply_filters( 'timber_kit/wpml_block_override/copy_fields', $copy_fields, $block_name );

		$cache[ $block_name ] = $copy_fields;
		set_transient( self::COPY_FIELDS_TRANSIENT, $cache, DAY_IN_SECONDS );

		return $copy_fields;
	}

	/**
	 * The source-language post id for the post currently being rendered.
	 *
	 * @return int 0 when there is no current post or no WPML translation mapping.
	 */
	public static function getSourcePostId( string $default_lang ): int {
		$post_id = get_the_ID();
		if ( ! \is_int( $post_id ) || $post_id <= 0 ) {
			return 0;
		}

		$post_type = get_post_type( $post_id );
		$source_id = apply_filters(
			'wpml_object_id',
			$post_id,
			\is_string( $post_type ) && '' !== $post_type ? $post_type : 'post',
			true,
			$default_lang
		);

		return \is_numeric( $source_id ) ? (int) $source_id : 0;
	}

	/**
	 * Parsed block tree of the source-language post, memoized per request.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function getSourceBlocks( int $source_post_id ): array {
		if ( isset( self::$sourceBlocksMemo[ $source_post_id ] ) ) {
			return self::$sourceBlocksMemo[ $source_post_id ];
		}

		$post    = get_post( $source_post_id );
		$content = ( \is_object( $post ) && isset( $post->post_content ) ) ? (string) $post->post_content : '';
		$blocks  = parse_blocks( $content );

		self::$sourceBlocksMemo[ $source_post_id ] = $blocks;

		return $blocks;
	}

	/**
	 * Find the source block paired with `$block` by its stable `attrs.id`.
	 *
	 * The `id` (e.g. `block_5fd76xyz`) is generated at source insert and inherited
	 * by every translation, so it's the reliable pairing key. Recurses into
	 * `innerBlocks` so a Copy-field block nested inside a layout block still
	 * matches.
	 *
	 * @param array<string, mixed>             $block
	 * @param array<int|string, array<string, mixed>> $source_blocks
	 * @return array<string, mixed>|null Null when no match (safe-degrade signal).
	 */
	public static function findSourceBlock( array $block, array $source_blocks ): ?array {
		$id = $block['attrs']['id'] ?? null;
		if ( ! \is_string( $id ) || '' === $id ) {
			return null;
		}

		foreach ( $source_blocks as $source ) {
			if ( ! \is_array( $source ) ) {
				continue;
			}

			if ( ( $source['attrs']['id'] ?? null ) === $id ) {
				return $source;
			}

			$inner = $source['innerBlocks'] ?? null;
			if ( \is_array( $inner ) && array() !== $inner ) {
				$found = self::findSourceBlock( $block, $inner );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Overwrite the block's Copy-field values with the source block's values.
	 *
	 * For each Copy field present on the source block, copies `attrs.data.<field>`
	 * (and ACF's `_<field>` field-key companion) onto the translation block.
	 * Numeric values are run through WPML's `wpml_object_id` for `attachment` so
	 * an image points at the translation's own duplicated media where one exists,
	 * falling back to the source id otherwise.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $source_block
	 * @param array<string, true>  $copy_fields
	 * @return array<string, mixed>
	 */
	public static function applyCopyFields( array $block, array $source_block, array $copy_fields, string $current_lang = '' ): array {
		$source_data = $source_block['attrs']['data'] ?? null;
		if ( ! \is_array( $source_data ) ) {
			return $block;
		}

		$attrs = $block['attrs'] ?? array();
		if ( ! \is_array( $attrs ) ) {
			$attrs = array();
		}
		$data = $attrs['data'] ?? array();
		if ( ! \is_array( $data ) ) {
			$data = array();
		}

		foreach ( $copy_fields as $field => $_enabled ) {
			if ( ! \array_key_exists( $field, $source_data ) ) {
				continue;
			}

			$data[ $field ] = self::remapAttachmentId( $source_data[ $field ], $current_lang );

			$key_field = '_' . $field;
			if ( \array_key_exists( $key_field, $source_data ) ) {
				$data[ $key_field ] = $source_data[ $key_field ];
			}
		}

		$attrs['data']   = $data;
		$block['attrs']  = $attrs;

		return $block;
	}

	/**
	 * Flush the per-block Copy-field transient (hooked to `acf/update_field_group`).
	 */
	public static function flushCopyFieldsCache(): void {
		delete_transient( self::COPY_FIELDS_TRANSIENT );
	}

	/**
	 * Remap a numeric attachment id to the current language's duplicated media.
	 *
	 * Non-numeric values (text, URLs, arrays) pass through untouched. For numeric
	 * values WPML returns the translation-language attachment id when "Duplicate
	 * media" produced one, or the original id otherwise — so a Copy image always
	 * resolves to a real attachment in the current language.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function remapAttachmentId( $value, string $current_lang ) {
		if ( '' === $current_lang || ! \is_numeric( $value ) ) {
			return $value;
		}

		$remapped = apply_filters( 'wpml_object_id', (int) $value, 'attachment', true, $current_lang );

		return \is_numeric( $remapped ) ? (int) $remapped : $value;
	}
}
