<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Guards against silently flattening an animated source the active backend
 * under-decodes.
 *
 * Real-world case (wearemullet): an animated AVIF image-sequence (`avis` brand,
 * 42 frames) on a box whose libheif is too old to read sequence tracks. The
 * structural sniff correctly detects animation, but Imagick decodes only the
 * primary frame, so `getNumberImages()` reports 1. Trusting that count would
 * route the source through the single-frame pipeline and flatten it — the exact
 * regression #60/#61 exist to prevent. The resizer must detect animation from
 * EITHER signal and, when the backend cannot decode the frames, pass the
 * original through rather than emit a flattened still.
 *
 * Decisions are driven through two overridable seams — `imagickFrameCount()`
 * (how many frames the backend decodes from this source) and `sniffAnimated()`
 * (structural animation detection) — so the scenario is deterministic without a
 * specific libheif build.
 */
class ResizerUnderdecodeGuardTest extends ResizerTestCase {

	/**
	 * @param array{frames:?int,sniff:bool,capable:bool,skip:bool} $opts
	 */
	private function guardResizer( array $opts, array &$calls ): Resizer {
		$calls = [ 'animated' => 0, 'static' => 0 ];
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default ) use ( $opts ) {
				return 'timber_kit_resizer_skip_animated' === $filter ? $opts['skip'] : $default;
			}
		);
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/avif', 'ext' => 'avif' ] );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => dirname( __FILE__ ), 'baseurl' => 'http://x/up' ] );

		return new class( $opts, $calls ) extends Resizer {
			private array $opts;
			public array $callsRef;
			public function __construct( array $opts, array &$calls ) {
				$this->opts = $opts;
				$this->callsRef = &$calls;
				parent::__construct();
			}
			public function canDecode( string $mime ): bool {
				return true;
			}
			protected function isAnimatableType( string $mime ): bool {
				return true;
			}
			protected function imagickFrameCount( string $source_path ): ?int {
				return $this->opts['frames'];
			}
			protected function sniffAnimated( string $source_path ): bool {
				return $this->opts['sniff'];
			}
			protected function canEncodeAnimated( string $format ): bool {
				return $this->opts['capable'];
			}
			protected function processAnimatedVariant( array $v, string $s, string $f, array $d ): ?array {
				$this->callsRef['animated']++;
				return [ 'src' => 'animated', 'type' => 'image/avif', 'width' => $v['width'], 'height' => $v['height'], 'media' => '', 'alt' => '', 'caption' => '', 'description' => '' ];
			}
			protected function processVariant( array $v, string $s, string $f, array $d ): ?array {
				$this->callsRef['static']++;
				return [ 'src' => 'static', 'type' => 'image/avif', 'width' => $v['width'], 'height' => $v['height'], 'media' => '', 'alt' => '', 'caption' => '', 'description' => '' ];
			}
		};
	}

	private function invoke( array $opts, array &$calls ): array {
		$resizer = $this->guardResizer( $opts, $calls );
		$image = [ 'src' => 'http://x/up/' . basename( __FILE__ ), 'width' => 8, 'height' => 6, 'alt' => '' ];
		return $resizer->resizer( $image, [ [ 100, 100, 0, 'crop' ] ] );
	}

	public function test_animated_by_sniff_but_backend_underdecodes_passes_through(): void {
		$calls = [];
		// Structurally animated (sniff=true) but the backend decodes one frame
		// (frames=1). Even though it can WRITE animated output (capable=true) and
		// we opted in (skip=false), resizing would flatten — so pass through.
		$out = $this->invoke( [ 'frames' => 1, 'sniff' => true, 'capable' => true, 'skip' => false ], $calls );
		$this->assertSame( 0, $calls['animated'], 'must not run the multi-frame path on an under-decoded source' );
		$this->assertSame( 0, $calls['static'], 'must not flatten via the static path' );
		$this->assertCount( 1, $out ); // original only (passthrough)
	}

	public function test_animated_and_decoded_multiframe_still_resizes(): void {
		$calls = [];
		// Backend decodes multiple frames (frames=2) and can write animated output
		// → the multi-frame path runs.
		$out = $this->invoke( [ 'frames' => 2, 'sniff' => true, 'capable' => true, 'skip' => false ], $calls );
		$this->assertSame( 1, $calls['animated'] );
		$this->assertSame( 0, $calls['static'] );
		$this->assertSame( 'animated', $out[0]['src'] );
	}

	public function test_static_source_unaffected(): void {
		$calls = [];
		// One frame, not structurally animated → ordinary static resize.
		$out = $this->invoke( [ 'frames' => 1, 'sniff' => false, 'capable' => true, 'skip' => false ], $calls );
		$this->assertSame( 0, $calls['animated'] );
		$this->assertSame( 1, $calls['static'] );
		$this->assertSame( 'static', $out[0]['src'] );
	}
}
