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
 * original, trusts only the outcome it can read back, and returns every row
 * and the `-scaled` file to their previous state when anything does not hold,
 * including after a process died half way.
 */
class OriginalImageRescalerTest extends TestCase {

	private string $uploads;

	/** @var array<int, array<string, mixed>> Post meta per row, keyed by meta key. */
	private array $meta = [];

	/** @var list<int> */
	private array $purged = [];

	/** @var list<string> */
	private array $missing_sizes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads = sys_get_temp_dir() . '/tk-rescale-' . uniqid();
		mkdir( $this->uploads );

		Functions\when( 'get_post_meta' )->alias( fn ( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->meta[ $id ][ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
			unset( $this->meta[ $id ][ $key ] );
			return true;
		} );
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn ( $id ) => $this->meta[ $id ]['_wp_attachment_metadata'] ?? false );
		Functions\when( 'wp_update_attachment_metadata' )->alias( function ( $id, $value ) {
			$this->meta[ $id ]['_wp_attachment_metadata'] = $value;
			return true;
		} );
		Functions\when( 'update_attached_file' )->alias( function ( $id, $file ) {
			$this->meta[ $id ]['_wp_attached_file'] = basename( $file );
			return true;
		} );
		Functions\when( 'get_attached_file' )->alias( fn ( $id ) => $this->uploads . '/' . ( $this->meta[ $id ]['_wp_attached_file'] ?? '' ) );
		Functions\when( 'wp_getimagesize' )->alias( fn ( $file ) => is_file( $file ) ? getimagesize( $file ) : false );
		Functions\when( 'wp_get_missing_image_subsizes' )->alias( fn () => $this->missing_sizes );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->uploads . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( $this->uploads );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A PNG that getimagesize() reads at the given size: the signature plus an
	 * IHDR chunk is all it parses, so no image library is needed.
	 */
	private function png( string $name, int $width, int $height ): string {
		$file = $this->uploads . '/' . $name;
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		file_put_contents(
			$file,
			"\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) )
		);
		return $file;
	}

	/** An attachment served as a 2560 px photo-scaled.png with its original beside it. */
	private function scaledAttachment( int $id, int $width, int $height ): void {
		$ratio = 2560 / max( $width, $height );
		$this->png( 'photo-scaled.png', (int) round( $width * $ratio ), (int) round( $height * $ratio ) );
		$original = $this->png( 'photo.png', $width, $height );
		$this->meta[ $id ]['_wp_attached_file']       = 'photo-scaled.png';
		$this->meta[ $id ]['_wp_attachment_metadata'] = [
			'file'           => 'photo-scaled.png',
			'width'          => (int) round( $width * $ratio ),
			'height'         => (int) round( $height * $ratio ),
			'original_image' => 'photo.png',
			'sizes'          => [ 'large' => [ 'file' => 'photo-1024x683.png' ] ],
		];
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
	}

	private function sibling( int $of, int $id ): void {
		$this->meta[ $id ] = $this->meta[ $of ];
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

	/** Core restoring the original: metadata written, attached file untouched. */
	private function coreRestores( int $id, int $width, int $height ): void {
		Functions\when( 'wp_create_image_subsizes' )->alias( function () use ( $id, $width, $height ) {
			$meta = [ 'file' => 'photo.png', 'width' => $width, 'height' => $height, 'sizes' => [] ];
			$this->meta[ $id ]['_wp_attachment_metadata'] = $meta;
			return $meta;
		} );
	}

	/** Core rescaling: a new photo-scaled.png written over the old one. */
	private function coreRescales( int $id, int $width, int $height ): void {
		Functions\when( 'wp_create_image_subsizes' )->alias( function () use ( $id, $width, $height ) {
			$this->png( 'photo-scaled.png', $width, $height );
			$this->meta[ $id ]['_wp_attached_file'] = 'photo-scaled.png';
			$meta = [ 'file' => 'photo-scaled.png', 'width' => $width, 'height' => $height, 'original_image' => 'photo.png', 'sizes' => [] ];
			$this->meta[ $id ]['_wp_attachment_metadata'] = $meta;
			return $meta;
		} );
	}

	private function journal( int $id ): mixed {
		return $this->meta[ $id ][ OriginalImageRescaler::JOURNAL_KEY ] ?? null;
	}

	/**
	 * @param array<int, array<string, mixed>> $before
	 */
	private function assertRolledBack( array $before ): void {
		$this->assertSame( $before, $this->meta );
		$this->assertFileDoesNotExist( $this->uploads . '/photo-scaled.png.rescale-backup' );
	}

	// --- skips ----------------------------------------------------------------

	public function test_skips_non_scaled_file_even_when_original_image_present(): void {
		// EXIF-rotated uploads carry original_image too, but were never downscaled.
		$this->meta[7]['_wp_attachment_metadata'] = [ 'file' => 'photo-rotated.jpg', 'original_image' => 'photo.jpg' ];
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$this->assertSame( 'not_scaled', $this->rescaler()->rescale( 7 )['status'] );
		$this->assertSame( [], $this->purged );
	}

	public function test_skips_when_no_original_image_in_metadata(): void {
		$this->meta[7]['_wp_attachment_metadata'] = [ 'file' => 'photo-scaled.jpg' ];

		$this->assertSame( 'no_original', $this->rescaler()->rescale( 7 )['status'] );
	}

	public function test_reports_missing_when_original_was_pruned(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		unlink( $this->uploads . '/photo.png' );
		Functions\expect( 'wp_create_image_subsizes' )->never();

		$this->assertSame( 'missing', $this->rescaler()->rescale( 7 )['status'] );
	}

	// --- dry run --------------------------------------------------------------

	public function test_dry_run_plans_a_restore_and_writes_nothing(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$this->threshold( 4000 );
		Functions\expect( 'wp_create_image_subsizes' )->never();
		$before = $this->meta;

		$result = $this->rescaler( [ 8 ] )->rescale( 7, true );

		$this->assertSame( 'would_restore', $result['status'] );
		$this->assertSame( [ 3417, 4000 ], [ $result['width'], $result['height'] ] );
		$this->assertSame( [ 8 ], $result['siblings'] );
		$this->assertSame( $before, $this->meta );
		$this->assertSame( [], $this->purged );
	}

	public function test_dry_run_plans_a_rescale(): void {
		$this->scaledAttachment( 7, 5000, 1644 );
		$this->threshold( 4000 );

		$this->assertSame( 'would_rescale', $this->rescaler()->rescale( 7, true )['status'] );
	}

	public function test_unchanged_when_the_threshold_did_not_grow(): void {
		$this->scaledAttachment( 7, 5000, 3334 );
		$this->threshold( 2560 );

		$this->assertSame( 'unchanged', $this->rescaler()->rescale( 7, true )['status'] );
	}

	public function test_unchanged_is_measured_on_the_file_not_the_row(): void {
		// A sibling row can carry stale dimensions. The file every row serves decides.
		$this->scaledAttachment( 7, 3417, 4000 );
		$this->meta[7]['_wp_attachment_metadata']['height'] = 4000;
		$this->threshold( 4000 );

		$this->assertSame( 'would_restore', $this->rescaler()->rescale( 7, true )['status'] );
	}

	public function test_disabled_threshold_restores_every_original(): void {
		$this->scaledAttachment( 7, 6000, 4000 );
		$this->threshold( 0 );

		$result = $this->rescaler()->rescale( 7, true );

		$this->assertSame( 'would_restore', $result['status'] );
		$this->assertSame( [ 6000, 4000 ], [ $result['width'], $result['height'] ] );
	}

	// --- apply ----------------------------------------------------------------

	public function test_restores_original_within_threshold(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );

		$result = $this->rescaler()->rescale( 7 );

		$this->assertSame( 'restored', $result['status'] );
		$this->assertSame( [ 3417, 4000 ], [ $result['width'], $result['height'] ] );
		// Core rewrites _wp_attached_file only when it downscales.
		$this->assertSame( 'photo.png', $this->meta[7]['_wp_attached_file'] );
		$this->assertSame( [ 7 ], $this->purged );
		$this->assertNull( $this->journal( 7 ) );
		$this->assertFileDoesNotExist( $this->uploads . '/photo-scaled.png.rescale-backup' );
	}

	public function test_rescales_original_above_threshold_and_updates_siblings(): void {
		$this->scaledAttachment( 7, 5000, 1644 );
		$this->sibling( 7, 8 );
		$this->threshold( 4000 );
		$this->coreRescales( 7, 4000, 1315 );

		$result = $this->rescaler( [ 8 ] )->rescale( 7 );

		$this->assertSame( 'rescaled', $result['status'] );
		$this->assertSame( [ 4000, 1315 ], [ $result['width'], $result['height'] ] );
		$this->assertSame( 'photo-scaled.png', $this->meta[8]['_wp_attached_file'] );
		$this->assertSame( 4000, $this->meta[8]['_wp_attachment_metadata']['width'] );
	}

	public function test_accepts_a_rotated_copy_core_wrote_instead_of_the_original(): void {
		$this->scaledAttachment( 7, 3000, 4000 );
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->alias( function () {
			$this->png( 'photo-rotated.png', 4000, 3000 );
			$this->meta[7]['_wp_attached_file'] = 'photo-rotated.png';
			$meta = [ 'file' => 'photo-rotated.png', 'width' => 4000, 'height' => 3000, 'original_image' => 'photo.png', 'sizes' => [] ];
			$this->meta[7]['_wp_attachment_metadata'] = $meta;
			return $meta;
		} );

		$this->assertSame( 'restored', $this->rescaler()->rescale( 7 )['status'] );
	}

	public function test_purges_and_finds_siblings_before_the_attached_file_changes(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );
		$seen = [];
		$rescaler = new OriginalImageRescaler(
			function ( int $id ) use ( &$seen ): void {
				$seen['purge'] = $this->meta[ $id ]['_wp_attached_file'];
			},
			function ( int $id ) use ( &$seen ): array {
				$seen['siblings'] = $this->meta[ $id ]['_wp_attached_file'];
				return [];
			}
		);

		$rescaler->rescale( 7 );

		$this->assertSame( [ 'siblings' => 'photo-scaled.png', 'purge' => 'photo-scaled.png' ], $seen );
	}

	public function test_keeps_metadata_keys_core_does_not_own_per_row(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$this->meta[7]['_wp_attachment_metadata']['blurhash'] = 'primary';
		$this->sibling( 7, 8 );
		$this->meta[8]['_wp_attachment_metadata']['blurhash'] = 'sibling';
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );

		$this->rescaler( [ 8 ] )->rescale( 7 );

		$this->assertSame( 'primary', $this->meta[7]['_wp_attachment_metadata']['blurhash'] );
		$this->assertSame( 'sibling', $this->meta[8]['_wp_attachment_metadata']['blurhash'] );
		$this->assertArrayNotHasKey( 'original_image', $this->meta[7]['_wp_attachment_metadata'] );
	}

	// --- failure --------------------------------------------------------------

	public function test_rescale_core_silently_skipped_rolls_back(): void {
		// Core never returns WP_Error. An editor that cannot load or save
		// returns metadata still describing the unscaled original.
		$this->scaledAttachment( 7, 5000, 1644 );
		$this->sibling( 7, 8 );
		$before = $this->meta;
		$this->threshold( 4000 );
		$this->coreRestores( 7, 5000, 1644 );

		$this->assertSame( 'failed', $this->rescaler( [ 8 ] )->rescale( 7 )['status'] );
		$this->assertRolledBack( $before );
	}

	public function test_missing_sub_sizes_roll_back(): void {
		// Core skips a failed sub-size silently and still returns full metadata.
		$this->scaledAttachment( 7, 3417, 4000 );
		$before = $this->meta;
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );
		$this->missing_sizes = [ 'large' ];

		$this->assertSame( 'failed', $this->rescaler()->rescale( 7 )['status'] );
		$this->assertRolledBack( $before );
	}

	public function test_attached_file_that_did_not_change_rolls_back(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$before = $this->meta;
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );
		Functions\when( 'update_attached_file' )->justReturn( false );

		$this->assertSame( 'failed', $this->rescaler()->rescale( 7 )['status'] );
		$this->assertRolledBack( $before );
	}

	public function test_a_sibling_write_that_did_not_stick_rolls_back_every_row(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$this->sibling( 7, 8 );
		$before = $this->meta;
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );
		// Row 8 silently keeps its old metadata; the rollback write still lands.
		Functions\when( 'wp_update_attachment_metadata' )->alias( function ( $id, $value ) {
			if ( 8 !== $id || isset( $value['original_image'] ) ) {
				$this->meta[ $id ]['_wp_attachment_metadata'] = $value;
			}
			return true;
		} );

		$this->assertSame( 'failed', $this->rescaler( [ 8 ] )->rescale( 7 )['status'] );
		$this->assertRolledBack( $before );
	}

	public function test_failed_rescale_puts_the_old_scaled_file_back(): void {
		// Core writes the new -scaled file over the old one under the same name.
		$this->scaledAttachment( 7, 5000, 1644 );
		$this->threshold( 4000 );
		$this->coreRescales( 7, 4000, 1315 );
		$this->missing_sizes = [ 'large' ];

		$this->rescaler()->rescale( 7 );

		$this->assertSame( 2560, getimagesize( $this->uploads . '/photo-scaled.png' )[0] );
	}

	public function test_exception_rolls_back_and_rethrows(): void {
		$this->scaledAttachment( 7, 3417, 4000 );
		$before = $this->meta;
		$this->threshold( 4000 );
		Functions\when( 'wp_create_image_subsizes' )->alias( function () {
			$this->meta[7]['_wp_attachment_metadata'] = [ 'file' => 'photo.png' ];
			throw new \RuntimeException( 'Imagick exhausted memory' );
		} );

		try {
			$this->rescaler()->rescale( 7 );
			$this->fail( 'the exception must reach the caller' );
		} catch ( \RuntimeException $e ) {
			$this->assertRolledBack( $before );
		}
	}

	// --- interrupted run ------------------------------------------------------

	/**
	 * The state a SIGKILL leaves after core's first metadata write: no catch
	 * block ran, the row no longer looks -scaled, the journal and the backup
	 * copy are still there.
	 *
	 * @return array<int, array<string, mixed>> The state before the killed run.
	 */
	private function killedMidWay( int $id ): array {
		$this->scaledAttachment( $id, 3417, 4000 );
		copy( $this->uploads . '/photo-scaled.png', $this->uploads . '/photo-scaled.png.rescale-backup' );
		$before = $this->meta;
		$this->meta[ $id ][ OriginalImageRescaler::JOURNAL_KEY ] = [
			'rows'        => [ $id => [ 'attached_file' => 'photo-scaled.png', 'metadata' => $before[ $id ]['_wp_attachment_metadata'] ] ],
			'scaled_path' => $this->uploads . '/photo-scaled.png',
		];
		$this->meta[ $id ]['_wp_attached_file']       = 'photo.png';
		$this->meta[ $id ]['_wp_attachment_metadata'] = [ 'file' => 'photo.png', 'width' => 3417, 'height' => 4000, 'sizes' => [] ];
		return $before;
	}

	public function test_dry_run_reports_an_interrupted_row(): void {
		$this->killedMidWay( 7 );

		$this->assertSame( 'interrupted', $this->rescaler()->rescale( 7, true )['status'] );
	}

	public function test_the_next_run_recovers_an_interrupted_row_and_finishes_it(): void {
		$this->killedMidWay( 7 );
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );

		$result = $this->rescaler()->rescale( 7 );

		$this->assertSame( 'restored', $result['status'] );
		$this->assertSame( 'photo.png', $this->meta[7]['_wp_attached_file'] );
		$this->assertNull( $this->journal( 7 ) );
		$this->assertFileDoesNotExist( $this->uploads . '/photo-scaled.png.rescale-backup' );
	}

	public function test_a_failed_recovery_run_still_leaves_the_row_as_it_was(): void {
		$before = $this->killedMidWay( 7 );
		$this->threshold( 4000 );
		$this->coreRestores( 7, 3417, 4000 );
		$this->missing_sizes = [ 'large' ];

		$this->assertSame( 'failed', $this->rescaler()->rescale( 7 )['status'] );
		$this->assertRolledBack( $before );
	}
}
