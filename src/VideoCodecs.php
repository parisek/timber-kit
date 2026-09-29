<?php

declare(strict_types=1);

/**
 * Video codec sniffing helpers.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

/**
 * Derives bare RFC 6381 codecs strings from local media files.
 */
class VideoCodecs {

	private const AV1_CONFIGURATION_BOX_BYTES = 4;
	private const AVC_CONFIGURATION_BOX_BYTES = 4;
	private const HEVC_CONFIGURATION_BOX_BYTES = 13;

	/** Bytes of a visual sample entry that precede its child boxes. */
	private const VISUAL_SAMPLE_ENTRY_BYTES = 78;

	/** Upper bounds. A parser that reads untrusted uploads needs a ceiling on every loop. */
	private const MAX_BOXES_PER_LEVEL = 256;
	private const MAX_TRACKS = 16;
	private const MAX_SAMPLE_ENTRIES = 8;
	private const MAX_EBML_ELEMENTS_PER_LEVEL = 128;
	private const MAX_EBML_TRACK_ENTRIES = 16;
	private const MAX_EBML_READ_BYTES = 64;

	private const EBML_ID_HEADER = 0x1A45DFA3;
	private const EBML_ID_SEGMENT = 0x18538067;
	private const EBML_ID_TRACKS = 0x1654AE6B;
	private const EBML_ID_CLUSTER = 0x1F43B675;
	private const EBML_ID_TRACK_ENTRY = 0xAE;
	private const EBML_ID_TRACK_TYPE = 0x83;
	private const EBML_ID_CODEC_ID = 0x86;
	private const EBML_ID_CODEC_PRIVATE = 0x63A2;

	/**
	 * Parse a local video file and return its bare codecs string when known.
	 *
	 * Covers MP4 (ISO BMFF) and WebM (Matroska). The value is the RFC 6381
	 * string of the first video track:
	 *
	 * - MP4 H.264: `avc1.640028` (from `avcC`; the entry type `avc1`..`avc4`
	 *   is kept as the file declares it).
	 * - MP4 HEVC: `hvc1.1.6.L93.B0` (from `hvcC`; `hvc1` or `hev1` as declared).
	 * - MP4 AV1: `av01.0.01M.08` (from `av1C`).
	 * - WebM AV1: the same `av01` string, from the `av1C` in `CodecPrivate`.
	 * - WebM VP9: `vp09.00.10.08` when `CodecPrivate` carries profile, level
	 *   and bit depth. Otherwise the short form `vp9`, which browsers accept.
	 *   The short form does not name the profile. A browser without support
	 *   for VP9 profile 2 (10-bit) can then claim it plays the file.
	 * - WebM VP8: `vp8`.
	 *
	 * The value names the video codec only. It omits the audio codec.
	 * The parser returns null when it cannot derive the value exactly:
	 * other codecs, an `av1C` that is missing, a truncated or malformed
	 * file, and unreadable input. Every read is bounded, so a hostile file
	 * costs a fixed amount of work.
	 *
	 * Callers compose the `<source type>` attribute themselves:
	 * `video/mp4; codecs="<value>"`.
	 */
	public static function codecsString( string $path ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return null;
		}

		try {
			$stat = fstat( $handle );
			if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || ! is_int( $stat['size'] ) || $stat['size'] < 8 ) {
				return null;
			}

			$magic = self::readAt( $handle, 0, 4 );
			if ( null !== $magic && self::EBML_ID_HEADER === self::uint32( $magic ) ) {
				return self::parseWebm( $handle, $stat['size'] );
			}

			$moov = self::findBox( $handle, 0, $stat['size'], 'moov' );
			if ( null === $moov ) {
				return null;
			}

			$trak = self::findBox( $handle, $moov['content_start'], $moov['end'], 'trak' );
			for ( $tracks = 0; null !== $trak && $tracks < self::MAX_TRACKS; $tracks++ ) {
				$mdia = self::findBox( $handle, $trak['content_start'], $trak['end'], 'mdia' );
				$minf = null !== $mdia ? self::findBox( $handle, $mdia['content_start'], $mdia['end'], 'minf' ) : null;
				$stbl = null !== $minf ? self::findBox( $handle, $minf['content_start'], $minf['end'], 'stbl' ) : null;
				$stsd = null !== $stbl ? self::findBox( $handle, $stbl['content_start'], $stbl['end'], 'stsd' ) : null;
				if ( null !== $stsd ) {
					$source_type = self::parseSampleDescriptionBox( $handle, $stsd['content_start'], $stsd['end'] );
					if ( null !== $source_type ) {
						return $source_type;
					}
				}

				$trak = self::findBox( $handle, $trak['end'], $moov['end'], 'trak' );
			}
		} finally {
			fclose( $handle );
		}

		return null;
	}

	/**
	 * @param resource $handle
	 */
	private static function parseSampleDescriptionBox( $handle, int $start, int $end ): ?string {
		if ( $start + 8 > $end ) {
			return null;
		}

		$entry_count_bytes = self::readAt( $handle, $start + 4, 4 );
		if ( null === $entry_count_bytes ) {
			return null;
		}

		$entry_count = self::uint32( $entry_count_bytes );
		$offset = $start + 8;

		$entry_count = min( $entry_count, self::MAX_SAMPLE_ENTRIES );

		for ( $i = 0; $i < $entry_count && $offset + 8 <= $end; $i++ ) {
			$entry = self::readBoxHeader( $handle, $offset, $end );
			if ( null === $entry ) {
				return null;
			}

			if ( 'av01' === $entry['type'] ) {
				return self::parseAv1SampleEntry( $handle, $entry['content_start'], $entry['end'] );
			}

			if ( in_array( $entry['type'], [ 'avc1', 'avc2', 'avc3', 'avc4' ], true ) ) {
				return self::parseAvcSampleEntry( $handle, $entry['type'], $entry['content_start'], $entry['end'] );
			}

			if ( in_array( $entry['type'], [ 'hvc1', 'hev1' ], true ) ) {
				return self::parseHevcSampleEntry( $handle, $entry['type'], $entry['content_start'], $entry['end'] );
			}

			$offset = $entry['end'];
		}

		return null;
	}

	/**
	 * Read the first bytes of a configuration box inside a visual sample entry.
	 *
	 * @param resource $handle
	 * @return list<int>|null Byte values, or null when the box is absent or too short.
	 */
	private static function readConfigurationBox( $handle, int $start, int $end, string $box_type, int $length ): ?array {
		$search_start = $start + self::VISUAL_SAMPLE_ENTRY_BYTES;
		if ( $search_start > $end ) {
			return null;
		}

		$box = self::findBox( $handle, $search_start, $end, $box_type );
		if ( null === $box || $box['content_start'] + $length > $box['end'] ) {
			return null;
		}

		$config = self::readAt( $handle, $box['content_start'], $length );
		if ( null === $config ) {
			return null;
		}

		$bytes = array_values( unpack( 'C*', $config ) ?: [] );

		return count( $bytes ) === $length ? $bytes : null;
	}

	/**
	 * @param resource $handle
	 */
	private static function parseAv1SampleEntry( $handle, int $start, int $end ): ?string {
		$bytes = self::readConfigurationBox( $handle, $start, $end, 'av1C', self::AV1_CONFIGURATION_BOX_BYTES );

		return null === $bytes ? null : self::av1CodecsFromConfiguration( $bytes );
	}

	/**
	 * H.264: `<entry type>.<profile><compatibility><level>`, three hex bytes.
	 *
	 * @param resource $handle
	 */
	private static function parseAvcSampleEntry( $handle, string $entry_type, int $start, int $end ): ?string {
		$bytes = self::readConfigurationBox( $handle, $start, $end, 'avcC', self::AVC_CONFIGURATION_BOX_BYTES );
		if ( null === $bytes || 1 !== $bytes[0] ) {
			return null;
		}

		return sprintf( '%s.%02X%02X%02X', $entry_type, $bytes[1], $bytes[2], $bytes[3] );
	}

	/**
	 * HEVC, per ISO/IEC 14496-15 Annex E:
	 * `<entry type>.<space+profile>.<compat flags>.<tier+level>[.<constraint bytes>]`.
	 *
	 * @param resource $handle
	 */
	private static function parseHevcSampleEntry( $handle, string $entry_type, int $start, int $end ): ?string {
		$bytes = self::readConfigurationBox( $handle, $start, $end, 'hvcC', self::HEVC_CONFIGURATION_BOX_BYTES );
		if ( null === $bytes || 1 !== $bytes[0] ) {
			return null;
		}

		$profile_space = ( $bytes[1] >> 6 ) & 0b11;
		$tier_high = ( $bytes[1] & 0b00100000 ) !== 0;
		$profile_idc = $bytes[1] & 0b00011111;

		$compat = ( $bytes[2] << 24 ) | ( $bytes[3] << 16 ) | ( $bytes[4] << 8 ) | $bytes[5];
		$compat_reversed = 0;
		for ( $bit = 0; $bit < 32; $bit++ ) {
			if ( ( $compat >> $bit ) & 1 ) {
				$compat_reversed |= 1 << ( 31 - $bit );
			}
		}

		// Six constraint bytes; trailing zero bytes are omitted.
		$constraints = array_slice( $bytes, 6, 6 );
		while ( [] !== $constraints && 0 === end( $constraints ) ) {
			array_pop( $constraints );
		}

		$parts = [
			$entry_type,
			( [ '', 'A', 'B', 'C' ][ $profile_space ] ) . $profile_idc,
			sprintf( '%X', $compat_reversed ),
			( $tier_high ? 'H' : 'L' ) . $bytes[12],
		];
		foreach ( $constraints as $byte ) {
			$parts[] = sprintf( '%02X', $byte );
		}

		return implode( '.', $parts );
	}

	/**
	 * Decode the four fixed bytes of an `av1C` record (ISOBMFF and WebM share it).
	 *
	 * @param list<int> $bytes At least four byte values.
	 */
	private static function av1CodecsFromConfiguration( array $bytes ): string {
		$seq_profile = ( $bytes[1] & 0b11100000 ) >> 5;
		$seq_level_idx_0 = $bytes[1] & 0b00011111;
		$seq_tier_0 = ( $bytes[2] & 0b10000000 ) !== 0;
		$high_bitdepth = ( $bytes[2] & 0b01000000 ) !== 0;
		$twelve_bit = ( $bytes[2] & 0b00100000 ) !== 0;
		$depth = ! $high_bitdepth ? '08' : ( $twelve_bit ? '12' : '10' );

		return sprintf(
			'av01.%d.%02d%s.%s',
			$seq_profile,
			$seq_level_idx_0,
			$seq_tier_0 ? 'H' : 'M',
			$depth
		);
	}

	/**
	 * Find the first video track in a WebM file and derive its codecs string.
	 *
	 * Walks EBML -> Segment -> Tracks -> TrackEntry. It stops at the first
	 * Cluster, because the Tracks element always precedes the media data.
	 *
	 * @param resource $handle
	 */
	private static function parseWebm( $handle, int $size ): ?string {
		$offset = 0;
		$segment = null;
		for ( $i = 0; $i < self::MAX_EBML_ELEMENTS_PER_LEVEL && $offset < $size; $i++ ) {
			$element = self::readEbmlElement( $handle, $offset, $size );
			if ( null === $element ) {
				return null;
			}
			if ( self::EBML_ID_SEGMENT === $element['id'] ) {
				$segment = $element;
				break;
			}
			$offset = $element['end'];
		}
		if ( null === $segment ) {
			return null;
		}

		$offset = $segment['start'];
		for ( $i = 0; $i < self::MAX_EBML_ELEMENTS_PER_LEVEL && $offset < $segment['end']; $i++ ) {
			$element = self::readEbmlElement( $handle, $offset, $segment['end'] );
			if ( null === $element || self::EBML_ID_CLUSTER === $element['id'] ) {
				return null;
			}
			if ( self::EBML_ID_TRACKS === $element['id'] ) {
				return self::parseWebmTracks( $handle, $element['start'], $element['end'] );
			}
			$offset = $element['end'];
		}

		return null;
	}

	/**
	 * @param resource $handle
	 */
	private static function parseWebmTracks( $handle, int $start, int $end ): ?string {
		$offset = $start;
		$entries = 0;
		for ( $i = 0; $i < self::MAX_EBML_ELEMENTS_PER_LEVEL && $offset < $end; $i++ ) {
			$element = self::readEbmlElement( $handle, $offset, $end );
			if ( null === $element ) {
				return null;
			}
			$offset = $element['end'];
			if ( self::EBML_ID_TRACK_ENTRY !== $element['id'] ) {
				continue;
			}
			if ( ++$entries > self::MAX_EBML_TRACK_ENTRIES ) {
				return null;
			}

			$track = self::readWebmTrackEntry( $handle, $element['start'], $element['end'] );
			if ( null !== $track && str_starts_with( $track['codec_id'], 'V_' ) && ( null === $track['type'] || 1 === $track['type'] ) ) {
				return self::webmCodecs( $track['codec_id'], $track['codec_private'] );
			}
		}

		return null;
	}

	/**
	 * @param resource $handle
	 * @return array{type: int|null, codec_id: string, codec_private: string}|null
	 */
	private static function readWebmTrackEntry( $handle, int $start, int $end ): ?array {
		$type = null;
		$codec_id = '';
		$codec_private = '';
		$offset = $start;

		for ( $i = 0; $i < self::MAX_EBML_ELEMENTS_PER_LEVEL && $offset < $end; $i++ ) {
			$element = self::readEbmlElement( $handle, $offset, $end );
			if ( null === $element ) {
				return null;
			}
			$offset = $element['end'];
			$length = min( $element['end'] - $element['start'], self::MAX_EBML_READ_BYTES );

			if ( self::EBML_ID_TRACK_TYPE === $element['id'] && $length >= 1 && $length <= 8 ) {
				$raw = self::readAt( $handle, $element['start'], $length );
				$type = null === $raw ? null : (int) hexdec( bin2hex( $raw ) );
			} elseif ( self::EBML_ID_CODEC_ID === $element['id'] ) {
				$raw = self::readAt( $handle, $element['start'], $length );
				$codec_id = null === $raw ? '' : rtrim( $raw, "\0" );
			} elseif ( self::EBML_ID_CODEC_PRIVATE === $element['id'] ) {
				$raw = self::readAt( $handle, $element['start'], $length );
				$codec_private = $raw ?? '';
			}
		}

		return '' === $codec_id ? null : [
			'type' => $type,
			'codec_id' => $codec_id,
			'codec_private' => $codec_private,
		];
	}

	private static function webmCodecs( string $codec_id, string $codec_private ): ?string {
		if ( 'V_VP8' === $codec_id ) {
			return 'vp8';
		}

		if ( 'V_VP9' === $codec_id ) {
			return self::vp9CodecsFromPrivate( $codec_private ) ?? 'vp9';
		}

		if ( 'V_AV1' === $codec_id ) {
			$bytes = array_values( unpack( 'C*', substr( $codec_private, 0, self::AV1_CONFIGURATION_BOX_BYTES ) ) ?: [] );
			// Marker bit set and version 1: the only record layout that exists.
			if ( count( $bytes ) < self::AV1_CONFIGURATION_BOX_BYTES || 0x81 !== $bytes[0] ) {
				return null;
			}

			return self::av1CodecsFromConfiguration( $bytes );
		}

		return null;
	}

	/**
	 * Read the VP9 `CodecPrivate` feature list: repeated (id, length, value)
	 * bytes with id 1 profile, 2 level, 3 bit depth. Level 0 means unknown.
	 */
	private static function vp9CodecsFromPrivate( string $private ): ?string {
		$features = [];
		$length = strlen( $private );
		for ( $i = 0; $i + 3 <= $length; $i += 3 ) {
			if ( 1 !== ord( $private[ $i + 1 ] ) ) {
				return null;
			}
			$features[ ord( $private[ $i ] ) ] = ord( $private[ $i + 2 ] );
		}

		if ( ! isset( $features[1], $features[2], $features[3] ) || $features[1] > 3 || 0 === $features[2] || ! in_array( $features[3], [ 8, 10, 12 ], true ) ) {
			return null;
		}

		return sprintf( 'vp09.%02d.%02d.%02d', $features[1], $features[2], $features[3] );
	}

	/**
	 * Read one EBML element header.
	 *
	 * Returns the element ID with its length marker kept (the usual notation,
	 * `0x1A45DFA3`), and the data range. An unknown size (all ones) extends to
	 * the parent's end. Returns null when the header is invalid or the element
	 * does not fit inside its parent.
	 *
	 * @param resource $handle
	 * @return array{id: int, start: int, end: int}|null
	 */
	private static function readEbmlElement( $handle, int $offset, int $parent_end ): ?array {
		$id_length = self::ebmlLength( $handle, $offset );
		if ( null === $id_length || $id_length > 4 ) {
			return null;
		}

		$id_bytes = self::readAt( $handle, $offset, $id_length );
		$size_offset = $offset + $id_length;
		$size_length = self::ebmlLength( $handle, $size_offset );
		$size_bytes = null === $size_length ? null : self::readAt( $handle, $size_offset, $size_length );
		if ( null === $id_bytes || null === $size_length || null === $size_bytes ) {
			return null;
		}

		$id = (int) hexdec( bin2hex( $id_bytes ) );
		$value = ord( $size_bytes[0] ) & ( 0xFF >> $size_length );
		for ( $i = 1; $i < $size_length; $i++ ) {
			$value = ( $value << 8 ) | ord( $size_bytes[ $i ] );
		}

		$start = $size_offset + $size_length;
		$unknown = $value === ( 1 << ( 7 * $size_length ) ) - 1;
		$end = $unknown ? $parent_end : $start + $value;
		if ( $start > $parent_end || $end > $parent_end || $end < $start ) {
			return null;
		}

		return [
			'id' => $id,
			'start' => $start,
			'end' => $end,
		];
	}

	/**
	 * Length in bytes (1 to 8) of the EBML variable-length integer at an offset.
	 *
	 * @param resource $handle
	 */
	private static function ebmlLength( $handle, int $offset ): ?int {
		$byte = self::readAt( $handle, $offset, 1 );
		if ( null === $byte || 0 === ord( $byte ) ) {
			return null;
		}

		$length = 1;
		for ( $mask = 0x80; 0 === ( ord( $byte ) & $mask ); $mask >>= 1 ) {
			$length++;
		}

		return $length;
	}

	/**
	 * @param resource $handle
	 * @return array{content_start: int, end: int}|null
	 */
	private static function findBox( $handle, int $start, int $end, string $type ): ?array {
		$offset = $start;
		for ( $i = 0; $i < self::MAX_BOXES_PER_LEVEL && $offset + 8 <= $end; $i++ ) {
			$box = self::readBoxHeader( $handle, $offset, $end );
			if ( null === $box ) {
				return null;
			}

			if ( $type === $box['type'] ) {
				return [
					'content_start' => $box['content_start'],
					'end' => $box['end'],
				];
			}

			if ( $box['end'] <= $offset ) {
				return null;
			}
			$offset = $box['end'];
		}

		return null;
	}

	/**
	 * @param resource $handle
	 * @return array{type: string, content_start: int, end: int}|null
	 */
	private static function readBoxHeader( $handle, int $offset, int $parent_end ): ?array {
		if ( $offset + 8 > $parent_end ) {
			return null;
		}

		$header = self::readAt( $handle, $offset, 8 );
		if ( null === $header ) {
			return null;
		}

		$size = self::uint32( substr( $header, 0, 4 ) );
		$type = substr( $header, 4, 4 );
		$header_size = 8;

		if ( 1 === $size ) {
			$large_size_bytes = self::readAt( $handle, $offset + 8, 8 );
			if ( null === $large_size_bytes ) {
				return null;
			}
			$large_size = self::uint64( $large_size_bytes );
			if ( null === $large_size ) {
				return null;
			}
			$size = $large_size;
			$header_size = 16;
		} elseif ( 0 === $size ) {
			$size = $parent_end - $offset;
		}

		if ( 'uuid' === $type ) {
			$header_size += 16;
		}

		if ( $size < $header_size ) {
			return null;
		}

		$box_end = $offset + $size;
		if ( $box_end > $parent_end || $box_end < $offset ) {
			return null;
		}

		return [
			'type' => $type,
			'content_start' => $offset + $header_size,
			'end' => $box_end,
		];
	}

	/**
	 * @param resource $handle
	 */
	private static function readAt( $handle, int $offset, int $length ): ?string {
		if ( $length < 0 || $offset < 0 ) {
			return null;
		}

		if ( 0 !== fseek( $handle, $offset ) ) {
			return null;
		}

		$data = '';
		while ( strlen( $data ) < $length && ! feof( $handle ) ) {
			$remaining = $length - strlen( $data );
			if ( $remaining < 1 ) {
				break;
			}

			$chunk = fread( $handle, $remaining );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}

		return strlen( $data ) === $length ? $data : null;
	}

	private static function uint32( string $bytes ): int {
		$value = unpack( 'N', $bytes );
		return is_array( $value ) ? (int) $value[1] : 0;
	}

	private static function uint64( string $bytes ): ?int {
		$parts = unpack( 'Nhigh/Nlow', $bytes );
		if ( ! is_array( $parts ) || ! isset( $parts['high'], $parts['low'] ) ) {
			return null;
		}

		if ( $parts['high'] > intdiv( PHP_INT_MAX, 4294967296 ) ) {
			return null;
		}

		return (int) ( $parts['high'] * 4294967296 + $parts['low'] );
	}
}
