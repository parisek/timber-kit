<?php

declare(strict_types=1);

namespace Tests\Unit\PreloadHeaders;

use Parisek\TimberKit\PreloadHeaders;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase {

	public function test_formats_a_font_entry_the_way_a_browser_reads_it(): void {
		$value = PreloadHeaders::format( [
			[
				'href' => 'https://example.com/fonts/Inter-Regular.woff2',
				'as' => 'font',
				'type' => 'font/woff2',
				'crossorigin' => 'anonymous',
			],
		] );

		$this->assertSame(
			'<https://example.com/fonts/Inter-Regular.woff2>; rel=preload; as=font; type="font/woff2"; crossorigin',
			$value
		);
	}

	public function test_a_font_gets_crossorigin_even_when_the_entry_omits_it(): void {
		// A font is fetched in CORS mode regardless, so the omission would
		// cost a second download of the same file.
		$value = PreloadHeaders::format( [
			[ 'href' => 'https://example.com/a.woff2', 'as' => 'font' ],
		] );

		$this->assertStringEndsWith( '; crossorigin', $value );
	}

	public function test_a_style_does_not_get_crossorigin(): void {
		$value = PreloadHeaders::format( [
			[ 'href' => 'https://example.com/style.css', 'as' => 'style' ],
		] );

		$this->assertSame( '<https://example.com/style.css>; rel=preload; as=style', $value );
	}

	public function test_preconnect_origins_come_first_and_lose_their_path(): void {
		$value = PreloadHeaders::format(
			[ [ 'href' => 'https://example.com/a.css', 'as' => 'style' ] ],
			[ 'https://www.googletagmanager.com/gtm.js' ]
		);

		$this->assertSame(
			'<https://www.googletagmanager.com>; rel=preconnect, '
				. '<https://example.com/a.css>; rel=preload; as=style',
			$value
		);
	}

	public function test_an_entry_without_as_is_dropped(): void {
		$value = PreloadHeaders::format( [
			[ 'href' => 'https://example.com/mystery.bin' ],
			[ 'href' => 'https://example.com/a.css', 'as' => 'style' ],
		] );

		$this->assertSame( '<https://example.com/a.css>; rel=preload; as=style', $value );
	}

	public function test_a_relative_url_is_dropped(): void {
		// It resolves against the document, and an edge replaying this as a
		// 103 has no document yet.
		$this->assertSame( '', PreloadHeaders::format( [
			[ 'href' => '/wp-content/themes/x/a.woff2', 'as' => 'font' ],
		] ) );
	}

	public function test_a_newline_in_the_url_cannot_write_a_second_header(): void {
		$this->assertSame( '', PreloadHeaders::format( [
			[ 'href' => "https://example.com/a.css\r\nX-Injected: 1", 'as' => 'style' ],
		] ) );
	}

	public function test_a_quote_in_an_attribute_drops_the_attribute_not_the_link(): void {
		$value = PreloadHeaders::format( [
			[ 'href' => 'https://example.com/a.css', 'as' => 'style', 'media' => 'all"; rel=stylesheet' ],
		] );

		$this->assertSame( '<https://example.com/a.css>; rel=preload; as=style', $value );
	}

	public function test_an_invalid_as_token_drops_the_link(): void {
		$this->assertSame( '', PreloadHeaders::format( [
			[ 'href' => 'https://example.com/a.css', 'as' => 'style; rel=preconnect' ],
		] ) );
	}

	public function test_the_same_url_is_sent_once(): void {
		$value = PreloadHeaders::format( [
			[ 'href' => 'https://example.com/a.woff2', 'as' => 'font' ],
			[ 'href' => 'https://example.com/a.woff2', 'as' => 'font' ],
		] );

		$this->assertSame( 1, substr_count( $value, 'a.woff2' ) );
	}

	public function test_the_value_is_capped_by_dropping_whole_entries(): void {
		$resources = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$resources[] = [
				'href' => 'https://example.com/' . str_repeat( 'x', 60 ) . $i . '.woff2',
				'as' => 'font',
			];
		}

		$value = PreloadHeaders::format( $resources );

		$this->assertLessThanOrEqual( 4096, strlen( $value ) );
		// Every surviving link is whole -- a truncated one would leave an
		// unclosed angle bracket and take the rest of the header with it.
		$this->assertSame( substr_count( $value, '<' ), substr_count( $value, '>' ) );
		$this->assertStringEndsWith( '; crossorigin', $value );
	}

	public function test_nothing_to_say_is_an_empty_string(): void {
		$this->assertSame( '', PreloadHeaders::format( [] ) );
	}
}
