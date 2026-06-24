<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Covers the animated-write capability probe: canEncodeAnimated() memoisation and
 * its delegation to the protected probeAnimatedEncode() seam. The live round-trip
 * self-test is exercised by a separate, backend-gated smoke test.
 */
class CanEncodeAnimatedTest extends ResizerTestCase {

	/**
	 * Resizer subclass that counts probe calls and returns a forced verdict,
	 * so memoisation and routing are testable without a live libheif build.
	 */
	private function probeStub( bool $verdict, ?int &$calls ): Resizer {
		$calls = 0;
		return new class( $verdict, $calls ) extends Resizer {
			private bool $verdict;
			private int $calls = 0;
			public function __construct( bool $verdict, int &$callsRef ) {
				$this->verdict = $verdict;
				$this->callsRef = &$callsRef;
				parent::__construct();
			}
			/** @var int reference to the test's counter */
			private $callsRef;
			protected function probeAnimatedEncode( string $format ): bool {
				$this->callsRef++;
				return $this->verdict;
			}
		};
	}

	protected function setUp(): void {
		parent::setUp();
		// Reset the static memo between tests (it persists per process otherwise).
		$ref = new \ReflectionProperty( Resizer::class, 'animated_encode_cache' );
		$ref->setValue( null, [] );
	}

	public function test_true_verdict_is_returned(): void {
		$resizer = $this->probeStub( true, $calls );
		$this->assertTrue( $this->callPrivate( $resizer, 'canEncodeAnimated', [ 'avif' ] ) );
	}

	public function test_false_verdict_is_returned(): void {
		$resizer = $this->probeStub( false, $calls );
		$this->assertFalse( $this->callPrivate( $resizer, 'canEncodeAnimated', [ 'avif' ] ) );
	}

	public function test_probe_runs_once_per_format(): void {
		$resizer = $this->probeStub( true, $calls );
		$this->callPrivate( $resizer, 'canEncodeAnimated', [ 'avif' ] );
		$this->callPrivate( $resizer, 'canEncodeAnimated', [ 'avif' ] );
		$this->callPrivate( $resizer, 'canEncodeAnimated', [ 'AVIF' ] ); // case-insensitive key
		$this->assertSame( 1, $calls );
	}

	public function test_distinct_formats_probe_independently(): void {
		$resizer = $this->probeStub( true, $calls );
		$this->callPrivate( $resizer, 'canEncodeAnimated', [ 'avif' ] );
		$this->callPrivate( $resizer, 'canEncodeAnimated', [ 'webp' ] );
		$this->assertSame( 2, $calls );
	}

	public function test_live_round_trip_smoke(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'imagick not available' );
		}
		// GIF multi-frame write is the universal Imagick baseline; assert the real
		// probe agrees. (AVIF/WebP depend on delegates and are intentionally not
		// asserted here.)
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'probeAnimatedEncode', [ 'gif' ] );
		$this->assertTrue( $result );
	}
}
