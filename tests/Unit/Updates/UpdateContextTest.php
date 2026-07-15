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
}
