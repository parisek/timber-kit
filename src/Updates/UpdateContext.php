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
		$summary = [ 'scanned' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => [] ];

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				$summary['errors'][] = sprintf( 'Post #%d: not found', $post_id );
				continue;
			}

			foreach ( $this->translationsFor( $post ) as $translation ) {
				++$summary['scanned'];
				$changed = $this->transformPostBlocks( $translation['post'], $translation['lang'], $block_name, $transform );
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
						'post_content' => $changed,
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
	private function transformPostBlocks( \WP_Post $post, string $lang, string $block_name, callable $transform ): ?string {
		$blocks  = parse_blocks( $post->post_content );
		$changed = $this->transformBlockList( $blocks, $block_name, $transform, $post, $lang );

		return $changed ? serialize_blocks( $blocks ) : null;
	}

	/**
	 * @param array<mixed>                                                         $blocks
	 * @param callable(array<string, mixed>, \WP_Post, string): (array<string, mixed>|null) $transform
	 */
	private function transformBlockList( array &$blocks, string $block_name, callable $transform, \WP_Post $post, string $lang ): bool {
		$changed = false;

		foreach ( $blocks as &$block ) {
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$changed = $this->transformBlockList( $block['innerBlocks'], $block_name, $transform, $post, $lang ) || $changed;
			}

			if ( ( $block['blockName'] ?? null ) !== $block_name ) {
				continue;
			}

			$data = $block['attrs']['data'] ?? [];
			if ( ! is_array( $data ) ) {
				$data = [];
			}

			$next = $transform( $data, $post, $lang );
			if ( null === $next || $next === $data ) {
				continue;
			}

			$block['attrs']['data'] = $next;
			$changed = true;
		}
		unset( $block );

		return $changed;
	}
}
