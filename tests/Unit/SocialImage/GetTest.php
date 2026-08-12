<?php

declare(strict_types=1);

namespace Tests\Unit\SocialImage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Parisek\TimberKit\SocialImage;
use PHPUnit\Framework\TestCase;

/**
 * `get()` is the thin glue: build the spec, hand it to the resizer, and refuse
 * anything that is not demonstrably the cut that was asked for. The refusal is
 * the interesting half — a caller falling back to its own default is a working
 * preview card, while a wrong or oversized image is a broken one.
 */
class GetTest extends TestCase {

	/** @var array<string, mixed> */
	private array $image = [ 'src' => 'https://example.com/hero.jpg', 'alt' => 'Hero' ];

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

	/**
	 * A resizer stub returning a canned variant list, so these tests exercise
	 * the decision rather than the encoder.
	 *
	 * @param array<int, array<string, mixed>> $variants
	 */
	private function resizerReturning( array $variants ): Resizer {
		$stub = $this->createStub( Resizer::class );
		$stub->method( 'resizer' )->willReturn( $variants );
		return $stub;
	}

	public function test_returns_the_variant_when_it_is_the_requested_cut(): void {
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/cache/1200x630-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ],
			[ 'src' => 'https://example.com/hero.jpg', 'type' => 'image/jpeg', 'width' => 4000, 'height' => 2250 ],
		] );

		$result = SocialImage::get( $this->image, [], $resizer );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/cache/1200x630-center/hero.jpeg', $result['src'] );
		$this->assertSame( 1200, $result['width'] );
		$this->assertSame( 630, $result['height'] );
	}

	public function test_refuses_the_untouched_original(): void {
		// resizer() returns the source alone when it cannot process it. Serving
		// that as a preview means handing a scraper the full-size upload.
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/hero.jpg', 'type' => 'image/jpeg', 'width' => 4000, 'height' => 2250 ],
		] );

		$this->assertNull( SocialImage::get( $this->image, [], $resizer ) );
	}

	public function test_refuses_a_format_no_scraper_reads(): void {
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/cache/1200x630-center/hero.avif', 'type' => 'image/avif', 'width' => 1200, 'height' => 630 ],
		] );

		$this->assertNull( SocialImage::get( $this->image, [], $resizer ) );
	}

	public function test_refuses_a_variant_of_the_wrong_size(): void {
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/cache/600x315-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 600, 'height' => 315 ],
		] );

		$this->assertNull( SocialImage::get( $this->image, [], $resizer ) );
	}

	public function test_refuses_a_variant_without_a_src(): void {
		$resizer = $this->resizerReturning( [
			[ 'src' => '', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ],
		] );

		$this->assertNull( SocialImage::get( $this->image, [], $resizer ) );
	}

	public function test_returns_null_for_an_empty_image(): void {
		$resizer = $this->resizerReturning( [] );

		$this->assertNull( SocialImage::get( [], [], $resizer ) );
		$this->assertNull( SocialImage::get( null, [], $resizer ) );
	}

	public function test_finds_the_cut_among_several_variants(): void {
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/cache/600x315-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 600, 'height' => 315 ],
			[ 'src' => 'https://example.com/cache/1200x630-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ],
		] );

		$result = SocialImage::get( $this->image, [], $resizer );

		$this->assertSame( 1200, $result['width'] );
	}

	public function test_refuses_the_source_even_when_it_matches_the_requested_cut(): void {
		// The source happens to be exactly 1200x630 JPEG already. resizer()
		// returns it untouched when it cannot process the image, and serving it
		// would break the class's "never the original" contract on the one input
		// where the dimension check cannot notice.
		$image = [ 'src' => 'https://example.com/already-a-card.jpeg', 'alt' => '' ];
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/already-a-card.jpeg', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ],
		] );

		$this->assertNull( SocialImage::get( $image, [], $resizer ) );
	}

	public function test_refuses_the_source_of_an_indexed_image_list_too(): void {
		// Helpers::formatImage() produces indexed lists, and Resizer takes the
		// last entry as the source. Reading `src` off the outer array would find
		// nothing here and quietly disarm the check for every such caller.
		$image = [
			[ 'src' => 'https://example.com/small.jpeg', 'width' => 300, 'height' => 158 ],
			[ 'src' => 'https://example.com/already-a-card.jpeg', 'width' => 1200, 'height' => 630 ],
		];
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/already-a-card.jpeg', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ],
		] );

		$this->assertNull( SocialImage::get( $image, [], $resizer ) );
	}

	public function test_an_indexed_image_list_still_yields_a_generated_variant(): void {
		$image = [
			[ 'src' => 'https://example.com/small.jpeg', 'width' => 300, 'height' => 158 ],
			[ 'src' => 'https://example.com/hero.jpg', 'width' => 4000, 'height' => 2250 ],
		];
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/cache/1200x630-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ],
		] );

		$result = SocialImage::get( $image, [], $resizer );

		$this->assertSame( 'https://example.com/cache/1200x630-center/hero.jpeg', $result['src'] );
	}

	public function test_honours_a_custom_size(): void {
		$resizer = $this->resizerReturning( [
			[ 'src' => 'https://example.com/cache/1000x1000-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 1000, 'height' => 1000 ],
		] );

		$result = SocialImage::get( $this->image, [ 'width' => 1000, 'height' => 1000 ], $resizer );

		$this->assertSame( 1000, $result['height'] );
	}
}
