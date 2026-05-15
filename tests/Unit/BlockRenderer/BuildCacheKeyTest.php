<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class BuildCacheKeyTest extends BlockRendererTestCase {

	private static function callPrivate( string $method, array $args ): mixed {
		$reflection = new \ReflectionClass( BlockRenderer::class );
		$m          = $reflection->getMethod( $method );
		return $m->invokeArgs( null, $args );
	}

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => json_encode( $value, JSON_THROW_ON_ERROR )
		);
	}

	public function test_returns_acf_block_prefixed_md5(): void {
		$key = self::callPrivate(
			'buildCacheKey',
			[ 'acf/hero', [ 'data' => [], 'anchor' => '', 'className' => '' ], 1 ]
		);

		$this->assertStringStartsWith( 'acf_block_', $key );
		// MD5 is 32 hex chars — total prefix + 32.
		$this->assertSame( 10 + 32, strlen( $key ) );
	}

	public function test_key_changes_when_cache_data_differs(): void {
		$attrs_a = [ 'data' => [ 'title' => 'Alpha' ], 'anchor' => '', 'className' => '' ];
		$attrs_b = [ 'data' => [ 'title' => 'Beta' ],  'anchor' => '', 'className' => '' ];

		$key_a = self::callPrivate( 'buildCacheKey', [ 'acf/hero', $attrs_a, 1 ] );
		$key_b = self::callPrivate( 'buildCacheKey', [ 'acf/hero', $attrs_b, 1 ] );

		$this->assertNotSame( $key_a, $key_b );
	}

	public function test_dispatches_cache_key_filter_with_full_args(): void {
		$captured_args = null;

		// Override the default apply_filters alias to capture the cache_key call.
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, mixed $value, mixed ...$rest ) use ( &$captured_args ): mixed {
				if ( 'timber_kit/block_renderer/cache_key' === $tag ) {
					$captured_args = [ 'default_key' => $value, 'cache_data' => $rest[0], 'block_name' => $rest[1] ];
				}
				return $value;
			}
		);

		$attrs = [ 'data' => [ 'x' => 1 ], 'anchor' => 'my-id', 'className' => 'extra' ];
		self::callPrivate( 'buildCacheKey', [ 'acf/promo', $attrs, 7 ] );

		$this->assertNotNull( $captured_args, 'timber_kit/block_renderer/cache_key filter was not dispatched' );
		$this->assertStringStartsWith( 'acf_block_', $captured_args['default_key'] );
		$this->assertSame( 'acf/promo', $captured_args['block_name'] );

		// Verify all 7 fields are present in the cache_data array.
		$cache_data = $captured_args['cache_data'];
		foreach ( [ 'name', 'data', 'anchor', 'className', 'post_id', 'lang', 'paged' ] as $field ) {
			$this->assertArrayHasKey( $field, $cache_data, "cache_data missing field: $field" );
		}
		$this->assertSame( 'acf/promo', $cache_data['name'] );
		$this->assertSame( 'my-id', $cache_data['anchor'] );
		$this->assertSame( 'extra', $cache_data['className'] );
		$this->assertSame( 7, $cache_data['post_id'] );
	}
}
