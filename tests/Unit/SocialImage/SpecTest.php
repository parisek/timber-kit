<?php

declare(strict_types=1);

namespace Tests\Unit\SocialImage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\SocialImage;
use PHPUnit\Framework\TestCase;

class SpecTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $filter, $args );
			return $default;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_spec_is_the_documented_preview_card(): void {
		$spec = SocialImage::spec();

		$this->assertSame( 1200, $spec['width'] );
		$this->assertSame( 630, $spec['height'] );
		$this->assertSame( 'center', $spec['crop'] );
		$this->assertSame( 'jpeg', $spec['format'] );
		$this->assertIsInt( $spec['quality'] );
	}

	public function test_options_override_the_defaults(): void {
		$spec = SocialImage::spec( [ 'width' => 1600, 'height' => 840, 'quality' => 95, 'crop' => 'top' ] );

		$this->assertSame( 1600, $spec['width'] );
		$this->assertSame( 840, $spec['height'] );
		$this->assertSame( 95, $spec['quality'] );
		$this->assertSame( 'top', $spec['crop'] );
	}

	public function test_unknown_options_are_dropped(): void {
		$spec = SocialImage::spec( [ 'nonsense' => 'value' ] );

		$this->assertArrayNotHasKey( 'nonsense', $spec );
	}

	public function test_a_format_no_scraper_reads_is_refused(): void {
		// The entire point of this class: AVIF is what the resizer writes by
		// default and what preview scrapers cannot read.
		$spec = SocialImage::spec( [ 'format' => 'avif' ] );

		$this->assertSame( 'jpeg', $spec['format'] );
	}

	public function test_another_scraper_safe_format_is_honoured(): void {
		$spec = SocialImage::spec( [ 'format' => 'png' ] );

		$this->assertSame( 'png', $spec['format'] );
	}

	public function test_format_is_lowercased_and_trimmed(): void {
		$spec = SocialImage::spec( [ 'format' => ' PNG ' ] );

		$this->assertSame( 'png', $spec['format'] );
	}

	public function test_non_positive_dimensions_fall_back_to_the_defaults(): void {
		$spec = SocialImage::spec( [ 'width' => 0, 'height' => -5 ] );

		$this->assertSame( 1200, $spec['width'] );
		$this->assertSame( 630, $spec['height'] );
	}

	public function test_a_crop_style_that_does_not_cut_exactly_is_refused(): void {
		// smart-crop silently degrades to a plain resize when the source is
		// smaller than the target, while the resizer still reports the requested
		// dimensions — so the entry would claim a cut it did not make.
		$this->assertSame( 'center', SocialImage::spec( [ 'crop' => 'smart-crop' ] )['crop'] );
	}

	public function test_an_unrecognised_crop_style_is_refused(): void {
		// A typo would otherwise route into the resizer's plain-resize branch,
		// which also reports dimensions it did not produce.
		$this->assertSame( 'center', SocialImage::spec( [ 'crop' => 'centre' ] )['crop'] );
	}

	public function test_every_exact_crop_style_is_honoured(): void {
		foreach ( [ 'center', 'crop', 'top', 'bottom', 'left', 'right' ] as $crop ) {
			$this->assertSame( $crop, SocialImage::spec( [ 'crop' => $crop ] )['crop'] );
		}
	}

	public function test_crop_is_lowercased_and_trimmed(): void {
		$this->assertSame( 'top', SocialImage::spec( [ 'crop' => ' TOP ' ] )['crop'] );
	}

	public function test_quality_above_the_encoder_range_is_clamped(): void {
		$this->assertSame( 100, SocialImage::spec( [ 'quality' => 500 ] )['quality'] );
	}

	public function test_quality_zero_reads_as_unset_and_takes_the_default(): void {
		// Not clamped to 1: nobody asks for the worst image the encoder can
		// make, so a non-positive quality is a missing one.
		$this->assertSame( 85, SocialImage::spec( [ 'quality' => 0 ] )['quality'] );
		$this->assertSame( 85, SocialImage::spec( [ 'quality' => -20 ] )['quality'] );
	}
}
