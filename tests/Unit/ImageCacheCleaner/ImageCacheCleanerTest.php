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
		Functions\when( 'sanitize_file_name' )->alias( fn ( $n ) => preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $n ) );
		$this->dir = sys_get_temp_dir() . '/tk-clear-' . uniqid();
		foreach ( [
			'1600x0-center-q80/miminko.avif',
			'1600x0-center-q80/miminko.webp',
			'3840x2043-crop-q80/mimco-5000-retus-scaled.avif',
			'900x0-center/2026/08/hero.png.avif',
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
		$this->assertCount( 6, ( new ImageCacheCleaner( $this->dir ) )->find() );
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

	public function test_a_name_matches_the_source_path_layout(): void {
		// Source-path layout keeps the source extension in the derivative name.
		$this->assertSame(
			[ '900x0-center/2026/08/hero.png.avif', '900x0-center/hero.avif' ],
			$this->relative( ( new ImageCacheCleaner( $this->dir ) )->find( [ 'hero.png' ] ) )
		);
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
