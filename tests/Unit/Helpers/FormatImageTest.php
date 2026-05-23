<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FormatImageTest extends HelpersTestCase {

	public function test_array_input(): void {
		$image = [
			'ID'          => 42,
			'url'         => 'https://example.com/image.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => 800,
			'height'      => 600,
			'alt'         => 'Test image',
			'caption'     => 'A caption',
			'description' => 'A description',
		];

		$result = Helpers::formatImage( $image );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $result[0]['src'] );
		$this->assertSame( 'image/jpeg', $result[0]['type'] );
		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( 'Test image', $result[0]['alt'] );
		$this->assertSame( 'A caption', $result[0]['caption'] );
		$this->assertSame( 'A description', $result[0]['description'] );
	}

	public function test_object_input(): void {
		$image = (object) [
			'ID'             => 42,
			'src'            => 'https://example.com/image.jpg',
			'post_mime_type' => 'image/jpeg',
			'width'          => 800,
			'height'         => 600,
			'alt'            => 'Test image',
			'caption'        => 'A caption',
			'description'    => 'A description',
		];

		$result = Helpers::formatImage( $image );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $result[0]['src'] );
		$this->assertSame( 'image/jpeg', $result[0]['type'] );
	}

	public function test_svg_1px_width_fix(): void {
		$image = [
			'ID'          => 1,
			'url'         => 'https://example.com/icon.svg',
			'mime_type'   => 'image/svg+xml',
			'width'       => 1,
			'height'      => 1,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatImage( $image );

		$this->assertNull( $result[0]['width'] );
		$this->assertNull( $result[0]['height'] );
	}

	public function test_svg_1px_width_fix_object(): void {
		$image = (object) [
			'ID'             => 1,
			'src'            => 'https://example.com/icon.svg',
			'post_mime_type' => 'image/svg+xml',
			'width'          => 1,
			'height'         => 1,
			'alt'            => '',
			'caption'        => '',
			'description'    => '',
		];

		$result = Helpers::formatImage( $image );

		$this->assertNull( $result[0]['width'] );
		$this->assertNull( $result[0]['height'] );
	}

	public function test_normal_dimensions_preserved(): void {
		$image = [
			'ID'          => 1,
			'url'         => 'https://example.com/photo.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => 1920,
			'height'      => 1080,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatImage( $image );

		$this->assertSame( 1920, $result[0]['width'] );
		$this->assertSame( 1080, $result[0]['height'] );
	}

	public function test_multi_value_gallery(): void {
		$images = [
			[
				'ID'          => 1,
				'url'         => 'https://example.com/a.jpg',
				'mime_type'   => 'image/jpeg',
				'width'       => 100,
				'height'      => 100,
				'alt'         => 'A',
				'caption'     => '',
				'description' => '',
			],
			[
				'ID'          => 2,
				'url'         => 'https://example.com/b.jpg',
				'mime_type'   => 'image/jpeg',
				'width'       => 200,
				'height'      => 200,
				'alt'         => 'B',
				'caption'     => '',
				'description' => '',
			],
		];

		$result = Helpers::formatImage( $images );

		$this->assertCount( 2, $result );
		// each item is unwrapped from the nested array returned by formatImage
		$this->assertSame( 1, $result[0][0]['id'] );
		$this->assertSame( 2, $result[1][0]['id'] );
	}

	public function test_empty_array_returns_empty(): void {
		$result = Helpers::formatImage( [] );
		$this->assertSame( [], $result );
	}

	public function test_false_input_returns_empty(): void {
		$result = Helpers::formatImage( false );
		$this->assertSame( [], $result );
	}

	public function test_null_input_returns_empty(): void {
		$result = Helpers::formatImage( null );
		$this->assertSame( [], $result );
	}

	// --- Iteration 2: WP-dependent paths ---

	public function test_numeric_id_input(): void {
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			if ( $id === 42 ) {
				return [
					'ID'          => 42,
					'url'         => 'https://example.com/image.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 800,
					'height'      => 600,
					'alt'         => 'Alt text',
					'caption'     => 'Caption',
					'description' => 'Desc',
				];
			}
			return false;
		} );

		$result = Helpers::formatImage( 42 );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $result[0]['src'] );
	}

	public function test_numeric_id_not_found_returns_empty(): void {
		Functions\when( 'acf_get_attachment' )->justReturn( false );

		$result = Helpers::formatImage( 999 );

		$this->assertSame( [], $result );
	}

	public function test_url_input(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 42 );
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			if ( $id === 42 ) {
				return [
					'ID'          => 42,
					'url'         => 'https://example.com/image.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 800,
					'height'      => 600,
					'alt'         => 'Alt',
					'caption'     => '',
					'description' => '',
				];
			}
			return false;
		} );

		$result = Helpers::formatImage( 'https://example.com/image.jpg' );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
	}

	public function test_url_input_not_found_returns_empty(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
		Functions\when( 'acf_get_attachment' )->justReturn( false );

		$result = Helpers::formatImage( 'https://example.com/nonexistent.jpg' );

		$this->assertSame( [], $result );
	}

	// ------------------------------------------------------------------
	// SVG 1px guard consistency across non-array branches (1.6.0)
	//
	// [Unreleased] CHANGELOG: "The WordPress SVG-1px width/height workaround
	// is now applied consistently across all three branches (previously only
	// the array branch had the guard)." These tests lock the guarantee for
	// numeric-ID and URL branches — the array branch is covered above by
	// test_svg_1px_width_fix() and via FormatImageFromTest directly.
	// ------------------------------------------------------------------

	public function test_numeric_id_branch_applies_svg_1px_guard(): void {
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			return [
				'ID'          => $id,
				'url'         => 'https://example.com/icon.svg',
				'mime_type'   => 'image/svg+xml',
				'width'       => 1,
				'height'      => 1,
				'alt'         => '',
				'caption'     => '',
				'description' => '',
			];
		} );

		$result = Helpers::formatImage( 7 );

		$this->assertCount( 1, $result );
		$this->assertNull( $result[0]['width'] );
		$this->assertNull( $result[0]['height'] );
	}

	public function test_url_branch_applies_svg_1px_guard(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 7 );
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			return [
				'ID'          => $id,
				'url'         => 'https://example.com/icon.svg',
				'mime_type'   => 'image/svg+xml',
				'width'       => 1,
				'height'      => 1,
				'alt'         => '',
				'caption'     => '',
				'description' => '',
			];
		} );

		$result = Helpers::formatImage( 'https://example.com/icon.svg' );

		$this->assertCount( 1, $result );
		$this->assertNull( $result[0]['width'] );
		$this->assertNull( $result[0]['height'] );
	}

	// ------------------------------------------------------------------
	// No-notice contract for non-array branches (1.6.0)
	//
	// [Unreleased] CHANGELOG: "missing keys on the associative-array,
	// numeric-ID, and URL-string input branches now resolve to null silently
	// instead of emitting Undefined index notices." Partial ACF payloads
	// (alt/caption/description absent) must not raise PHP warnings.
	// ------------------------------------------------------------------

	public function test_numeric_id_branch_no_notice_on_partial_acf_payload(): void {
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			// Minimal payload — ID + url + mime + width/height only; ACF
			// occasionally hands back attachments shaped like this when
			// metadata is missing in the DB.
			return [
				'ID'        => $id,
				'url'       => 'https://example.com/sparse.jpg',
				'mime_type' => 'image/jpeg',
				'width'     => 800,
				'height'    => 600,
				// alt / caption / description deliberately absent
			];
		} );

		// Treat ANY notice/warning during formatImage() as a test failure —
		// the contract is "silent null", not "null with a notice". Limit the
		// promotion to the levels named in the contract: deprecations and
		// other levels (E_USER_*, E_STRICT etc.) bubble to PHPUnit normally,
		// so e.g. a PHP 8.4 deprecation inside a downstream library doesn't
		// turn this test red for an unrelated reason.
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			throw new \ErrorException( $errstr, 0, $errno );
		}, E_NOTICE | E_WARNING );

		try {
			$result = Helpers::formatImage( 42 );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $result );
		$this->assertNull( $result[0]['alt'] );
		$this->assertNull( $result[0]['caption'] );
		$this->assertNull( $result[0]['description'] );
	}

	public function test_url_branch_no_notice_on_partial_acf_payload(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 42 );
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			return [
				'ID'        => $id,
				'url'       => 'https://example.com/sparse.jpg',
				'mime_type' => 'image/jpeg',
				'width'     => 800,
				'height'    => 600,
				// alt / caption / description deliberately absent
			];
		} );

		set_error_handler( static function ( int $errno, string $errstr ): bool {
			throw new \ErrorException( $errstr, 0, $errno );
		} );

		try {
			$result = Helpers::formatImage( 'https://example.com/sparse.jpg' );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $result );
		$this->assertNull( $result[0]['alt'] );
		$this->assertNull( $result[0]['caption'] );
		$this->assertNull( $result[0]['description'] );
	}

	// ------------------------------------------------------------------
	// int cast from ACF string-ish values (1.6.0)
	//
	// [Unreleased] CHANGELOG: "formatImageFrom() (and therefore those three
	// formatImage() branches) now explicitly casts id / width / height to
	// int|null to match its documented return type — ACF sometimes hands
	// numeric strings". Lock the cast on the non-array branches; the array
	// branch is covered by FormatImageFromTest.
	// ------------------------------------------------------------------

	public function test_numeric_id_branch_casts_string_dimensions_to_int(): void {
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			// ACF in the wild has been observed handing back numeric strings
			// for ID / width / height when payload comes from certain meta
			// codepaths. Documented contract is int|null, not string|null.
			return [
				'ID'        => (string) $id, // '42'
				'url'       => 'https://example.com/photo.jpg',
				'mime_type' => 'image/jpeg',
				'width'     => '1920',
				'height'    => '1080',
				'alt'       => '',
				'caption'   => '',
				'description' => '',
			];
		} );

		$result = Helpers::formatImage( 42 );

		// Strict identity (===) — `42` not `'42'`. assertSame catches the
		// type-leak the cast was added to prevent.
		$this->assertSame( 42,   $result[0]['id'] );
		$this->assertSame( 1920, $result[0]['width'] );
		$this->assertSame( 1080, $result[0]['height'] );
	}

	public function test_url_branch_casts_string_dimensions_to_int(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 42 );
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			return [
				'ID'          => (string) $id,
				'url'         => 'https://example.com/photo.jpg',
				'mime_type'   => 'image/jpeg',
				'width'       => '800',
				'height'      => '600',
				'alt'         => '',
				'caption'     => '',
				'description' => '',
			];
		} );

		$result = Helpers::formatImage( 'https://example.com/photo.jpg' );

		$this->assertSame( 42,  $result[0]['id'] );
		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
	}
}
