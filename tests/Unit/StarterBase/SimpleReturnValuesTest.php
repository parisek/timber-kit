<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class SimpleReturnValuesTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_jpeg_quality_returns_100(): void {
		$this->assertSame( 100, $this->base->jpeg_quality( 82 ) );
	}

	public function test_wp_editor_set_quality_returns_100(): void {
		$this->assertSame( 100, $this->base->wp_editor_set_quality( 82 ) );
	}

	public function test_get_site_icon_url_returns_favicon_path(): void {
		\Brain\Monkey\Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.com/wp-content/themes/test' );

		$result = $this->base->get_site_icon_url( '', 512, 1 );

		$this->assertSame( 'https://example.com/wp-content/themes/test/static/images/touch/favicon.svg', $result );
	}

	public function test_get_site_icon_url_with_custom_favicon_path(): void {
		\Brain\Monkey\Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.com/wp-content/themes/test' );

		$base = $this->createStarterBase( [ 'favicon_path' => 'images/favicon.ico' ] );
		$result = $base->get_site_icon_url( '', 32, 1 );

		$this->assertSame( 'https://example.com/wp-content/themes/test/static/images/favicon.ico', $result );
	}

	public function test_timber_cache_location_sets_cache_path(): void {
		\Brain\Monkey\Functions\when( 'wp_mkdir_p' )->justReturn( true );

		$options = [];
		$result = $this->base->timber_cache_location( $options );

		$this->assertSame( '/tmp/wp-content/cache/timber', $result['cache'] );
	}

	public function test_theme_page_templates_passes_through(): void {
		$templates = [ 'template-full.php' => 'Full Width' ];
		$this->assertSame( $templates, $this->base->theme_page_templates( $templates ) );
	}

	public function test_tiny_mce_before_init_adds_mild_cleanup_rules(): void {
		$result = $this->base->tiny_mce_before_init( [] );

		$this->assertStringContainsString( 'body.mce-content-body', $result['content_style'] );
		$this->assertTrue( $result['verify_html'] );
		$this->assertSame( 'script,iframe', $result['invalid_elements'] );
		$this->assertSame( 'none', $result['paste_webkit_styles'] );
		$this->assertArrayNotHasKey( 'paste_remove_spans', $result );
		$this->assertArrayNotHasKey( 'valid_elements', $result );
	}

	public function test_acf_input_admin_footer_prints_tinymce_sanitization_script(): void {
		ob_start();
		$this->base->acf_input_admin_footer();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'sanitizeTinyMceContent', $output );
		$this->assertStringContainsString( 'BeforeSetContent', $output );
		$this->assertStringContainsString( 'PastePreProcess', $output );
		$this->assertStringContainsString( 'GetContent', $output );
		$this->assertStringContainsString( '<script', $output );
		$this->assertStringContainsString( '<iframe', $output );
		$this->assertStringContainsString( 'style', $output );
		// spans are intentionally allowed in editor output, so the JS must not strip them
		$this->assertStringNotContainsString( 'span\\b', $output );
		$this->assertStringNotContainsString( 'class=', $output );
	}

	public function test_sanitize_acf_editor_value_removes_unwanted_markup_before_save(): void {
		$captured_allowed = null;
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed_html ) use ( &$captured_allowed ) {
			$captured_allowed = $allowed_html;
			// Simulate wp_kses: strip disallowed tags entirely. This verifies the
			// pipeline relies on wp_kses for security, not a broad regex pre-pass.
			$value = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $value );
			$value = preg_replace( '/<iframe\b[^>]*>[\s\S]*?<\/iframe>/i', '', $value );
			$value = preg_replace( '/\sstyle=(["\']).*?\1/i', '', $value );
			return $value;
		} );

		$value = '<p class="lead"><span class="x" style="color:red">Text</span><script>alert(1)</script><iframe src="https://example.com"></iframe></p>';

		$result = $this->base->sanitize_acf_editor_value( $value );

		// Allow-list shape: span is permitted with class, script/iframe are not,
		// inline style is not a permitted attribute on any element.
		$this->assertIsArray( $captured_allowed );
		$this->assertArrayHasKey( 'p', $captured_allowed );
		$this->assertSame( [ 'class' => true ], $captured_allowed['p'] );
		$this->assertArrayHasKey( 'a', $captured_allowed );
		$this->assertArrayHasKey( 'img', $captured_allowed );
		$this->assertArrayHasKey( 'span', $captured_allowed );
		$this->assertSame( [ 'class' => true ], $captured_allowed['span'] );
		$this->assertArrayNotHasKey( 'target', $captured_allowed['a'] );
		$this->assertArrayNotHasKey( 'iframe', $captured_allowed );
		$this->assertArrayNotHasKey( 'script', $captured_allowed );
		$this->assertArrayNotHasKey( 'style', $captured_allowed['p'] );

		// The stubbed wp_kses strips disallowed tags and style attributes.
		// Legitimate <span class="..."> is preserved end-to-end.
		$this->assertSame( '<p class="lead"><span class="x">Text</span></p>', $result );
	}

	public function test_sanitize_acf_editor_value_keeps_literal_style_text(): void {
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed_html ) {
			return $value;
		} );

		$value = '<p>Use <code>style="color:red"</code> in this example</p>';

		$result = $this->base->sanitize_acf_editor_value( $value );

		$this->assertSame( $value, $result );
	}

	public function test_sanitize_acf_editor_value_returns_empty_string_for_visually_empty_content(): void {
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed_html ) {
			return $value;
		} );

		$value = '<p><span data-mce-type="bookmark">&#xfeff;</span><br data-mce-bogus="1">&nbsp;</p>';

		$result = $this->base->sanitize_acf_editor_value( $value );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_acf_editor_value_keeps_allowed_markup_and_class(): void {
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed_html ) {
			return $value;
		} );

		$value = '<p class="lead"><a class="btn" href="https://example.com" rel="noopener">Link</a> <strong class="accent">Text</strong></p>';

		$result = $this->base->sanitize_acf_editor_value( $value );

		$this->assertSame( $value, $result );
	}

	public function test_sanitize_acf_editor_value_keeps_image_only_content(): void {
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed_html ) {
			return $value;
		} );

		$value = '<figure><img src="https://example.com/a.jpg" alt=""></figure>';

		$result = $this->base->sanitize_acf_editor_value( $value );

		$this->assertSame( $value, $result );
	}

	public function test_sanitize_acf_editor_value_preserves_spans_with_class(): void {
		// Regression test: legitimate <span class="..."> from editors must survive
		// both the regex pre-pass and the wp_kses allow-list.
		Functions\when( 'wp_kses' )->alias( function ( $value, $allowed_html ) {
			return $value;
		} );

		$value = '<p>Text with <span class="highlight">highlighted</span> word.</p>';

		$result = $this->base->sanitize_acf_editor_value( $value );

		$this->assertSame( $value, $result );
	}

	public function test_sanitize_acf_editor_value_returns_empty_string_for_null_and_false(): void {
		$this->assertSame( '', $this->base->sanitize_acf_editor_value( null ) );
		$this->assertSame( '', $this->base->sanitize_acf_editor_value( false ) );
	}

	public function test_sanitize_acf_editor_value_returns_non_string_values_as_is(): void {
		$this->assertSame( [ 'x' => 1 ], $this->base->sanitize_acf_editor_value( [ 'x' => 1 ] ) );
		$this->assertSame( 123, $this->base->sanitize_acf_editor_value( 123 ) );
	}
}
