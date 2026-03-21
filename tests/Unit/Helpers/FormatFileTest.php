<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FormatFileTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'size_format' )->alias( function ( $bytes ) {
			return round( $bytes / 1024 ) . ' KB';
		} );
		// PDF preview path calls wp_get_attachment_image_src — default to false
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
	}

	public function test_array_input(): void {
		$file = [
			'ID'          => 10,
			'url'         => 'https://example.com/doc.pdf',
			'mime_type'   => 'application/pdf',
			'subtype'     => 'pdf',
			'filename'    => 'doc.pdf',
			'filesize'    => 102400,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatFile( $file );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
		$this->assertSame( 'https://example.com/doc.pdf', $result['src'] );
		$this->assertSame( 'application/pdf', $result['type'] );
		$this->assertSame( 'pdf', $result['subtype'] );
		$this->assertSame( 'doc.pdf', $result['filename'] );
		$this->assertSame( '100 KB', $result['filesize'] );
	}

	public function test_object_input(): void {
		$file = (object) [
			'ID'             => 10,
			'src'            => 'https://example.com/doc.pdf',
			'post_mime_type' => 'application/pdf',
			'subtype'        => 'pdf',
			'filename'       => 'doc.pdf',
			'filesize'       => 51200,
			'alt'            => '',
			'caption'        => 'File caption',
			'description'    => '',
		];

		$result = Helpers::formatFile( $file );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
		$this->assertSame( 'https://example.com/doc.pdf', $result['src'] );
		$this->assertSame( 'application/pdf', $result['type'] );
		$this->assertSame( 'File caption', $result['caption'] );
	}

	public function test_numeric_id_input(): void {
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			if ( $id === 10 ) {
				return [
					'ID'          => 10,
					'url'         => 'https://example.com/doc.pdf',
					'mime_type'   => 'application/pdf',
					'subtype'     => 'pdf',
					'filename'    => 'doc.pdf',
					'filesize'    => 51200,
					'alt'         => '',
					'caption'     => '',
					'description' => '',
				];
			}
			return false;
		} );

		$result = Helpers::formatFile( 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
	}

	public function test_url_input(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 10 );
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			if ( $id === 10 ) {
				return [
					'ID'          => 10,
					'url'         => 'https://example.com/doc.pdf',
					'mime_type'   => 'application/pdf',
					'subtype'     => 'pdf',
					'filename'    => 'doc.pdf',
					'filesize'    => 1024,
					'alt'         => '',
					'caption'     => '',
					'description' => '',
				];
			}
			return false;
		} );

		$result = Helpers::formatFile( 'https://example.com/doc.pdf' );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
	}

	public function test_empty_returns_empty_string(): void {
		$result = Helpers::formatFile( null );
		$this->assertSame( '', $result );
	}

	public function test_false_returns_empty_string(): void {
		$result = Helpers::formatFile( false );
		$this->assertSame( '', $result );
	}

	public function test_url_not_found_returns_empty_string(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );

		$result = Helpers::formatFile( 'https://example.com/nope.pdf' );
		$this->assertSame( '', $result );
	}

	public function test_pdf_preview_generated(): void {
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( [
			'https://example.com/doc-preview.jpg',
			800,
			600,
		] );

		$file = [
			'ID'          => 10,
			'url'         => 'https://example.com/doc.pdf',
			'mime_type'   => 'application/pdf',
			'subtype'     => 'pdf',
			'filename'    => 'doc.pdf',
			'filesize'    => 1024,
			'alt'         => 'Alt',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatFile( $file );

		$this->assertNotEmpty( $result['preview'] );
		$this->assertSame( 'https://example.com/doc-preview.jpg', $result['preview'][0]['src'] );
	}

	public function test_non_pdf_has_empty_preview(): void {
		$file = [
			'ID'          => 10,
			'url'         => 'https://example.com/doc.docx',
			'mime_type'   => 'application/msword',
			'subtype'     => 'msword',
			'filename'    => 'doc.docx',
			'filesize'    => 1024,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatFile( $file );

		$this->assertSame( [], $result['preview'] );
	}

	public function test_non_numeric_filesize_returns_empty_string(): void {
		$file = [
			'ID'          => 10,
			'url'         => 'https://example.com/doc.pdf',
			'mime_type'   => 'application/pdf',
			'subtype'     => 'pdf',
			'filename'    => 'doc.pdf',
			'filesize'    => '',
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatFile( $file );

		$this->assertSame( '', $result['filesize'] );
	}
}
