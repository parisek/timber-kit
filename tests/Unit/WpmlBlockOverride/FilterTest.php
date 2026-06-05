<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

class FilterTest extends WpmlBlockOverrideTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! \defined( 'DAY_IN_SECONDS' ) ) {
			\define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	public function test_non_array_block_passes_through(): void {
		$this->assertSame( 'x', WpmlBlockOverride::filter( 'x' ) );
	}

	public function test_source_language_request_is_left_unchanged(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( fn ( $hook, $value = null, ...$rest ) =>
			\in_array( $hook, array( 'wpml_current_language', 'wpml_default_language' ), true ) ? 'en' : $value
		);

		$block = array( 'blockName' => 'acf/x', 'attrs' => array( 'id' => 'b1', 'data' => array( 'image' => 1 ) ) );

		// current === default → shouldOverride() is false, block returned verbatim.
		$this->assertSame( $block, WpmlBlockOverride::filter( $block ) );
	}

	public function test_overrides_copy_image_from_source_end_to_end(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'acf_get_field_groups' )->justReturn( array( array( 'key' => 'g' ) ) );
		Functions\when( 'acf_get_fields' )->justReturn( array( array( 'name' => 'image' ) ) );
		Functions\when( 'get_the_ID' )->justReturn( 10 );          // the translation post
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'src' ) );
		Functions\when( 'parse_blocks' )->justReturn( array(
			array( 'blockName' => 'acf/x', 'attrs' => array( 'id' => 'b1', 'data' => array( 'image' => 456 ) ), 'innerBlocks' => array() ),
		) );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null, ...$rest ) {
			switch ( $hook ) {
				case 'wpml_current_language':
					return 'sk';
				case 'wpml_default_language':
					return 'en';
				case 'acfml_field_group_mode_field_translation_preference':
					return 1; // Copy
				case 'wpml_object_id':
					$type = $rest[0] ?? '';
					return 'attachment' === $type ? $value : 5; // post 10 → source post 5; no media duplicate
				default:
					return $value; // copy_fields filter passthrough
			}
		} );

		$block  = array( 'blockName' => 'acf/x', 'attrs' => array( 'id' => 'b1', 'data' => array( 'image' => 111 ) ) );
		$result = WpmlBlockOverride::filter( $block );

		// The stale translation value (111) is replaced by the source value (456).
		$this->assertSame( 456, $result['attrs']['data']['image'] );
	}
}
