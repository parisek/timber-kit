<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class CleanUploadedFilenameTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_removes_diacritics(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( function ( $str ) {
			return transliterator_transliterate( 'Any-Latin; Latin-ASCII', $str );
		} );
		$this->assertSame( 'cestacky.jpg', $this->base->clean_uploaded_filename( 'čěšťáčky.jpg' ) );
	}

	public function test_lowercases_filename(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'myphoto.jpg', $this->base->clean_uploaded_filename( 'MyPhoto.jpg' ) );
	}

	public function test_replaces_spaces_with_hyphens(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'my-photo.jpg', $this->base->clean_uploaded_filename( 'my photo.jpg' ) );
	}

	public function test_replaces_underscores_with_hyphens(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'my-photo.jpg', $this->base->clean_uploaded_filename( 'my_photo.jpg' ) );
	}

	public function test_replaces_mixed_spaces_and_underscores(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'my-photo-2.jpg', $this->base->clean_uploaded_filename( 'my photo_2.jpg' ) );
	}

	public function test_removes_special_characters(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'file.jpg', $this->base->clean_uploaded_filename( 'file@#$.jpg' ) );
	}

	public function test_collapses_multiple_hyphens(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'a-b.jpg', $this->base->clean_uploaded_filename( 'a---b.jpg' ) );
	}

	public function test_trims_hyphens_from_edges(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'photo.jpg', $this->base->clean_uploaded_filename( '-photo-.jpg' ) );
	}

	public function test_preserves_jpg_extension(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$result = $this->base->clean_uploaded_filename( 'test.jpg' );
		$this->assertStringEndsWith( '.jpg', $result );
	}

	public function test_preserves_png_extension(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$result = $this->base->clean_uploaded_filename( 'test.png' );
		$this->assertStringEndsWith( '.png', $result );
	}

	public function test_preserves_webp_extension(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$result = $this->base->clean_uploaded_filename( 'test.webp' );
		$this->assertStringEndsWith( '.webp', $result );
	}

	public function test_handles_file_without_extension(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'readme', $this->base->clean_uploaded_filename( 'README' ) );
	}

	public function test_handles_complex_real_world_filename(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'img-20240315-wa0042.jpg', $this->base->clean_uploaded_filename( 'IMG_20240315_WA0042.jpg' ) );
	}

	public function test_handles_dots_in_filename(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( fn( $s ) => $s );
		$this->assertSame( 'myfilev2.jpg', $this->base->clean_uploaded_filename( 'my.file.v2.jpg' ) );
	}

	public function test_handles_unicode_characters(): void {
		\Brain\Monkey\Functions\when( 'remove_accents' )->alias( function ( $str ) {
			return preg_replace( '/[^\x20-\x7E]/', '', $str );
		} );
		$result = $this->base->clean_uploaded_filename( '写真.jpg' );
		$this->assertSame( '.jpg', $result );
	}
}
