<?php

declare(strict_types=1);

namespace Tests\Unit\DevMediaProxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\DevMediaProxy;
use PHPUnit\Framework\TestCase;

/**
 * The proxy reconstructs a remote Resizer variant URL when the local source is
 * missing. That reconstruction has to agree with what the Resizer would have
 * written locally — every value it re-derives instead of reading off the
 * variant is a chance for the two to drift apart.
 */
class MissingSourceVariantsTest extends TestCase {

	private string $uploads_base_url = 'https://local.test/wp-content/uploads';
	private string $uploads_base_dir = '/tmp/wp-content/uploads';
	private string $origin_base_url = 'https://origin.test/wp-content/uploads';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DevMediaProxy::reset_for_tests();
		@mkdir( $this->uploads_base_dir, 0777, true );
		Functions\when( 'home_url' )->justReturn( 'https://local.test' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'baseurl' => $this->uploads_base_url,
				'basedir' => $this->uploads_base_dir,
			)
		);
		// Skip the HEAD probe so the test asserts the URL that would be probed
		// rather than the network's opinion of it.
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timber_kit_resizer_probe_remote_variants' === $filter ? false : $default;
			}
		);
		Functions\when( 'content_url' )->alias(
			function ( $path = '' ) {
				return 'https://local.test/wp-content/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'wp_check_filetype' )->alias(
			function ( $filename ) {
				$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				$map = array( 'avif' => 'image/avif', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'webp' => 'image/webp' );
				return array( 'type' => $map[ $ext ] ?? null, 'ext' => $ext );
			}
		);
		DevMediaProxy::register( $this->origin_base_url );
	}

	protected function tearDown(): void {
		DevMediaProxy::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function variant( array $overrides = array() ): array {
		return array_merge(
			array(
				'width' => 1200,
				'height' => 630,
				'media' => 0,
				'image_style' => 'center',
				'quality' => 100,
				'format' => 'avif',
				'cache_key' => '1200x630-center',
			),
			$overrides
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 * @return array<int, array<string, mixed>>
	 */
	private function probe( array $variants ): array {
		return DevMediaProxy::filter_resizer_missing_source_variants(
			null,
			$variants,
			'hero',
			array( 'alt' => '', 'caption' => '', 'description' => '' ),
			array(
				'uploads_base_url' => $this->uploads_base_url,
				'target_format' => 'avif',
				'image_cache_dir' => '/tmp/wp-content/cache/image',
			)
		);
	}

	public function test_variant_format_wins_over_the_request_wide_one(): void {
		// The request encodes AVIF; this variant asked for JPEG. Probing the
		// origin for the AVIF that was never written finds nothing.
		$result = $this->probe( array( $this->variant( array( 'format' => 'jpeg' ) ) ) );

		$this->assertCount( 1, $result );
		$this->assertStringEndsWith( '/1200x630-center/hero.jpeg', $result[0]['src'] );
		$this->assertSame( 'image/jpeg', $result[0]['type'] );
	}

	public function test_request_wide_format_is_used_when_the_variant_carries_none(): void {
		$variant = $this->variant();
		unset( $variant['format'] );

		$result = $this->probe( array( $variant ) );

		$this->assertStringEndsWith( '/1200x630-center/hero.avif', $result[0]['src'] );
	}

	public function test_the_variants_own_cache_key_is_used_verbatim(): void {
		// Quality-in-key is opt-in, so the segment the Resizer wrote is the only
		// thing that knows whether a -q suffix is there.
		$result = $this->probe( array( $this->variant( array( 'quality' => 82, 'cache_key' => '1200x630-center-q82' ) ) ) );

		$this->assertStringEndsWith( '/1200x630-center-q82/hero.avif', $result[0]['src'] );
	}

	public function test_a_variant_without_a_cache_key_falls_back_to_the_legacy_shape(): void {
		$variant = $this->variant( array( 'image_style' => 'top' ) );
		unset( $variant['cache_key'] );

		$result = $this->probe( array( $variant ) );

		$this->assertStringEndsWith( '/1200x630-top/hero.avif', $result[0]['src'] );
	}

	public function test_mixed_variants_each_keep_their_own_format_and_key(): void {
		$result = $this->probe(
			array(
				$this->variant(),
				$this->variant( array( 'width' => 600, 'height' => 315, 'format' => 'jpeg', 'cache_key' => '600x315-center-q82' ) ),
			)
		);

		$this->assertCount( 2, $result );
		$this->assertStringEndsWith( '/1200x630-center/hero.avif', $result[0]['src'] );
		$this->assertStringEndsWith( '/600x315-center-q82/hero.jpeg', $result[1]['src'] );
	}
}
