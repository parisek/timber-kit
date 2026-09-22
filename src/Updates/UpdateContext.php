<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Updates;

class UpdateContext {

	/** @var callable(string): void|null */
	private $logger;

	/**
	 * @param (callable(string): void)|null $logger
	 */
	public function __construct( private readonly bool $dry_run, ?callable $logger = null ) {
		$this->logger = $logger;
	}

	public function isDryRun(): bool {
		return $this->dry_run;
	}

	public function log( string $message ): void {
		if ( null !== $this->logger ) {
			( $this->logger )( $message );
			return;
		}

		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( $message );
			return;
		}

		error_log( $message );
	}

	/**
	 * @param callable(array<string, mixed>, \WP_Post, string): (array<string, mixed>|null) $transform
	 * @param list<int>                                                               $post_ids
	 * @return array{scanned: int, changed: int, skipped: int, errors: list<string>}
	 */
	public function transformBlocks( string $block_name, callable $transform, array $post_ids ): array {
		return $this->walkPosts( $block_name, null, $transform, $post_ids );
	}

	/**
	 * Rename a block while rewriting its data — "adopt" one block into another.
	 *
	 * `transformBlocks()` replaces `attrs.data` and never touches `blockName`,
	 * so it cannot express a rename. This method does, and it reuses the same
	 * walk: nested `innerBlocks`, WPML fan-out, revisions, `wp_slash()`,
	 * dry-run suppression and the summary shape are identical.
	 *
	 * Two differences from `transformBlocks()`, both deliberate:
	 *
	 *  - **Unchanged data still writes.** The rename is itself the change, so
	 *    returning the array you were handed is a valid no-op transform that
	 *    still produces a write.
	 *  - **Blocks already carrying `$to` are left alone**, which makes the
	 *    author's half of the two-layer idempotence contract the default
	 *    rather than a thing to remember.
	 *
	 * `attrs.name` is written alongside `blockName`, because ACF resolves the
	 * field group through it; rewriting only `blockName` yields a renamed
	 * block with no fields.
	 *
	 * Pair it with `mapFieldKeys()` when the two blocks have different field
	 * groups — the `_`-prefixed twins hold the OLD group's field keys and must
	 * be resolved against the new one.
	 *
	 * @param callable(array<string, mixed>, \WP_Post, string): (array<string, mixed>|null) $transform Returns the new data, or null to leave the block untouched.
	 * @param list<int>                                                                     $post_ids
	 * @return array{scanned: int, changed: int, skipped: int, errors: list<string>}
	 */
	public function adoptBlocks( string $from, string $to, callable $transform, array $post_ids ): array {
		if ( $from === $to ) {
			// Every block would match while the unchanged-data short-circuit
			// stays off, so each run would rewrite every post again.
			throw new \InvalidArgumentException(
				sprintf( 'adoptBlocks() renames one block into another; for data-only work on %s use transformBlocks().', $from )
			);
		}

		return $this->walkPosts( $from, $to, $transform, $post_ids );
	}

	/**
	 * Resolve every value key's `_`-prefixed field-key twin from the block's
	 * registered ACF field group.
	 *
	 * The field keys are read from the group, never derived from the block
	 * name. A derived key holds only while every project names its fields
	 * `field_<block>_<name>`, which is a convention, not a rule: a group built
	 * in the ACF UI carries keys like `field_5f3a91c2b7e04`, and a repeater's
	 * row key (`items_0_label`) never matches its sub-field's key at all.
	 * Reading the group is correct in all three cases.
	 *
	 * Incoming twins are discarded and rewritten, so data lifted from another
	 * block can be passed straight in.
	 *
	 * @param array<string, mixed> $data       Value keys, with or without their twins.
	 * @param string               $block_name Target block, e.g. `acf/hero-glossary`.
	 * @return array<string, mixed> The data with every twin resolved.
	 * @throws \RuntimeException         When the block has no registered field group.
	 * @throws \InvalidArgumentException When a value key is not defined by that group.
	 */
	public function mapFieldKeys( array $data, string $block_name ): array {
		$fields = $this->fieldsFor( $block_name );

		if ( [] === $fields ) {
			throw new \RuntimeException(
				sprintf( 'No ACF field group is registered for block %s — is it loaded, and is ACF active?', $block_name )
			);
		}

		$mapped  = [];
		$unknown = [];

		foreach ( $data as $key => $value ) {
			if ( str_starts_with( (string) $key, '_' ) ) {
				continue;
			}

			$field_keys = $this->resolveFieldKeys( $fields, (string) $key );

			if ( [] === $field_keys ) {
				$unknown[] = (string) $key;
				continue;
			}

			if ( count( $field_keys ) > 1 ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Block %s flattens more than one field to %s (%s) — the twin cannot be resolved without guessing.',
						$block_name,
						$key,
						implode( ', ', $field_keys )
					)
				);
			}

			$mapped[ $key ]       = $value;
			$mapped[ '_' . $key ] = $field_keys[0];
		}

		if ( [] !== $unknown ) {
			throw new \InvalidArgumentException(
				sprintf( 'Block %s defines no field for: %s', $block_name, implode( ', ', $unknown ) )
			);
		}

		return $mapped;
	}

	/**
	 * @param callable(array<string, mixed>, \WP_Post, string): (array<string, mixed>|null) $transform
	 * @param list<int>                                                                     $post_ids
	 * @return array{scanned: int, changed: int, skipped: int, errors: list<string>}
	 */
	private function walkPosts( string $block_name, ?string $rename, callable $transform, array $post_ids ): array {
		$summary = [ 'scanned' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => [] ];

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				$summary['errors'][] = sprintf( 'Post #%d: not found', $post_id );
				continue;
			}

			foreach ( $this->translationsFor( $post ) as $translation ) {
				++$summary['scanned'];
				$changed = $this->transformPostBlocks( $translation['post'], $translation['lang'], $block_name, $rename, $transform );
				if ( null === $changed ) {
					++$summary['skipped'];
					continue;
				}

				++$summary['changed'];
				if ( $this->dry_run ) {
					$this->log( sprintf( 'Dry-run post #%d: %s', $translation['post']->ID, substr( $changed, 0, 500 ) ) );
					continue;
				}

				$result = wp_update_post(
					[
						'ID'           => $translation['post']->ID,
						// wp_update_post() runs wp_unslash() on its input; raw
						// serialized block JSON would lose every backslash
						// (\\u003c escapes render as literal "u003c" on the
						// front end), so the content must go in slashed.
						'post_content' => wp_slash( $changed ),
					],
					true
				);
				if ( $result instanceof \WP_Error ) {
					$summary['errors'][] = sprintf( 'Post #%d: %s', $translation['post']->ID, $result->get_error_message() );
				}
			}
		}

		return $summary;
	}

	public function mapAttachment( int $attachment_id, string $lang ): int {
		$mapped = apply_filters( 'wpml_object_id', $attachment_id, 'attachment', true, $lang );

		return null === $mapped ? $attachment_id : (int) $mapped;
	}

	/**
	 * @return list<array{post: \WP_Post, lang: string}>
	 */
	private function translationsFor( \WP_Post $post ): array {
		$element_type = 'post_' . $post->post_type;
		$trid         = apply_filters( 'wpml_element_trid', null, $post->ID, $element_type );
		if ( null === $trid ) {
			return [ [ 'post' => $post, 'lang' => '' ] ];
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
		if ( ! is_array( $translations ) ) {
			return [ [ 'post' => $post, 'lang' => '' ] ];
		}

		$posts = [];
		foreach ( $translations as $translation ) {
			$element_id = is_object( $translation ) && isset( $translation->element_id ) ? (int) $translation->element_id : 0;
			if ( $element_id <= 0 ) {
				continue;
			}

			$translated_post = get_post( $element_id );
			if ( ! $translated_post instanceof \WP_Post ) {
				continue;
			}

			$lang = is_object( $translation ) && isset( $translation->language_code ) ? (string) $translation->language_code : '';
			$posts[] = [ 'post' => $translated_post, 'lang' => $lang ];
		}

		return [] === $posts ? [ [ 'post' => $post, 'lang' => '' ] ] : $posts;
	}

	/**
	 * @param callable(array<string, mixed>, \WP_Post, string): (array<string, mixed>|null) $transform
	 */
	private function transformPostBlocks( \WP_Post $post, string $lang, string $block_name, ?string $rename, callable $transform ): ?string {
		$blocks  = parse_blocks( $post->post_content );
		$changed = $this->transformBlockList( $blocks, $block_name, $rename, $transform, $post, $lang );

		return $changed ? serialize_blocks( $blocks ) : null;
	}

	/**
	 * @param array<mixed>                                                         $blocks
	 * @param callable(array<string, mixed>, \WP_Post, string): (array<string, mixed>|null) $transform
	 */
	private function transformBlockList( array &$blocks, string $block_name, ?string $rename, callable $transform, \WP_Post $post, string $lang ): bool {
		$changed = false;

		foreach ( $blocks as &$block ) {
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$changed = $this->transformBlockList( $block['innerBlocks'], $block_name, $rename, $transform, $post, $lang ) || $changed;
			}

			$name  = $block['blockName'] ?? null;
			$attrs = $block['attrs']['name'] ?? null;

			// A rename that rewrote blockName but not attrs.name leaves ACF
			// unable to resolve the field group. Treat it as unfinished rather
			// than as already adopted, or that state becomes permanent.
			$is_match = $name === $block_name
				|| ( null !== $rename && $name === $rename && $attrs === $block_name );

			if ( ! $is_match ) {
				continue;
			}

			$data = $block['attrs']['data'] ?? [];
			if ( ! is_array( $data ) ) {
				$data = [];
			}

			$next = $transform( $data, $post, $lang );
			if ( null === $next ) {
				continue;
			}

			// A rename is a change even when the data comes back untouched,
			// so only the data-only path may short-circuit here.
			if ( null === $rename && $next === $data ) {
				continue;
			}

			$block['attrs']['data'] = $next;

			if ( null !== $rename ) {
				$block['blockName']     = $rename;
				$block['attrs']['name'] = $rename;
			}

			$changed = true;
		}
		unset( $block );

		return $changed;
	}

	/**
	 * Every field of the block's registered group, sub-fields included.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fieldsFor( string $block_name ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}

		$fields = [];
		foreach ( \acf_get_field_groups( [ 'block' => $block_name ] ) as $group ) {
			$fields = [ ...$fields, ...array_values( (array) ( \acf_get_fields( $group ) ?: [] ) ) ];
		}

		return $fields;
	}

	/**
	 * Every ACF key a value key could belong to, descending into containers.
	 *
	 * Returns a list rather than one key on purpose: two fields can flatten to
	 * the same value key — a top-level `item_label` and a group `item` with a
	 * `label` sub-field both produce `item_label` — and picking one by
	 * traversal order would hand ACF the wrong field. The caller refuses
	 * instead.
	 *
	 * A repeater's rows prefix the sub-field with an index (`items_0_label`);
	 * a group's do not (`settings_title`). The index is therefore stripped
	 * only under a repeater, so `settings_9_title` stays unknown rather than
	 * resolving to the `title` sub-field.
	 *
	 * @param list<array<string, mixed>> $fields
	 * @return list<string> Distinct field keys, empty when nothing matches.
	 */
	private function resolveFieldKeys( array $fields, string $key ): array {
		$found = [];

		foreach ( $fields as $field ) {
			if ( ( $field['name'] ?? null ) === $key && isset( $field['key'] ) ) {
				$found[] = (string) $field['key'];
			}
		}

		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name || ! str_starts_with( $key, $name . '_' ) ) {
				continue;
			}

			$children = $this->childFields( $field );
			if ( [] === $children ) {
				continue;
			}

			$rest       = substr( $key, strlen( $name ) + 1 );
			$candidates = [ $rest ];

			if ( 'repeater' === ( $field['type'] ?? '' ) ) {
				$without_index = preg_replace( '/^\d+_/', '', $rest );
				if ( is_string( $without_index ) && $without_index !== $rest ) {
					$candidates = [ $without_index ];
				}
			}

			foreach ( $candidates as $candidate ) {
				$found = [ ...$found, ...$this->resolveFieldKeys( $children, $candidate ) ];
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Sub-fields of a container.
	 *
	 * Flexible content is deliberately absent. Its layouts may each define a
	 * field of the same name, and which one a row uses is recorded in the
	 * data (`content_0_acf_fc_layout`), not in the schema — so resolving by
	 * name alone would return whichever layout happens to come first. Until
	 * the row's layout is read, such a key resolves to nothing and the caller
	 * throws, which is the safe half of the choice.
	 *
	 * @param array<string, mixed>       $field
	 * @return list<array<string, mixed>>
	 */
	private function childFields( array $field ): array {
		if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			return array_values( $field['sub_fields'] );
		}

		return [];
	}
}
