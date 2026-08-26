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
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08' ] ] ) )->plan();

		$this->assertSame(
			[ $src => $this->dir . '/900x0-center/2026/08/hero.avif' ],
			$plan['move']
		);
		$this->assertSame( [], $plan['ambiguous'] );
	}

	public function test_ambiguous_name_is_reported_with_its_candidates(): void {
		$this->seed( '900x0-center/11.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ '11' => [ '2022/03', '2022/10' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [ '2022/03', '2022/10' ], $plan['ambiguous']['900x0-center/11.avif'] );
	}

	public function test_name_absent_from_the_database_is_reported_as_orphaned(): void {
		$this->seed( '900x0-center/gone.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [ '900x0-center/gone.avif' ], $plan['orphaned'] );
	}

	public function test_existing_target_is_reported_as_a_conflict_not_overwritten(): void {
		$this->seed( '900x0-center/hero.avif' );
		$this->seed( '900x0-center/2026/08/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [ '900x0-center/hero.avif' ], $plan['conflict'] );
	}

	/** A file already in the new layout is not a candidate. */
	public function test_already_migrated_file_is_left_alone(): void {
		$this->seed( '900x0-center/2026/08/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [], $plan['orphaned'] );
	}

	/**
	 * dirs[0] === '' means a genuine root upload -- the flat cache path IS
	 * the final location already (no year/month directory, or the flag-off
	 * byte-identical case from the ADR). `<cache>/<size>//<filename>`
	 * collapses to the source file itself, so a naive is_file() check would
	 * call every such file its own conflict. It must instead be recognised
	 * as already in place: not moved, not reported as a conflict.
	 */
	public function test_root_upload_flat_derivative_is_already_in_place_not_a_conflict(): void {
		$this->seed( '900x0-center/hero.avif' );
		$plan = ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '' ] ] ) )->plan();

		$this->assertSame( [], $plan['move'] );
		$this->assertSame( [], $plan['conflict'] );
		$this->assertSame( [], $plan['orphaned'] );
		$this->assertSame( [], $plan['ambiguous'] );
		$this->assertSame( [ '900x0-center/hero.avif' ], $plan['already_in_place'] );
	}

	public function test_apply_moves_the_file_and_a_second_run_finds_nothing(): void {
		$src = $this->seed( '900x0-center/hero.avif' );
		$migrator = new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08' ] ] );

		$result = $migrator->apply( $migrator->plan() );

		$this->assertSame( 1, $result['moved'] );
		$this->assertSame( [], $result['failed'] );
		$this->assertFileDoesNotExist( $src );
		$this->assertFileExists( $this->dir . '/900x0-center/2026/08/hero.avif' );

		$this->assertSame( [], ( new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08' ] ] ) )->plan()['move'] );
	}

	/**
	 * A target can appear between `plan()` snapshotting the filesystem and
	 * `apply()` reaching that entry — another invocation, a manually placed
	 * file, anything. `rename()` silently replaces an existing destination on
	 * POSIX, so `apply()` must re-check immediately before calling it, not
	 * trust the snapshot `plan()` took.
	 */
	public function test_apply_does_not_overwrite_a_target_that_appeared_after_planning(): void {
		$src = $this->seed( '900x0-center/hero.avif' );
		$migrator = new ImageCacheMigrator( $this->dir, [ 'hero' => [ '2026/08' ] ] );

		$plan = $migrator->plan();
		$target = $this->seed( '900x0-center/2026/08/hero.avif' );
		file_put_contents( $target, 'pre-existing' );

		$result = $migrator->apply( $plan );

		$this->assertSame( 0, $result['moved'] );
		$this->assertSame( [ $src ], $result['failed'] );
		$this->assertFileExists( $src );
		$this->assertSame( 'pre-existing', file_get_contents( $target ) );
	}
}
