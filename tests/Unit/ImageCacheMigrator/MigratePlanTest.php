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
}
