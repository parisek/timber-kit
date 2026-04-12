<?php

declare(strict_types=1);

namespace Tests\Unit\DevMediaProxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\DevMediaProxy;

class HooksTest extends TestCase {

	private string $uploads_base_url = 'https://local.test/wp-content/uploads';
	private string $uploads_base_dir = '/tmp/wp-content/uploads';
	private string $origin_base_url = 'https://origin.test/wp-content/uploads';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DevMediaProxy::reset_for_tests();
		@mkdir( $this->uploads_base_dir . '/2024/01', 0777, true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'baseurl' => $this->uploads_base_url,
				'basedir' => $this->uploads_base_dir,
			)
		);
	}

	protected function tearDown(): void {
		DevMediaProxy::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_hooks_only_once(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag, mixed $callback, int $priority, int $accepted_args ) use ( &$filters ) {
				$filters[] = array( $tag, $callback, $priority, $accepted_args );
				return true;
			}
		);

		DevMediaProxy::register( $this->origin_base_url );
		DevMediaProxy::register( $this->origin_base_url );

		$this->assertCount( 6, $filters );
		$this->assertSame( 'wp_get_attachment_url', $filters[0][0] );
		$this->assertSame( 'wp_prepare_attachment_for_js', $filters[4][0] );
		$this->assertSame( 'timber_kit_resizer_missing_source_variants', $filters[5][0] );
	}

	public function test_filter_attachment_url_rewrites_missing_file(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		DevMediaProxy::register( $this->origin_base_url );

		$url = $this->uploads_base_url . '/2024/01/missing.jpg';

		$this->assertSame(
			$this->origin_base_url . '/2024/01/missing.jpg',
			DevMediaProxy::filter_attachment_url( $url, 1 )
		);
	}

	public function test_filter_image_src_rewrites_first_element_only(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		DevMediaProxy::register( $this->origin_base_url );

		$image = array( $this->uploads_base_url . '/2024/01/missing.jpg', 800, 600, false );

		$result = DevMediaProxy::filter_image_src( $image, 1, 'large', false );

		$this->assertSame( $this->origin_base_url . '/2024/01/missing.jpg', $result[0] );
		$this->assertSame( 800, $result[1] );
	}

	public function test_filter_srcset_rewrites_only_missing_urls(): void {
		file_put_contents( $this->uploads_base_dir . '/2024/01/existing.jpg', 'ok' );
		Functions\when( 'add_filter' )->justReturn( true );
		DevMediaProxy::register( $this->origin_base_url );

		$sources = array(
			'800w' => array(
				'url' => $this->uploads_base_url . '/2024/01/existing.jpg',
				'descriptor' => 'w',
				'value' => 800,
			),
			'1600w' => array(
				'url' => $this->uploads_base_url . '/2024/01/missing.jpg',
				'descriptor' => 'w',
				'value' => 1600,
			),
		);

		$result = DevMediaProxy::filter_srcset( $sources, array( 800, 600 ), '', array(), 1 );

		$this->assertSame( $this->uploads_base_url . '/2024/01/existing.jpg', $result['800w']['url'] );
		$this->assertSame( $this->origin_base_url . '/2024/01/missing.jpg', $result['1600w']['url'] );
	}

	public function test_filter_image_attributes_rewrites_src_and_srcset(): void {
		file_put_contents( $this->uploads_base_dir . '/2024/01/existing.jpg', 'ok' );
		Functions\when( 'add_filter' )->justReturn( true );
		DevMediaProxy::register( $this->origin_base_url );

		$attr = array(
			'src' => $this->uploads_base_url . '/2024/01/missing.jpg',
			'srcset' => $this->uploads_base_url . '/2024/01/existing.jpg 800w, ' . $this->uploads_base_url . '/2024/01/missing.jpg 1600w',
		);

		$result = DevMediaProxy::filter_image_attributes( $attr, null, 'large' );

		$this->assertSame( $this->origin_base_url . '/2024/01/missing.jpg', $result['src'] );
		$this->assertSame(
			$this->uploads_base_url . '/2024/01/existing.jpg 800w, ' . $this->origin_base_url . '/2024/01/missing.jpg 1600w',
			$result['srcset']
		);
	}

	public function test_filter_attachment_for_js_rewrites_nested_sizes_and_icon(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		DevMediaProxy::register( $this->origin_base_url );

		$response = array(
			'url' => $this->uploads_base_url . '/2024/01/full.jpg',
			'icon' => $this->uploads_base_url . '/2024/01/icon.jpg',
			'sizes' => array(
				'thumbnail' => array(
					'url' => $this->uploads_base_url . '/2024/01/thumb.jpg',
				),
			),
		);

		$result = DevMediaProxy::filter_attachment_for_js( $response, null, null );

		$this->assertSame( $this->origin_base_url . '/2024/01/full.jpg', $result['url'] );
		$this->assertSame( $this->origin_base_url . '/2024/01/icon.jpg', $result['icon'] );
		$this->assertSame( $this->origin_base_url . '/2024/01/thumb.jpg', $result['sizes']['thumbnail']['url'] );
	}

	public function test_filter_resizer_missing_source_variants_builds_remote_variant_from_domain_only_origin(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, mixed $default ) {
				return $default;
			}
		);
		Functions\when( 'content_url' )->justReturn( 'https://local.test/wp-content' );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/avif', 'ext' => 'avif' ] );
		Functions\when( 'wp_remote_head' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( array $response ) {
			return $response['response']['code'] ?? 0;
		} );
		Functions\when( 'is_wp_error' )->justReturn( false );

		DevMediaProxy::register( 'https://origin.test' );

		$result = DevMediaProxy::filter_resizer_missing_source_variants(
			null,
			[
				[
					'width' => 800,
					'height' => 600,
					'media' => 768,
					'image_style' => 'crop',
				],
			],
			'photo',
			[
				'alt' => 'Photo',
				'caption' => '',
				'description' => '',
			],
			[
				'uploads_base_url' => $this->uploads_base_url,
				'target_format' => 'avif',
				'image_cache_dir' => '/var/www/html/wp-content/cache/image',
			]
		);

		$this->assertSame(
			[
				[
					'src' => 'https://origin.test/wp-content/cache/image/800x600-crop/photo.avif',
					'type' => 'image/avif',
					'width' => 800,
					'height' => 600,
					'media' => '(min-width: 768px)',
					'alt' => 'Photo',
					'caption' => '',
					'description' => '',
				],
			],
			$result
		);
	}

	public function test_filter_resizer_missing_source_variants_supports_full_uploads_origin(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, mixed $default ) {
				return $default;
			}
		);
		Functions\when( 'content_url' )->justReturn( 'https://local.test/wp-content' );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/avif', 'ext' => 'avif' ] );
		Functions\when( 'wp_remote_head' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( array $response ) {
			return $response['response']['code'] ?? 0;
		} );
		Functions\when( 'is_wp_error' )->justReturn( false );

		DevMediaProxy::register( 'https://origin.test/wp-content/uploads' );

		$result = DevMediaProxy::filter_resizer_missing_source_variants(
			null,
			[
				[
					'width' => 400,
					'height' => 300,
					'media' => 320,
					'image_style' => 'crop',
				],
			],
			'photo',
			[
				'alt' => 'Photo',
				'caption' => '',
				'description' => '',
			],
			[
				'uploads_base_url' => $this->uploads_base_url,
				'target_format' => 'avif',
				'image_cache_dir' => '/var/www/html/wp-content/cache/image',
			]
		);

		$this->assertSame( 'https://origin.test/wp-content/cache/image/400x300-crop/photo.avif', $result[0]['src'] );
	}

	public function test_filter_resizer_missing_source_variants_skips_nonexistent_remote_variant(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, mixed $default ) {
				return $default;
			}
		);
		Functions\when( 'content_url' )->justReturn( 'https://local.test/wp-content' );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/avif', 'ext' => 'avif' ] );
		Functions\when( 'wp_remote_head' )->justReturn( [ 'response' => [ 'code' => 404 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( array $response ) {
			return $response['response']['code'] ?? 0;
		} );
		Functions\when( 'is_wp_error' )->justReturn( false );

		DevMediaProxy::register( 'https://origin.test' );

		$result = DevMediaProxy::filter_resizer_missing_source_variants(
			null,
			[
				[
					'width' => 800,
					'height' => 600,
					'media' => 768,
					'image_style' => 'crop',
				],
			],
			'photo',
			[
				'alt' => 'Photo',
				'caption' => '',
				'description' => '',
			],
			[
				'uploads_base_url' => $this->uploads_base_url,
				'target_format' => 'avif',
				'image_cache_dir' => '/var/www/html/wp-content/cache/image',
			]
		);

		$this->assertSame( [], $result );
	}
}
