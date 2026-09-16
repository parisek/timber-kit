<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * Selects the derivatives a run works on: which paths the arguments reach,
 * which the filters keep, and which have no source left to read.
 */
class PlanTest extends TestCase {

	private string $cache;

	private string $uploads;

	protected function setUp(): void {
		parent::setUp();
		$this->cache   = sys_get_temp_dir() . '/tk-regen-' . uniqid();
		$this->uploads = $this->cache . '-uploads';

		foreach ( array(
			'1440x0-center-q80/2026/01/hero.jpg.avif',
			'1440x0-center-q80/2026/01/hero.jpg.webp',
			'1440x0-center-q80/2026/02/team.png.avif',
			'600x800-top/2026/01/hero.jpg.avif',
			'600x800-top/2026/01/gone.jpg.avif',
			'1440x0-center-q80/flat.avif',
		) as $file ) {
			$this->write( $this->cache . '/' . $file );
		}
		foreach ( array( '2026/01/hero.jpg', '2026/02/team.png' ) as $file ) {
			$this->write( $this->uploads . '/' . $file );
		}
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->cache ) . ' ' . escapeshellarg( $this->uploads ) );
		parent::tearDown();
	}

	private function write( string $path ): void {
		@mkdir( dirname( $path ), 0777, true );
		file_put_contents( $path, 'x' );
	}

	private function regenerator(): ImageCacheRegenerator {
		return new ImageCacheRegenerator( $this->cache, $this->uploads, 80 );
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 * @return list<string>
	 */
	private function relative( array $entries ): array {
		$out = array_map( fn ( $e ) => substr( (string) $e['path'], strlen( $this->cache ) + 1 ), $entries );
		sort( $out );
		return $out;
	}

	public function testWithoutArgumentsItReachesEveryDerivative(): void {
		$targets = $this->regenerator()->resolveTargets( array() );

		$this->assertSame( array(), $targets['outside'] );
		$this->assertCount( 6, $targets['paths'] );
	}

	public function testADirectoryArgumentNarrowsTheRunToItsOwnTree(): void {
		$targets = $this->regenerator()->resolveTargets( array( '600x800-top' ) );

		$this->assertCount( 2, $targets['paths'] );
	}

	public function testAFileArgumentSelectsExactlyThatFile(): void {
		$targets = $this->regenerator()->resolveTargets( array( $this->cache . '/1440x0-center-q80/2026/01/hero.jpg.avif' ) );

		$this->assertSame( array( $this->cache . '/1440x0-center-q80/2026/01/hero.jpg.avif' ), $targets['paths'] );
	}

	public function testItRefusesAPathResolvingOutsideTheCacheDirectory(): void {
		$targets = $this->regenerator()->resolveTargets( array( $this->uploads . '/2026/01/hero.jpg', '../..' ) );

		$this->assertSame( array(), $targets['paths'] );
		$this->assertCount( 2, $targets['outside'] );
	}

	public function testTheFormatFilterKeepsOnlyThatOutputFormat(): void {
		$targets = $this->regenerator()->resolveTargets( array() );
		$plan    = $this->regenerator()->plan( $targets['paths'], 'webp' );

		$this->assertSame( array( '1440x0-center-q80/2026/01/hero.jpg.webp' ), $this->relative( $plan['entries'] ) );
		// Every other file, the flat-layout one included: the format filter runs
		// on the extension, before the path is read back.
		$this->assertSame( 5, $plan['skipped'] );
	}

	public function testTheOlderThanFilterSkipsAFileAlreadyRegenerated(): void {
		$fresh = $this->cache . '/1440x0-center-q80/2026/01/hero.jpg.avif';
		touch( $fresh, time() );
		foreach ( array(
			'1440x0-center-q80/2026/01/hero.jpg.webp',
			'1440x0-center-q80/2026/02/team.png.avif',
			'600x800-top/2026/01/hero.jpg.avif',
		) as $old ) {
			touch( $this->cache . '/' . $old, time() - 86400 );
		}

		$targets = $this->regenerator()->resolveTargets( array() );
		$plan    = $this->regenerator()->plan( $targets['paths'], null, time() - 3600 );

		$this->assertNotContains( '1440x0-center-q80/2026/01/hero.jpg.avif', $this->relative( $plan['entries'] ) );
		$this->assertCount( 3, $plan['entries'] );
	}

	public function testTheLimitStopsAfterThatManyEntries(): void {
		$targets = $this->regenerator()->resolveTargets( array() );
		$plan    = $this->regenerator()->plan( $targets['paths'], null, null, 2 );

		$this->assertCount( 2, $plan['entries'] );
		$this->assertSame( 2, $plan['remaining'] );
	}

	public function testADerivativeWhoseSourceIsGoneIsReportedAndNeverQueued(): void {
		$targets = $this->regenerator()->resolveTargets( array() );
		$plan    = $this->regenerator()->plan( $targets['paths'] );

		$this->assertSame( array( $this->cache . '/600x800-top/2026/01/gone.jpg.avif' ), $plan['orphan'] );
		$this->assertNotContains( '600x800-top/2026/01/gone.jpg.avif', $this->relative( $plan['entries'] ) );
		$this->assertFileExists( $this->cache . '/600x800-top/2026/01/gone.jpg.avif' );
	}

	public function testADerivativeWithNoSourcePathInItIsReportedSeparately(): void {
		$targets = $this->regenerator()->resolveTargets( array() );
		$plan    = $this->regenerator()->plan( $targets['paths'] );

		$this->assertSame( array( $this->cache . '/1440x0-center-q80/flat.avif' ), $plan['unreadable'] );
	}
}
