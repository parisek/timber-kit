<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * `timber_kit_resizer_cache_version` changes every derivative URL, not its
 * path, so browsers and proxies holding an old copy fetch the new one.
 *
 * Empty is the default and must leave URLs byte-identical: a version bump of
 * the package cannot move anything on its own.
 */
class CacheVersionTest extends ResizerTestCase {

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function append( string $url, string $version ): string {
		return Resizer::appendCacheVersion( $url, $version );
	}

	public function test_empty_version_leaves_the_url_unchanged(): void {
		$this->assertSame( 'https://x.test/cache/image/900x0-center/hero.avif', $this->append( 'https://x.test/cache/image/900x0-center/hero.avif', '' ) );
	}

	public function test_version_is_appended_as_a_query_parameter(): void {
		$this->assertSame( 'https://x.test/a/hero.avif?v=2026-09-15', $this->append( 'https://x.test/a/hero.avif', '2026-09-15' ) );
	}

	public function test_version_is_url_encoded(): void {
		$this->assertSame( 'https://x.test/a/hero.avif?v=a%20b%26c', $this->append( 'https://x.test/a/hero.avif', 'a b&c' ) );
	}

	public function test_an_existing_query_is_kept(): void {
		$this->assertSame( 'https://x.test/a/hero.avif?x=1&v=3', $this->append( 'https://x.test/a/hero.avif?x=1', '3' ) );
	}

	public function test_the_filter_is_read_trimmed(): void {
		Functions\when( 'apply_filters' )->alias(
			fn ( $filter, $default ) => 'timber_kit_resizer_cache_version' === $filter ? ' 7 ' : $default
		);

		$this->assertSame( '7', $this->getPrivateProperty( new Resizer(), 'cache_version' ) );
	}

	public function test_a_missing_source_hands_the_version_to_the_proxy(): void {
		Monkey\setUp();
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/png', 'ext' => 'png' ] );
		Functions\when( 'sanitize_file_name' )->alias( fn( $n ) => (string) $n );
		\Patchwork\redefine( 'file_exists', fn () => false );

		$context = [];
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) use ( &$context ) {
				if ( 'timber_kit_resizer_cache_version' === $filter ) {
					return '5';
				}
				if ( 'timber_kit_resizer_missing_source_variants' === $filter ) {
					$context = $args[3];
				}
				return $default;
			}
		);

		( new Resizer() )->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/hero.png', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertIsArray( $context );
		$this->assertSame( '5', $context['cache_version'] ?? null );
	}

	/**
	 * The line the feature exists for: a derivative returned by resizer()
	 * carries the version, and the source appended after it does not.
	 */
	public function test_resizer_returns_versioned_derivatives_and_an_unversioned_source(): void {
		Monkey\setUp();
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'wp_check_filetype' )->alias(
			fn ( $f ) => [ 'type' => str_ends_with( (string) $f, '.avif' ) ? 'image/avif' : 'image/png', 'ext' => '' ]
		);
		Functions\when( 'sanitize_file_name' )->alias( fn( $n ) => (string) $n );
		Functions\when( 'content_url' )->alias( fn ( $p = '' ) => 'https://example.com/wp-content/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'apply_filters' )->alias(
			fn ( $filter, $default ) => 'timber_kit_resizer_cache_version' === $filter ? '9' : $default
		);
		// Source and target both on disk: the cached-hit branch, no encoding.
		\Patchwork\redefine( 'file_exists', fn () => true );

		$images = ( new Resizer() )->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/hero.png', 'width' => 1000, 'height' => 1000, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringEndsWith( '/hero.avif?v=9', $images[0]['src'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/hero.png', end( $images )['src'] );
	}
}
