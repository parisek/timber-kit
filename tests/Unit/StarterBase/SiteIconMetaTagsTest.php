<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies the opt-in site-icon tag set: the favicon files are discovered on
 * disk by convention (no configured paths), an uploaded Site Icon wins over
 * them, and the emitted markup replaces WordPress's four legacy tags.
 */
class SiteIconMetaTagsTest extends StarterBaseTestCase {

	private string $themeDir;

	protected function setUp(): void {
		parent::setUp();

		$this->themeDir = sys_get_temp_dir() . '/timber-kit-site-icon-' . uniqid();
		@mkdir( $this->themeDir . '/static/images/touch', 0777, true );

		Functions\when( 'get_template_directory' )->justReturn( $this->themeDir );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/theme' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->themeDir . '/static/images/touch/*' ) ?: [] as $file ) {
			@unlink( $file );
		}
		parent::tearDown();
	}

	private function ship( string ...$files ): void {
		foreach ( $files as $file ) {
			file_put_contents( $this->themeDir . '/static/images/touch/' . $file, 'x' );
		}
	}

	private function instance(): StarterBase {
		$instance = ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
		$prop     = ( new \ReflectionClass( StarterBase::class ) )->getProperty( 'site_icon_tags' );
		$prop->setValue( $instance, true );

		return $instance;
	}

	private function tags( StarterBase $instance ): array {
		return $instance->site_icon_meta_tags( [ '<link rel="icon" href="core.png" sizes="32x32" />' ] );
	}

	public function test_discovers_the_modern_favicon_set_without_configuration(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->ship( 'favicon.svg', 'favicon-96x96.png', 'favicon.ico', 'apple-touch-icon.png' );

		$markup = implode( "\n", $this->tags( $this->instance() ) );

		$this->assertStringContainsString( 'type="image/svg+xml"', $markup );
		$this->assertStringContainsString( 'favicon-96x96.png', $markup );
		$this->assertStringContainsString( 'sizes="96x96"', $markup );
		$this->assertStringContainsString( 'rel="shortcut icon"', $markup );
	}

	public function test_discovers_the_legacy_favicon_set_without_configuration(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->ship( 'favicon-32x32.png', 'favicon.ico', 'apple-touch-icon.png' );

		$markup = implode( "\n", $this->tags( $this->instance() ) );

		$this->assertStringContainsString( 'favicon-32x32.png', $markup );
		$this->assertStringContainsString( 'sizes="32x32"', $markup );
		$this->assertStringNotContainsString( 'image/svg+xml', $markup );
	}

	public function test_apple_touch_icon_is_never_an_svg(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->ship( 'favicon.svg' );

		$markup = implode( "\n", $this->tags( $this->instance() ) );

		$this->assertStringNotContainsString( 'apple-touch-icon', $markup );
	}

	public function test_drops_the_core_legacy_tags(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->ship( 'favicon.svg' );

		$markup = implode( "\n", $this->tags( $this->instance() ) );

		$this->assertStringNotContainsString( 'core.png', $markup );
		$this->assertStringNotContainsString( 'msapplication-TileImage', $markup );
	}

	public function test_uploaded_site_icon_wins_over_the_theme_files(): void {
		Functions\when( 'get_option' )->justReturn( 42 );
		$this->ship( 'favicon.svg', 'favicon-96x96.png' );

		$core = [ '<link rel="icon" href="core.png" sizes="32x32" />' ];

		$this->assertSame( $core, $this->instance()->site_icon_meta_tags( $core ) );
	}

	public function test_reads_theme_color_and_app_title_from_the_manifest(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->ship( 'favicon.svg' );
		file_put_contents(
			$this->themeDir . '/static/images/touch/site.webmanifest',
			'{"short_name":"Sloneek","theme_color":"#123456"}'
		);

		$markup = implode( "\n", $this->tags( $this->instance() ) );

		$this->assertStringContainsString( 'rel="manifest"', $markup );
		$this->assertStringContainsString( 'name="theme-color" content="#123456"', $markup );
		$this->assertStringContainsString( 'apple-mobile-web-app-title" content="Sloneek"', $markup );
	}

	public function test_leaves_core_alone_when_no_favicon_file_is_shipped(): void {
		Functions\when( 'get_option' )->justReturn( 0 );

		$core = [ '<link rel="icon" href="core.png" sizes="32x32" />' ];

		$this->assertSame( $core, $this->instance()->site_icon_meta_tags( $core ) );
	}
}
