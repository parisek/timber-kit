<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class RenderTest extends BlockRendererTestCase {

	protected function setUp(): void {
		parent::setUp();
		Fixtures::resetPreviewMemo();

		// Default no-op mocks for WP functions every render path touches.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);
		Functions\when( 'wp_scripts' )->alias(
			static fn() => (object) [ 'queue' => [] ]
		);
		Functions\when( 'wp_styles' )->alias(
			static fn() => (object) [ 'queue' => [] ]
		);
		Functions\when( 'acf_get_valid_post_id' )->justReturn( 0 );
		Functions\when( 'get_query_var' )->justReturn( 0 );
	}

	public function test_real_post_id_resolution_falls_back_to_global_post(): void {
		// When callback $post_id is a "block_*" string and ACF resolves it to a
		// "block_*" string too, the renderer must fall back to global $post->ID
		// for the cache group naming.
		$GLOBALS['post'] = (object) [ 'ID' => 42 ];

		Functions\when( 'acf_get_valid_post_id' )->justReturn( 'block_abc123' );

		$captured_group = null;
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\expect( 'wp_cache_get' )
			->andReturnUsing( function ( string $key, string $group ) use ( &$captured_group ) {
				$captured_group = $group;
				return false;
			} );

		ob_start();
		BlockRenderer::render(
			Fixtures::attributes(),
			'',
			false,
			'block_abc123',
			null
		);
		ob_end_clean();

		$this->assertSame( 'acf_block_42', $captured_group );

		unset( $GLOBALS['post'] );
	}
}
