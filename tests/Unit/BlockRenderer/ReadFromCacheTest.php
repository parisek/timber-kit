<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class ReadFromCacheTest extends BlockRendererTestCase {

	private static function callPrivate( string $method, array $args ): mixed {
		$reflection = new \ReflectionClass( BlockRenderer::class );
		$m          = $reflection->getMethod( $method );
		return $m->invokeArgs( null, $args );
	}

	private static function setPreviewMemo( array $memo ): void {
		$ref  = new \ReflectionClass( BlockRenderer::class );
		$prop = $ref->getProperty( 'preview_memo' );
		$prop->setValue( null, $memo );
	}

	protected function setUp(): void {
		parent::setUp();
		Fixtures::resetPreviewMemo();
	}

	protected function tearDown(): void {
		Fixtures::resetPreviewMemo();
		parent::tearDown();
	}

	public function test_returns_null_on_preview_memo_miss(): void {
		$result = self::callPrivate(
			'readFromCache',
			[ 'some_key', 'acf_block_1', true, false ]
		);

		$this->assertNull( $result );
	}

	public function test_returns_cached_string_on_preview_memo_hit(): void {
		self::setPreviewMemo( [ 'some_key' => '<p>cached output</p>' ] );

		$result = self::callPrivate(
			'readFromCache',
			[ 'some_key', 'acf_block_1', true, false ]
		);

		$this->assertSame( '<p>cached output</p>', $result );
	}

	public function test_calls_wp_cache_get_only_when_use_cache_true(): void {
		Functions\expect( 'wp_cache_get' )
			->once()
			->with( 'some_key', 'acf_block_1' )
			->andReturn( false );

		$result = self::callPrivate(
			'readFromCache',
			[ 'some_key', 'acf_block_1', false, true ]
		);

		$this->assertNull( $result );
	}

	public function test_returns_null_when_wp_cache_get_returns_false(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );

		$result = self::callPrivate(
			'readFromCache',
			[ 'some_key', 'acf_block_1', false, true ]
		);

		$this->assertNull( $result );
	}

	public function test_returns_cached_string_when_wp_cache_get_hits(): void {
		Functions\when( 'wp_cache_get' )->justReturn( '<div>frontend cached</div>' );

		$result = self::callPrivate(
			'readFromCache',
			[ 'some_key', 'acf_block_1', false, true ]
		);

		$this->assertSame( '<div>frontend cached</div>', $result );
	}

	public function test_skips_wp_cache_get_when_use_cache_false(): void {
		// wp_cache_get must NOT be called when use_cache is false.
		Functions\expect( 'wp_cache_get' )->never();

		$result = self::callPrivate(
			'readFromCache',
			[ 'some_key', 'acf_block_1', false, false ]
		);

		$this->assertNull( $result );
	}
}
