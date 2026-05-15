<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\BlockRenderer;
use Tests\Unit\BlockRendererTestCase;

class WriteToCacheTest extends BlockRendererTestCase {

	private static function callPrivate( string $method, array $args ): mixed {
		$reflection = new \ReflectionClass( BlockRenderer::class );
		$m          = $reflection->getMethod( $method );
		return $m->invokeArgs( null, $args );
	}

	private static function getPreviewMemo(): array {
		$ref  = new \ReflectionClass( BlockRenderer::class );
		$prop = $ref->getProperty( 'preview_memo' );
		return $prop->getValue( null );
	}

	protected function setUp(): void {
		parent::setUp();
		Fixtures::resetPreviewMemo();
	}

	protected function tearDown(): void {
		Fixtures::resetPreviewMemo();
		parent::tearDown();
	}

	public function test_skips_write_when_template_output_empty(): void {
		// Neither preview memo nor wp_cache_set should be touched.
		Functions\expect( 'wp_cache_set' )->never();

		self::callPrivate(
			'writeToCache',
			[ '', 'key_x', 'acf_block_1', false, true, false, false ]
		);

		$this->assertEmpty( self::getPreviewMemo() );
	}

	public function test_writes_to_preview_memo_in_preview_mode(): void {
		Functions\expect( 'wp_cache_set' )->never();

		self::callPrivate(
			'writeToCache',
			[ '<p>preview</p>', 'preview_key', 'acf_block_1', true, false, false, false ]
		);

		$memo = self::getPreviewMemo();
		$this->assertArrayHasKey( 'preview_key', $memo );
		$this->assertSame( '<p>preview</p>', $memo['preview_key'] );
	}

	public function test_skips_external_cache_when_has_side_effects(): void {
		Functions\expect( 'wp_cache_set' )->never();

		self::callPrivate(
			'writeToCache',
			[ '<form>…</form>', 'key_x', 'acf_block_1', false, true, true, false ]
		);

		$this->assertEmpty( self::getPreviewMemo() );
	}

	public function test_skips_external_cache_when_rendered_empty_alert(): void {
		Functions\expect( 'wp_cache_set' )->never();

		self::callPrivate(
			'writeToCache',
			[ '<div class="block-editor-warning">…</div>', 'key_x', 'acf_block_1', false, true, false, true ]
		);

		$this->assertEmpty( self::getPreviewMemo() );
	}

	public function test_skips_external_cache_when_use_cache_false(): void {
		Functions\expect( 'wp_cache_set' )->never();

		self::callPrivate(
			'writeToCache',
			[ '<p>output</p>', 'key_x', 'acf_block_1', false, false, false, false ]
		);

		$this->assertEmpty( self::getPreviewMemo() );
	}

	public function test_writes_to_external_cache_when_all_guards_pass(): void {
		Functions\expect( 'wp_cache_set' )
			->once()
			->with( 'key_x', '<p>output</p>', 'acf_block_1', HOUR_IN_SECONDS );

		self::callPrivate(
			'writeToCache',
			[ '<p>output</p>', 'key_x', 'acf_block_1', false, true, false, false ]
		);

		$this->assertEmpty( self::getPreviewMemo() );
	}
}
