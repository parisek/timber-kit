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

	/**
	 * Parse a local video file and return its bare codecs string when known.
	 *
	 * Returns e.g. `av01.0.01M.08` for AV1-in-MP4; null for anything the
	 * parser doesn't cover (non-AV1 MP4, WebM, unreadable/garbage input).
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

			$moov = self::findBox( $handle, 0, $stat['size'], 'moov' );
			if ( null === $moov ) {
				return null;
			}

			$trak = self::findBox( $handle, $moov['content_start'], $moov['end'], 'trak' );
			while ( null !== $trak ) {
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

		for ( $i = 0; $i < $entry_count && $offset + 8 <= $end; $i++ ) {
			$entry = self::readBoxHeader( $handle, $offset, $end );
			if ( null === $entry ) {
				return null;
			}

			if ( 'av01' === $entry['type'] ) {
				return self::parseAv1SampleEntry( $handle, $entry['content_start'], $entry['end'] );
			}

			$offset = $entry['end'];
		}

		return null;
	}

	/**
	 * @param resource $handle
	 */
	private static function parseAv1SampleEntry( $handle, int $start, int $end ): ?string {
		$av1c_search_start = $start + 78;
		if ( $av1c_search_start > $end ) {
			return null;
		}

		$av1c = self::findBox( $handle, $av1c_search_start, $end, 'av1C' );
		if ( null === $av1c || $av1c['content_start'] + self::AV1_CONFIGURATION_BOX_BYTES > $av1c['end'] ) {
			return null;
		}

		$config = self::readAt( $handle, $av1c['content_start'], self::AV1_CONFIGURATION_BOX_BYTES );
		if ( null === $config ) {
			return null;
		}

		$bytes = array_values( unpack( 'C*', $config ) ?: [] );
		if ( count( $bytes ) < self::AV1_CONFIGURATION_BOX_BYTES ) {
			return null;
		}

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
	 * @param resource $handle
	 * @return array{content_start: int, end: int}|null
	 */
	private static function findBox( $handle, int $start, int $end, string $type ): ?array {
		$offset = $start;
		while ( $offset + 8 <= $end ) {
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
