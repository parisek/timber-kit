<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * One run at a time, and no litter from the run before.
 *
 * Two overlapping runs plan the same files and encode each one twice, which
 * costs double and lets two processes rename over the same target. A killed
 * run leaves temp files that nothing else removes, because the sweep skips
 * dotfiles and the cleaner does not know the prefix.
 */
class RunLockTest extends TestCase {

	private string $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = sys_get_temp_dir() . '/tk-lock-' . uniqid();
		@mkdir( $this->cache . '/1440x0-center-q80/2026/01', 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->cache ) );
		parent::tearDown();
	}

	private function regenerator(): ImageCacheRegenerator {
		return new ImageCacheRegenerator( $this->cache, $this->cache . '-uploads', 80 );
	}

	private function temp( string $name ): string {
		$path = $this->cache . '/1440x0-center-q80/2026/01/' . $name;
		file_put_contents( $path, 'half-written' );
		return $path;
	}

	public function testTheFirstRunTakesTheLock(): void {
		$this->assertTrue( $this->regenerator()->acquireLock() );
		$this->assertFileExists( $this->cache . '/' . ImageCacheRegenerator::LOCK_FILE );
	}

	public function testASecondRunIsRefusedWhileTheFirstHoldsTheLock(): void {
		$first = $this->regenerator();
		$this->assertTrue( $first->acquireLock() );

		$this->assertFalse( $this->regenerator()->acquireLock() );
	}

	public function testReleasingTheLockLetsTheNextRunIn(): void {
		$first = $this->regenerator();
		$first->acquireLock();
		$first->releaseLock();

		$this->assertTrue( $this->regenerator()->acquireLock() );
	}

	public function testReleasingALockThisRunNeverTookIsHarmless(): void {
		$this->regenerator()->releaseLock();

		$this->assertTrue( $this->regenerator()->acquireLock() );
	}

	public function testTheLockFileIsNeverPlannedAsADerivative(): void {
		$this->regenerator()->acquireLock();

		$paths = $this->regenerator()->resolveTargets( array() )['paths'];

		$this->assertSame( array(), $paths );
	}

	public function testATempFileFromADeadRunIsSwept(): void {
		$dead = $this->temp( ImageCacheRegenerator::TEMP_PREFIX . '999999-hero.jpg.avif' );

		$swept = $this->regenerator()->sweepStaleTemps();

		$this->assertSame( array( $dead ), $swept['deleted'] );
		$this->assertFileDoesNotExist( $dead );
	}

	public function testATempFileFromALiveRunIsKept(): void {
		$live = $this->temp( ImageCacheRegenerator::TEMP_PREFIX . getmypid() . '-hero.jpg.avif' );

		$swept = $this->regenerator()->sweepStaleTemps();

		$this->assertSame( array(), $swept['deleted'] );
		$this->assertSame( array( $live ), $swept['kept'] );
		$this->assertFileExists( $live );
	}

	public function testATempFileWithNoReadablePidIsKept(): void {
		// The name says nothing about who owns it, so deleting it is a guess
		// against a file another process may be writing this second.
		$odd = $this->temp( ImageCacheRegenerator::TEMP_PREFIX . 'not-a-pid-hero.jpg.avif' );

		$swept = $this->regenerator()->sweepStaleTemps();

		$this->assertSame( array(), $swept['deleted'] );
		$this->assertFileExists( $odd );
	}

	public function testARealDerivativeIsNeverSwept(): void {
		$real = $this->temp( 'hero.jpg.avif' );

		$swept = $this->regenerator()->sweepStaleTemps();

		$this->assertSame( array(), $swept['deleted'] );
		$this->assertFileExists( $real );
	}
}
