<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * Re-encodes one derivative in place.
 *
 * The target is replaced only by a file that encoded, weighs something and
 * decodes. Every other outcome leaves the old file exactly as it was, because
 * a visitor is reading it while this runs.
 */
class RegenerateTest extends TestCase {

	private string $cache;

	private string $uploads;

	private string $target;

	protected function setUp(): void {
		parent::setUp();
		$this->cache   = sys_get_temp_dir() . '/tk-regen-' . uniqid();
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

	/** @return list<string> Files left in the target's directory. */
	private function directory(): array {
		$files = array_values( array_diff( (array) scandir( dirname( $this->target ) ), array( '.', '..' ) ) );
		sort( $files );
		return $files;
	}

	public function testASuccessfulEncodeReplacesTheTargetAtTheSamePath(): void {
		$encoder = function ( array $variant, string $source, string $temp ): bool {
			file_put_contents( $temp, 'fresh-derivative' );
			return true;
		};

		$result = $this->regenerator()->regenerate( $this->entry(), $encoder, fn () => true );

		$this->assertSame( 'regenerated', $result['status'] );
		$this->assertSame( 'fresh-derivative', file_get_contents( $this->target ) );
		$this->assertSame( 14, $result['before'] );
		$this->assertSame( 16, $result['after'] );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}

	public function testTheEncoderIsHandedTheParsedVariantAndAPathBesideTheTarget(): void {
		$seen = array();

		$this->regenerator()->regenerate(
			$this->entry(),
			function ( array $variant, string $source, string $temp ) use ( &$seen ): bool {
				$seen = array( $variant, $source, $temp );
				file_put_contents( $temp, 'fresh' );
				return true;
			},
			fn () => true
		);

		$this->assertSame( 1440, $seen[0]['width'] );
		$this->assertSame( 'center', $seen[0]['image_style'] );
		$this->assertSame( 80, $seen[0]['quality'] );
		$this->assertSame( 'avif', $seen[0]['format'] );
		$this->assertSame( $this->uploads . '/2026/01/hero.jpg', $seen[1] );
		$this->assertSame( dirname( $this->target ), dirname( $seen[2] ) );
		$this->assertNotSame( $this->target, $seen[2] );
	}

	public function testAFailedEncodeKeepsTheOldFileAndLeavesNoTemp(): void {
		$result = $this->regenerator()->regenerate( $this->entry(), fn () => false, fn () => true );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}

	public function testAnEmptyTempFileNeverReachesTheTarget(): void {
		$encoder = function ( array $variant, string $source, string $temp ): bool {
			file_put_contents( $temp, '' );
			return true;
		};

		$result = $this->regenerator()->regenerate( $this->entry(), $encoder, fn () => true );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}

	public function testATempFileThatDoesNotDecodeNeverReachesTheTarget(): void {
		$encoder = function ( array $variant, string $source, string $temp ): bool {
			file_put_contents( $temp, 'not-an-image' );
			return true;
		};

		$result = $this->regenerator()->regenerate( $this->entry(), $encoder, fn () => false );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}

	public function testADryRunReportsTheEntryAndWritesNothing(): void {
		$called = false;

		$result = $this->regenerator()->regenerate(
			$this->entry(),
			function () use ( &$called ): bool {
				$called = true;
				return true;
			},
			fn () => true,
			true
		);

		$this->assertSame( 'would_regenerate', $result['status'] );
		$this->assertFalse( $called );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}

	public function testAnExceptionFromTheEncoderIsAFailureAndNotACrash(): void {
		$encoder = function ( array $variant, string $source, string $temp ): bool {
			throw new \RuntimeException( 'encoder blew up' );
		};

		$result = $this->regenerator()->regenerate( $this->entry(), $encoder, fn () => true );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'old-derivative', file_get_contents( $this->target ) );
		$this->assertSame( array( 'hero.jpg.avif' ), $this->directory() );
	}
}
