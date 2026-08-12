<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

class NormalizeVariantsTest extends ResizerTestCase {

	public function test_basic_normalization(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '800', '600', '768', 'crop', '80' ] ],
		] );

		$this->assertCount( 1, $result );
		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( 768, $result[0]['media'] );
		$this->assertSame( 'crop', $result[0]['image_style'] );
		$this->assertSame( 80, $result[0]['quality'] );
	}

	public function test_defaults_for_missing_params(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '800', '600' ] ],
		] );

		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( 0, $result[0]['media'] );
		$this->assertSame( 'center', $result[0]['image_style'] );
		$this->assertSame( 100, $result[0]['quality'] );
	}

	public function test_empty_strings_become_zero(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '', '', '', '', '' ] ],
		] );

		$this->assertSame( 0, $result[0]['width'] );
		$this->assertSame( 0, $result[0]['height'] );
		$this->assertSame( 0, $result[0]['media'] );
		$this->assertSame( 'center', $result[0]['image_style'] );
		$this->assertSame( 100, $result[0]['quality'] );
	}

	public function test_single_dimension_width_only(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '400' ] ],
		] );

		$this->assertSame( 400, $result[0]['width'] );
		$this->assertSame( 0, $result[0]['height'] );
	}

	public function test_sorting_by_media_desc(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[
				[ '400', '300', '320', 'crop' ],
				[ '1680', '1260', '768', 'crop' ],
				[ '800', '600', '512', 'crop' ],
			],
		] );

		$this->assertCount( 3, $result );
		$this->assertSame( 768, $result[0]['media'] );
		$this->assertSame( 512, $result[1]['media'] );
		$this->assertSame( 320, $result[2]['media'] );
	}

	public function test_empty_variants(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[],
		] );

		$this->assertSame( [], $result );
	}

	public function test_smart_crop_style(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '800', '600', '', 'smart-crop' ] ],
		] );

		$this->assertSame( 'smart-crop', $result[0]['image_style'] );
	}

	public function test_quality_defaults_to_target(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '800', '600', '768', 'crop' ] ],
		] );

		// Default target_quality from constructor is 100
		$this->assertSame( 100, $result[0]['quality'] );
	}

	public function test_zero_string_treated_as_empty(): void {
		$resizer = $this->createResizer();

		// PHP's empty('0') is true, so '0' should be treated like empty
		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '0', '600', '0', 'crop' ] ],
		] );

		$this->assertSame( 0, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( 0, $result[0]['media'] );
	}

	public function test_numeric_input(): void {
		$resizer = $this->createResizer();

		// intval() works on both strings and integers
		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 800, 600, 768, 'crop', 90 ] ],
		] );

		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( 768, $result[0]['media'] );
		$this->assertSame( 90, $result[0]['quality'] );
	}

	public function test_positional_variant_gets_the_request_format(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '800', '600' ] ],
		] );

		$this->assertSame( 'avif', $result[0]['format'] );
	}

	public function test_associative_variant_reads_every_key(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[
				[
					'width' => 1200,
					'height' => 630,
					'media' => 768,
					'image_style' => 'smart-crop',
					'quality' => 82,
					'format' => 'jpeg',
				],
			],
		] );

		$this->assertSame( 1200, $result[0]['width'] );
		$this->assertSame( 630, $result[0]['height'] );
		$this->assertSame( 768, $result[0]['media'] );
		$this->assertSame( 'smart-crop', $result[0]['image_style'] );
		$this->assertSame( 82, $result[0]['quality'] );
		$this->assertSame( 'jpeg', $result[0]['format'] );
	}

	public function test_associative_variant_accepts_crop_as_style_alias(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 'width' => 800, 'height' => 600, 'crop' => 'top' ] ],
		] );

		$this->assertSame( 'top', $result[0]['image_style'] );
	}

	public function test_associative_image_style_wins_over_crop_alias(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 'width' => 800, 'image_style' => 'bottom', 'crop' => 'top' ] ],
		] );

		$this->assertSame( 'bottom', $result[0]['image_style'] );
	}

	public function test_associative_variant_falls_back_to_defaults(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 'width' => 800 ] ],
		] );

		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 0, $result[0]['height'] );
		$this->assertSame( 0, $result[0]['media'] );
		$this->assertSame( 'center', $result[0]['image_style'] );
		$this->assertSame( 100, $result[0]['quality'] );
		$this->assertSame( 'avif', $result[0]['format'] );
	}

	public function test_associative_format_is_lowercased_and_trimmed(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 'width' => 800, 'format' => ' JPEG ' ] ],
		] );

		$this->assertSame( 'jpeg', $result[0]['format'] );
	}

	public function test_associative_unknown_format_falls_back_to_request_format(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 'width' => 800, 'format' => 'not-an-image-format' ] ],
		] );

		$this->assertSame( 'avif', $result[0]['format'] );
	}

	public function test_positional_and_associative_variants_mix_in_one_call(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[
				[ '400', '300', '320', 'crop' ],
				[ 'width' => 1200, 'height' => 630, 'media' => 768, 'format' => 'jpeg' ],
			],
		] );

		$this->assertCount( 2, $result );
		// Sorted by media desc, so the associative one comes first.
		$this->assertSame( 1200, $result[0]['width'] );
		$this->assertSame( 'jpeg', $result[0]['format'] );
		$this->assertSame( 400, $result[1]['width'] );
		$this->assertSame( 'avif', $result[1]['format'] );
	}

	public function test_sorting_stability_same_media(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[
				[ '400', '300', '768', 'crop' ],
				[ '800', '600', '768', 'center' ],
				[ '1200', '900', '320', 'top' ],
			],
		] );

		// Both 768 variants stay before 320, widths preserved
		$this->assertSame( 768, $result[0]['media'] );
		$this->assertSame( 768, $result[1]['media'] );
		$this->assertSame( 320, $result[2]['media'] );
	}
}
