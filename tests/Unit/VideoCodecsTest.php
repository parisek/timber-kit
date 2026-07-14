<?php

declare(strict_types=1);

namespace Tests\Unit;

use Parisek\TimberKit\VideoCodecs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideoCodecsTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function provideFixtureCodecs(): array {
		return [
			'av1 8-bit mp4' => [ 'av1-8bit.mp4', 'av01.0.00M.08' ],
			'av1 10-bit mp4' => [ 'av1-10bit.mp4', 'av01.0.00M.10' ],
			'h264 mp4' => [ 'h264.mp4', null ],
			'av1 webm' => [ 'av1.webm', null ],
			'truncated mp4' => [ 'truncated.mp4', null ],
		];
	}

	#[DataProvider( 'provideFixtureCodecs' )]
	public function test_parses_fixture_codecs( string $filename, ?string $expected ): void {
		$this->assertSame(
			$expected,
			VideoCodecs::codecsString( dirname( __DIR__ ) . '/Fixtures/video/' . $filename )
		);
	}

	public function test_bad_input_returns_null_without_warning(): void {
		$prevLevel = error_reporting( E_ALL );
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			throw new \RuntimeException( "Unexpected PHP error: $errstr" );
		} );

		try {
			$this->assertNull( VideoCodecs::codecsString( dirname( __DIR__ ) . '/Fixtures/video/missing.mp4' ) );
			$this->assertNull( VideoCodecs::codecsString( __FILE__ ) );
		} finally {
			restore_error_handler();
			error_reporting( $prevLevel );
		}
	}
}
