<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Updates\UpdateContext;
use PHPUnit\Framework\TestCase;

class UpdateContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_transform_blocks_updates_single_language_post(): void {
		$writes = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '<!-- old -->' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/card', 'attrs' => [ 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post, bool $wp_error ) use ( &$writes ): int {
				$writes[] = compact( 'post', 'wp_error' );
				return (int) $post['ID'];
			}
		);

		$summary = ( new UpdateContext( false ) )->transformBlocks(
			'acf/card',
			static fn ( array $data ): array => [ 'title' => $data['title'] . '!' ],
			[ 10 ]
		);

		$this->assertSame( [ 'scanned' => 1, 'changed' => 1, 'skipped' => 0, 'errors' => [] ], $summary );
		$this->assertTrue( $writes[0]['wp_error'] );
		$this->assertStringContainsString( 'Old!', $writes[0]['post']['post_content'] );
	}

	public function test_transform_blocks_slashes_content_before_wp_update_post(): void {
		// Regression (mairateam 2026-07-15): wp_update_post() unslashes
		// post_content, so serialized block JSON written without wp_slash()
		// loses every backslash - \u003c escapes render as literal "u003c"
		// on the front end. The runner must hand wp_update_post slashed content.
		$writes = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '<!-- old -->' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/card', 'attrs' => [ 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->justReturn( '<!-- wp:acf/card {"perex":"a \\u003cstrong\\u003eb\\u003c/strong\\u003e"} /-->' );
		Functions\when( 'wp_slash' )->alias( static fn ( string $value ): string => addslashes( $value ) );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post, bool $wp_error ) use ( &$writes ): int {
				$writes[] = $post;
				return (int) $post['ID'];
			}
		);

		( new UpdateContext( false ) )->transformBlocks(
			'acf/card',
			static fn ( array $data ): array => [ 'title' => 'New' ],
			[ 10 ]
		);

		$this->assertSame(
			addslashes( '<!-- wp:acf/card {"perex":"a \\u003cstrong\\u003eb\\u003c/strong\\u003e"} /-->' ),
			$writes[0]['post_content'],
			'post_content must be wp_slash()ed so wp_update_post\'s unslash restores the original'
		);
	}

	public function test_transform_blocks_fans_out_wpml_translations_with_languages(): void {
		$seen = [];
		Functions\when( 'get_post' )->alias( static fn ( int $id ): \WP_Post => new \WP_Post( [ 'ID' => $id, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $filter, mixed $default, mixed ...$args ): mixed {
				if ( 'wpml_element_trid' === $filter ) {
					return 99;
				}
				if ( 'wpml_get_element_translations' === $filter ) {
					return [
						'en' => (object) [ 'element_id' => 10, 'language_code' => 'en' ],
						'cs' => (object) [ 'element_id' => 11, 'language_code' => 'cs' ],
					];
				}
				return $default;
			}
		);
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/card', 'attrs' => [ 'data' => [ 'seen' => false ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\when( 'wp_update_post' )->justReturn( 1 );

		$summary = ( new UpdateContext( false ) )->transformBlocks(
			'acf/card',
			function ( array $data, \WP_Post $post, string $lang ) use ( &$seen ): array {
				$seen[] = [ $post->ID, $lang ];
				return [ 'seen' => true ];
			},
			[ 10 ]
		);

		$this->assertSame( [ [ 10, 'en' ], [ 11, 'cs' ] ], $seen );
		$this->assertSame( 2, $summary['changed'] );
	}

	public function test_null_transform_and_dry_run_do_not_write(): void {
		$logs = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/card', 'attrs' => [ 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\expect( 'wp_update_post' )->never();

		$noChange = ( new UpdateContext( false ) )->transformBlocks( 'acf/card', static fn (): null => null, [ 10 ] );
		$dryRun   = ( new UpdateContext( true, function ( string $message ) use ( &$logs ): void { $logs[] = $message; } ) )
			->transformBlocks( 'acf/card', static fn ( array $data ): array => [ 'title' => 'New' ], [ 10 ] );

		$this->assertSame( 1, $noChange['skipped'] );
		$this->assertSame( 1, $dryRun['changed'] );
		$this->assertStringContainsString( 'Dry-run post #10', $logs[0] );
	}

	public function test_wp_update_post_error_is_collected(): void {
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/card', 'attrs' => [ 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->justReturn( '<!-- new -->' );
		Functions\when( 'wp_update_post' )->justReturn( new \WP_Error( 'bad', 'Could not update' ) );

		$summary = ( new UpdateContext( false ) )->transformBlocks( 'acf/card', static fn (): array => [ 'title' => 'New' ], [ 10 ] );

		$this->assertSame( [ 'Post #10: Could not update' ], $summary['errors'] );
	}

	public function test_map_attachment_uses_wpml_mapping_or_falls_back_to_input(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $filter, mixed $default, mixed ...$args ): mixed => 'wpml_object_id' === $filter ? 456 : $default
		);

		$this->assertSame( 456, ( new UpdateContext( false ) )->mapAttachment( 123, 'cs' ) );
	}

	public function test_adopt_blocks_renames_block_and_sets_attrs_name(): void {
		$writes = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/old', 'attrs' => [ 'name' => 'acf/old', 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post ) use ( &$writes ): int {
				$writes[] = $post;
				return (int) $post['ID'];
			}
		);

		$summary = ( new UpdateContext( false ) )->adoptBlocks(
			'acf/old',
			'acf/new',
			static fn ( array $data ): array => [ 'title' => $data['title'] ],
			[ 10 ]
		);

		$this->assertSame( [ 'scanned' => 1, 'changed' => 1, 'skipped' => 0, 'errors' => [] ], $summary );
		$written = json_decode( (string) $writes[0]['post_content'], true );
		$this->assertSame( 'acf/new', $written[0]['blockName'] );
		$this->assertSame( 'acf/new', $written[0]['attrs']['name'], 'ACF resolves its field group through attrs.name' );
	}

	public function test_adopt_blocks_writes_even_when_the_data_is_unchanged(): void {
		// The rename is itself the change, so - unlike transformBlocks() - an
		// identical data array must still produce a write.
		$writes = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/old', 'attrs' => [ 'data' => [ 'title' => 'Same' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post ) use ( &$writes ): int {
				$writes[] = $post;
				return (int) $post['ID'];
			}
		);

		$summary = ( new UpdateContext( false ) )->adoptBlocks(
			'acf/old',
			'acf/new',
			static fn ( array $data ): array => $data,
			[ 10 ]
		);

		$this->assertSame( 1, $summary['changed'] );
		$this->assertCount( 1, $writes );
	}

	public function test_adopt_blocks_skips_a_post_whose_blocks_already_carry_the_target_name(): void {
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/new', 'attrs' => [ 'name' => 'acf/new', 'data' => [ 'title' => 'Done' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\expect( 'wp_update_post' )->never();

		$summary = ( new UpdateContext( false ) )->adoptBlocks(
			'acf/old',
			'acf/new',
			static fn ( array $data ): array => $data,
			[ 10 ]
		);

		$this->assertSame( 1, $summary['skipped'], 'an already-adopted post is idempotence layer two' );
	}

	public function test_adopt_blocks_leaves_a_block_alone_when_the_transform_returns_null(): void {
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/old', 'attrs' => [ 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\expect( 'wp_update_post' )->never();

		$summary = ( new UpdateContext( false ) )->adoptBlocks( 'acf/old', 'acf/new', static fn (): null => null, [ 10 ] );

		$this->assertSame( 1, $summary['skipped'] );
	}

	public function test_adopt_blocks_descends_into_inner_blocks(): void {
		$writes = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[
				'blockName'   => 'core/group',
				'attrs'       => [],
				'innerBlocks' => [
					[ 'blockName' => 'acf/old', 'attrs' => [ 'data' => [ 'title' => 'Nested' ] ], 'innerBlocks' => [] ],
				],
			],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post ) use ( &$writes ): int {
				$writes[] = $post;
				return (int) $post['ID'];
			}
		);

		( new UpdateContext( false ) )->adoptBlocks( 'acf/old', 'acf/new', static fn ( array $data ): array => $data, [ 10 ] );

		$written = json_decode( (string) $writes[0]['post_content'], true );
		$this->assertSame( 'acf/new', $written[0]['innerBlocks'][0]['blockName'] );
	}

	public function test_adopt_blocks_dry_run_writes_nothing(): void {
		$logs = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/old', 'attrs' => [ 'data' => [ 'title' => 'Old' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\expect( 'wp_update_post' )->never();

		$summary = ( new UpdateContext( true, function ( string $message ) use ( &$logs ): void { $logs[] = $message; } ) )
			->adoptBlocks( 'acf/old', 'acf/new', static fn ( array $data ): array => $data, [ 10 ] );

		$this->assertSame( 1, $summary['changed'] );
		$this->assertStringContainsString( 'Dry-run post #10', $logs[0] );
	}

	public function test_map_field_keys_resolves_twins_from_the_registered_group(): void {
		$this->stubFieldGroup( 'acf/new', [
			[ 'name' => 'title', 'key' => 'field_new_title', 'type' => 'text' ],
			[ 'name' => 'perex', 'key' => 'field_new_perex', 'type' => 'wysiwyg' ],
		] );

		$mapped = ( new UpdateContext( false ) )->mapFieldKeys( [ 'title' => 'A', 'perex' => 'B' ], 'acf/new' );

		$this->assertSame(
			[ 'title' => 'A', '_title' => 'field_new_title', 'perex' => 'B', '_perex' => 'field_new_perex' ],
			$mapped
		);
	}

	public function test_map_field_keys_resolves_repeater_rows_by_sub_field_name(): void {
		// The value key carries a row index (items_0_label); the field key does
		// not - it is the sub-field's own key. A prefix rewrite cannot produce
		// this, which is why the group is the source of truth.
		$this->stubFieldGroup( 'acf/new', [
			[
				'name'       => 'items',
				'key'        => 'field_new_items',
				'type'       => 'repeater',
				'sub_fields' => [
					[ 'name' => 'label', 'key' => 'field_new_item_label', 'type' => 'text' ],
				],
			],
		] );

		$mapped = ( new UpdateContext( false ) )->mapFieldKeys(
			[ 'items' => 2, 'items_0_label' => 'One', 'items_1_label' => 'Two' ],
			'acf/new'
		);

		$this->assertSame( 'field_new_items', $mapped['_items'] );
		$this->assertSame( 'field_new_item_label', $mapped['_items_0_label'] );
		$this->assertSame( 'field_new_item_label', $mapped['_items_1_label'] );
	}

	public function test_map_field_keys_ignores_incoming_twins_and_rewrites_them(): void {
		$this->stubFieldGroup( 'acf/new', [
			[ 'name' => 'title', 'key' => 'field_new_title', 'type' => 'text' ],
		] );

		$mapped = ( new UpdateContext( false ) )->mapFieldKeys(
			[ 'title' => 'A', '_title' => 'field_old_title' ],
			'acf/new'
		);

		$this->assertSame( 'field_new_title', $mapped['_title'] );
	}

	public function test_map_field_keys_throws_on_a_value_key_the_group_does_not_define(): void {
		$this->stubFieldGroup( 'acf/new', [
			[ 'name' => 'title', 'key' => 'field_new_title', 'type' => 'text' ],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'button' );

		( new UpdateContext( false ) )->mapFieldKeys( [ 'title' => 'A', 'button' => '' ], 'acf/new' );
	}

	public function test_map_field_keys_throws_when_the_block_has_no_registered_group(): void {
		Functions\when( 'acf_get_field_groups' )->justReturn( [] );
		Functions\when( 'acf_get_fields' )->justReturn( [] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'acf/new' );

		( new UpdateContext( false ) )->mapFieldKeys( [ 'title' => 'A' ], 'acf/new' );
	}


	public function test_map_field_keys_rejects_a_row_index_on_a_group(): void {
		// A group flattens to `settings_title`, never `settings_9_title`. Only a
		// repeater's rows carry an index, so stripping one anywhere would turn
		// an unknown key into a plausible-looking wrong key.
		$this->stubFieldGroup( 'acf/new', [
			[
				'name'       => 'settings',
				'key'        => 'field_new_settings',
				'type'       => 'group',
				'sub_fields' => [
					[ 'name' => 'title', 'key' => 'field_new_settings_title', 'type' => 'text' ],
				],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'settings_9_title' );

		( new UpdateContext( false ) )->mapFieldKeys( [ 'settings_9_title' => 'X' ], 'acf/new' );
	}

	public function test_map_field_keys_resolves_group_sub_fields_without_an_index(): void {
		$this->stubFieldGroup( 'acf/new', [
			[
				'name'       => 'settings',
				'key'        => 'field_new_settings',
				'type'       => 'group',
				'sub_fields' => [
					[ 'name' => 'title', 'key' => 'field_new_settings_title', 'type' => 'text' ],
				],
			],
		] );

		$mapped = ( new UpdateContext( false ) )->mapFieldKeys( [ 'settings_title' => 'X' ], 'acf/new' );

		$this->assertSame( 'field_new_settings_title', $mapped['_settings_title'] );
	}

	public function test_map_field_keys_throws_when_two_fields_claim_the_same_flattened_key(): void {
		// A top-level `item_label` and a group `item` with a `label` sub-field
		// both flatten to `item_label`. Picking one by traversal order would
		// hand ACF the wrong field, which is worse than refusing.
		$this->stubFieldGroup( 'acf/new', [
			[ 'name' => 'item_label', 'key' => 'field_new_top_item_label', 'type' => 'text' ],
			[
				'name'       => 'item',
				'key'        => 'field_new_item',
				'type'       => 'group',
				'sub_fields' => [
					[ 'name' => 'label', 'key' => 'field_new_item_label', 'type' => 'text' ],
				],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'item_label' );

		( new UpdateContext( false ) )->mapFieldKeys( [ 'item_label' => 'X' ], 'acf/new' );
	}

	public function test_map_field_keys_refuses_flexible_content_rather_than_guessing_a_layout(): void {
		// Two layouts may both define `title`; the row's layout lives in the
		// data, not in the schema. Until that is read, resolving by name would
		// return whichever layout comes first.
		$this->stubFieldGroup( 'acf/new', [
			[
				'name'    => 'content',
				'key'     => 'field_new_content',
				'type'    => 'flexible_content',
				'layouts' => [
					[ 'name' => 'hero', 'sub_fields' => [ [ 'name' => 'title', 'key' => 'field_new_hero_title', 'type' => 'text' ] ] ],
					[ 'name' => 'text', 'sub_fields' => [ [ 'name' => 'title', 'key' => 'field_new_text_title', 'type' => 'text' ] ] ],
				],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'content_0_title' );

		( new UpdateContext( false ) )->mapFieldKeys( [ 'content_0_title' => 'Hero' ], 'acf/new' );
	}

	public function test_adopt_blocks_repairs_a_block_renamed_without_its_attrs_name(): void {
		// A hand-written migration that rewrote blockName and forgot attrs.name
		// leaves ACF unable to resolve the field group. Skipping it as "already
		// adopted" would make that state permanent.
		$writes = [];
		Functions\when( 'get_post' )->justReturn( new \WP_Post( [ 'ID' => 10, 'post_type' => 'page', 'post_content' => '' ] ) );
		Functions\when( 'apply_filters' )->alias( static fn ( string $filter, mixed $default ) => $default );
		Functions\when( 'parse_blocks' )->justReturn( [
			[ 'blockName' => 'acf/new', 'attrs' => [ 'name' => 'acf/old', 'data' => [ 'title' => 'A' ] ], 'innerBlocks' => [] ],
		] );
		Functions\when( 'serialize_blocks' )->alias( static fn ( array $blocks ): string => (string) json_encode( $blocks ) );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post ) use ( &$writes ): int {
				$writes[] = $post;
				return (int) $post['ID'];
			}
		);

		$summary = ( new UpdateContext( false ) )->adoptBlocks(
			'acf/old',
			'acf/new',
			static fn ( array $data ): array => $data,
			[ 10 ]
		);

		$this->assertSame( 1, $summary['changed'] );
		$written = json_decode( (string) $writes[0]['post_content'], true );
		$this->assertSame( 'acf/new', $written[0]['attrs']['name'] );
	}


	public function test_adopt_blocks_refuses_a_rename_to_the_same_name(): void {
		// With $from === $to every block matches and the unchanged-data
		// short-circuit is off, so each run would rewrite the post again.
		// Data-only work belongs in transformBlocks().
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'transformBlocks' );

		( new UpdateContext( false ) )->adoptBlocks( 'acf/card', 'acf/card', static fn ( array $data ): array => $data, [ 10 ] );
	}

	/**
	 * @param list<array<string, mixed>> $fields
	 */
	private function stubFieldGroup( string $block_name, array $fields ): void {
		Functions\when( 'acf_get_field_groups' )->alias(
			static fn ( array $filter = [] ): array => ( $filter['block'] ?? '' ) === $block_name
				? [ [ 'key' => 'group_stub' ] ]
				: []
		);
		Functions\when( 'acf_get_fields' )->justReturn( $fields );
	}
}
