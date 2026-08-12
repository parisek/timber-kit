<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

class VariantDirnameTest extends ResizerTestCase {

	/**
	 * A resizer with `timber_kit_resizer_quality_in_cache_key` returning true.
	 */
	private function createResizerWithQualityInKey(): Resizer {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $args );
			return 'timber_kit_resizer_quality_in_cache_key' === $filter ? true : $default;
		} );
		return new Resizer();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function variant( array $overrides = [] ): array {
		return array_merge(
			[
				'width' => 1200,
				'height' => 630,
				'media' => 0,
				'image_style' => 'center',
				'quality' => 100,
				'format' => 'avif',
			],
			$overrides
		);
	}

	public function test_quality_stays_out_of_the_key_by_default(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 82 ] ) ] );

		$this->assertSame( '1200x630-center', $result );
	}

	public function test_default_quality_keeps_the_historic_dirname(): void {
		$resizer = $this->createResizerWithQualityInKey();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant() ] );

		$this->assertSame( '1200x630-center', $result );
	}

	public function test_opted_in_non_default_quality_is_part_of_the_key(): void {
		$resizer = $this->createResizerWithQualityInKey();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 82 ] ) ] );

		$this->assertSame( '1200x630-center-q82', $result );
	}

	public function test_opted_in_two_qualities_of_one_cut_do_not_share_a_key(): void {
		$resizer = $this->createResizerWithQualityInKey();

		$low = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 60 ] ) ] );
		$high = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 95 ] ) ] );

		$this->assertNotSame( $low, $high );
	}

	public function test_style_is_part_of_the_key(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'image_style' => 'smart-crop' ] ) ] );

		$this->assertSame( '1200x630-smart-crop', $result );
	}

	public function test_every_recognised_style_survives_sanitising_unchanged(): void {
		$resizer = $this->createResizer();

		foreach ( [ 'center', 'crop', 'smart-crop', 'top', 'bottom', 'left', 'right' ] as $style ) {
			$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'image_style' => $style ] ) ] );

			$this->assertSame( '1200x630-' . $style, $result );
		}
	}

	public function test_a_style_cannot_walk_out_of_the_cache_directory(): void {
		$resizer = $this->createResizer();

		// The value reaches wp_mkdir_p(), so traversal characters must not
		// survive into the path segment.
		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'image_style' => '../../outside' ] ) ] );

		$this->assertStringNotContainsString( '..', $result );
		$this->assertStringNotContainsString( '/', $result );
	}

	public function test_a_style_stripped_to_nothing_falls_back_to_center(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'image_style' => '../' ] ) ] );

		$this->assertSame( '1200x630-center', $result );
	}

	public function test_format_stays_out_of_the_dirname(): void {
		$resizer = $this->createResizer();

		// The format is the file extension, so it already separates variants
		// without a second copy of it in the directory segment.
		$avif = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant() ] );
		$jpeg = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'format' => 'jpeg' ] ) ] );

		$this->assertSame( $avif, $jpeg );
	}

	public function test_normalized_variants_carry_their_cache_key(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ 'width' => 1200, 'height' => 630, 'crop' => 'top' ] ],
		] );

		// Carried, not re-derived downstream: DevMediaProxy reads this key
		// rather than rebuilding the path from width/height/style.
		$this->assertSame( '1200x630-top', $result[0]['cache_key'] );
	}
}
