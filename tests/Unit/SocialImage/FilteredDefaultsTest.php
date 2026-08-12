<?php

declare(strict_types=1);

namespace Tests\Unit\SocialImage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\SocialImage;
use PHPUnit\Framework\TestCase;

/**
 * A project can move the defaults through `timber_kit_social_image_defaults`.
 * It must not be able to move them somewhere that defeats the class: a default
 * the scrapers cannot read, or a crop that does not cut exactly, would turn the
 * guarantee off for every call at once — silently, and site-wide.
 */
class FilteredDefaultsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $defaults
	 */
	private function withDefaults( array $defaults ): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) use ( $defaults ) {
			unset( $args );
			return 'timber_kit_social_image_defaults' === $filter ? $defaults : $default;
		} );
	}

	public function test_filtered_defaults_are_applied(): void {
		$this->withDefaults( [ 'width' => 1600, 'height' => 840, 'quality' => 90 ] );

		$spec = SocialImage::spec();

		$this->assertSame( 1600, $spec['width'] );
		$this->assertSame( 840, $spec['height'] );
		$this->assertSame( 90, $spec['quality'] );
	}

	public function test_partial_filtered_defaults_keep_the_package_values(): void {
		$this->withDefaults( [ 'quality' => 90 ] );

		$spec = SocialImage::spec();

		$this->assertSame( 1200, $spec['width'] );
		$this->assertSame( 'jpeg', $spec['format'] );
	}

	public function test_an_unreadable_filtered_default_format_cannot_take_hold(): void {
		$this->withDefaults( [ 'format' => 'avif' ] );

		$this->assertSame( 'jpeg', SocialImage::spec()['format'] );
	}

	public function test_an_unreadable_filtered_default_is_not_used_as_a_fallback_either(): void {
		// The request is unreadable too, so resolution walks past the filtered
		// default and lands on the package one rather than accepting AVIF.
		$this->withDefaults( [ 'format' => 'avif' ] );

		$this->assertSame( 'jpeg', SocialImage::spec( [ 'format' => 'tiff' ] )['format'] );
	}

	public function test_an_inexact_filtered_default_crop_cannot_take_hold(): void {
		$this->withDefaults( [ 'crop' => 'smart-crop' ] );

		$this->assertSame( 'center', SocialImage::spec()['crop'] );
	}

	public function test_an_invalid_filtered_default_dimension_cannot_take_hold(): void {
		// A zero dimension routes the resizer into proportional resizing while it
		// still reports the dimensions it was handed, so an invalid default here
		// would produce a spec whose result cannot be checked.
		$this->withDefaults( [ 'width' => 0, 'height' => 'nonsense' ] );

		$spec = SocialImage::spec();

		$this->assertSame( 1200, $spec['width'] );
		$this->assertSame( 630, $spec['height'] );
	}

	public function test_an_invalid_filtered_default_dimension_is_not_used_as_a_fallback_either(): void {
		$this->withDefaults( [ 'width' => -10 ] );

		$this->assertSame( 1200, SocialImage::spec( [ 'width' => 0 ] )['width'] );
	}

	public function test_the_package_format_is_not_used_when_filtered_out_of_the_readable_list(): void {
		// Both filters at once: the allow-list drops jpeg, so the package default
		// is no longer a valid answer and the spec must land inside the list the
		// project actually declared.
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $args );
			if ( 'timber_kit_social_image_formats' === $filter ) {
				return array( 'png', 'webp' );
			}
			return $default;
		} );

		$this->assertContains( SocialImage::spec( [ 'format' => 'avif' ] )['format'], array( 'png', 'webp' ) );
	}

	public function test_a_filtered_default_quality_above_the_encoder_range_is_clamped(): void {
		$this->withDefaults( [ 'quality' => 500 ] );

		$this->assertSame( 100, SocialImage::spec()['quality'] );
	}

	public function test_a_non_array_filter_return_is_ignored(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $args );
			return 'timber_kit_social_image_defaults' === $filter ? 'nonsense' : $default;
		} );

		$this->assertSame( 1200, SocialImage::spec()['width'] );
	}
}
