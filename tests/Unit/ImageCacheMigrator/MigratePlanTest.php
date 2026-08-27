<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheMigrator;

use Parisek\TimberKit\ImageCacheMigrator;
use PHPUnit\Framework\TestCase;

class MigratePlanTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/tk-cache-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/900x0-center', 0777, true );
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	private function seed( string $relative ): string {
		$path = $this->dir . '/' . $relative;
		@mkdir( dirname( $path ), 0777, true );
		file_put_contents( $path, 'x' );
		return $path;
	}

	public function test_unambiguous_name_is_planned_for_a_move(): void {
		$src = $this->seed( '900x0-center/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.webp' ] ] ) )->plan();

		$this->assertSame(
			[ $src => $this->dir . '/900x0-center/2026/08/hero.webp.avif' ],
			$plan['move']
		);
		$this->assertSame( [], $plan['ambiguous'] );
	}

	public function test_ambiguous_name_is_reported_with_its_candidates(): void {
		$this->seed( '900x0-center/11.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ '11' => [ '2022/03/11.png', '2022/10/11.png' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [ '2022/03/11.png', '2022/10/11.png' ], $plan['ambiguous']['900x0-center/11.avif'] );
	}

	public function test_name_absent_from_the_database_is_reported_as_orphaned(): void {
		$this->seed( '900x0-center/gone.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [ '900x0-center/gone.avif' ], $plan['orphaned'] );
	}

	public function test_existing_target_is_reported_as_a_conflict_not_overwritten(): void {
		$this->seed( '900x0-center/hero.avif' );
		$this->seed( '900x0-center/2026/08/hero.webp.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.webp' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [ '900x0-center/hero.avif' ], $plan['conflict'] );
	}

	/** A file already in the new layout is not a candidate -- it isn't sitting flat at the size-directory root. */
	public function test_already_migrated_file_is_left_alone(): void {
		$this->seed( '900x0-center/2026/08/hero.webp.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.webp' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [], $plan['orphaned'] );
	}

	/**
	 * A root upload whose own filename happens to carry no extension is the
	 * one case where the new target filename equals the flat candidate's
	 * filename byte-for-byte -- there is genuinely nothing to move.
	 */
	public function test_root_upload_with_extensionless_source_name_is_already_in_place(): void {
		$this->seed( '900x0-center/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ 'hero' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [], $plan['conflict'] );
		$this->assertSame( [], $plan['orphaned'] );
		$this->assertSame( [], $plan['ambiguous'] );
		$this->assertSame( [ '900x0-center/hero.avif' ], $plan['already_in_place'] );
	}

	/**
	 * A genuine root upload (no year/month directory) still gets its source
	 * extension folded into the target name -- the flag adds the extension
	 * unconditionally, independent of whether a directory segment applies.
	 */
	public function test_root_upload_with_extension_is_still_planned_for_a_move(): void {
		$src = $this->seed( '900x0-center/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ 'hero.png' ] ] ) )->plan();

		$this->assertSame(
			[ $src => $this->dir . '/900x0-center/hero.png.avif' ],
			$plan['move']
		);
	}

	/**
	 * ADR 0007's worked example: two sources sharing a directory and a stem
	 * but not an extension collided under the old flat name, and recovering
	 * which one `hero.avif` came from is exactly what that layout destroyed.
	 * The migrator must report the ambiguity and move nothing, rather than
	 * guess.
	 */
	public function test_same_directory_different_extension_is_ambiguous_and_left_unmigrated(): void {
		$this->seed( '900x0-center/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.jpg', '2026/08/hero.png' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame(
			[ '2026/08/hero.jpg', '2026/08/hero.png' ],
			$plan['ambiguous']['900x0-center/hero.avif']
		);
	}

	public function test_apply_moves_the_file_and_a_second_run_finds_nothing(): void {
		$src = $this->seed( '900x0-center/hero.avif' );
		$migrator = new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.webp' ] ] );

		$result = $migrator->apply( $migrator->plan() );

		$this->assertSame( 1, $result['moved'] );
		$this->assertSame( [], $result['failed'] );
		$this->assertFileDoesNotExist( $src );
		$this->assertFileExists( $this->dir . '/900x0-center/2026/08/hero.webp.avif' );

		$second_plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.webp' ] ] ) )->plan();
		$this->assertSame( [], $second_plan['move'] );
		$this->assertSame( [], $second_plan['ambiguous'] );
		$this->assertSame( [], $second_plan['orphaned'] );
		$this->assertSame( [], $second_plan['conflict'] );
	}

	/**
	 * A root upload's migrated derivative (`hero.png.avif`) sits at the same
	 * depth in the size directory as an unmigrated legacy one -- path depth
	 * alone cannot tell "already done" from "still to do". A second run must
	 * recognise the migrated file as `already_in_place`, not misread it as an
	 * orphan (its legacy stripped name `hero.png` isn't a key in the map,
	 * which is keyed by the un-migrated legacy names).
	 */
	public function test_root_upload_with_extension_is_idempotent_on_a_second_run(): void {
		$this->seed( '900x0-center/hero.avif' );
		$name_to_source_paths = [ 'hero' => [ 'hero.png' ] ];
		$migrator = new ImageCacheMigrator( $this->dir, $name_to_source_paths );

		$first = $migrator->apply( $migrator->plan() );
		$this->assertSame( 1, $first['moved'] );
		$this->assertFileExists( $this->dir . '/900x0-center/hero.png.avif' );

		$second_plan = ( new ImageCacheMigrator( $this->dir, $name_to_source_paths ) )->plan();
		$this->assertSame( [], $second_plan['move'] );
		$this->assertSame( [], $second_plan['ambiguous'] );
		$this->assertSame( [], $second_plan['orphaned'] );
		$this->assertSame( [], $second_plan['conflict'] );
		$this->assertSame( [ '900x0-center/hero.png.avif' ], $second_plan['already_in_place'] );
	}

	/**
	 * The genuine aliasing case ADR 0007 calls out: a new derivative for root
	 * source `hero.png` has the same on-disk spelling as the OLD flat
	 * derivative for a different root source, `hero.png.jpg` (whose legacy
	 * name -- `pathinfo(..., PATHINFO_FILENAME)` strips only the last
	 * extension -- is also `hero.png`). Recovering which one produced the
	 * file on disk is exactly what the flat layout destroyed, so this must
	 * be reported as ambiguous, never guessed.
	 */
	public function test_root_target_name_colliding_with_a_different_legacy_source_is_ambiguous(): void {
		$this->seed( '900x0-center/hero.png.avif' );

		// 'hero' => ['hero.png'] is the root source whose migrated derivative
		// is the on-disk file. 'hero.png' => ['hero.png.jpg'] is an unrelated
		// legacy source whose OWN stripped name (PATHINFO_FILENAME drops only
		// the last extension) also happens to be 'hero.png'.
		$plan = ( new ImageCacheMigrator(
			$this->dir,
			[ 'hero' => [ 'hero.png' ], 'hero.png' => [ 'hero.png.jpg' ] ]
		) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [], $plan['already_in_place'] );
		$this->assertSame(
			[ 'hero.png', 'hero.png.jpg' ],
			$plan['ambiguous']['900x0-center/hero.png.avif']
		);
	}

	/**
	 * A target can appear between `plan()` snapshotting the filesystem and
	 * `apply()` reaching that entry -- another invocation, a manually placed
	 * file, anything. `link()` fails outright when the target already
	 * exists, so `apply()` is safe against this race without a separate
	 * pre-check, and the source file must be left completely untouched.
	 */
	public function test_apply_does_not_overwrite_a_target_that_appeared_after_planning(): void {
		$src = $this->seed( '900x0-center/hero.avif' );
		$migrator = new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08/hero.webp' ] ] );

		$plan = $migrator->plan();
		$target = $this->seed( '900x0-center/2026/08/hero.webp.avif' );
		file_put_contents( $target, 'pre-existing' );

		$result = $migrator->apply( $plan );

		$this->assertSame( 0, $result['moved'] );
		$this->assertSame( [ $src ], $result['failed'] );
		$this->assertFileExists( $src );
		$this->assertSame( 'x', file_get_contents( $src ) );
		$this->assertSame( 'pre-existing', file_get_contents( $target ) );
	}
}
