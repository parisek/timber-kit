<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Tests\Unit\ResizerTestCase;

class SourcePathCacheKeyTest extends ResizerTestCase {

	protected function setUp(): void {
		parent::setUp();

		// sourcePathSegment() validates each path segment via sanitize_file_name();
		// mirror WordPress's own behaviour so real-looking year/month segments pass.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
	}

	/** The flag off must produce byte-identical keys to today. */
	public function test_cache_key_is_unchanged_when_the_flag_is_off(): void {
		$keys = $this->cacheKeysFor( 'https://x.test/wp-content/uploads/2026/08/hero.webp', false );

		$this->assertSame( [ '900x0-center' ], $keys );
	}

	public function test_cache_key_carries_the_source_directory_when_the_flag_is_on(): void {
		$keys = $this->cacheKeysFor( 'https://x.test/wp-content/uploads/2026/08/hero.webp', true );

		$this->assertSame( [ '900x0-center/2026/08' ], $keys );
	}

	/** A site without year/month folders must not be relocated by the flag. */
	public function test_cache_key_is_unchanged_when_the_source_sits_at_the_uploads_root(): void {
		$keys = $this->cacheKeysFor( 'https://x.test/wp-content/uploads/hero.webp', true );

		$this->assertSame( [ '900x0-center' ], $keys );
	}

	/** The regression this whole change exists for. */
	public function test_two_sources_sharing_a_name_get_different_keys(): void {
		$march = $this->cacheKeysFor( 'https://x.test/wp-content/uploads/2022/03/11.png', true );
		$october = $this->cacheKeysFor( 'https://x.test/wp-content/uploads/2022/10/11.png', true );

		$this->assertNotSame( $march, $october );
	}

	/**
	 * Returns the cache_key of each normalized variant after resize() has
	 * augmented it. Implemented in Step 3 of this task.
	 *
	 * @return list<string>
	 */
	private function cacheKeysFor( string $src, bool $flag ): array {
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/wp-content/uploads',
			'baseurl' => 'https://x.test/wp-content/uploads',
		] );

		$resizer = $this->createResizerWithSourcePathFlag( $flag );
		$variants = $this->callPrivate( $resizer, 'normalizeVariants', [ [ [ 900, 0, 0, 'center' ] ] ] );
		$segment = $flag
			? $this->callPrivate( $resizer, 'sourcePathSegment', [ $src, 'https://x.test/wp-content/uploads' ] )
			: '';

		$keys = [];
		foreach ( $variants as $variant ) {
			$keys[] = $segment === '' ? $variant['cache_key'] : $variant['cache_key'] . '/' . $segment;
		}

		return $keys;
	}
}
