<?php

declare(strict_types=1);

/**
 * WpmlBlockOverride — runtime override of Copy field values in ACF Gutenberg blocks.
 *
 * @package Parisek\TimberKit
 *
 * Hooks `render_block_data` at priority 20 (after WPML's own handlers) and, for
 * ACF blocks rendered in a non-default language, overrides `attrs.data.<field>`
 * for fields marked `wpml_cf_preferences = 1` (Copy) with the source-language
 * post's value. Reference ids are remapped to their target-language equivalents
 * via `wpml_object_id` so a translated page points at translated entities:
 * image/file/gallery → attachments, post_object/relationship/page_link → posts,
 * taxonomy → terms. Nested fields inside repeater/group containers are supported
 * through path-aware key generation.
 *
 * A translation block is matched to its source counterpart by position — the Nth
 * occurrence of a block name in the translation pairs with the Nth in the source.
 * ACF blocks carry no stable per-instance id in `post_content` (`attrs.id` is the
 * optional, manually-set HTML anchor), and WPML/ATE preserves block order and
 * count when rebuilding a translation, so the ordinal is a reliable join key.
 * Guarded by a structural-integrity check: if the source and translation disagree
 * on how many blocks of a name exist (block added / removed / manually reordered),
 * that whole block name is skipped — a safe no-op rather than a risk of overriding
 * the wrong instance.
 *
 * Solves the long-standing WPML problem where changing a Copy field (typically
 * an image) in the source language never propagates to translated post_content
 * without manual ATE re-job. ACF configuration becomes the single source of truth
 * for Copy fields; no DB writes, no admin UI, no drift.
 *
 * Filters exposed (package-owned, stable across versions):
 *   - timber_kit/wpml_block_override/should_override  (bool $default, array $block, string $current_lang, string $default_lang)
 *   - timber_kit/wpml_block_override/copy_fields      (array $copy_fields, string $block_name)
 *
 * Requirements (verified at register()):
 *   - WPML active (ICL_SITEPRESS_VERSION defined)
 *   - ACF Pro active (acf_get_field_groups available)
 *
 * Known limitation — programmatic field group registration:
 *   Invalidation hooks (acf/update_field_group + save_post_acf-field-group) do not
 *   fire when field groups are registered purely in PHP via acf_add_local_field_group().
 *   Code-only changes to wpml_cf_preferences will serve stale cache for up to
 *   CACHE_TTL. Under WP_DEBUG the persistent transient is bypassed so dev iteration
 *   is unaffected. Production workaround: `wp transient delete timber_kit_wpml_copy_fields_index`
 *   in the deploy script, or include a theme-version constant in the cache key.
 *
 * Not supported (this iteration):
 *   - flexible_content sub-fields (per-layout sub_fields require layout-name awareness)
 *
 * @see https://github.com/parisek/timber-kit/issues/29 — research, prior art, design discussion
 */

namespace Parisek\TimberKit;

final class WpmlBlockOverride {

	private const HOOK_PRIORITY = 20;
	private const CACHE_KEY = 'timber_kit_wpml_copy_fields_index';
	// Literal seconds-per-day instead of `DAY_IN_SECONDS` — class constants are
	// evaluated at autoload time, before WordPress bootstrap is guaranteed to
	// have defined the global constant. Using the literal keeps the class
	// self-contained for unit tests and any non-WP load context.
	private const CACHE_TTL = 86400;

	/** @var array<int, array<int, array>> per-request memo: source_post_id → flat blocks */
	private static array $sourceBlocksMemo = [];

	/** @var array<string, array>|null per-request memo of full copy-fields index */
	private static ?array $copyFieldsIndex = null;

	/** @var array<int, array<string, int>> per-render-pass positional counter: source_post_id → block_name → next ordinal */
	private static array $blockOrdinals = [];

	public static function register(): void {
		if ( ! self::dependenciesAvailable() ) return;

		\add_filter( 'render_block_data', [ self::class, 'filter' ], self::HOOK_PRIORITY, 2 );
		// Reset positional counters at the start of each `the_content` pass (priority
		// before do_blocks at 9) so a secondary do_blocks() pass — e.g. an SEO plugin
		// parsing content for a meta description — can't desync the ordinals the
		// visible render relies on.
		\add_filter( 'the_content', [ self::class, 'resetBlockOrdinals' ], 5 );
		// Both hooks fire on a field-group save: acf/update_field_group from the admin
		// UI, save_post_acf-field-group additionally from programmatic saves such as
		// `wp acf json sync`. Registering both keeps the cache invalidated across paths.
		\add_action( 'acf/update_field_group', [ self::class, 'invalidateCopyFieldsCache' ] );
		\add_action( 'save_post_acf-field-group', [ self::class, 'invalidateCopyFieldsCache' ] );
	}

	/**
	 * Soft-dependency probe for the two plugins this feature builds on. These
	 * capability checks are the idiomatic WordPress way to detect optional
	 * dependencies — there is no plugin registry to query at this layer, and
	 * `is_plugin_active()` needs an admin include and only reflects activation,
	 * not load order. WPML advertises itself via the `ICL_SITEPRESS_VERSION`
	 * constant; ACF Pro via the `acf_get_field_groups()` function we actually
	 * call (checking the exact API guards against partial/stub ACF builds too).
	 */
	private static function dependenciesAvailable(): bool {
		return \defined( 'ICL_SITEPRESS_VERSION' ) && \function_exists( 'acf_get_field_groups' );
	}

	public static function resetBlockOrdinals( string $content ): string {
		self::$blockOrdinals = [];
		return $content;
	}

	public static function filter( array $block, array $source_block ): array {
		// $source_block is the pre-filter copy (WP core hook arg) — NOT the source-lang block.
		// Source-language block resolution happens below via getSourceBlocks().
		if ( ! self::shouldOverride( $block ) ) return $block;

		$block_name = self::getAcfBlockName( $block['blockName'] );
		$copy_fields = self::getCopyFields( $block_name );
		if ( empty( $copy_fields ) ) return $block;

		global $post;
		if ( ! $post ) return $block;

		$source_post_id = self::getSourcePostId( $post->ID, $post->post_type );
		if ( ! $source_post_id ) return $block;

		$source_blocks = self::getSourceBlocks( $source_post_id );

		// Structural-integrity gate: only override when source and translation agree
		// on how many blocks of this name exist. Any difference means the
		// translation's structure has diverged from the source (block added,
		// removed, or manually reordered), so positional matching could land a Copy
		// value on the wrong instance — skip the whole block name as a no-op.
		// getSourceBlocks() parses + flattens + memoizes any post; here it's the
		// translation being rendered.
		$translation_blocks = self::getSourceBlocks( $post->ID );
		if ( ! self::blockCountsMatch( $block['blockName'] ?? '', $source_blocks, $translation_blocks ) ) {
			self::logStructuralMismatch( $block, $source_post_id );
			return $block;
		}

		$matched = self::findSourceBlock( $block, $source_blocks, $source_post_id );
		if ( ! $matched ) {
			self::logMissingMatch( $block, $source_post_id );
			return $block;
		}

		$current_lang = (string) \apply_filters( 'wpml_current_language', '' );
		return self::applyCopyFields(
			$block, $matched, $copy_fields, $source_post_id, $current_lang
		);
	}

	private static function shouldOverride( array $block ): bool {
		if ( ! \str_starts_with( $block['blockName'] ?? '', 'acf/' ) ) return false;
		if ( \is_admin() ) return false;
		if ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;

		$current = \apply_filters( 'wpml_current_language', null );
		$default = \apply_filters( 'wpml_default_language', null );
		if ( ! $current || ! $default || $current === $default ) return false;

		return (bool) \apply_filters(
			'timber_kit/wpml_block_override/should_override',
			true, $block, $current, $default
		);
	}

	private static function getAcfBlockName( string $full_name ): string {
		return \substr( $full_name, \strlen( 'acf/' ) );
	}

	private static function getSourcePostId( int $current_id, string $post_type ): ?int {
		$source_lang = \apply_filters( 'wpml_default_language', null );
		if ( ! $source_lang ) return null;

		$source = \apply_filters( 'wpml_object_id', $current_id, $post_type, false, $source_lang );
		// `! $source` covers both null (no translation) and 0 (trashed source edge case).
		// Don't refactor to `=== null` without re-adding the zero guard.
		if ( ! $source || (int) $source === $current_id ) return null;
		return (int) $source;
	}

	private static function getSourceBlocks( int $source_post_id ): array {
		if ( isset( self::$sourceBlocksMemo[ $source_post_id ] ) ) {
			return self::$sourceBlocksMemo[ $source_post_id ];
		}
		$post = \get_post( $source_post_id );
		if ( ! $post ) return [];

		$flat = self::flattenBlocks( \parse_blocks( $post->post_content ) );
		self::$sourceBlocksMemo[ $source_post_id ] = $flat;
		return $flat;
	}

	private static function flattenBlocks( array $blocks ): array {
		$result = [];
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) $result[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$result = \array_merge( $result, self::flattenBlocks( $block['innerBlocks'] ) );
			}
		}
		return $result;
	}

	/**
	 * Match a translation block to its source-language counterpart by position.
	 *
	 * Real ACF blocks carry no stable per-instance identifier in their serialized
	 * `post_content` — `attrs.id` is the optional HTML anchor (block.json
	 * `supports.anchor`), set manually by an editor and absent on virtually all
	 * blocks. So we pair the Nth occurrence of a block name in the translation
	 * with the Nth occurrence in the source. WPML/ATE rebuilds a translation from
	 * the source as a template, preserving block order and count, which makes the
	 * ordinal a reliable join key.
	 *
	 * The per-name ordinal advances once per call; callers rely on `filter()`
	 * invoking this in document order. If a custom `should_override` filter vetoes
	 * only *some* instances of a duplicated block name it can desync the ordinal —
	 * keep such a filter's decision deterministic per block name, not per instance.
	 *
	 * `filter()` runs `blockCountsMatch()` upstream, so by the time we get here the
	 * counts agree and every ordinal has a counterpart. The null-on-overflow return
	 * below is kept as defense-in-depth (and for direct unit testing).
	 */
	private static function findSourceBlock( array $block, array $source_blocks, int $source_post_id ): ?array {
		$block_name = $block['blockName'] ?? '';
		if ( $block_name === '' ) return null;

		$ordinal = self::$blockOrdinals[ $source_post_id ][ $block_name ] ?? 0;
		self::$blockOrdinals[ $source_post_id ][ $block_name ] = $ordinal + 1;

		$occurrence = 0;
		foreach ( $source_blocks as $candidate ) {
			if ( ( $candidate['blockName'] ?? '' ) !== $block_name ) continue;
			if ( $occurrence === $ordinal ) return $candidate;
			$occurrence++;
		}
		return null;
	}

	/**
	 * Structural-integrity gate. True only when the source and translation hold the
	 * same number of blocks of `$block_name` — the precondition under which
	 * positional matching is sound. A mismatch (added / removed / reordered block)
	 * means the Nth source block may not correspond to the Nth translation block, so
	 * callers skip the override entirely rather than risk landing a Copy value on the
	 * wrong instance.
	 */
	private static function blockCountsMatch( string $block_name, array $source_blocks, array $translation_blocks ): bool {
		if ( $block_name === '' ) return false;
		return self::countByName( $source_blocks, $block_name ) === self::countByName( $translation_blocks, $block_name );
	}

	private static function countByName( array $blocks, string $block_name ): int {
		$count = 0;
		foreach ( $blocks as $b ) {
			if ( ( $b['blockName'] ?? '' ) === $block_name ) $count++;
		}
		return $count;
	}

	private static function getCopyFields( string $block_name ): array {
		$index = self::getCopyFieldsIndex();
		return $index[ $block_name ] ?? [];
	}

	/**
	 * Build (or fetch from cache) the full index of block_short_name → copy_fields[].
	 *
	 * Cold cache cost is one sweep of all registered ACF block types + their
	 * field groups + recursive walkFields(). Stored as a single transient so
	 * subsequent renders make at most one cache lookup regardless of how many
	 * block types appear on the page.
	 *
	 * Per-request memo prevents repeated transient hits within one render.
	 *
	 * Under WP_DEBUG the persistent transient is bypassed entirely — see the
	 * class-level docblock for the rationale (programmatic field group
	 * registration gap).
	 */
	private static function getCopyFieldsIndex(): array {
		if ( self::$copyFieldsIndex !== null ) {
			return self::$copyFieldsIndex;
		}

		// Under WP_DEBUG, bypass the persistent transient — developers iterating
		// on PHP-registered field groups (`acf_add_local_field_group`) get the
		// fresh state on each request without needing to manually invalidate.
		// Per-request memo still applies, so render perf within one request is
		// unaffected.
		$skip_transient = \defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( ! $skip_transient ) {
			$cached = \get_transient( self::CACHE_KEY );
			if ( \is_array( $cached ) ) {
				self::$copyFieldsIndex = $cached;
				return self::$copyFieldsIndex;
			}
		}

		$index = [];
		if ( \function_exists( 'acf_get_block_types' ) ) {
			foreach ( \acf_get_block_types() as $block ) {
				$full_name = $block['name'] ?? '';
				if ( ! $full_name ) continue;
				$short = \str_starts_with( $full_name, 'acf/' )
					? \substr( $full_name, \strlen( 'acf/' ) )
					: $full_name;

				$copy_fields = [];
				foreach ( \acf_get_field_groups( [ 'block' => $full_name ] ) as $group ) {
					$fields = \acf_get_fields( $group ) ?: [];
					$copy_fields = [ ...$copy_fields, ...self::walkFields( $fields, [] ) ];
				}

				$filtered = (array) \apply_filters(
					'timber_kit/wpml_block_override/copy_fields',
					$copy_fields, $short
				);
				// Normalize filter output — themes/plugins may return entries
				// without the expected shape. Drop anything missing `field.name`;
				// default `path` to []. This keeps applyCopyFields()'s reads safe.
				$index[ $short ] = self::normalizeCopyFields( $filtered );
			}
		}

		if ( ! $skip_transient ) {
			\set_transient( self::CACHE_KEY, $index, self::CACHE_TTL );
		}
		self::$copyFieldsIndex = $index;
		return self::$copyFieldsIndex;
	}

	/**
	 * Recursively collect Copy-marked leaf fields with their container path.
	 *
	 * Containers (repeater, group) are not themselves overridden — we descend into
	 * their sub_fields. Each leaf marked Copy (`wpml_cf_preferences === 1`) is
	 * collected with the chain of parent containers needed to reconstruct ACF's
	 * flattened key pattern at apply time.
	 *
	 * flexible_content is intentionally skipped — its per-layout sub_fields
	 * require layout-name awareness and aren't supported in this iteration.
	 */
	private static function walkFields( array $fields, array $parent_path ): array {
		$copy_fields = [];
		foreach ( $fields as $field ) {
			$name = $field['name'] ?? '';
			if ( $name === '' || \str_starts_with( $name, '_' ) ) continue;

			$type = $field['type'] ?? '';

			if ( $type === 'repeater' || $type === 'group' ) {
				$sub_fields = $field['sub_fields'] ?? [];
				$child_path = [ ...$parent_path, [ 'name' => $name, 'type' => $type ] ];
				$copy_fields = [ ...$copy_fields, ...self::walkFields( $sub_fields, $child_path ) ];
				continue;
			}

			if ( $type === 'flexible_content' ) continue;

			if ( (int) ( $field['wpml_cf_preferences'] ?? 0 ) === 1 ) {
				$copy_fields[] = [ 'field' => $field, 'path' => $parent_path ];
			}
		}
		return $copy_fields;
	}

	/**
	 * Normalize the output of the `timber_kit/wpml_block_override/copy_fields`
	 * filter to the shape applyCopyFields() expects. Drops malformed entries
	 * silently — themes that hook this filter shouldn't crash the render
	 * pipeline by returning garbage.
	 *
	 * Required shape per entry: `['field' => ['name' => non-empty-string, ...], 'path' => array]`.
	 */
	private static function normalizeCopyFields( array $entries ): array {
		$result = [];
		foreach ( $entries as $entry ) {
			if ( ! \is_array( $entry ) ) continue;
			if ( ! isset( $entry['field'] ) || ! \is_array( $entry['field'] ) ) continue;
			$name = $entry['field']['name'] ?? null;
			if ( ! \is_string( $name ) || $name === '' ) continue;

			// Default missing or scalar `path` to []; validate elements of an
			// array `path` so overrideNestedPaths() can safely do
			// $component['name'] / ['type'] access. Drop the whole entry if any
			// component is malformed (theme returned garbage via filter).
			if ( ! isset( $entry['path'] ) || ! \is_array( $entry['path'] ) ) {
				$entry['path'] = [];
			} else {
				$valid = true;
				foreach ( $entry['path'] as $component ) {
					if ( ! \is_array( $component ) ) { $valid = false; break; }
					$comp_name = $component['name'] ?? null;
					$comp_type = $component['type'] ?? null;
					if ( ! \is_string( $comp_name ) || $comp_name === '' ) { $valid = false; break; }
					if ( ! \in_array( $comp_type, [ 'repeater', 'group' ], true ) ) { $valid = false; break; }
				}
				if ( ! $valid ) continue;
			}

			$result[] = $entry;
		}
		return $result;
	}

	public static function invalidateCopyFieldsCache(): void {
		self::$copyFieldsIndex = null;
		\delete_transient( self::CACHE_KEY );
	}

	private static function applyCopyFields(
		array $block,
		array $source_block,
		array $copy_fields,
		int $source_post_id,
		string $current_lang
	): array {
		// Defensive: a prior render_block_data filter may set attrs.data to a scalar.
		// Writing to $arr['data'][k] on non-array fatals in PHP 8.
		if ( ! \is_array( $block['attrs']['data'] ?? null ) ) {
			$block['attrs']['data'] = [];
		}
		// Source block can have the same corruption — array_key_exists requires array.
		$source_data = \is_array( $source_block['attrs']['data'] ?? null )
			? $source_block['attrs']['data']
			: [];

		foreach ( $copy_fields as $entry ) {
			$field = $entry['field'];
			$path = $entry['path'] ?? [];

			if ( empty( $path ) ) {
				// Top-level: key is the field name directly.
				$block = self::overrideKey(
					$block, $source_data, $field['name'], $field, $current_lang, $source_post_id
				);
				continue;
			}

			// Nested: walk path with recursive prefix generation, then apply leaf.
			$block = self::overrideNestedPaths(
				$block, $source_data, $path, $field, '', $current_lang, $source_post_id
			);
		}
		return $block;
	}

	/**
	 * Iterate container path, expanding repeater rows and group prefixes,
	 * then call overrideKey() for each generated flat key.
	 */
	private static function overrideNestedPaths(
		array $block,
		array $source_data,
		array $remaining_path,
		array $field,
		string $prefix,
		string $current_lang,
		int $source_post_id
	): array {
		if ( empty( $remaining_path ) ) {
			$key = $prefix . $field['name'];
			return self::overrideKey(
				$block, $source_data, $key, $field, $current_lang, $source_post_id
			);
		}

		$component = $remaining_path[0];
		$rest = \array_slice( $remaining_path, 1 );
		$name = $component['name'];
		$type = $component['type'];

		if ( $type === 'group' ) {
			$new_prefix = "{$prefix}{$name}_";
			return self::overrideNestedPaths(
				$block, $source_data, $rest, $field, $new_prefix, $current_lang, $source_post_id
			);
		}

		// repeater: row count lives at "{prefix}{name}" in source_data.
		$count_key = "{$prefix}{$name}";
		$count = (int) ( $source_data[ $count_key ] ?? 0 );
		for ( $i = 0; $i < $count; $i++ ) {
			$new_prefix = "{$prefix}{$name}_{$i}_";
			$block = self::overrideNestedPaths(
				$block, $source_data, $rest, $field, $new_prefix, $current_lang, $source_post_id
			);
		}
		return $block;
	}

	/**
	 * Apply Copy override to a single flat key. No-op if source key is absent
	 * or new value equals current.
	 */
	private static function overrideKey(
		array $block,
		array $source_data,
		string $key,
		array $field,
		string $current_lang,
		int $source_post_id
	): array {
		if ( ! \array_key_exists( $key, $source_data ) ) return $block;

		$old = $block['attrs']['data'][ $key ] ?? null;
		$new = self::remapReference( $source_data[ $key ], $field, $current_lang );

		if ( $old === $new ) return $block;

		$block['attrs']['data'][ $key ] = $new;
		self::logOverride( $field['name'], $old, $new, $block['blockName'], $source_post_id );
		return $block;
	}

	/**
	 * Remap a Copy field's reference id(s) to the target language so a translated
	 * page points at the translated entity, not the source-language one.
	 *
	 * Element type passed to `wpml_object_id` depends on the ACF field type:
	 *   - image / file / gallery → 'attachment'
	 *   - post_object / relationship / page_link → the referenced post's own post
	 *     type (post_object can target mixed types, so it's resolved per id)
	 *   - taxonomy → the field's taxonomy slug
	 *
	 * Other types (text, user, link, …) are returned unchanged — `user` because
	 * WPML doesn't translate users, `link` because it stores a URL structure WPML
	 * converts through its own link handling. Non-numeric entries (e.g. a
	 * `page_link` holding a raw URL) also pass through untouched.
	 */
	private static function remapReference( mixed $value, array $field, string $target_lang ): mixed {
		$type = $field['type'] ?? '';

		if ( \in_array( $type, [ 'image', 'file', 'gallery' ], true ) ) {
			return self::remapIds( $value, $target_lang, static fn( int $id ): string => 'attachment' );
		}

		if ( \in_array( $type, [ 'post_object', 'relationship', 'page_link' ], true ) ) {
			return self::remapIds( $value, $target_lang, static fn( int $id ): string => \get_post_type( $id ) ?: 'post' );
		}

		if ( $type === 'taxonomy' ) {
			$taxonomy = $field['taxonomy'] ?? '';
			if ( $taxonomy === '' ) return $value;
			return self::remapIds( $value, $target_lang, static fn( int $id ): string => $taxonomy );
		}

		return $value;
	}

	/**
	 * Apply `wpml_object_id` to a single id or a list of ids. `$element_type_for`
	 * resolves the WPML element type for each id. Non-numeric / non-positive
	 * values pass through unchanged.
	 *
	 * @param callable(int):string $element_type_for
	 */
	private static function remapIds( mixed $value, string $target_lang, callable $element_type_for ): mixed {
		if ( \is_array( $value ) ) {
			return \array_map(
				static fn( $id ) => self::remapId( $id, $target_lang, $element_type_for ),
				$value
			);
		}
		return self::remapId( $value, $target_lang, $element_type_for );
	}

	/**
	 * @param callable(int):string $element_type_for
	 */
	private static function remapId( mixed $value, string $target_lang, callable $element_type_for ): mixed {
		if ( ! \is_numeric( $value ) ) return $value;
		$id = (int) $value;
		if ( $id <= 0 ) return $value;
		return \apply_filters( 'wpml_object_id', $id, $element_type_for( $id ), true, $target_lang );
	}

	private static function logOverride(
		string $field, mixed $old, mixed $new, string $block_name, int $source_post_id
	): void {
		if ( ! \defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		\error_log( \sprintf(
			'[timber_kit/wpml_block_override] override field=%s block=%s source_post=%d old=%s new=%s',
			$field, $block_name, $source_post_id,
			self::shortDump( $old ), self::shortDump( $new )
		) );
	}

	private static function logMissingMatch( array $block, int $source_post_id ): void {
		if ( ! \defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		// A miss now means structural drift: the source post has fewer same-named
		// blocks than the translation at this position. Report the ordinal that
		// failed (the counter was already advanced in findSourceBlock).
		$block_name = $block['blockName'] ?? '?';
		$ordinal = ( self::$blockOrdinals[ $source_post_id ][ $block_name ] ?? 1 ) - 1;
		\error_log( \sprintf(
			'[timber_kit/wpml_block_override] no source match blockName=%s ordinal=%d source_post=%d',
			$block_name,
			$ordinal,
			$source_post_id
		) );
	}

	private static function logStructuralMismatch( array $block, int $source_post_id ): void {
		if ( ! \defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		\error_log( \sprintf(
			'[timber_kit/wpml_block_override] structural mismatch blockName=%s source_post=%d — block counts differ, skipping',
			$block['blockName'] ?? '?',
			$source_post_id
		) );
	}

	private static function shortDump( mixed $value ): string {
		if ( \is_scalar( $value ) ) return (string) $value;
		return \substr( (string) \json_encode( $value ), 0, 80 );
	}
}
