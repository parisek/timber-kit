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
			'h264 high mp4' => [ 'h264.mp4', 'avc1.64000A' ],
			'h264 constrained baseline mp4' => [ 'h264-baseline.mp4', 'avc1.42C01E' ],
			'hevc main hvc1 mp4' => [ 'hevc-hvc1.mp4', 'hvc1.1.6.L30.90' ],
			'hevc main hev1 mp4' => [ 'hevc-hev1.mp4', 'hev1.1.6.L30.90' ],
			'hevc main 10 mp4' => [ 'hevc-main10.mp4', 'hvc1.2.4.L30.90' ],
			'av1 webm' => [ 'av1.webm', 'av01.0.00M.08' ],
			'vp8 webm' => [ 'vp8.webm', 'vp8' ],
			'vp9 webm without CodecPrivate' => [ 'vp9.webm', 'vp9' ],
			'vp9 10-bit webm without CodecPrivate' => [ 'vp9-10bit.webm', 'vp9' ],
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

	/**
	 * A file cut at any length must give null or the full-file value, never a
	 * different value, a warning, or a hang.
	 */
	#[DataProvider( 'provideFixtureCodecs' )]
	public function test_every_truncation_is_null_or_the_full_value( string $filename, ?string $expected ): void {
		$bytes = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/video/' . $filename );
		$path = $this->tempFile();

		$this->failOnPhpErrors( function () use ( $bytes, $path, $expected ): void {
			// Sampled cuts: the first 64 bytes one by one, then about 100 more spread over the file.
			// A cut at every byte would make Patchwork's stream wrapper exhaust memory.
			$step = max( 1, intdiv( strlen( $bytes ), 100 ) );
			for ( $length = 0; $length < strlen( $bytes ); $length += $length < 64 ? 1 : $step ) {
				file_put_contents( $path, substr( $bytes, 0, $length ) );
				$this->assertContains( VideoCodecs::codecsString( $path ), [ null, $expected ], "cut at $length bytes" );
			}
		} );
	}

	public function test_hevc_maps_profile_space_tier_compat_flags_and_constraints(): void {
		// Tier flag high, profile idc 1, compat flags 0x60000000, constraint B0, level 93.
		$this->assertSame(
			'hvc1.1.6.H93.B0',
			$this->mp4Codecs( 'hvc1', 'hvcC', $this->hvcc( 0x21, 0x60000000, [ 0xB0, 0, 0, 0, 0, 0 ], 93 ) )
		);
		// Profile space 2 prints as `B`; the constraint bytes keep inner zeros and drop trailing zeros.
		$this->assertSame(
			'hev1.B4.20000000.L120.00.11',
			$this->mp4Codecs( 'hev1', 'hvcC', $this->hvcc( 0x84, 0x00000004, [ 0, 0x11, 0, 0, 0, 0 ], 120 ) )
		);
		// No constraint bytes set: the last part is the level.
		$this->assertSame(
			'hvc1.1.6.L93',
			$this->mp4Codecs( 'hvc1', 'hvcC', $this->hvcc( 0x01, 0x60000000, [ 0, 0, 0, 0, 0, 0 ], 93 ) )
		);
	}

	public function test_hevc_and_avc_reject_short_or_unversioned_configuration(): void {
		$hvcc = $this->hvcc( 0x01, 0x60000000, [ 0xB0, 0, 0, 0, 0, 0 ], 93 );
		$this->assertNull( $this->mp4Codecs( 'hvc1', 'hvcC', substr( $hvcc, 0, 12 ) ) );
		$this->assertNull( $this->mp4Codecs( 'hvc1', 'hvcC', "\x02" . substr( $hvcc, 1 ) ) );
		$this->assertNull( $this->mp4Codecs( 'avc1', 'avcC', "\x01\x64\x00" ) );
		$this->assertNull( $this->mp4Codecs( 'avc1', 'avcC', "\x00\x64\x00\x1F" ) );
	}

	public function test_avc_keeps_the_entry_type_the_file_declares(): void {
		$this->assertSame( 'avc1.64001F', $this->mp4Codecs( 'avc1', 'avcC', "\x01\x64\x00\x1F" ) );
		$this->assertSame( 'avc3.640028', $this->mp4Codecs( 'avc3', 'avcC', "\x01\x64\x00\x28" ) );
	}

	public function test_mp4_entry_without_its_configuration_box_returns_null(): void {
		$this->assertNull( $this->mp4Codecs( 'avc1', 'free', "\x01\x64\x00\x1F" ) );
		$this->assertNull( $this->mp4Codecs( 'hvc1', 'avcC', "\x01\x64\x00\x1F" ) );
	}

	public function test_mp4_box_scan_is_bounded(): void {
		$moov = $this->box( 'moov', '' );
		$filler = str_repeat( $this->box( 'free', '' ), 300 );
		$path = $this->tempFile();
		file_put_contents( $path, $filler . $moov );

		$this->assertNull( VideoCodecs::codecsString( $path ) );
	}

	public function test_mp4_sample_entry_claiming_more_bytes_than_the_file_has_returns_null(): void {
		$entry = pack( 'N', 0x7FFFFFFF ) . 'avc1' . str_repeat( "\0", 78 );
		$stsd = $this->box( 'stsd', "\0\0\0\0" . pack( 'N', 1 ) . $entry );
		$path = $this->tempFile();
		file_put_contents( $path, $this->box( 'moov', $this->box( 'trak', $this->box( 'mdia', $this->box( 'minf', $this->box( 'stbl', $stsd ) ) ) ) ) );

		$this->assertNull( VideoCodecs::codecsString( $path ) );
	}

	public function test_webm_vp9_reads_profile_level_and_bit_depth_from_codec_private(): void {
		$private = "\x01\x01\x00" . "\x02\x01\x1F" . "\x03\x01\x08" . "\x04\x01\x01";
		$this->assertSame( 'vp09.00.31.08', $this->webmCodecs( 'V_VP9', $private ) );
		$this->assertSame( 'vp09.02.41.10', $this->webmCodecs( 'V_VP9', "\x01\x01\x02\x02\x01\x29\x03\x01\x0A" ) );
	}

	public function test_webm_vp9_falls_back_to_the_short_form_when_it_cannot_derive_the_rest(): void {
		$this->assertSame( 'vp9', $this->webmCodecs( 'V_VP9', '' ) );
		// Level 0 means unknown.
		$this->assertSame( 'vp9', $this->webmCodecs( 'V_VP9', "\x01\x01\x00\x02\x01\x00\x03\x01\x08" ) );
		// Bit depth missing.
		$this->assertSame( 'vp9', $this->webmCodecs( 'V_VP9', "\x01\x01\x00\x02\x01\x1F" ) );
		// Wrong feature length.
		$this->assertSame( 'vp9', $this->webmCodecs( 'V_VP9', "\x01\x02\x00\x00\x02\x01\x1F" ) );
		// Impossible values.
		$this->assertSame( 'vp9', $this->webmCodecs( 'V_VP9', "\x01\x01\x09\x02\x01\x1F\x03\x01\x08" ) );
		$this->assertSame( 'vp9', $this->webmCodecs( 'V_VP9', "\x01\x01\x00\x02\x01\x1F\x03\x01\x09" ) );
	}

	public function test_webm_av1_needs_a_valid_av1c_in_codec_private(): void {
		$this->assertSame( 'av01.0.04M.10', $this->webmCodecs( 'V_AV1', "\x81\x04\x4C\x00" ) );
		$this->assertNull( $this->webmCodecs( 'V_AV1', '' ) );
		$this->assertNull( $this->webmCodecs( 'V_AV1', "\x81\x04\x4C" ) );
		$this->assertNull( $this->webmCodecs( 'V_AV1', "\x01\x04\x4C\x00" ) );
	}

	public function test_webm_other_codecs_and_non_video_tracks_return_null(): void {
		$this->assertSame( 'vp8', $this->webmCodecs( 'V_VP8', '' ) );
		$this->assertNull( $this->webmCodecs( 'V_THEORA', '' ) );
		$this->assertNull( $this->webmCodecs( 'A_OPUS', '', 2 ) );
		$this->assertNull( $this->webmCodecs( 'V_VP8', '', 2 ) );
	}

	public function test_webm_with_unknown_size_segment_is_read(): void {
		$tracks = $this->webmTracks( 'V_VP8', '', 1 );
		$path = $this->tempFile();
		file_put_contents( $path, $this->ebml( 0x1A45DFA3, '' ) . "\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF" . $tracks );

		$this->assertSame( 'vp8', VideoCodecs::codecsString( $path ) );
	}

	public function test_webm_stops_at_the_first_cluster(): void {
		$path = $this->tempFile();
		file_put_contents(
			$path,
			$this->ebml( 0x1A45DFA3, '' ) . $this->ebml( 0x18538067, $this->ebml( 0x1F43B675, '' ) . $this->webmTracks( 'V_VP8', '', 1 ) )
		);

		$this->assertNull( VideoCodecs::codecsString( $path ) );
	}

	public function test_webm_malformed_structure_returns_null(): void {
		$path = $this->tempFile();
		$magic = pack( 'N', 0x1A45DFA3 );
		$cases = [
			'magic only' => $magic,
			'zero size byte' => $magic . "\x00",
			'element larger than the file' => $magic . "\x80" . "\x18\x53\x80\x67\x4F\xFF\xFF\xFF",
			'id longer than four bytes' => $magic . "\x80" . "\x08\x00\x00\x00\x00\x00\x00\x00\x00",
			'no segment' => $this->ebml( 0x1A45DFA3, '' ) . $this->ebml( 0xEC, str_repeat( "\0", 32 ) ),
			'empty segment' => $this->ebml( 0x1A45DFA3, '' ) . $this->ebml( 0x18538067, '' ),
			'tracks without entries' => $this->ebml( 0x1A45DFA3, '' ) . $this->ebml( 0x18538067, $this->ebml( 0x1654AE6B, '' ) ),
			'random bytes after magic' => $magic . str_repeat( "\xFF", 200 ),
		];

		$this->failOnPhpErrors( function () use ( $cases, $path ): void {
			foreach ( $cases as $name => $bytes ) {
				file_put_contents( $path, $bytes );
				$this->assertNull( VideoCodecs::codecsString( $path ), $name );
			}
		} );
	}

	public function test_webm_element_scan_is_bounded(): void {
		$void = str_repeat( $this->ebml( 0xEC, '' ), 200 );
		$path = $this->tempFile();
		file_put_contents(
			$path,
			$this->ebml( 0x1A45DFA3, '' ) . $this->ebml( 0x18538067, $void . $this->webmTracks( 'V_VP8', '', 1 ) )
		);

		$this->assertNull( VideoCodecs::codecsString( $path ) );
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

	/** @var list<string> */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $file ) {
			@unlink( $file );
		}
		parent::tearDown();
	}

	private function tempFile(): string {
		$file = tempnam( sys_get_temp_dir(), 'tk-video-' );
		$this->tempFiles[] = $file;

		return $file;
	}

	private function failOnPhpErrors( callable $callback ): void {
		$previous = error_reporting( E_ALL );
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			throw new \RuntimeException( "Unexpected PHP error: $errstr" );
		} );

		try {
			$callback();
		} finally {
			restore_error_handler();
			error_reporting( $previous );
		}
	}

	private function box( string $type, string $payload ): string {
		return pack( 'N', 8 + strlen( $payload ) ) . $type . $payload;
	}

	/**
	 * @param list<int> $constraints Six constraint indicator bytes.
	 */
	private function hvcc( int $space_tier_profile, int $compat, array $constraints, int $level ): string {
		return "\x01" . chr( $space_tier_profile ) . pack( 'N', $compat ) . implode( '', array_map( 'chr', $constraints ) ) . chr( $level );
	}

	/**
	 * Build a one-track MP4 with one visual sample entry and return the parsed codecs.
	 */
	private function mp4Codecs( string $entry_type, string $config_type, string $config ): ?string {
		$entry = $this->box( $entry_type, str_repeat( "\0", 78 ) . $this->box( $config_type, $config ) );
		$stsd = $this->box( 'stsd', "\0\0\0\0" . pack( 'N', 1 ) . $entry );
		$moov = $this->box( 'moov', $this->box( 'trak', $this->box( 'mdia', $this->box( 'minf', $this->box( 'stbl', $stsd ) ) ) ) );
		$path = $this->tempFile();
		file_put_contents( $path, $this->box( 'ftyp', 'isom' . "\0\0\0\0" ) . $moov );

		return VideoCodecs::codecsString( $path );
	}

	private function ebml( int $id, string $payload ): string {
		$id_bytes = ltrim( pack( 'N', $id ), "\0" );
		$size = strlen( $payload );
		for ( $length = 1; $length <= 8; $length++ ) {
			if ( $size < ( 1 << ( 7 * $length ) ) - 1 ) {
				$marked = $size | ( 1 << ( 7 * $length ) );
				return $id_bytes . substr( pack( 'J', $marked ), 8 - $length ) . $payload;
			}
		}

		throw new \LogicException( 'Payload too large.' );
	}

	private function webmTracks( string $codec_id, string $codec_private, int $track_type ): string {
		$entry = $this->ebml( 0xD7, "\x01" ) . $this->ebml( 0x83, chr( $track_type ) ) . $this->ebml( 0x86, $codec_id );
		if ( '' !== $codec_private ) {
			$entry .= $this->ebml( 0x63A2, $codec_private );
		}

		return $this->ebml( 0x1654AE6B, $this->ebml( 0xAE, $entry ) );
	}

	private function webmCodecs( string $codec_id, string $codec_private, int $track_type = 1 ): ?string {
		$path = $this->tempFile();
		file_put_contents(
			$path,
			$this->ebml( 0x1A45DFA3, $this->ebml( 0x4282, 'webm' ) ) . $this->ebml( 0x18538067, $this->webmTracks( $codec_id, $codec_private, $track_type ) )
		);

		return VideoCodecs::codecsString( $path );
	}
}
