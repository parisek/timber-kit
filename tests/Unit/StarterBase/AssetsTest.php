<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that assets() enqueues the correct stylesheet based on $minify_style
 * and the WP_DEBUG constant.
 */
class AssetsTest extends StarterBaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Stubs shared by all tests in this suite.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/wp-content/themes/test' );
		Functions\when( 'get_template_directory' )->justReturn( '/nonexistent/path/that/will/not/exist' );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_dequeue_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script_module' )->justReturn( true );
	}

	/**
	 * Default: $minify_style = true, WP_DEBUG not defined → style.min.css.
	 */
	public function test_default_enqueues_minified_stylesheet_in_production(): void {
		$enqueued_src = null;
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src ) use ( &$enqueued_src ) {
				// Capture only the main theme style (no suffix on handle).
				if ( $handle === 'test_theme' ) {
					$enqueued_src = $src;
				}
			}
		);

		$base = $this->createStarterBase( [
			'theme_name'     => 'test_theme',
			'font_stylesheets' => [],
		] );
		$base->assets();

		$this->assertStringContainsString(
			'style.min.css',
			(string) $enqueued_src,
			'With $minify_style = true (default) and no WP_DEBUG, assets() must enqueue style.min.css'
		);
	}

	/**
	 * $minify_style = false, WP_DEBUG not defined → always style.css.
	 */
	public function test_minify_style_false_enqueues_unminified_stylesheet(): void {
		$enqueued_src = null;
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src ) use ( &$enqueued_src ) {
				if ( $handle === 'test_theme' ) {
					$enqueued_src = $src;
				}
			}
		);

		$base = $this->createStarterBase( [
			'theme_name'       => 'test_theme',
			'font_stylesheets' => [],
			'minify_style'     => false,
		] );
		$base->assets();

		$this->assertStringContainsString(
			'style.css',
			(string) $enqueued_src,
			'With $minify_style = false, assets() must enqueue style.css regardless of WP_DEBUG'
		);
		$this->assertStringNotContainsString(
			'style.min.css',
			(string) $enqueued_src,
			'With $minify_style = false, assets() must NOT enqueue style.min.css'
		);
	}
}
