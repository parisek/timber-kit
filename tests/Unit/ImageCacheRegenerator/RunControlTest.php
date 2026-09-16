<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a run decides before and around the encode itself.
 *
 * The cutoff it accepts, the disk it needs, the threads it hands the encoder
 * and the status it leaves for cron to read.
 */
class RunControlTest extends TestCase {

	private string $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = sys_get_temp_dir() . '/tk-control-' . uniqid();
		@mkdir( $this->cache, 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->cache ) );
		parent::tearDown();
	}

	private function regenerator(): ImageCacheRegenerator {
		return new ImageCacheRegenerator( $this->cache, $this->cache . '-uploads', 80 );
	}

	/** @return list<array{0: string}> */
	public static function relativeCutoffs(): array {
		return array(
			array( '-2 days' ),
			array( '2 days ago' ),
			array( 'yesterday' ),
			array( 'now' ),
			array( '-1 week' ),
		);
	}

	#[DataProvider( 'relativeCutoffs' )]
	public function testARelativeCutoffIsRefused( string $value ): void {
		// A relative cutoff moves with every run, so the same files fall
		// before it again a few nights later and are re-encoded for nothing.
		$cutoff = ImageCacheRegenerator::parseCutoff( $value );

		$this->assertTrue( $cutoff['relative'] );
		$this->assertNull( $cutoff['time'] );
	}

	/** @return list<array{0: string}> */
	public static function absoluteCutoffs(): array {
		return array(
			array( '2026-09-16' ),
			array( '2026-09-16 22:00' ),
			array( '2026-09-16T22:00:00+02:00' ),
			array( '@1758052800' ),
		);
	}

	#[DataProvider( 'absoluteCutoffs' )]
	public function testAnAbsoluteCutoffIsAccepted( string $value ): void {
		$cutoff = ImageCacheRegenerator::parseCutoff( $value );

		$this->assertFalse( $cutoff['relative'] );
		$this->assertIsInt( $cutoff['time'] );
	}

	public function testAnUnreadableCutoffIsNeitherRelativeNorATime(): void {
		$cutoff = ImageCacheRegenerator::parseCutoff( 'last tuesday of never' );

		$this->assertFalse( $cutoff['relative'] );
		$this->assertNull( $cutoff['time'] );
	}

	public function testAnAbsoluteCutoffIsReadInTheProcessTimezone(): void {
		// The value carries no zone, so the answer moves with the process
		// timezone. The docblock and the README say so; this pins it.
		$was = date_default_timezone_get();
		date_default_timezone_set( 'UTC' );
		$utc = ImageCacheRegenerator::parseCutoff( '2026-09-16 00:00' )['time'];
		date_default_timezone_set( 'Europe/Prague' );
		$prague = ImageCacheRegenerator::parseCutoff( '2026-09-16 00:00' )['time'];
		date_default_timezone_set( $was );

		$this->assertSame( 7200, $utc - (int) $prague );
	}

	public function testAZoneInTheValueItselfSurvivesTheProcessTimezone(): void {
		$was = date_default_timezone_get();
		date_default_timezone_set( 'UTC' );
		$utc = ImageCacheRegenerator::parseCutoff( '2026-09-16T00:00:00+02:00' )['time'];
		date_default_timezone_set( 'Europe/Prague' );
		$prague = ImageCacheRegenerator::parseCutoff( '2026-09-16T00:00:00+02:00' )['time'];
		date_default_timezone_set( $was );

		$this->assertSame( $utc, $prague );
	}

	public function testTheDiskPreflightRefusesWhenFreeSpaceIsBelowTheFactor(): void {
		$free = (int) disk_free_space( $this->cache );
		// Ask for a total whose factored need is the whole disk and then some.
		$check = $this->regenerator()->diskPreflight( $free );

		$this->assertFalse( $check['ok'] );
		$this->assertSame( (int) ( $free * ImageCacheRegenerator::DISK_FACTOR ), $check['needed'] );
	}

	public function testTheDiskPreflightPassesWhenThereIsRoom(): void {
		$check = $this->regenerator()->diskPreflight( 1024 );

		$this->assertTrue( $check['ok'] );
		$this->assertSame( 3072, $check['needed'] );
		$this->assertGreaterThan( 0, $check['free'] );
	}

	public function testAnEmptySelectionNeedsNothingAndPasses(): void {
		$check = $this->regenerator()->diskPreflight( 0 );

		$this->assertTrue( $check['ok'] );
		$this->assertSame( 0, $check['needed'] );
	}

	public function testAnUnreadableDiskDoesNotStopTheRun(): void {
		$elsewhere = new ImageCacheRegenerator( $this->cache . '/gone', $this->cache, 80 );

		$check = $elsewhere->diskPreflight( 1024 );

		$this->assertTrue( $check['ok'] );
		$this->assertSame( -1, $check['free'] );
	}

	public function testAFailedFileLeavesANonZeroStatus(): void {
		$this->assertSame( 1, ImageCacheRegenerator::exitStatus( 1, false ) );
	}

	public function testGivingUpOnTheLoadGateLeavesANonZeroStatus(): void {
		$this->assertSame( 1, ImageCacheRegenerator::exitStatus( 0, true ) );
	}

	public function testACleanRunLeavesZero(): void {
		$this->assertSame( 0, ImageCacheRegenerator::exitStatus( 0, false ) );
	}

	public function testTheThreadLimitIsOnlyAppliedWhereImagickCanTakeIt(): void {
		$applied = ImageCacheRegenerator::applyThreadLimit( 1 );

		$this->assertSame( class_exists( '\Imagick' ), $applied );
	}

	public function testAThreadLimitBelowOneIsRefused(): void {
		$this->assertFalse( ImageCacheRegenerator::applyThreadLimit( 0 ) );
		$this->assertFalse( ImageCacheRegenerator::applyThreadLimit( -4 ) );
	}
}
