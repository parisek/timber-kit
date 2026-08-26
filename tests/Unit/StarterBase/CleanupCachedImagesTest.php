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

	/**
	 * Paths this test created, so teardown removes its own trace and nothing
	 * else. WP_CONTENT_DIR is a constant fixed by the bootstrap, so the cache
	 * directory cannot be varied per run — deleting the tree wholesale would
	 * take a concurrent run's fixtures (or a developer's scratch files) with it.
	 *
	 * @var list<string>
	 */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();

		$this->cache_dir = WP_CONTENT_DIR . '/cache/image';
		$this->makeDir( $this->cache_dir . '/900x0-center' );
		$this->makeDir( $this->cache_dir . '/1439x0-center' );

		$this->stubFilesystem();
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->created ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				@rmdir( $path ); // Only succeeds while empty, which is the intent.
			}
		}
		$this->created = [];
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_filesystem'] );
		parent::tearDown();
	}

	/** Create $path and every missing parent, recording only what did not exist. */
	private function makeDir( string $path ): void {
		$missing = [];
		for ( $dir = $path; ! is_dir( $dir ); $dir = dirname( $dir ) ) {
			$missing[] = $dir;
		}
		mkdir( $path, 0777, true );
		// Deepest first, so teardown's reverse walk removes children before parents.
		$this->created = array_merge( $this->created, array_reverse( $missing ) );
	}

	private function seedFile( string $path ): void {
		file_put_contents( $path, 'x' );
		$this->created[] = $path;
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
	private function stubAttachment( int $attachment_id, string $relative_path, int|null $siblings, string $last_error = '' ): void {
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/wp-content/uploads/' . $relative_path );
		Functions\when( 'get_post_meta' )->justReturn( $relative_path );

		$GLOBALS['wpdb'] = new class( $siblings, $last_error ) extends \wpdb {
			public string $postmeta = 'wp_postmeta';

			public string $last_error = '';

			/** @var list<mixed> Arguments the production code passed to prepare(). */
			public array $prepare_args = [];

			public string $prepared_query = '';

			public function __construct( private readonly int|null $siblings, string $last_error ) {
				$this->last_error = $last_error;
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepared_query = $query;
				$this->prepare_args = $args;
				return $query;
			}

			public function get_var( string $query ): string|null {
				return null === $this->siblings ? null : (string) $this->siblings;
			}
		};
	}

	private function seedDerivatives(): void {
		$this->seedFile( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
		$this->seedFile( $this->cache_dir . '/1439x0-center/homepage-hero-desktop.avif' );
		$this->seedFile( $this->cache_dir . '/900x0-center/unrelated.avif' );
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
	/**
	 * A failed query answers nothing. `get_var()` returns null on error, and the
	 * naive `(int) null > 0` reading of that is indistinguishable from a real
	 * zero — which would delete the files on any transient database error.
	 */
	public function test_keeps_derivatives_when_the_query_fails(): void {
		$this->seedDerivatives();
		$this->stubAttachment( 59741, '2026/08/homepage-hero-desktop.webp', siblings: null );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileExists( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
	}

	public function test_keeps_derivatives_when_the_query_reported_an_error(): void {
		$this->seedDerivatives();
		$this->stubAttachment( 59741, '2026/08/homepage-hero-desktop.webp', siblings: 0, last_error: 'MySQL server has gone away' );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileExists( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
	}

	/**
	 * An empty `_wp_attached_file` with a non-empty `get_attached_file()` means a
	 * filter supplied the path (offloaded media). Sharing is then unanswerable.
	 */
	public function test_keeps_derivatives_when_the_attached_file_meta_is_empty(): void {
		$this->seedDerivatives();
		$this->stubAttachment( 59741, '2026/08/homepage-hero-desktop.webp', siblings: 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileExists( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
	}

	/** The stub returns a count whatever the SQL says, so pin the SQL itself. */
	public function test_asks_the_database_the_question_it_claims_to_ask(): void {
		$this->seedDerivatives();
		$this->stubAttachment( 59741, '2026/08/homepage-hero-desktop.webp', siblings: 0 );

		$this->base->cleanup_cached_images( 59741 );

		$wpdb = $GLOBALS['wpdb'];
		$this->assertStringContainsString( "meta_key = '_wp_attached_file'", $wpdb->prepared_query );
		$this->assertStringContainsString( 'post_id != %d', $wpdb->prepared_query );
		$this->assertSame(
			[ 'wp_postmeta', '2026/08/homepage-hero-desktop.webp', 59741 ],
			$wpdb->prepare_args,
			'the table, the shared path and the excluded row must all reach prepare()'
		);
	}

	public function test_keeps_derivatives_when_the_sibling_count_is_unavailable(): void {
		$this->seedDerivatives();
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/wp-content/uploads/2026/08/homepage-hero-desktop.webp' );
		Functions\when( 'get_post_meta' )->justReturn( '2026/08/homepage-hero-desktop.webp' );
		unset( $GLOBALS['wpdb'] );

		$this->base->cleanup_cached_images( 59741 );

		$this->assertFileExists( $this->cache_dir . '/900x0-center/homepage-hero-desktop.avif' );
	}
}
