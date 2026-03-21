<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

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
		$options = [];
		$result = $this->base->timber_cache_location( $options );

		$this->assertSame( '/tmp/wp-content/cache/timber', $result['cache'] );
	}

	public function test_theme_page_templates_passes_through(): void {
		$templates = [ 'template-full.php' => 'Full Width' ];
		$this->assertSame( $templates, $this->base->theme_page_templates( $templates ) );
	}
}
