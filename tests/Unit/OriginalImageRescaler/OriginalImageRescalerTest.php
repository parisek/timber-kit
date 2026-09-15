<?php

declare(strict_types=1);

namespace Tests\Unit\OriginalImageRescaler;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\OriginalImageRescaler;
use PHPUnit\Framework\TestCase;

/**
 * Verifies OriginalImageRescaler re-runs the upload pipeline from a preserved
 * original, points every attachment row that shares the file at the result,
 * purges stale resizer derivatives first, and leaves the attachment untouched
 * when anything short of a finished rescale happens.
 */
class OriginalImageRescalerTest extends TestCase {

	/** @var string[] */
	private array $temp_files = [];

	/** @var array<int, string> */
	private array $attached = [];

	/** @var array<int, array<string, mixed>> */
	private array $metadata = [];

	/** @var list<int> */
	private array $purged = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_get_attachment_metadata' )->alias( fn ( $id ) => $this->metadata[ $id ] ?? false );
		Functions\when( 'wp_update_attachment_metadata' )->alias( function ( $id, $meta ) {
			$this->metadata[ $id ] = $meta;
			return true;
		} );
		Functions\when( 'get_post_meta' )->alias( fn ( $id ) => $this->attached[ $id ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->attached[ $id ] = $value;
			return true;
		} );
		Functions\when( 'update_attached_file' )->alias( function ( $id, $file ) {
			$this->attached[ $id ] = basename( $file );
			return true;
		} );
		Functions\when( 'wp_getimagesize' )->alias( fn ( $file ) => getimagesize( $file ) );
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ) => $thing instanceof \WP_Error );
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A PNG that getimagesize() reads at the given size: the signature plus an
	 * IHDR chunk is all it parses, so no image library is needed.
	 */
	private function tempOriginal( int $width, int $height ): string {
		$file = tempnam( sys_get_temp_dir(), 'tk-orig-' ) . '.png';
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		file_put_contents(
			$file,
			"\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) )
		);
		$this->temp_files[] = $file;
		return $file;
	}

	private function scaledAttachment( int $id, string $original ): void {
		$this->attached[ $id ] = 'photo-scaled.png';
		$this->metadata[ $id ] = [
			'file'           => 'photo-scaled.png',
			'width'          => 2560,
			'height'         => 1707,
			'original_image' => basename( $original ),
		];
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
	}

	/**
	 * @param list<int> $siblings
	 */
	private function rescaler( array $siblings = [] ): OriginalImageRescaler {
		return new OriginalImageRescaler(
			function ( int $id ): void {
				$this->purged[] = $id;
			},
			fn ( int $id ): array => $siblings
		);
	}

	private function threshold( int $px ): void {
		Filters\expectApplied( 'big_image_size_threshold' )->andReturn( $px );
	}

	// --- skips ----------------------------------------------------------------

	public function test_skips_non_scaled_file_even_when_original_image_present(): void {
		// EXIF-rotated uploads carry original_image too, but were never downscaled.
		$this->metadata[7] = [ 'file' => 'photo-rotated.jpg', 'original_image' => 'photo.jpg' ];
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$result = $this->rescaler()->rescale( 7 );

		$this->assertSame( 'not_scaled', $result['status'] );
		$this->assertSame( [], $this->purged );
	}

	public function test_skips_when_no_original_image_in_metadata(): void {
		$this->metadata[7] = [ 'file' => 'photo-scaled.jpg' ];
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$this->assertSame( 'no_original', $this->rescaler()->rescale( 7 )['status'] );
	}

	public function test_reports_missing_when_original_was_pruned(): void {
		$this->scaledAttachment( 7, '/tmp/does-not-exist-' . uniqid() . '.png' );
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$this->assertSame( 'missing', $this->rescaler()->rescale( 7 )['status'] );
		$this->assertSame( 'photo-scaled.png', $this->attached[7] );
	}

	// --- dry run --------------------------------------------------------------

	public function test_dry_run_reports_restore_for_original_within_threshold(): void {
		$this->scaledAttachment( 7, $this->tempOriginal( 3417, 4000 ) );
		$this->threshold( 4000 );
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$result = $this->rescaler()->rescale( 7, true );

		$this->assertSame( 'would_restore', $result['status'] );
		$this->assertSame( [ 3417, 4000 ], [ $result['width'], $result['height'] ] );
		$this->assertSame( 'photo-scaled.png', $this->attached[7] );
		$this->assertSame( [], $this->purged );
	}

	public function test_dry_run_reports_rescale_for_original_above_threshold(): void {
		$this->scaledAttachment( 7, $this->tempOriginal( 5000, 1644 ) );
		$this->threshold( 4000 );
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$this->assertSame( 'would_rescale', $this->rescaler()->rescale( 7, true )['status'] );
	}

	public function test_dry_run_reports_unchanged_when_threshold_did_not_grow(): void {
		// Regenerating at the threshold that produced the -scaled file would
		// only rewrite the same pixels.
		$this->scaledAttachment( 7, $this->tempOriginal( 5000, 3334 ) );
		$this->threshold( 2560 );

		$this->assertSame( 'unchanged', $this->rescaler()->rescale( 7, true )['status'] );
	}

	// --- apply ----------------------------------------------------------------

	public function test_restores_original_within_threshold(): void {
		$original = $this->tempOriginal( 3417, 4000 );
		$this->scaledAttachment( 7, $original );
		$this->threshold( 4000 );
		Functions\expect( 'wp_create_image_subsizes' )->once()->with( $original, 7 )->andReturn( [
			'file'   => basename( $original ),
			'width'  => 3417,
			'height' => 4000,
			'sizes'  => [],
		] );

		$result = $this->rescaler()->rescale( 7 );

		$this->assertSame( 'restored', $result['status'] );
		// Core only rewrites _wp_attached_file when it downscales, so the
		// rescaler must point the row at the original itself.
		$this->assertSame( basename( $original ), $this->attached[7] );
		$this->assertSame( 3417, $this->metadata[7]['width'] );
		$this->assertSame( [ 7 ], $this->purged );
	}

	public function test_rescales_original_above_threshold(): void {
		$original = $this->tempOriginal( 5000, 1644 );
		$this->scaledAttachment( 7, $original );
		$this->threshold( 4000 );
		Functions\expect( 'wp_create_image_subsizes' )->once()->andReturnUsing( function () {
			$this->attached[7] = 'photo-scaled.png';
			return [
				'file'           => 'photo-scaled.png',
				'width'          => 4000,
				'height'         => 1315,
				'original_image' => 'photo.png',
				'sizes'          => [],
			];
		} );

		$this->attached[8] = 'photo-scaled.png';

		$result = $this->rescaler( [ 8 ] )->rescale( 7 );

		$this->assertSame( 'rescaled', $result['status'] );
		$this->assertSame( 'photo-scaled.png', $this->attached[8] );
		$this->assertSame( 4000, $this->metadata[8]['width'] );
		$this->assertSame( [ 4000, 1315 ], [ $result['width'], $result['height'] ] );
	}

	public function test_purges_derivatives_before_the_attached_file_changes(): void {
		// The purger locates derivatives by the attached file name, so it has to
		// run while that name is still the -scaled one.
		$this->scaledAttachment( 7, $this->tempOriginal( 3417, 4000 ) );
		$this->threshold( 4000 );
		$seen = null;
		$rescaler = new OriginalImageRescaler(
			function ( int $id ) use ( &$seen ): void {
				$seen = $this->attached[ $id ];
			},
			fn (): array => []
		);
		Functions\when( 'wp_create_image_subsizes' )->justReturn( [ 'file' => 'photo.png', 'width' => 3417, 'height' => 4000 ] );

		$rescaler->rescale( 7 );

		$this->assertSame( 'photo-scaled.png', $seen );
	}

	public function test_copies_result_to_every_row_sharing_the_file(): void {
		// WPML writes one attachment row per language over one file and syncs
		// _wp_attached_file, but not _wp_attachment_metadata.
		$original = $this->tempOriginal( 3417, 4000 );
		$this->scaledAttachment( 7, $original );
		$this->attached[8] = 'photo-scaled.png';
		$this->metadata[8] = $this->metadata[7];
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->justReturn( [ 'file' => basename( $original ), 'width' => 3417, 'height' => 4000 ] );

		$result = $this->rescaler( [ 8 ] )->rescale( 7 );

		$this->assertSame( [ 8 ], $result['siblings'] );
		$this->assertSame( basename( $original ), $this->attached[8] );
		$this->assertSame( $this->metadata[7], $this->metadata[8] );
	}

	public function test_rescale_that_core_silently_skipped_rolls_back(): void {
		// Core never returns WP_Error here. When the editor cannot load or save,
		// it returns the default metadata, which still describes the original.
		$original = $this->tempOriginal( 5000, 1644 );
		$this->scaledAttachment( 7, $original );
		$before = $this->metadata[7];
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->alias( function () use ( $original ) {
			$meta = [ 'file' => basename( $original ), 'width' => 5000, 'height' => 1644, 'sizes' => [] ];
			$this->metadata[7] = $meta;
			return $meta;
		} );

		$result = $this->rescaler( [ 8 ] )->rescale( 7 );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'photo-scaled.png', $this->attached[7] );
		$this->assertSame( $before, $this->metadata[7] );
		$this->assertArrayNotHasKey( 8, $this->attached, 'siblings are written only after a verified success' );
	}

	public function test_restore_that_returned_a_different_file_rolls_back(): void {
		$original = $this->tempOriginal( 3417, 4000 );
		$this->scaledAttachment( 7, $original );
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->justReturn( [] );

		$this->assertSame( 'failed', $this->rescaler()->rescale( 7 )['status'] );
		$this->assertSame( 'photo-scaled.png', $this->attached[7] );
	}

	public function test_exception_during_regeneration_rolls_back_and_rethrows(): void {
		$this->scaledAttachment( 7, $this->tempOriginal( 3417, 4000 ) );
		$before = $this->metadata[7];
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->alias( function () {
			$this->metadata[7] = [ 'file' => 'partial.png' ];
			throw new \RuntimeException( 'Imagick exhausted memory' );
		} );

		try {
			$this->rescaler()->rescale( 7 );
			$this->fail( 'the exception must reach the caller' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'photo-scaled.png', $this->attached[7] );
			$this->assertSame( $before, $this->metadata[7] );
		}
	}

	public function test_keeps_metadata_keys_core_does_not_own(): void {
		// Plugins add top-level keys; core's array carries only its own.
		$original = $this->tempOriginal( 3417, 4000 );
		$this->scaledAttachment( 7, $original );
		$this->metadata[7]['blurhash'] = 'LEHV6nWB2yk8';
		$this->attached[8] = 'photo-scaled.png';
		$this->metadata[8] = $this->metadata[7];
		$this->metadata[8]['blurhash'] = 'sibling-own';
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->justReturn( [ 'file' => basename( $original ), 'width' => 3417, 'height' => 4000, 'sizes' => [] ] );

		$this->rescaler( [ 8 ] )->rescale( 7 );

		$this->assertSame( 'LEHV6nWB2yk8', $this->metadata[7]['blurhash'] );
		$this->assertSame( 'sibling-own', $this->metadata[8]['blurhash'] );
		$this->assertSame( 3417, $this->metadata[8]['width'] );
		$this->assertArrayNotHasKey( 'original_image', $this->metadata[7], 'a restored original has no original_image' );
	}

	public function test_finds_siblings_before_the_attached_file_changes(): void {
		// The sibling query matches on _wp_attached_file; after re-pointing it
		// would find nothing.
		$original = $this->tempOriginal( 3417, 4000 );
		$this->scaledAttachment( 7, $original );
		$this->threshold( 4000 );
		$seen = null;
		$rescaler = new OriginalImageRescaler(
			function (): void {},
			function ( int $id ) use ( &$seen ): array {
				$seen = $this->attached[ $id ];
				return [];
			}
		);
		Functions\when( 'wp_create_image_subsizes' )->justReturn( [ 'file' => basename( $original ), 'width' => 3417, 'height' => 4000 ] );

		$rescaler->rescale( 7 );

		$this->assertSame( 'photo-scaled.png', $seen );
	}

	public function test_dry_run_reports_siblings(): void {
		$this->scaledAttachment( 7, $this->tempOriginal( 3417, 4000 ) );
		$this->threshold( 4000 );

		$this->assertSame( [ 8 ], $this->rescaler( [ 8 ] )->rescale( 7, true )['siblings'] );
	}

	public function test_disabled_threshold_restores_every_original(): void {
		$this->scaledAttachment( 7, $this->tempOriginal( 6000, 4000 ) );
		$this->threshold( 0 );

		$result = $this->rescaler()->rescale( 7, true );

		$this->assertSame( 'would_restore', $result['status'] );
		$this->assertSame( [ 6000, 4000 ], [ $result['width'], $result['height'] ] );
	}
}
