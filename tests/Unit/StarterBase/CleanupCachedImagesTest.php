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

		// Same stub shape as guardSourceDir()'s own test and Resizer's
		// SourcePathSegmentTest: keep A-Za-z0-9._-, strip the rest. This is
		// the function the writer (Resizer.php) sanitizes names through, so
		// stubbing it here lets the flag-on tests exercise the same
		// name-collapsing rule the real cache key was written with.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
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

	/**
	 * Create $path, and fail the test if it is already there.
	 *
	 * Ownership is then creation-based, not path-based: a fixture this run did
	 * not make is never adopted, and so never removed by this run's teardown.
	 * `x` decides that atomically, which `file_exists()` followed by a write
	 * cannot -- a concurrent run can create the file between the two.
	 */
	private function seedFile( string $path ): void {
		$handle = @fopen( $path, 'x' );
		if ( false === $handle ) {
			self::fail( "Fixture path already exists, so this run does not own it: {$path}" );
		}
		fwrite( $handle, 'x' );
		fclose( $handle );
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
		$this->assertMatchesRegularExpression(
			'/\bFROM\s+%i\b/i',
			$wpdb->prepared_query,
			'the table must be the %i placeholder, not a literal name the args then contradict'
		);
		// The stub hands the query back verbatim rather than substituting, so an
		// argument list alone proves nothing about where each value lands. Pin the
		// placeholders in order: `FROM wrong_table` would drop the `%i` and shift
		// every remaining argument onto the wrong one.
		preg_match_all( '/%[isd]/', $wpdb->prepared_query, $placeholders );
		$this->assertSame(
			[ '%i', '%s', '%d' ],
			$placeholders[0],
			'table, then shared path, then excluded row -- in that order'
		);
		$this->assertSame(
			[ 'wp_postmeta', '2026/08/homepage-hero-desktop.webp', 59741 ],
			$wpdb->prepare_args,
			'the table, the shared path and the excluded row must all reach prepare()'
		);
	}

	/** Same override the flag's own registration test uses. */
	private function enableSourcePathCacheKey(): void {
		( new \ReflectionClass( \Parisek\TimberKit\StarterBase::class ) )
			->getProperty( 'resizer_source_path_in_cache_key' )
			->setValue( $this->base, true );
	}

	public function test_with_the_flag_on_only_the_matching_source_directory_is_deleted(): void {
		mkdir( $this->cache_dir . '/900x0-center/2022/03', 0777, true );
		mkdir( $this->cache_dir . '/900x0-center/2022/10', 0777, true );
		$this->created[] = $this->cache_dir . '/900x0-center/2022/03';
		$this->created[] = $this->cache_dir . '/900x0-center/2022/10';
		$this->seedFile( $this->cache_dir . '/900x0-center/2022/03/11.png.avif' );
		$this->seedFile( $this->cache_dir . '/900x0-center/2022/10/11.png.avif' );

		$this->stubAttachment( 4711, '2022/03/11.png', siblings: 0 );
		$this->enableSourcePathCacheKey();

		$this->base->cleanup_cached_images( 4711 );

		$this->assertFileDoesNotExist( $this->cache_dir . '/900x0-center/2022/03/11.png.avif' );
		$this->assertFileExists(
			$this->cache_dir . '/900x0-center/2022/10/11.png.avif',
			'a different upload that happens to share a name must survive'
		);
	}

	/**
	 * ADR 0008's amendment: the extension is now part of the derivative
	 * name too, so `11.jpg` and `11.png` sharing one directory no longer
	 * collide on one derivative, and deleting one leaves the other's
	 * derivative on disk untouched.
	 */
	public function test_with_the_flag_on_same_directory_different_extension_are_not_conflated(): void {
		mkdir( $this->cache_dir . '/900x0-center/2026/08', 0777, true );
		$this->created[] = $this->cache_dir . '/900x0-center/2026/08';
		$this->seedFile( $this->cache_dir . '/900x0-center/2026/08/hero.jpg.avif' );
		$this->seedFile( $this->cache_dir . '/900x0-center/2026/08/hero.png.avif' );

		$this->stubAttachment( 4711, '2026/08/hero.jpg', siblings: 0 );
		$this->enableSourcePathCacheKey();

		$this->base->cleanup_cached_images( 4711 );

		$this->assertFileDoesNotExist( $this->cache_dir . '/900x0-center/2026/08/hero.jpg.avif' );
		$this->assertFileExists(
			$this->cache_dir . '/900x0-center/2026/08/hero.png.avif',
			'a sibling upload differing only by extension must survive'
		);
	}

	/**
	 * `img[0-9].png` is a legitimate (if odd) filename that reaches
	 * `_wp_attached_file` unsanitized in scenarios the upload sanitizer never
	 * saw -- a migration script, an offloaded-media plugin writing meta
	 * directly. The writer names its derivative `img[0-9].png.avif`, verbatim.
	 *
	 * Handing that name to `glob()` would read the brackets as a character
	 * class: it would match an unrelated `img5.png.avif` and miss the target's
	 * own file entirely. The delete reads the directory and compares instead,
	 * so a metacharacter is just a character. The name is no longer rewritten,
	 * which is why the two files below are the ones actually on disk.
	 */
	public function test_with_the_flag_on_a_glob_metacharacter_in_the_name_is_matched_literally(): void {
		mkdir( $this->cache_dir . '/900x0-center/2022/03', 0777, true );
		$this->created[] = $this->cache_dir . '/900x0-center/2022/03';
		$this->seedFile( $this->cache_dir . '/900x0-center/2022/03/img[0-9].png.avif' );
		$this->seedFile( $this->cache_dir . '/900x0-center/2022/03/img5.png.avif' );

		$this->stubAttachment( 4711, '2022/03/img[0-9].png', siblings: 0 );
		$this->enableSourcePathCacheKey();

		$this->base->cleanup_cached_images( 4711 );

		$this->assertFileDoesNotExist(
			$this->cache_dir . '/900x0-center/2022/03/img[0-9].png.avif',
			'the attachment\'s own derivative, named verbatim, must be found and deleted'
		);
		$this->assertFileExists(
			$this->cache_dir . '/900x0-center/2022/03/img5.png.avif',
			'a bracket expression must never be read as a pattern that reaches another upload'
		);
	}

	/**
	 * `_wp_attached_file` is database content. A corrupted or plugin-written
	 * value can carry a `..` segment; unguarded, that walks the glob outside
	 * both the source directory and the cache root. Mirrors
	 * `MigrateImageCacheCommand::guardSourceDir()`, which collapses any
	 * invalid directory (including one with a traversal component) to the
	 * flat root — matching the writer, which does the same when its own
	 * mirrored guard (`Resizer::sourcePathSegment()`) rejects a component.
	 */
	public function test_with_the_flag_on_a_traversal_segment_in_the_directory_is_treated_as_the_flat_root(): void {
		// The target's real derivative, at the flat root the guard falls back to.
		$this->seedFile( $this->cache_dir . '/900x0-center/11.png.avif' );

		// A file well outside the cache tree — a `..` component joined onto
		// a size directory and walked by the filesystem can reach anything
		// under WP_CONTENT_DIR, not just a sibling inside the cache. Placed
		// outside `$cache_dir` on purpose, so it can never be reached by
		// enumerating `$cache_dir`'s own subdirectories either.
		$outside_dir = dirname( $this->cache_dir ) . '/decoy';
		$this->makeDir( $outside_dir );
		$this->seedFile( $outside_dir . '/11.png.avif' );

		$this->stubAttachment( 4711, '../decoy/11.png', siblings: 0 );
		$this->enableSourcePathCacheKey();

		$this->base->cleanup_cached_images( 4711 );

		$this->assertFileExists(
			$outside_dir . '/11.png.avif',
			'a traversal segment must never let the delete walk outside the cache tree'
		);
		$this->assertFileDoesNotExist(
			$this->cache_dir . '/900x0-center/11.png.avif',
			'the guard collapses the whole invalid directory to the flat root, matching the writer\'s own fallback'
		);
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
	/**
	 * One stored name can be a prefix of another: `hero.png` and
	 * `hero.png.old` are both legal filenames, and their derivatives sit side
	 * by side as `hero.png.avif` and `hero.png.old.avif`.
	 *
	 * A plain prefix test matches both when `hero.png` is deleted, which is
	 * the same over-match the old `glob()` had, in a different spelling. The
	 * remainder after the name must be a bare extension, carrying no dot.
	 */
	public function test_with_the_flag_on_a_name_that_prefixes_another_does_not_delete_it(): void {
		mkdir( $this->cache_dir . '/900x0-center/2026/08', 0777, true );
		$this->created[] = $this->cache_dir . '/900x0-center/2026/08';
		$this->seedFile( $this->cache_dir . '/900x0-center/2026/08/hero.png.avif' );
		$this->seedFile( $this->cache_dir . '/900x0-center/2026/08/hero.png.old.avif' );

		$this->stubAttachment( 4711, '2026/08/hero.png', siblings: 0 );
		$this->enableSourcePathCacheKey();

		$this->base->cleanup_cached_images( 4711 );

		$this->assertFileDoesNotExist( $this->cache_dir . '/900x0-center/2026/08/hero.png.avif' );
		$this->assertFileExists(
			$this->cache_dir . '/900x0-center/2026/08/hero.png.old.avif',
			'a longer stored name that merely starts the same belongs to another upload'
		);
	}

}
