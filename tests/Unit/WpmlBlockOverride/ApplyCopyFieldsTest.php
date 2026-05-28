<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Tests\Unit\WpmlBlockOverrideTestCase;

class ApplyCopyFieldsTest extends WpmlBlockOverrideTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Mock apply_filters as a passthrough — wpml_object_id returns its input unchanged.
		// This lets assertions be exact: source value 999 overrides translation 111
		// and the result is 999 (no language remap occurs).
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Top-level Copy field tests
	// ─────────────────────────────────────────────────────────────────────────

	public function test_top_level_copy_overrides_translation_value(): void {
		$source      = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'img' => 999 ] ] ];
		$translation = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'img' => 111 ] ] ];
		$copy_fields = [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source, $copy_fields, 1, 'en' ] );

		// With passthrough apply_filters, source value 999 passes through unchanged.
		$this->assertSame( 999, $result['attrs']['data']['img'] );
	}

	public function test_top_level_copy_preserves_translate_fields(): void {
		$source      = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'img' => 999, 'title' => 'EN source' ] ] ];
		$translation = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'img' => 111, 'title' => 'CS translation' ] ] ];
		$copy_fields = [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source, $copy_fields, 1, 'en' ] );

		$this->assertSame( 'CS translation', $result['attrs']['data']['title'], 'title (Translate field) must not be touched' );
	}

	public function test_no_op_when_old_equals_new(): void {
		// Pick a non-image type so no remap happens.
		$value       = 'same value';
		$source      = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'label' => $value ] ] ];
		$translation = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'label' => $value ] ] ];
		$copy_fields = [ [ 'field' => [ 'name' => 'label', 'type' => 'text' ], 'path' => [] ] ];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source, $copy_fields, 1, 'en' ] );

		$this->assertSame( $value, $result['attrs']['data']['label'], 'no-op assignment when old===new' );
	}

	public function test_scalar_source_data_does_not_fatal(): void {
		$source      = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => 'BAD_SCALAR' ] ];
		$translation = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'img' => 111 ] ] ];
		$copy_fields = [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source, $copy_fields, 1, 'en' ] );

		// Source data is scalar → treated as empty → source key absent → translation preserved.
		$this->assertSame( 111, $result['attrs']['data']['img'], 'scalar source guards: translation preserved' );
	}

	public function test_scalar_translation_data_does_not_fatal(): void {
		$source      = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => [ 'img' => 999 ] ] ];
		$translation = [ 'blockName' => 'acf/foo', 'attrs' => [ 'data' => 'BAD_SCALAR' ] ];
		$copy_fields = [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source, $copy_fields, 1, 'en' ] );

		// $block['attrs']['data'] was scalar → reset to [], then overridden with source.
		$this->assertIsArray( $result['attrs']['data'], 'translation data reset to array' );
		$this->assertArrayHasKey( 'img', $result['attrs']['data'], 'image overridden from source after array reset' );
		$this->assertSame( 999, $result['attrs']['data']['img'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Repeater (single-level) tests
	// ─────────────────────────────────────────────────────────────────────────

	public function test_repeater_image_subfield_overrides_all_rows(): void {
		// Real fixture: how-it-works has steps_N_image.
		$source_full = Fixtures::load( 'how-it-works' );

		// Build translation where each row image differs from the source.
		$translation_data              = $source_full['attrs']['data'];
		$translation_data['steps_0_image'] = 100;
		$translation_data['steps_1_image'] = 200;

		$translation = [
			'blockName' => 'acf/how-it-works',
			'attrs'     => [ 'data' => $translation_data ],
		];

		$copy_fields = [
			[
				'field' => [ 'name' => 'image', 'type' => 'image' ],
				'path'  => [ [ 'name' => 'steps', 'type' => 'repeater' ] ],
			],
		];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source_full, $copy_fields, 1, 'en' ] );

		// After override, row images come from source (no longer 100/200).
		$this->assertSame(
			$source_full['attrs']['data']['steps_0_image'],
			$result['attrs']['data']['steps_0_image'],
			'steps_0_image overridden from source'
		);
		$this->assertSame(
			$source_full['attrs']['data']['steps_1_image'],
			$result['attrs']['data']['steps_1_image'],
			'steps_1_image overridden from source'
		);
	}

	public function test_repeater_preserves_text_subfields(): void {
		// package-list: only price is Copy; category is Translate.
		$source_full = Fixtures::load( 'package-list' );

		$translation_data                     = $source_full['attrs']['data'];
		$translation_data['items_0_category'] = 'TRANSLATED CATEGORY';
		$translation_data['items_0_price']    = 'TRANSLATED PRICE';

		$translation = [
			'blockName' => 'acf/package-list',
			'attrs'     => [ 'data' => $translation_data ],
		];

		// Only price is Copy; category is Translate.
		$copy_fields = [
			[
				'field' => [ 'name' => 'price', 'type' => 'text' ],
				'path'  => [ [ 'name' => 'items', 'type' => 'repeater' ] ],
			],
		];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source_full, $copy_fields, 1, 'en' ] );

		$this->assertSame( 'TRANSLATED CATEGORY', $result['attrs']['data']['items_0_category'], 'category (Translate) preserved' );
		$this->assertSame( $source_full['attrs']['data']['items_0_price'], $result['attrs']['data']['items_0_price'], 'price (Copy) overridden from source' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Repeater (two-level nested) tests
	// ─────────────────────────────────────────────────────────────────────────

	public function test_nested_repeater_overrides_innermost_keys(): void {
		// faq-group: faq_sections_N_items_M_title pattern.
		$source_full = Fixtures::load( 'faq-group-nested' );

		$translation_data = $source_full['attrs']['data'];
		if ( isset( $translation_data['faq_sections_0_items_0_title'] ) ) {
			$translation_data['faq_sections_0_items_0_title'] = 'TAMPERED TITLE';
		}

		$translation = [
			'blockName' => 'acf/faq-group',
			'attrs'     => [ 'data' => $translation_data ],
		];

		$copy_fields = [
			[
				'field' => [ 'name' => 'title', 'type' => 'text' ],
				'path'  => [
					[ 'name' => 'faq_sections', 'type' => 'repeater' ],
					[ 'name' => 'items', 'type' => 'repeater' ],
				],
			],
		];

		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source_full, $copy_fields, 1, 'en' ] );

		$this->assertSame(
			$source_full['attrs']['data']['faq_sections_0_items_0_title'],
			$result['attrs']['data']['faq_sections_0_items_0_title'],
			'two-level nested title overridden from source'
		);
	}

	public function test_nested_repeater_with_zero_count_skips_rows(): void {
		// faq-group section 1 has items: 0 (zero rows). Verify no error, no fictional keys created.
		$source_full = Fixtures::load( 'faq-group-nested' );

		$translation = [
			'blockName' => 'acf/faq-group',
			'attrs'     => [ 'data' => $source_full['attrs']['data'] ],
		];

		$copy_fields = [
			[
				'field' => [ 'name' => 'title', 'type' => 'text' ],
				'path'  => [
					[ 'name' => 'faq_sections', 'type' => 'repeater' ],
					[ 'name' => 'items', 'type' => 'repeater' ],
				],
			],
		];

		// Should not throw.
		$result = self::callPrivate( 'applyCopyFields', [ $translation, $source_full, $copy_fields, 1, 'en' ] );

		// Sanity: no fictional faq_sections_1_items_0_title appeared.
		$this->assertArrayNotHasKey(
			'faq_sections_1_items_0_title',
			$result['attrs']['data'],
			'no fictional keys created for zero-row inner repeater'
		);
	}
}
