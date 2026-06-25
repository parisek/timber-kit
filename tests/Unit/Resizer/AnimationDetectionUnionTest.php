<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * The union in isAnimated(): a source is animated when Imagick decodes more than
 * one frame OR the structural sniff detects animation. The crucial case is a
 * source the backend UNDER-DECODES — Imagick reports one frame — that is in fact
 * animated. Trusting the frame count alone would treat it as static and flatten
 * it on resize; the structural sniff must override that.
 *
 * Real-world: animated AVIF image-sequences (`avis` brand, dozens of frames) on a
 * libheif too old to read sequence tracks decode to a single frame through
 * Imagick — verified on the wearemullet dev (libheif 1.19.8) and production
 * (ImageMagick 7.1.2-8) boxes. Simulated here by overriding `imagickFrameCount()`
 * to 1 while feeding a structurally-animated container.
 */
class AnimationDetectionUnionTest extends ResizerTestCase {

	/** @var string[] */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ( $this->tmp as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tmp = [];
		parent::tearDown();
	}

	/** Minimal two-frame GIF89a (two Image Descriptors) — sniff sees animation. */
	private function animatedGifBytes(): string {
		$header = 'GIF89a' . "\x01\x00\x01\x00" . "\x00" . "\x00" . "\x00";
		$frame = "\x2C" . "\x00\x00\x00\x00\x01\x00\x01\x00" . "\x00" . "\x02" . "\x00";
		return $header . $frame . $frame . "\x3B";
	}

	/** Single-frame GIF89a (one Image Descriptor) — sniff sees no animation. */
	private function staticGifBytes(): string {
		$header = 'GIF89a' . "\x01\x00\x01\x00" . "\x00" . "\x00" . "\x00";
		$frame = "\x2C" . "\x00\x00\x00\x00\x01\x00\x01\x00" . "\x00" . "\x02" . "\x00";
		return $header . $frame . "\x3B";
	}

	/** Resizer whose Imagick probe always reports a single frame (under-decode). */
	private function underDecodingResizer(): Resizer {
		Functions\when( 'apply_filters' )->alias( static fn( $filter, $default, ...$args ) => $default );
		return new class extends Resizer {
			protected function imagickFrameCount( string $source_path ): ?int {
				return 1;
			}
		};
	}

	private function tmpFile( string $bytes, string $tag ): string {
		$path = sys_get_temp_dir() . '/tk_' . $tag . '_' . uniqid() . '.gif';
		file_put_contents( $path, $bytes );
		$this->tmp[] = $path;
		return $path;
	}

	public function test_under_decoded_animated_source_detected_via_sniff(): void {
		$path = $this->tmpFile( $this->animatedGifBytes(), 'union' );
		$result = $this->callPrivate( $this->underDecodingResizer(), 'isAnimated', [ $path ] );
		$this->assertTrue( $result, 'sniff must detect animation when Imagick under-decodes to one frame' );
	}

	public function test_genuinely_static_single_frame_not_animated(): void {
		$path = $this->tmpFile( $this->staticGifBytes(), 'static' );
		$result = $this->callPrivate( $this->underDecodingResizer(), 'isAnimated', [ $path ] );
		$this->assertFalse( $result, 'one decoded frame and no structural animation marker → static' );
	}

	public function test_imagick_multiframe_count_alone_marks_animated(): void {
		Functions\when( 'apply_filters' )->alias( static fn( $filter, $default, ...$args ) => $default );
		$resizer = new class extends Resizer {
			protected function imagickFrameCount( string $source_path ): ?int {
				return 5;
			}
		};
		// Frame count > 1 short-circuits to animated without touching the sniff,
		// so the path need not even exist.
		$result = $this->callPrivate( $resizer, 'isAnimated', [ '/nonexistent.avif' ] );
		$this->assertTrue( $result );
	}
}
