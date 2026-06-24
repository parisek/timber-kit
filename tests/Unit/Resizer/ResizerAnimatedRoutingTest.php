<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Covers resizer()'s animated routing: capable backend → animated path,
 * incapable backend → passthrough, skip_animated → passthrough, static → normal.
 * Imagick is never touched — the decision inputs (imagickFrameCount,
 * sniffAnimated, canEncodeAnimated) and both processors are overridden on a
 * Resizer subclass.
 */
class ResizerAnimatedRoutingTest extends ResizerTestCase {

	/**
	 * @param array{animated:bool,capable:bool,skip:bool} $opts
	 */
	private function routingResizer( array $opts, array &$calls ): Resizer {
		$calls = [ 'animated' => 0, 'static' => 0 ];
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default ) use ( $opts ) {
				return 'timber_kit_resizer_skip_animated' === $filter ? $opts['skip'] : $default;
			}
		);
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/gif', 'ext' => 'gif' ] );

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
				// Animated sources here decode cleanly to multiple frames; static
				// sources to one. The under-decode case (animated structure, one
				// decoded frame) is covered by ResizerUnderdecodeGuardTest.
				return $this->opts['animated'] ? 2 : 1;
			}
			protected function sniffAnimated( string $source_path ): bool {
				return false;
			}
			protected function canEncodeAnimated( string $format ): bool {
				return $this->opts['capable'];
			}
			protected function processAnimatedVariant( array $v, string $s, string $f, array $d ): ?array {
				$this->callsRef['animated']++;
				return [ 'src' => 'animated', 'type' => 'image/gif', 'width' => $v['width'], 'height' => $v['height'], 'media' => '', 'alt' => '', 'caption' => '', 'description' => '' ];
			}
			protected function processVariant( array $v, string $s, string $f, array $d ): ?array {
				$this->callsRef['static']++;
				return [ 'src' => 'static', 'type' => 'image/avif', 'width' => $v['width'], 'height' => $v['height'], 'media' => '', 'alt' => '', 'caption' => '', 'description' => '' ];
			}
		};
	}

	private function invoke( array $opts, array &$calls ): array {
		$resizer = $this->routingResizer( $opts, $calls );
		// file_exists() must be true for the real source path; point src at this file.
		$image = [ 'src' => 'http://x/up/' . basename( __FILE__ ), 'width' => 8, 'height' => 6, 'alt' => '' ];
		// Map the URL back onto this test file so file_exists() passes.
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => dirname( __FILE__ ), 'baseurl' => 'http://x/up' ] );
		return $resizer->resizer( $image, [ [ 100, 100, 0, 'center' ] ] );
	}

	public function test_animated_capable_routes_to_animated_path(): void {
		$calls = [];
		$out = $this->invoke( [ 'animated' => true, 'capable' => true, 'skip' => false ], $calls );
		$this->assertSame( 1, $calls['animated'] );
		$this->assertSame( 0, $calls['static'] );
		$this->assertSame( 'animated', $out[0]['src'] );
	}

	public function test_animated_incapable_passes_through(): void {
		$calls = [];
		$out = $this->invoke( [ 'animated' => true, 'capable' => false, 'skip' => false ], $calls );
		$this->assertSame( 0, $calls['animated'] );
		$this->assertSame( 0, $calls['static'] );
		$this->assertCount( 1, $out ); // original only
	}

	public function test_skip_animated_passes_through_even_when_capable(): void {
		$calls = [];
		$out = $this->invoke( [ 'animated' => true, 'capable' => true, 'skip' => true ], $calls );
		$this->assertSame( 0, $calls['animated'] );
		$this->assertSame( 0, $calls['static'] );
		$this->assertCount( 1, $out );
	}

	public function test_static_source_uses_normal_path(): void {
		$calls = [];
		$out = $this->invoke( [ 'animated' => false, 'capable' => true, 'skip' => false ], $calls );
		$this->assertSame( 0, $calls['animated'] );
		$this->assertSame( 1, $calls['static'] );
		$this->assertSame( 'static', $out[0]['src'] );
	}
}
