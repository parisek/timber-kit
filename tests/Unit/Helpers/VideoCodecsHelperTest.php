<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class VideoCodecsHelperTest extends HelpersTestCase {

	private const CACHE_KEY = '_timber_kit_video_codecs';

	public function test_cache_hit_returns_stored_codecs_without_recompute(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 10, self::CACHE_KEY, true )
			->andReturn( 'av01.0.00M.08' );
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$this->assertSame( 'av01.0.00M.08', Helpers::videoCodecs( 10 ) );
	}

	public function test_cached_none_sentinel_maps_to_null_without_recompute(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 14, self::CACHE_KEY, true )
			->andReturn( 'none' );
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$this->assertNull( Helpers::videoCodecs( 14 ) );
	}

	public function test_cache_miss_computes_and_stores_parser_result_for_id(): void {
		$path = dirname( __DIR__, 2 ) . '/Fixtures/video/av1-10bit.mp4';

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 11, self::CACHE_KEY, true )
			->andReturn( '' );
		Functions\expect( 'get_attached_file' )->once()->with( 11 )->andReturn( $path );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 11, self::CACHE_KEY, 'av01.0.00M.10' );

		$this->assertSame( 'av01.0.00M.10', Helpers::videoCodecs( 11 ) );
	}

	public function test_non_av1_file_returns_null_and_stores_none_sentinel(): void {
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
		Functions\expect( 'update_post_meta' )->once()->with( 12, self::CACHE_KEY, 'none' );

		$this->assertNull( Helpers::videoCodecs( $attachment ) );
	}

	public function test_input_without_resolvable_id_returns_null_without_meta_access(): void {
		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$this->assertNull( Helpers::videoCodecs( [ 'url' => 'https://example.test/uploads/no-id.mp4' ] ) );
		$this->assertNull( Helpers::videoCodecs( 0 ) );
	}

	public function test_format_video_sources_preserves_order_drops_empty_and_splits_type_from_codecs(): void {
		$variants = [
			null,
			[
				'ID' => 21,
				'url' => 'https://example.test/uploads/desktop-av1.mp4',
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
					'src' => 'https://example.test/uploads/desktop-av1.mp4',
					'type' => 'video/mp4',
					'codecs' => 'av01.0.00M.08',
				],
				[
					'src' => 'https://example.test/uploads/mobile.webm',
					'type' => 'video/webm',
					'codecs' => null,
				],
			],
			Helpers::formatVideoSources( $variants )
		);
	}
}
