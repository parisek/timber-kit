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
		Functions\when( 'esc_url' )->returnArg();
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
		$this->assertMatchesRegularExpression(
			'#^@import url\(\'https://example\.test/wp-content/themes/test/static/fonts/brand\.css\?v=\d+\'\);$#',
			$result['styles'][0]['css']
		);
		$this->assertMatchesRegularExpression(
			'#^@import url\(\'https://example\.test/wp-content/themes/test/static/fonts/display\.css\?v=\d+\'\);$#',
			$result['styles'][1]['css']
		);
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
