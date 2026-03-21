<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class DataTransformTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	// block_categories_all

	public function test_block_categories_all_appends_custom_category(): void {
		\Brain\Monkey\Functions\when( '__' )->alias( fn( $s ) => $s );

		$existing = [ [ 'slug' => 'text', 'title' => 'Text' ] ];
		$result = $this->base->block_categories_all( $existing );

		$this->assertCount( 2, $result );
		$this->assertSame( 'custom', $result[1]['slug'] );
		$this->assertSame( 'Custom', $result[1]['title'] );
	}

	public function test_block_categories_all_uses_configured_category(): void {
		\Brain\Monkey\Functions\when( '__' )->alias( fn( $s ) => $s );

		$base = $this->createStarterBase( [ 'block_category' => [ 'slug' => 'theme', 'title' => 'Theme Blocks' ] ] );
		$result = $base->block_categories_all( [] );

		$this->assertSame( 'theme', $result[0]['slug'] );
		$this->assertSame( 'Theme Blocks', $result[0]['title'] );
	}

	// tiny_mce_before_init

	public function test_tiny_mce_appends_margin_styles(): void {
		$mceInit = [];
		$result = $this->base->tiny_mce_before_init( $mceInit );

		$this->assertStringContainsString( 'margin-top:0', $result['content_style'] );
		$this->assertStringContainsString( 'margin-bottom:0', $result['content_style'] );
	}

	public function test_tiny_mce_preserves_existing_styles(): void {
		$mceInit = [ 'content_style' => 'body { color: red; }' ];
		$result = $this->base->tiny_mce_before_init( $mceInit );

		$this->assertStringContainsString( 'color: red', $result['content_style'] );
		$this->assertStringContainsString( 'margin-top:0', $result['content_style'] );
	}

	// wp_get_attachment_image_attributes

	public function test_adds_img_fluid_class(): void {
		$attr = [ 'class' => 'wp-image-123' ];
		$result = $this->base->wp_get_attachment_image_attributes( $attr, null );

		$this->assertStringContainsString( 'img-fluid', $result['class'] );
	}

	public function test_does_not_duplicate_img_fluid(): void {
		$attr = [ 'class' => 'img-fluid wp-image-123' ];
		$result = $this->base->wp_get_attachment_image_attributes( $attr, null );

		$this->assertSame( 1, substr_count( $result['class'], 'img-fluid' ) );
	}

	// render_block_data

	public function test_render_block_data_no_parent(): void {
		$parsed_block = [ 'blockName' => 'core/paragraph' ];
		$result = $this->base->render_block_data( $parsed_block, new \stdClass(), null );

		$this->assertNull( $result['parent'] );
	}

	public function test_render_block_data_with_parent(): void {
		$parent = new \stdClass();
		$parent->parsed_block = [ 'blockName' => 'core/columns' ];
		$parent->name = 'core/columns';
		$parent->attributes = [ 'align' => 'full' ];

		$parsed_block = [ 'blockName' => 'core/column' ];
		$result = $this->base->render_block_data( $parsed_block, new \stdClass(), $parent );

		$this->assertSame( 'core/columns', $result['parent']['name'] );
		$this->assertSame( [ 'align' => 'full' ], $result['parent']['attributes'] );
	}

	// preload_resources

	public function test_preload_resources_empty_when_no_fonts(): void {
		$result = $this->base->preload_resources( [] );

		$this->assertSame( [], $result );
	}

	public function test_preload_resources_adds_font_entries(): void {
		\Brain\Monkey\Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.com/wp-content/themes/test' );

		$base = $this->createStarterBase( [ 'preload_fonts' => [ 'fonts/inter.woff2', 'fonts/bold.woff2' ] ] );
		$result = $base->preload_resources( [] );

		$this->assertCount( 2, $result );
		$this->assertSame( 'font', $result[0]['as'] );
		$this->assertSame( 'font/woff2', $result[0]['type'] );
		$this->assertStringContainsString( 'fonts/inter.woff2', $result[0]['href'] );
	}

	public function test_preload_resources_preserves_existing(): void {
		\Brain\Monkey\Functions\when( 'get_template_directory_uri' )->justReturn( '' );

		$base = $this->createStarterBase( [ 'preload_fonts' => [ 'fonts/a.woff2' ] ] );
		$existing = [ [ 'href' => '/existing.css', 'as' => 'style' ] ];
		$result = $base->preload_resources( $existing );

		$this->assertCount( 2, $result );
		$this->assertSame( '/existing.css', $result[0]['href'] );
	}
}
