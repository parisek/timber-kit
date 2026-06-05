<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

class ApplyCopyFieldsTest extends WpmlBlockOverrideTestCase {

	public function test_overwrites_copy_field_from_source(): void {
		$block  = array( 'attrs' => array( 'id' => 'b1', 'data' => array( 'background_image' => 111 ) ) );
		$source = array( 'attrs' => array( 'id' => 'b1', 'data' => array( 'background_image' => 456 ) ) );

		// current_lang '' → no attachment remap, pure overwrite.
		$result = WpmlBlockOverride::applyCopyFields( $block, $source, array( 'background_image' => true ), '' );

		$this->assertSame( 456, $result['attrs']['data']['background_image'] );
	}

	public function test_copies_acf_field_key_companion(): void {
		$block  = array( 'attrs' => array( 'data' => array( 'image' => 1, '_image' => 'field_old' ) ) );
		$source = array( 'attrs' => array( 'data' => array( 'image' => 9, '_image' => 'field_new' ) ) );

		$result = WpmlBlockOverride::applyCopyFields( $block, $source, array( 'image' => true ), '' );

		$this->assertSame( 9, $result['attrs']['data']['image'] );
		$this->assertSame( 'field_new', $result['attrs']['data']['_image'] );
	}

	public function test_leaves_non_copy_fields_untouched(): void {
		$block  = array( 'attrs' => array( 'data' => array( 'title' => 'translated', 'image' => 1 ) ) );
		$source = array( 'attrs' => array( 'data' => array( 'title' => 'source', 'image' => 9 ) ) );

		$result = WpmlBlockOverride::applyCopyFields( $block, $source, array( 'image' => true ), '' );

		// `title` is Translate, not Copy → must keep the translation value.
		$this->assertSame( 'translated', $result['attrs']['data']['title'] );
		$this->assertSame( 9, $result['attrs']['data']['image'] );
	}

	public function test_skips_copy_fields_absent_on_source(): void {
		$block  = array( 'attrs' => array( 'data' => array( 'image' => 1 ) ) );
		$source = array( 'attrs' => array( 'data' => array() ) );

		$result = WpmlBlockOverride::applyCopyFields( $block, $source, array( 'image' => true ), '' );

		$this->assertSame( 1, $result['attrs']['data']['image'] );
	}

	public function test_remaps_numeric_attachment_to_current_language(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $type = '', $original = true, $lang = '' ) {
				// Pretend the SK-language duplicate of attachment 456 is 999.
				if ( 'wpml_object_id' === $hook && 456 === $value && 'attachment' === $type && 'sk' === $lang ) {
					return 999;
				}
				return $value;
			}
		);

		$block  = array( 'attrs' => array( 'data' => array( 'image' => 1 ) ) );
		$source = array( 'attrs' => array( 'data' => array( 'image' => 456 ) ) );

		$result = WpmlBlockOverride::applyCopyFields( $block, $source, array( 'image' => true ), 'sk' );

		$this->assertSame( 999, $result['attrs']['data']['image'] );
	}

	public function test_non_numeric_value_passes_through_without_remap(): void {
		// No apply_filters stub needed — non-numeric short-circuits before WPML.
		$block  = array( 'attrs' => array( 'data' => array( 'heading' => 'old' ) ) );
		$source = array( 'attrs' => array( 'data' => array( 'heading' => 'Crème brûlée' ) ) );

		$result = WpmlBlockOverride::applyCopyFields( $block, $source, array( 'heading' => true ), 'sk' );

		$this->assertSame( 'Crème brûlée', $result['attrs']['data']['heading'] );
	}
}
