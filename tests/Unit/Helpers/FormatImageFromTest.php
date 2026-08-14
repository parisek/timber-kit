<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Example tests for the pure-core formatter extracted from
 * Helpers::formatImage()'s associative-array branch. Locks the documented
 * contract independently of property-based testing.
 */
class FormatImageFromTest extends HelpersTestCase {

	public function test_null_input_returns_null(): void {
		$this->assertNull( Helpers::formatImageFrom( null ) );
	}

	public function test_empty_array_returns_null(): void {
		$this->assertNull( Helpers::formatImageFrom( [] ) );
	}

	public function test_well_formed_acf_array_maps_to_documented_shape(): void {
		$raw = [
			'ID'          => 42,
			'url'         => 'https://example.com/image.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => 800,
			'height'      => 600,
			'alt'         => 'Test image',
			'caption'     => 'A caption',
			'description' => 'A description',
		];

		$this->assertSame(
			[
				'id'          => 42,
				'src'         => 'https://example.com/image.jpg',
				'type'        => 'image/jpeg',
				'width'       => 800,
				'height'      => 600,
				'alt'         => 'Test image',
				'caption'     => 'A caption',
				'description' => 'A description',
			],
			Helpers::formatImageFrom( $raw )
		);
	}

	public function test_svg_1px_dimensions_are_coerced_to_null(): void {
		$raw = [
			'ID'          => 1,
			'url'         => 'https://example.com/icon.svg',
			'mime_type'   => 'image/svg+xml',
			'width'       => 1,
			'height'      => 1,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		// The read path now tries to resolve an SVG's real size from its file.
		// Point it at nothing, so this keeps testing the 1px guard rather than
		// the resolver: with no file to read, both axes must still come back null.
		Functions\when( 'get_attached_file' )->justReturn( '/nonexistent/icon.svg' );

		$result = Helpers::formatImageFrom( $raw );
		$this->assertNull( $result['width'] );
		$this->assertNull( $result['height'] );
	}

	public function test_missing_keys_default_to_null_without_warning(): void {
		$prevLevel = error_reporting( E_ALL );
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			throw new \RuntimeException( "Unexpected PHP error: $errstr" );
		} );
		try {
			$result = Helpers::formatImageFrom( [ 'ID' => 7 ] );
		} finally {
			restore_error_handler();
			error_reporting( $prevLevel );
		}

		$this->assertSame( 7,    $result['id'] );
		$this->assertNull( $result['src'] );
		$this->assertNull( $result['type'] );
		$this->assertNull( $result['alt'] );
	}

	public function test_numeric_string_dimensions_and_id_are_cast_to_int(): void {
		// ACF sometimes hands back numeric strings instead of ints
		// (custom return-format normalisers, legacy meta, etc.).
		// The contract is int|null, so the formatter must cast.
		$raw = [
			'ID'          => '42',
			'url'         => 'https://example.com/image.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => '800',
			'height'      => '600',
			'alt'         => 'x',
			'caption'     => 'x',
			'description' => 'x',
		];

		$result = Helpers::formatImageFrom( $raw );

		$this->assertSame( 42,  $result['id'] );
		$this->assertSame( 800, $result['width'] );
		$this->assertSame( 600, $result['height'] );
	}

	public function test_non_numeric_dimensions_do_not_emit_warnings(): void {
		// `'foo' > 1` in earlier PHPs emitted "non-well-formed numeric value"
		// notices. The isset + is_numeric guard avoids that branch entirely.
		$prevLevel = error_reporting( E_ALL );
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			throw new \RuntimeException( "Unexpected PHP error: $errstr" );
		} );
		try {
			$result = Helpers::formatImageFrom( [
				'ID'     => 'not-numeric',
				'width'  => 'abc',
				'height' => 'def',
			] );
		} finally {
			restore_error_handler();
			error_reporting( $prevLevel );
		}

		$this->assertNull( $result['id'] );
		$this->assertNull( $result['width'] );
		$this->assertNull( $result['height'] );
	}
}
