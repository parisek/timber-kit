<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class VideoSourceTypeTest extends HelpersTestCase {

	private const CACHE_KEY = '_timber_kit_video_source_type';

	public function test_cache_hit_returns_stored_source_type_without_recompute(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 10, self::CACHE_KEY, true )
			->andReturn( 'video/mp4; codecs="av01.0.00M.08"' );
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$this->assertSame( 'video/mp4; codecs="av01.0.00M.08"', Helpers::videoSourceType( 10 ) );
	}

	public function test_cache_miss_computes_and_stores_parser_result_for_id(): void {
		$path = dirname( __DIR__, 2 ) . '/Fixtures/video/av1-10bit.mp4';

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 11, self::CACHE_KEY, true )
			->andReturn( '' );
		Functions\expect( 'get_attached_file' )->once()->with( 11 )->andReturn( $path );
		Functions\expect( 'get_post_mime_type' )->never();
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 11, self::CACHE_KEY, 'video/mp4; codecs="av01.0.00M.10"' );

		$this->assertSame( 'video/mp4; codecs="av01.0.00M.10"', Helpers::videoSourceType( 11 ) );
	}

	public function test_array_input_uses_array_mime_as_fallback_and_stores_it(): void {
		$attachment = [
			'ID' => 12,
			'url' => 'https://example.test/uploads/h264.mp4',
			'mime_type' => 'video/mp4',
		];

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 12, self::CACHE_KEY, true )
			->andReturn( false );
		Functions\expect( 'get_attached_file' )
			->once()
			->with( 12 )
			->andReturn( dirname( __DIR__, 2 ) . '/Fixtures/video/h264.mp4' );
		Functions\expect( 'get_post_mime_type' )->never();
		Functions\expect( 'update_post_meta' )->once()->with( 12, self::CACHE_KEY, 'video/mp4' );

		$this->assertSame( 'video/mp4', Helpers::videoSourceType( $attachment ) );
	}

	public function test_id_input_falls_back_to_post_mime_and_then_default(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 13, self::CACHE_KEY, true )
			->andReturn( [] );
		Functions\expect( 'get_attached_file' )->once()->with( 13 )->andReturn( false );
		Functions\expect( 'get_post_mime_type' )->once()->with( 13 )->andReturn( false );
		Functions\expect( 'update_post_meta' )->once()->with( 13, self::CACHE_KEY, 'video/mp4' );

		$this->assertSame( 'video/mp4', Helpers::videoSourceType( 13 ) );
	}

	public function test_format_video_sources_preserves_order_and_drops_empty_items(): void {
		$variants = [
			null,
			[
				'ID' => 21,
				'url' => 'https://example.test/uploads/desktop.mp4',
				'mime_type' => 'video/mp4',
			],
			[],
			[
				'ID' => 22,
				'url' => 'https://example.test/uploads/mobile.webm',
				'mime_type' => 'video/webm',
			],
		];

		Functions\expect( 'get_post_meta' )->times( 2 )->andReturn( '', '' );
		Functions\expect( 'get_attached_file' )
			->times( 2 )
			->andReturn(
				dirname( __DIR__, 2 ) . '/Fixtures/video/av1-8bit.mp4',
				dirname( __DIR__, 2 ) . '/Fixtures/video/av1.webm'
			);
		Functions\expect( 'update_post_meta' )
			->times( 2 )
			->andReturnUsing( static fn(): bool => true );

		$this->assertSame(
			[
				[
					'src' => 'https://example.test/uploads/desktop.mp4',
					'type' => 'video/mp4; codecs="av01.0.00M.08"',
				],
				[
					'src' => 'https://example.test/uploads/mobile.webm',
					'type' => 'video/webm',
				],
			],
			Helpers::formatVideoSources( $variants )
		);
	}
}
