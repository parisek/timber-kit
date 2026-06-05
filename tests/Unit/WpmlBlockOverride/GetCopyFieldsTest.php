<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

class GetCopyFieldsTest extends WpmlBlockOverrideTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! \defined( 'DAY_IN_SECONDS' ) ) {
			\define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	public function test_collects_only_fields_with_the_copy_preference(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'acf_get_field_groups' )->justReturn( array( array( 'key' => 'group_1' ) ) );
		Functions\when( 'acf_get_fields' )->justReturn( array(
			array( 'name' => 'background_image', 'type' => 'image' ),
			array( 'name' => 'title', 'type' => 'text' ),
		) );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value, ...$rest ) {
			if ( 'acfml_field_group_mode_field_translation_preference' === $hook ) {
				$field = $rest[0] ?? array();
				return 'background_image' === ( $field['name'] ?? '' ) ? 1 : 2; // 1 = Copy, 2 = Translate
			}
			return $value; // copy_fields filter passthrough
		} );

		$this->assertSame( array( 'background_image' => true ), WpmlBlockOverride::getCopyFields( 'acf/jumbotron' ) );
	}

	public function test_skips_underscore_system_fields(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'acf_get_field_groups' )->justReturn( array( array( 'key' => 'g' ) ) );
		Functions\when( 'acf_get_fields' )->justReturn( array(
			array( 'name' => '_wpml_word_count', 'type' => 'text' ),
			array( 'name' => 'image', 'type' => 'image' ),
		) );
		Functions\when( 'apply_filters' )->alias( fn ( $hook, $value, ...$rest ) =>
			'acfml_field_group_mode_field_translation_preference' === $hook ? 1 : $value
		);

		$copy = WpmlBlockOverride::getCopyFields( 'acf/x' );

		$this->assertArrayNotHasKey( '_wpml_word_count', $copy );
		$this->assertArrayHasKey( 'image', $copy );
	}

	public function test_project_filter_can_declare_fields_when_autodetect_is_empty(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'acf_get_field_groups' )->justReturn( array() );
		Functions\when( 'acf_get_fields' )->justReturn( array() );
		Functions\when( 'apply_filters' )->alias( fn ( $hook, $value, ...$rest ) =>
			'timber_kit/wpml_block_override/copy_fields' === $hook ? array( 'gallery' => true ) : $value
		);

		$this->assertSame( array( 'gallery' => true ), WpmlBlockOverride::getCopyFields( 'acf/manual' ) );
	}

	public function test_returns_cached_map_without_hitting_acf(): void {
		// acf_get_field_groups is deliberately NOT stubbed — a cache hit must
		// return before any ACF lookup, so reaching it would error.
		Functions\when( 'get_transient' )->justReturn( array( 'acf/cached' => array( 'img' => true ) ) );

		$this->assertSame( array( 'img' => true ), WpmlBlockOverride::getCopyFields( 'acf/cached' ) );
	}
}
