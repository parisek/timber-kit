<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

class VariantDirnameTest extends ResizerTestCase {

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

	public function test_default_quality_keeps_the_historic_dirname(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant() ] );

		$this->assertSame( '1200x630-center', $result );
	}

	public function test_non_default_quality_is_part_of_the_key(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 82 ] ) ] );

		$this->assertSame( '1200x630-center-q82', $result );
	}

	public function test_two_qualities_of_the_same_cut_do_not_share_a_key(): void {
		$resizer = $this->createResizer();

		$low = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 60 ] ) ] );
		$high = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'quality' => 95 ] ) ] );

		$this->assertNotSame( $low, $high );
	}

	public function test_style_is_part_of_the_key(): void {
		$resizer = $this->createResizer();

		$result = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'image_style' => 'smart-crop' ] ) ] );

		$this->assertSame( '1200x630-smart-crop', $result );
	}

	public function test_format_stays_out_of_the_dirname(): void {
		$resizer = $this->createResizer();

		// The format is the file extension, so it already separates variants
		// without a second copy of it in the directory segment.
		$avif = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant() ] );
		$jpeg = $this->callPrivate( $resizer, 'variantDirname', [ $this->variant( [ 'format' => 'jpeg' ] ) ] );

		$this->assertSame( $avif, $jpeg );
	}
}
