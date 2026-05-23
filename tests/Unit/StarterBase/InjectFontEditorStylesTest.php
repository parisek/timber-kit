<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class InjectFontEditorStylesTest extends StarterBaseTestCase {

	private string $fontDir;

	protected function setUp(): void {
		parent::setUp();
		$this->fontDir = sys_get_temp_dir() . '/timber-kit-fonts-' . uniqid();
		mkdir( $this->fontDir . '/static/fonts', 0777, true );

		Functions\when( 'get_template_directory' )->justReturn( $this->fontDir );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/wp-content/themes/test' );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_query_arg' )->alias( function ( $key, $value, $url ) {
			$sep = strpos( $url, '?' ) === false ? '?' : '&';
			return $url . $sep . $key . '=' . $value;
		} );
	}

	protected function tearDown(): void {
		// Best-effort cleanup; ignore failures if directory was already removed.
		@array_map( 'unlink', glob( $this->fontDir . '/static/fonts/*' ) ?: [] );
		@rmdir( $this->fontDir . '/static/fonts' );
		@rmdir( $this->fontDir . '/static' );
		@rmdir( $this->fontDir );
		parent::tearDown();
	}

	public function test_relative_paths_become_import_url_entries_with_cache_bust(): void {
		file_put_contents( $this->fontDir . '/static/fonts/brand.css', '/* brand */' );
		file_put_contents( $this->fontDir . '/static/fonts/display.css', '/* display */' );

		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [
				'brand'   => 'fonts/brand.css',
				'display' => 'fonts/display.css',
			],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		$this->assertCount( 2, $result['styles'] );
		// `ver` matches what wp_enqueue_style() emits in assets() so the
		// browser can dedupe in non-iframed editor mode.
		$this->assertMatchesRegularExpression(
			'#^@import url\(\'https://example\.test/wp-content/themes/test/static/fonts/brand\.css\?ver=\d+\'\);$#',
			$result['styles'][0]['css']
		);
		$this->assertMatchesRegularExpression(
			'#^@import url\(\'https://example\.test/wp-content/themes/test/static/fonts/display\.css\?ver=\d+\'\);$#',
			$result['styles'][1]['css']
		);
	}

	public function test_relative_path_with_existing_query_string_uses_ampersand_separator(): void {
		// add_query_arg() must compose `&ver=...` not `?ver=...` when the
		// path already carries a query — e.g. a CDN-revved manifest path
		// like `fonts/brand.css?h=abc123`.
		mkdir( $this->fontDir . '/static/fonts-q', 0777, true );
		file_put_contents( $this->fontDir . '/static/fonts-q/brand.css', '/* brand */' );

		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [
				'brand' => 'fonts-q/brand.css',
			],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		// Sanity — single ? then ver=:
		$this->assertCount( 1, $result['styles'] );
		$this->assertSame( 1, substr_count( $result['styles'][0]['css'], '?' ) );

		// Cleanup
		@unlink( $this->fontDir . '/static/fonts-q/brand.css' );
		@rmdir( $this->fontDir . '/static/fonts-q' );
	}

	public function test_absolute_urls_pass_through_without_static_prefix_or_cache_bust(): void {
		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [
				'google' => 'https://fonts.googleapis.com/css2?family=Inter',
			],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		$this->assertCount( 1, $result['styles'] );
		$this->assertSame(
			"@import url('https://fonts.googleapis.com/css2?family=Inter');",
			$result['styles'][0]['css']
		);
	}

	public function test_ampersand_in_url_is_not_html_entity_encoded(): void {
		// Google Fonts URLs routinely contain `&display=swap`. esc_url() would
		// HTML-entity-encode `&` to `&amp;`, which CSS does not decode — the
		// browser would then request a URL with literal `&amp;`. esc_url_raw()
		// preserves the raw `&`. Regression guard for the original `esc_url()` bug.
		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [
				'google' => 'https://fonts.googleapis.com/css2?family=Inter&display=swap',
			],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		$this->assertCount( 1, $result['styles'] );
		$this->assertStringNotContainsString( '&amp;', $result['styles'][0]['css'] );
		$this->assertStringContainsString( '&display=swap', $result['styles'][0]['css'] );
	}

	public function test_single_quote_in_url_is_css_escaped(): void {
		// Single quote in URL would close the @import url('…') string. The
		// CSS-context escape (backslash) prevents breakout. esc_url_raw passes
		// the quote through; the str_replace inside the formatter escapes it.
		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [
				// Single quote in path is implausible but the defence costs nothing.
				'evil' => "https://example.com/font.css?x='breakout",
			],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		$this->assertCount( 1, $result['styles'] );
		// The literal single quote must appear escaped (`\'`), not raw.
		$this->assertStringContainsString( "\\'breakout", $result['styles'][0]['css'] );
	}

	public function test_missing_file_is_skipped_silently(): void {
		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [
				'ghost' => 'fonts/does-not-exist.css',
			],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		$this->assertSame( [], $result['styles'] );
	}

	public function test_gutenberg_editor_styles_flag_disables_injection(): void {
		file_put_contents( $this->fontDir . '/static/fonts/brand.css', '/* brand */' );

		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => false,
			'font_stylesheets' => [ 'brand' => 'fonts/brand.css' ],
		] );

		$result = $base->inject_font_editor_styles( [ 'styles' => [] ] );

		$this->assertSame( [], $result['styles'] );
	}

	public function test_empty_font_stylesheets_returns_settings_unchanged(): void {
		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [],
		] );

		$input  = [ 'styles' => [ [ 'css' => '/* existing */' ] ] ];
		$result = $base->inject_font_editor_styles( $input );

		$this->assertSame( $input, $result );
	}

	public function test_existing_styles_are_preserved_and_new_entries_appended(): void {
		file_put_contents( $this->fontDir . '/static/fonts/brand.css', '/* brand */' );

		$base = $this->createStarterBase( [
			'gutenberg_editor_styles' => true,
			'font_stylesheets' => [ 'brand' => 'fonts/brand.css' ],
		] );

		$result = $base->inject_font_editor_styles( [
			'styles' => [
				[ 'css' => '/* core */' ],
			],
		] );

		$this->assertCount( 2, $result['styles'] );
		$this->assertSame( '/* core */', $result['styles'][0]['css'] );
		$this->assertStringContainsString( 'brand.css', $result['styles'][1]['css'] );
	}
}
