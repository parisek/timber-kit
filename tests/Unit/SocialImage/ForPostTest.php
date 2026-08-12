<?php

declare(strict_types=1);

namespace Tests\Unit\SocialImage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Parisek\TimberKit\SocialImage;
use PHPUnit\Framework\TestCase;

/**
 * Resolving a post's preview image is the half every project was writing by
 * hand. The only project-specific facts in it are a post type and a field name.
 */
class ForPostTest extends TestCase {

	/** @var array<string, mixed> */
	private array $variant = [
		'src' => 'https://example.com/cache/1200x630-center/hero.jpeg',
		'type' => 'image/jpeg',
		'width' => 1200,
		'height' => 630,
	];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post(): \WP_Post {
		return new \WP_Post( [ 'ID' => 7, 'post_type' => 'project' ] );
	}

	/**
	 * @param array<string, mixed> $map     Post-type → field map.
	 * @param array<string, mixed> $fields  What the field reader returns.
	 * @param int                  $thumb   Featured-image id, 0 for none.
	 */
	private function wire( array $map, array $fields, int $thumb = 0 ): void {
		Functions\when( 'get_post_type' )->justReturn( 'project' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( $thumb );
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) use ( $map, $fields ) {
				unset( $args );
				if ( 'timber_kit_social_image_fields' === $filter ) {
					return $map;
				}
				if ( 'timber_kit_social_image_post_fields' === $filter ) {
					return $fields;
				}
				return $default;
			}
		);
	}

	private function resizerReturning( array $variants ): Resizer {
		$stub = $this->createStub( Resizer::class );
		$stub->method( 'resizer' )->willReturn( $variants );
		return $stub;
	}

	/**
	 * A resizer answering differently per call, for candidate-order assertions.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $answers
	 */
	private function resizerAnswering( array $answers ): Resizer {
		$stub = $this->createStub( Resizer::class );
		$stub->method( 'resizer' )->willReturnOnConsecutiveCalls( ...$answers );
		return $stub;
	}

	public function test_reads_the_field_the_map_names_for_the_post_type(): void {
		$this->wire(
			[ 'project' => 'hero_image' ],
			[ 'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg' ] ] ]
		);

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertSame( $this->variant['src'], $result['src'] );
	}

	public function test_a_field_chain_takes_the_first_non_empty(): void {
		$this->wire(
			[ 'project' => [ 'lead_image', 'hero_image' ] ],
			[ 'lead_image' => null, 'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg' ] ] ]
		);

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertSame( $this->variant['src'], $result['src'] );
	}

	public function test_falls_back_to_the_featured_image_when_the_map_has_no_entry(): void {
		// An empty map is today's behaviour, so adopting this changes nothing
		// until a project fills one in.
		$this->wire( [], [], 42 );
		Functions\when( 'acf_get_attachment' )->justReturn( [ 'url' => 'https://example.com/featured.jpg', 'width' => 4000, 'height' => 2250, 'mime_type' => 'image/jpeg' ] );

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertIsArray( $result );
	}

	public function test_falls_back_to_the_featured_image_when_every_mapped_field_is_empty(): void {
		$this->wire( [ 'project' => 'hero_image' ], [ 'hero_image' => null ], 42 );
		Functions\when( 'acf_get_attachment' )->justReturn( [ 'url' => 'https://example.com/featured.jpg', 'width' => 4000, 'height' => 2250, 'mime_type' => 'image/jpeg' ] );

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertIsArray( $result );
	}

	public function test_returns_null_when_nothing_resolves(): void {
		$this->wire( [ 'project' => 'hero_image' ], [ 'hero_image' => null ], 0 );

		$this->assertNull( SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) ) );
	}

	public function test_returns_null_for_a_non_post(): void {
		$this->wire( [], [] );

		$this->assertNull( SocialImage::forPost( null, [], $this->resizerReturning( [ $this->variant ] ) ) );
		$this->assertNull( SocialImage::forPost( 'not a post', [], $this->resizerReturning( [ $this->variant ] ) ) );
	}

	public function test_a_bare_formatted_record_also_resolves(): void {
		// formatImage() normalises to a list, but a project overriding the field
		// reader may hand back a single record.
		$this->wire(
			[ 'project' => 'hero_image' ],
			[ 'hero_image' => [ 'id' => 9, 'src' => 'https://example.com/hero.jpg' ] ]
		);

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertIsArray( $result );
	}

	public function test_a_non_empty_but_unusable_field_does_not_swallow_the_chain(): void {
		// A repeater is non-empty and is not an image. Returning on it would
		// skip the next field name and the featured-image fallback alike.
		$this->wire(
			[ 'project' => [ 'rows', 'hero_image' ] ],
			[
				'rows' => [ [ 'label' => 'Investor', 'value' => 'PM' ] ],
				'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg' ] ],
			]
		);

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertIsArray( $result );
	}

	public function test_a_non_empty_but_unusable_field_still_reaches_the_featured_image(): void {
		$this->wire(
			[ 'project' => 'rows' ],
			[ 'rows' => [ [ 'label' => 'Investor', 'value' => 'PM' ] ] ],
			42
		);
		Functions\when( 'acf_get_attachment' )->justReturn( [ 'url' => 'https://example.com/featured.jpg', 'width' => 4000, 'height' => 2250, 'mime_type' => 'image/jpeg' ] );

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertIsArray( $result );
	}

	public function test_an_attachment_id_in_the_field_resolves(): void {
		$this->wire( [ 'project' => 'hero_image' ], [ 'hero_image' => 9 ] );
		Functions\when( 'acf_get_attachment' )->justReturn( [ 'url' => 'https://example.com/hero.jpg', 'width' => 4000, 'height' => 2250, 'mime_type' => 'image/jpeg' ] );

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertIsArray( $result );
	}

	public function test_a_mapped_file_field_is_not_mistaken_for_an_image(): void {
		// formatFile() and formatVideo() also produce records with a `src` key,
		// so `src` alone cannot decide. A PDF must not reach the resizer as a
		// picture, and must not consume the chain either.
		$this->wire(
			[ 'project' => [ 'brochure', 'hero_image' ] ],
			[
				'brochure' => [ [ 'src' => 'https://example.com/brochure.pdf', 'type' => 'application/pdf' ] ],
				'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg', 'type' => 'image/jpeg' ] ],
			]
		);

		$result = SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) );

		$this->assertSame( $this->variant['src'], $result['src'] );
	}

	public function test_a_record_without_a_type_is_still_tried(): void {
		// A formatter that could not read the mime is not evidence against the
		// value; the resizer refuses what it cannot decode anyway.
		$this->wire(
			[ 'project' => 'hero_image' ],
			[ 'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg' ] ] ]
		);

		$this->assertIsArray( SocialImage::forPost( $this->post(), [], $this->resizerReturning( [ $this->variant ] ) ) );
	}

	public function test_a_candidate_that_yields_no_usable_cut_moves_on_to_the_next(): void {
		// Resolving and cutting are separate steps and a value can pass the
		// first while failing the second — an SVG, a missing file, a format the
		// backend cannot decode. The chain must survive that.
		$this->wire(
			[ 'project' => [ 'vector', 'hero_image' ] ],
			[
				'vector' => [ [ 'src' => 'https://example.com/logo.svg', 'type' => 'image/svg+xml' ] ],
				'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg', 'type' => 'image/jpeg' ] ],
			]
		);

		// First call: the resizer hands back the source untouched, which get()
		// refuses. Second call: a real cut.
		$resizer = $this->resizerAnswering( [
			[ [ 'src' => 'https://example.com/logo.svg', 'width' => 512, 'height' => 512 ] ],
			[ $this->variant ],
		] );

		$result = SocialImage::forPost( $this->post(), [], $resizer );

		$this->assertSame( $this->variant['src'], $result['src'] );
	}

	public function test_the_featured_image_is_the_last_candidate_not_the_first(): void {
		$this->wire(
			[ 'project' => 'hero_image' ],
			[ 'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg', 'type' => 'image/jpeg' ] ] ],
			42
		);
		Functions\when( 'acf_get_attachment' )->justReturn( [ 'url' => 'https://example.com/featured.jpg', 'width' => 4000, 'height' => 2250, 'mime_type' => 'image/jpeg' ] );

		$featured_cut = [ 'src' => 'https://example.com/c/1200x630-center/featured.jpeg', 'type' => 'image/jpeg', 'width' => 1200, 'height' => 630 ];
		$resizer = $this->resizerAnswering( [ [ $this->variant ], [ $featured_cut ] ] );

		$result = SocialImage::forPost( $this->post(), [], $resizer );

		$this->assertSame( $this->variant['src'], $result['src'] );
	}

	public function test_options_are_passed_through(): void {
		$this->wire( [ 'project' => 'hero_image' ], [ 'hero_image' => [ [ 'id' => 9, 'src' => 'https://example.com/hero.jpg' ] ] ] );
		$square = [ 'src' => 'https://example.com/c/1000x1000-center/hero.jpeg', 'type' => 'image/jpeg', 'width' => 1000, 'height' => 1000 ];

		$result = SocialImage::forPost( $this->post(), [ 'width' => 1000, 'height' => 1000 ], $this->resizerReturning( [ $square ] ) );

		$this->assertSame( 1000, $result['width'] );
	}
}
