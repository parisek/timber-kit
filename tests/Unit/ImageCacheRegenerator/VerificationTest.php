<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * What a re-encoded file has to prove before it replaces a working one.
 *
 * "It weighs something and decodes" is a weak claim: a truncated `mdat`, a
 * 0-quality encode and an encode by an old library all pass it. The target is
 * live, so the checks here are the price of replacing it, and each one that
 * fails keeps the old file.
 */
class VerificationTest extends TestCase {

	private string $cache;

	private string $uploads;

	private string $target;

	protected function setUp(): void {
		parent::setUp();
		$this->cache   = sys_get_temp_dir() . '/tk-verify-' . uniqid();
		$this->uploads = $this->cache . '-uploads';
		$this->target  = $this->cache . '/1440x0-center-q80/2026/01/hero.jpg.avif';

		@mkdir( dirname( $this->target ), 0777, true );
		file_put_contents( $this->target, 'old-derivative' );
		@mkdir( $this->uploads . '/2026/01', 0777, true );
		file_put_contents( $this->uploads . '/2026/01/hero.jpg', 'source' );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->cache ) . ' ' . escapeshellarg( $this->uploads ) );
		parent::tearDown();
	}

	private function regenerator(): ImageCacheRegenerator {
		return new ImageCacheRegenerator( $this->cache, $this->uploads, 80 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entry(): array {
		$entry = $this->regenerator()->parse( $this->target );
		$this->assertNotNull( $entry );
		return $entry;
	}

	/**
	 * @param string $bytes What the encoder writes.
	 * @param int    $mode  Mode the encoder leaves on the temp file.
	 * @return callable(array<string, mixed>, string, string): bool
	 */
	private function encoderWriting( string $bytes, int $mode = 0600 ): callable {
		return function ( array $variant, string $source, string $temp ) use ( $bytes, $mode ): bool {
			file_put_contents( $temp, $bytes );
			chmod( $temp, $mode );
			return true;
		};
	}

	/** @return list<string> Files left in the target's directory. */
	private function directory(): array {
		$files = array_values( array_diff( (array) scandir( dirname( $this->target ) ), array( '.', '..' ) ) );
		sort( $files );
		return $files;
	}

	public function testTheNewFileKeepsTheModeTheOldOneHad(): void {
		chmod( $this->target, 0644 );

		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivative', 0600 ), fn () => true );

		$this->assertSame( 'regenerated', $result['status'] );
		$this->assertSame( 0644, fileperms( $this->target ) & 0777 );
	}

	public function testADifferentModeOnTheOldFileIsCarriedOverToo(): void {
		chmod( $this->target, 0664 );

		$this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivative', 0600 ), fn () => true );

		$this->assertSame( 0664, fileperms( $this->target ) & 0777 );
	}

	public function testDimensionsThatDoNotMatchTheOldFileAreRefused(): void {
		$measurer = fn ( string $path ): ?array => str_contains( basename( $path ), ImageCacheRegenerator::TEMP_PREFIX )
			? array( 720, 405 )
			: array( 1440, 810 );

		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivative' ), fn () => true, false, $measurer );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'dimensions', $result['reason'] );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}

	public function testDimensionsThatMatchAreAccepted(): void {
		$measurer = fn ( string $path ): ?array => array( 1440, 810 );

		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivative' ), fn () => true, false, $measurer );

		$this->assertSame( 'regenerated', $result['status'] );
		$this->assertSame( 'fresh-derivative', file_get_contents( $this->target ) );
	}

	public function testAnUnmeasurableOldFileDoesNotBlockTheReplacement(): void {
		// The old file is exactly what this command exists to replace, so an
		// old file nothing can measure is a reason to re-encode, not to stop.
		$measurer = fn ( string $path ): ?array => str_contains( basename( $path ), ImageCacheRegenerator::TEMP_PREFIX )
			? array( 1440, 810 )
			: null;

		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivative' ), fn () => true, false, $measurer );

		$this->assertSame( 'regenerated', $result['status'] );
	}

	public function testAnUnmeasurableNewFileIsRefused(): void {
		$measurer = fn ( string $path ): ?array => str_contains( basename( $path ), ImageCacheRegenerator::TEMP_PREFIX )
			? null
			: array( 1440, 810 );

		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivative' ), fn () => true, false, $measurer );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'dimensions', $result['reason'] );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
	}

	public function testAFileThatBarelyGrewIsReportedAsSuspect(): void {
		// 14 bytes to 15 is a 1.07x ratio. The encoder change this command
		// exists for makes an AVIF several times bigger, so a file that stayed
		// about the same size is worth a look.
		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh-derivat' . 'iv' ), fn () => true );

		$this->assertSame( 'regenerated', $result['status'] );
		$this->assertTrue( $result['suspect'] );
		$this->assertEqualsWithDelta( 15 / 14, $result['ratio'], 0.001 );
	}

	public function testAFileThatGrewWellPastTheThresholdIsNotSuspect(): void {
		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( str_repeat( 'x', 140 ) ), fn () => true );

		$this->assertSame( 'regenerated', $result['status'] );
		$this->assertFalse( $result['suspect'] );
		$this->assertEqualsWithDelta( 10.0, $result['ratio'], 0.001 );
	}

	public function testSuspectIsAReportAndNeverARefusal(): void {
		$result = $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'tiny' ), fn () => true );

		$this->assertSame( 'regenerated', $result['status'] );
		$this->assertTrue( $result['suspect'] );
		$this->assertSame( 'tiny', file_get_contents( $this->target ) );
	}

	public function testTheMedianRatioOfARunIsReadableFromTheRatios(): void {
		$this->assertEqualsWithDelta( 2.0, ImageCacheRegenerator::median( array( 1.0, 2.0, 9.0 ) ), 0.001 );
		$this->assertEqualsWithDelta( 2.5, ImageCacheRegenerator::median( array( 1.0, 2.0, 3.0, 9.0 ) ), 0.001 );
		$this->assertEqualsWithDelta( 0.0, ImageCacheRegenerator::median( array() ), 0.001 );
	}

	public function testAFailureReportsWhichCheckRefusedIt(): void {
		$this->assertSame( 'encoder', $this->regenerator()->regenerate( $this->entry(), fn () => false, fn () => true )['reason'] );
		$this->assertSame( 'empty', $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( '' ), fn () => true )['reason'] );
		$this->assertSame( 'undecodable', $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'x' ), fn () => false )['reason'] );
	}

	public function testASuccessCarriesNoFailureReason(): void {
		$this->assertSame( '', $this->regenerator()->regenerate( $this->entry(), $this->encoderWriting( 'fresh' ), fn () => true )['reason'] );
	}
}
