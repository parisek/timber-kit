<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class FlushPostBlockCacheTest extends BlockRendererTestCase {

	public function test_flushes_correct_group_when_ext_cache_available(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->alias(
			static fn( string $feature ): bool => $feature === 'flush_group'
		);
		Functions\expect( 'wp_cache_flush_group' )
			->once()
			->with( 'acf_block_42' );

		BlockRenderer::flushPostBlockCache( 42 );

		$this->addToAssertionCount( 1 );
	}

	public function test_skips_flush_when_post_id_not_numeric(): void {
		// E.g. 'option' (ACF options pages) or 'block_*' opaque ids must not
		// trigger a flush_group call.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\expect( 'wp_cache_flush_group' )->never();

		BlockRenderer::flushPostBlockCache( 'option' );

		$this->addToAssertionCount( 1 );
	}

	public function test_skips_flush_when_external_cache_unavailable(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_flush_group' )->never();

		BlockRenderer::flushPostBlockCache( 42 );

		$this->addToAssertionCount( 1 );
	}
}
