<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

/**
 * Covers the backend-independent structural animation sniff
 * (Resizer::sniffAnimated + gifIsAnimated). Crafted minimal-but-valid container
 * fixtures are written to temp files and fed through the private method via
 * reflection.
 */
class SniffAnimatedTest extends ResizerTestCase {

	/** @var string[] */
	private array $tmp_files = [];

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tmp_files = [];
		parent::tearDown();
	}

	private function writeTmp( string $bytes ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'tk_anim_' );
		file_put_contents( $path, $bytes );
		$this->tmp_files[] = $path;
		return $path;
	}

	private function sniff( string $bytes ): bool {
		$resizer = $this->createResizer();
		return (bool) $this->callPrivate( $resizer, 'sniffAnimated', [ $this->writeTmp( $bytes ) ] );
	}

	// ---- GIF -------------------------------------------------------------

	/** GIF89a header, no Global Color Table (packed = 0x00). */
	private function gifHeader(): string {
		return 'GIF89a' . "\x01\x00\x01\x00" . "\x00" . "\x00" . "\x00";
	}

	/** One Image Descriptor + empty image data + sub-block terminator. */
	private function gifFrame(): string {
		return "\x2C" . "\x00\x00\x00\x00\x01\x00\x01\x00" . "\x00" . "\x02" . "\x00";
	}

	public function test_animated_gif_two_frames_is_detected(): void {
		$bytes = $this->gifHeader() . $this->gifFrame() . $this->gifFrame() . "\x3B";
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_static_gif_single_frame_is_not_detected(): void {
		$bytes = $this->gifHeader() . $this->gifFrame() . "\x3B";
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_animated_gif_without_gce_is_detected(): void {
		// Two frames, no Graphic Control Extension and no NETSCAPE loop block —
		// the old heuristic would miss this; the descriptor walk catches it.
		$bytes = $this->gifHeader() . $this->gifFrame() . $this->gifFrame() . $this->gifFrame() . "\x3B";
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	// ---- WebP ------------------------------------------------------------

	public function test_animated_webp_vp8x_anim_flag_is_detected(): void {
		// VP8X chunk with the animation flag (0x02) set at the flags byte.
		$bytes = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8X' . "\x0A\x00\x00\x00"
			. "\x02" . str_repeat( "\x00", 9 );
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_static_webp_vp8x_without_anim_flag_is_not_detected(): void {
		$bytes = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8X' . "\x0A\x00\x00\x00"
			. "\x00" . str_repeat( "\x00", 9 );
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_static_webp_simple_lossy_is_not_detected(): void {
		// No VP8X chunk at all (plain lossy WebP) → single image.
		$bytes = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8 ' . str_repeat( "\x00", 16 );
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_static_webp_with_anim_bytes_in_payload_is_not_detected(): void {
		// A static (VP8L) WebP whose payload literally contains "ANIM" must NOT
		// be misread as animated — the old substring scan failed this.
		$bytes = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8L' . "\x10\x00\x00\x00"
			. 'ANIM' . 'ANMF' . str_repeat( "\x00", 8 );
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	// ---- AVIF ------------------------------------------------------------

	public function test_animated_avif_avis_brand_is_detected(): void {
		// ftyp box (size 0x14) with 'avis' major brand.
		$bytes = "\x00\x00\x00\x14" . 'ftyp' . 'avis' . "\x00\x00\x00\x00" . 'avif';
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_static_avif_is_not_detected(): void {
		$bytes = "\x00\x00\x00\x14" . 'ftyp' . 'avif' . "\x00\x00\x00\x00" . 'mif1';
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_avif_with_avis_outside_ftyp_box_is_not_detected(): void {
		// 'avis' appears only AFTER the ftyp box (size 0x10 ends at byte 16);
		// confining the brand search to the box prevents a false positive.
		$bytes = "\x00\x00\x00\x10" . 'ftyp' . 'avif' . "\x00\x00\x00\x00"
			. 'mdat' . 'avis' . str_repeat( "\x00", 8 );
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	// ---- Misc ------------------------------------------------------------

	public function test_non_image_bytes_are_not_detected(): void {
		$this->assertFalse( $this->sniff( 'not an image at all' ) );
	}

	public function test_empty_file_is_not_detected(): void {
		$this->assertFalse( $this->sniff( '' ) );
	}
}
