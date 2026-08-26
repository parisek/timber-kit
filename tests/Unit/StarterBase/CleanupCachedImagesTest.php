<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * `cleanup_cached_images()` keys the resizer cache by file, not by attachment.
 *
 * A single file routinely carries more than one attachment row: WPML writes one
 * per language, and a duplicate upload can be pointed at an existing path. The
 * derivatives under wp-content/cache/image are shared by all of them, so
 * deleting one row must not take the other rows' images with it.
 */
class CleanupCachedImagesTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	private string $cache_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();

		$this->cache_dir = WP_CONTENT_DIR . '/cache/image';
		$this->removeCacheDir();
		mkdir( $this->cache_dir . '/900x0-center', 0777, true );
		mkdir( $this->cache_dir . '/1439x0-center', 0777, true );

		$this->stubFilesystem();
	}

	protected function tearDown(): void {
		$this->removeCacheDir();
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_filesystem'] );
		parent::tearDown();
	}

	private function removeCacheDir(): void {
		if ( ! is_dir( $this->cache_dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $this->cache_dir );
	}

	/** Real unlink through the WP_Filesystem seam the production code uses. */
	private function stubFilesystem(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$GLOBALS['wp_filesystem'] = new class {
			public function exists( string $path ): bool {
				return file_exists( $path );
			}

			public function delete( string $path ): bool {
				return unlink( $path );
			}
		};
	}

	/**
	 * @param int $siblings Attachment rows OTHER than the one being deleted that
	 *                      point at the same `_wp_attached_file` value.
	 */
	private function stubAttachment( int $attachment_id, string $relative_path, int $siblings ): void {
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/wp-content/uploads/' . $relative_path );
		Functions\when( 'get_post_meta' )->justReturn( $relative_path );

		$GLOBALS['wpdb'] = new class( $siblings ) extends \wpdb {
			public string $postmeta = 'wp_postmeta';

			public function __construct( private readonly int $siblings ) {
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query ): string {
				return (string) $this->siblings;
			}
		};
	}

	private function seedDerivatives(): void {
		file_put_contents( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif', 'x' );
		file_put_contents( $this->cache_dir . '/1439x0-center/homepage-hero-desktop.avif', 'x' );
		file_put_contents( $this->cache_dir . '/900x0-center/unrelated.avif', 'x' );
	}

	public function test_deletes_derivatives_when_no_other_attachment_shares_the_file(): void {
		$this->seedDerivatives();
		$this->stubAttachment( 59741, '2026/08/homepage-hero-desktop.webp', siblings: 0 );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileDoesNotExist( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
		$this->assertFileDoesNotExist( $this->cache_dir . '/1439x0-center/homepage-hero-desktop.avif' );
		$this->assertFileExists(
			$this->cache_dir . '/900x0-center/unrelated.avif',
			'a different basename must never be touched'
		);
	}

	public function test_keeps_derivatives_when_another_attachment_shares_the_file(): void {
		$this->seedDerivatives();
		// The WPML case: five rows, one file. Deleting one language's row leaves
		// four rows — and the live page — still rendering these derivatives.
		$this->stubAttachment( 59741, '2026/08/homepage-hero-desktop.webp', siblings: 4 );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileExists( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
		$this->assertFileExists( $this->cache_dir . '/1439x0-center/homepage-hero-desktop.avif' );
	}

	/**
	 * Without a database the sibling question cannot be answered. Skipping the
	 * delete leaves a stale file that the next resize overwrites; guessing wrong
	 * in the other direction destroys an image that is still on the page.
	 */
	public function test_keeps_derivatives_when_the_sibling_count_is_unavailable(): void {
		$this->seedDerivatives();
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/wp-content/uploads/2026/08/homepage-hero-desktop.webp' );
		Functions\when( 'get_post_meta' )->justReturn( '2026/08/homepage-hero-desktop.webp' );
		unset( $GLOBALS['wpdb'] );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileExists( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
	}
}
