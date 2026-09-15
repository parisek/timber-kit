<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheCleaner;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\ImageCacheCleaner;
use PHPUnit\Framework\TestCase;

/**
 * Finds and deletes resizer derivatives: all of them, one format, or those of
 * named images, in both cache layouts. Deletes files only, and only inside
 * the cache directory.
 */
class ImageCacheCleanerTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Close to core: whitespace runs become '-', a few specials go, UTF-8
		// letters stay.
		Functions\when( 'sanitize_file_name' )->alias(
			fn ( $n ) => preg_replace( '/[\s]+/u', '-', str_replace( [ '?', '#', '%', '&' ], '', (string) $n ) )
		);
		$this->dir = sys_get_temp_dir() . '/tk-clear-' . uniqid();
		foreach ( [
			'1600x0-center-q80/miminko.avif',
			'1600x0-center-q80/miminko.webp',
			'3840x2043-crop-q80/mimco-5000-retus-scaled.avif',
			'900x0-center/2026/08/hero.png.avif',
			'900x0-center/2026/10/hero.png.avif',
			'900x0-center/2026/08/photo.v2.jpg.avif',
			'900x0-center/my-photo.avif',
			'900x0-center/kočka.avif',
			'900x0-center/photo.v2.avif',
			'unreadable/x.avif',
			'900x0-center/hero-banner.avif',
			'900x0-center/hero.avif',
		] as $file ) {
			@mkdir( dirname( $this->dir . '/' . $file ), 0777, true );
			file_put_contents( $this->dir . '/' . $file, 'x' );
		}
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @param list<string> $paths @return list<string> */
	private function relative( array $paths ): array {
		$out = array_map( fn ( $p ) => substr( $p, strlen( $this->dir ) + 1 ), $paths );
		sort( $out );
		return $out;
	}

	public function test_finds_every_derivative_without_a_selection(): void {
		$this->assertCount( 12, ( new ImageCacheCleaner( $this->dir ) )->find() );
	}

	public function test_format_limits_the_selection(): void {
		$this->assertSame(
			[ '1600x0-center-q80/miminko.webp' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [], 'webp' ) )
		);
	}

	public function test_a_file_name_matches_its_derivatives_with_or_without_extension(): void {
		$cleaner = new ImageCacheCleaner( $this->dir );
		$expected = [ '1600x0-center-q80/miminko.avif', '1600x0-center-q80/miminko.webp' ];

		$this->assertSame( $expected, $this->relative( $cleaner->find( [ 'miminko.jpg' ] ) ) );
		$this->assertSame( $expected, $this->relative( $cleaner->find( [ 'miminko' ] ) ) );
	}

	public function test_a_name_matches_the_scaled_file_wordpress_serves(): void {
		$this->assertSame(
			[ '3840x2043-crop-q80/mimco-5000-retus-scaled.avif' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [ 'mimco-5000-retus.jpg' ] ) )
		);
	}

	public function test_a_bare_name_matches_the_source_path_layout_in_every_directory(): void {
		// Source-path layout keeps the source extension in the derivative name.
		$this->assertSame(
			[ '900x0-center/2026/08/hero.png.avif', '900x0-center/2026/10/hero.png.avif', '900x0-center/hero.avif' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir, true ) )->find( [ 'hero.png' ] ) )
		);
	}

	public function test_a_relative_path_is_scoped_to_its_directory_in_the_source_path_layout(): void {
		// An attachment's _wp_attached_file: another month's hero.png is a
		// different upload and must stay.
		$this->assertSame(
			[ '900x0-center/2026/08/hero.png.avif' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir, true ) )->find( [ '2026/08/hero.png' ] ) )
		);
	}

	public function test_a_relative_path_matches_by_name_in_the_flat_layout(): void {
		// Flat derivatives carry no directory, so the directory cannot narrow.
		$this->assertContains(
			'900x0-center/hero.avif',
			$this->relative( ( new ImageCacheCleaner( $this->dir, false ) )->find( [ '2026/08/hero.png' ] ) )
		);
	}

	public function test_a_name_with_a_space_matches_the_sanitised_flat_stem(): void {
		$this->assertSame(
			[ '900x0-center/my-photo.avif' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [ 'my photo.jpg' ] ) )
		);
	}

	public function test_a_unicode_name_matches(): void {
		$this->assertSame(
			[ '900x0-center/kočka.avif' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [ 'kočka.jpg' ] ) )
		);
	}

	public function test_a_dotted_bare_name_keeps_its_dot(): void {
		// Only a known image extension is split off: photo.v2 is a stem.
		$this->assertContains(
			'900x0-center/photo.v2.avif',
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [ 'photo.v2' ] ) )
		);
	}

	public function test_jpg_and_jpeg_are_one_format(): void {
		file_put_contents( $this->dir . '/900x0-center/hero.jpeg', 'x' );

		$this->assertSame(
			[ '900x0-center/hero.jpeg' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [], 'JPG' ) )
		);
	}

	public function test_an_unreadable_directory_is_skipped_not_fatal(): void {
		chmod( $this->dir . '/unreadable', 0000 );
		try {
			$found = ( new ImageCacheCleaner( $this->dir ) )->find();
		} finally {
			chmod( $this->dir . '/unreadable', 0777 );
		}
		$this->assertNotEmpty( $found );
	}

	public function test_a_name_never_matches_a_longer_name_sharing_its_prefix(): void {
		$this->assertNotContains(
			'900x0-center/hero-banner.avif',
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [ 'hero' ] ) )
		);
	}

	public function test_delete_removes_files_and_keeps_directories(): void {
		$cleaner = new ImageCacheCleaner( $this->dir );

		$deleted = $cleaner->delete( $cleaner->find( [ 'miminko' ] ) );

		$this->assertSame( 2, $deleted );
		$this->assertFileDoesNotExist( $this->dir . '/1600x0-center-q80/miminko.avif' );
		$this->assertDirectoryExists( $this->dir . '/1600x0-center-q80' );
		$this->assertFileExists( $this->dir . '/900x0-center/hero.avif' );
	}

	public function test_delete_refuses_a_path_outside_the_cache_directory(): void {
		$outside = sys_get_temp_dir() . '/tk-outside-' . uniqid() . '.avif';
		file_put_contents( $outside, 'x' );

		$deleted = ( new ImageCacheCleaner( $this->dir ) )->delete( [ $outside, $this->dir . '/../' . basename( $outside ) ] );

		$this->assertSame( 0, $deleted );
		$this->assertFileExists( $outside );
		unlink( $outside );
	}

	public function test_a_missing_cache_directory_finds_nothing(): void {
		$this->assertSame( [], ( new ImageCacheCleaner( $this->dir . '/nope' ) )->find() );
	}
}
