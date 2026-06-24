<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

/**
 * Covers the backend-independent byte-signature animation sniff
 * (Resizer::sniffAnimated). Crafted minimal fixtures are written to temp files
 * and fed through the private method via reflection.
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

	public function test_animated_gif_netscape_loop_is_detected(): void {
		// GIF89a header + NETSCAPE2.0 application extension (the loop marker).
		$bytes = "GIF89a" . str_repeat( "\x00", 7 ) . "\x21\xFF\x0BNETSCAPE2.0" . str_repeat( "\x00", 8 );
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_animated_gif_multiple_gce_is_detected(): void {
		// Two Graphic Control Extension blocks (0x21 0xF9) → multi-frame.
		$gce = "\x21\xF9\x04\x00\x00\x00\x00\x00";
		$bytes = "GIF89a" . str_repeat( "\x00", 7 ) . $gce . "\x2C" . $gce . "\x2C";
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_static_gif_is_not_detected(): void {
		// Single GCE, no loop extension.
		$bytes = "GIF89a" . str_repeat( "\x00", 7 ) . "\x21\xF9\x04\x00\x00\x00\x00\x00" . "\x2C" . str_repeat( "\x00", 9 );
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_animated_webp_anim_chunk_is_detected(): void {
		$bytes = "RIFF" . "\x00\x00\x00\x00" . "WEBP" . "VP8X" . "\x0A\x00\x00\x00" . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . "ANIM" . "\x06\x00\x00\x00";
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_static_webp_is_not_detected(): void {
		$bytes = "RIFF" . "\x00\x00\x00\x00" . "WEBP" . "VP8 " . str_repeat( "\x00", 16 );
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_animated_avif_avis_brand_is_detected(): void {
		// ISOBMFF ftyp box with the 'avis' image-sequence major brand.
		$bytes = "\x00\x00\x00\x20" . "ftyp" . "avis" . "\x00\x00\x00\x00" . "avifmif1miafmsf1";
		$this->assertTrue( $this->sniff( $bytes ) );
	}

	public function test_static_avif_is_not_detected(): void {
		// ftyp box with the still-image 'avif' major brand, no sequence markers.
		$bytes = "\x00\x00\x00\x1C" . "ftyp" . "avif" . "\x00\x00\x00\x00" . "avifmif1miaf";
		$this->assertFalse( $this->sniff( $bytes ) );
	}

	public function test_non_image_bytes_are_not_detected(): void {
		$this->assertFalse( $this->sniff( "not an image at all" ) );
	}

	public function test_empty_file_is_not_detected(): void {
		$this->assertFalse( $this->sniff( '' ) );
	}
}
