<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Gives SVG attachments the intrinsic `width`/`height` WordPress cannot measure
 * for them, so an `<img>` reserves its box instead of shifting the layout.
 *
 * `getimagesize()` does not parse SVG, so core's `wp_generate_attachment_metadata()`
 * stores no dimensions at all for `image/svg+xml`. The `svg-support` plugin fills
 * part of the gap, but its reader takes only the `width` and `height` attributes
 * on the root element — an SVG exported with just a `viewBox` (the current Figma
 * and Illustrator default) yields an empty string, which it stores as `0`.
 *
 * ## Refusing is a correct answer
 *
 * Every ambiguity resolves to `null` rather than to a guess. A wrong number is
 * worse than no number here: it is written to the database, it survives the
 * package version, and a later sweep without `--force` reads it back as
 * authoritative. So an encoding this cannot decode, a prolog it cannot walk, a
 * root tag past the read limit, a unit it cannot convert — all return `null`,
 * and the attachment is simply reported as unreadable.
 *
 * ## Coexisting with other plugins
 *
 * - Dimensions go in `_wp_attachment_metadata`'s own `width`/`height` keys —
 *   core's structure, the one `wp_get_attachment_image_src()` reads — so the
 *   answer is shared with every consumer rather than hidden in a private key.
 * - Each axis is considered **separately**, and a valid stored value is never
 *   replaced. Filling a missing height must not disturb a width another plugin
 *   already resolved.
 * - A stored `0` counts as missing: it is `intval( '' )` from a reader that
 *   found nothing, not a size anything can lay out.
 *
 * ## What `viewBox` is allowed to mean
 *
 * W3C defines `viewBox` as a source of intrinsic **aspect ratio**, not of
 * intrinsic width and height. Writing its coordinates as pixels is therefore a
 * deliberate policy, not a measurement, and it is recorded as one: for an image
 * whose box comes from CSS — which is every call site this exists for — the
 * ratio is the whole point, and it is also what `svg-support` intends. The cost
 * is that an `<img>` with no CSS sizing renders at these numbers instead of the
 * SVG's own default size. An explicit `width`/`height` pair always wins over
 * `viewBox`, and a single explicit axis is combined with the ratio rather than
 * discarded.
 */
class SvgDimensions {

	/**
	 * How far into a file the root start tag may begin and end.
	 *
	 * Generous next to real exports (the largest root tag in a 3520-file library
	 * is under 1 kB, and Illustrator's DOCTYPE subset adds a few hundred bytes),
	 * while keeping a hostile or corrupt file from being read into memory whole.
	 * Reaching the limit returns null — see the class note on refusing.
	 */
	private const MAX_PROLOG_BYTES = 65536;

	/**
	 * CSS absolute length units, in pixels. Relative units (`em`, `ex`, `%`) are
	 * deliberately absent: they depend on a context an attachment does not have,
	 * so they are not intrinsic dimensions and fall through to `viewBox`.
	 *
	 * @var array<string, float>
	 */
	private const UNITS = [
		'px' => 1.0,
		'pt' => 96.0 / 72.0,
		'pc' => 16.0,
		'in' => 96.0,
		'cm' => 96.0 / 2.54,
		'mm' => 96.0 / 25.4,
		'q'  => 96.0 / 101.6,
	];

	/**
	 * Reads the intrinsic size out of SVG markup.
	 *
	 * @param string $markup Raw SVG document, or enough of its head to contain
	 *                       the root start tag.
	 * @return array{width: int, height: int}|null Null when the markup carries no
	 *         usable size, or when anything about it is ambiguous.
	 */
	public static function fromMarkup( string $markup ): ?array {
		$markup = self::toUtf8( $markup );

		if ( null === $markup ) {
			return null;
		}

		$tag = self::rootStartTag( $markup );

		if ( null === $tag ) {
			return null;
		}

		$element = self::parseTag( $tag );

		if ( null === $element ) {
			return null;
		}

		$attributes = $element->attributes();

		if ( null === $attributes ) {
			return null;
		}

		$width  = self::absoluteLength( (string) ( $attributes->width ?? '' ) );
		$height = self::absoluteLength( (string) ( $attributes->height ?? '' ) );

		if ( null !== $width && null !== $height ) {
			return self::sane( $width, $height );
		}

		$ratio = self::viewBoxRatio( (string) ( $attributes->viewBox ?? '' ) );

		// One explicit axis plus the ratio beats the ratio alone: the author
		// stated that axis, and discarding it in favour of viewBox coordinates
		// would report a size the file does not claim.
		if ( null !== $ratio ) {
			if ( null !== $width ) {
				return self::sane( $width, (int) round( $width / $ratio['aspect'] ) );
			}

			if ( null !== $height ) {
				return self::sane( (int) round( $height * $ratio['aspect'] ), $height );
			}

			return self::sane( $ratio['width'], $ratio['height'] );
		}

		return null;
	}

	/**
	 * Reads the intrinsic size out of an SVG file.
	 *
	 * Reads incrementally and stops at the root start tag, so a 22 MB file whose
	 * dimensions sit in its first 32 bytes costs one buffer rather than 22 MB of
	 * memory. That is not only an optimisation: parsing whole documents also lost
	 * files to libxml's 10 MB attribute-value limit, tripped by an embedded
	 * `<image href="data:image/png;base64,…">` far below the part being read.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array{width: int, height: int}|null
	 */
	public static function fromFile( string $path ): ?array {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$head = '';

		while ( strlen( $head ) < self::MAX_PROLOG_BYTES && ! feof( $handle ) ) {
			$chunk = fread( $handle, 8192 );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$head .= $chunk;

			$size = self::fromMarkup( $head );

			if ( null !== $size ) {
				fclose( $handle );

				return $size;
			}
		}

		fclose( $handle );

		return null;
	}

	/**
	 * Fills in whichever axis an SVG image array is missing, for the read path.
	 *
	 * This is the base behaviour, not an opt-in: a template asking for an image
	 * must get its dimensions whether or not anyone has run the sweep or flipped
	 * the upload flag. Stored metadata is still worth having — the media library,
	 * `wp_get_attachment_image_src()` and srcset all read it and never come
	 * through here — but a template no longer depends on it.
	 *
	 * Reads nothing when both axes are known, when the attachment is not an SVG,
	 * or when there is no ID to resolve a file from. Results are memoised per
	 * request, including failures, so a component repeating one logo across a
	 * marquee opens the file once. Measured on a 3520-file library: 0.06 ms per
	 * distinct file, against 0.38 ms when the whole file was read.
	 *
	 * @param int|null    $id     Attachment ID.
	 * @param string|null $mime   Attachment MIME type.
	 * @param int|null    $width  Width already known, if any.
	 * @param int|null    $height Height already known, if any.
	 * @return array{width: int|null, height: int|null}
	 */
	public static function resolveSvg( ?int $id, ?string $mime, ?int $width, ?int $height ): array {
		if ( ( null !== $width && null !== $height ) || 'image/svg+xml' !== $mime || null === $id ) {
			return [ 'width' => $width, 'height' => $height ];
		}

		// `Helpers` also runs outside WordPress — `parisek/styleguide` boots Timber
		// against the theme's templates with no CMS behind it — so the attachment
		// API is not guaranteed to exist. There is no attachment to resolve there
		// either way; a fixture supplies its own dimensions.
		if ( ! function_exists( 'get_attached_file' ) ) {
			return [ 'width' => $width, 'height' => $height ];
		}

		/** @var array<int, array{width: int, height: int}|null> $memo */
		static $memo = [];

		if ( ! array_key_exists( $id, $memo ) ) {
			$file = get_attached_file( $id );

			$memo[ $id ] = is_string( $file ) ? self::fromFile( $file ) : null;
		}

		$size = $memo[ $id ];

		if ( null === $size ) {
			return [ 'width' => $width, 'height' => $height ];
		}

		return [
			'width'  => $width ?? $size['width'],
			'height' => $height ?? $size['height'],
		];
	}

	/**
	 * Stores the derived dimensions on one attachment.
	 *
	 * Deliberately a batch operation rather than a lazy write during rendering: a
	 * page render must not write to the database, or the healing becomes a
	 * property of who happened to request an uncached page, and stops entirely on
	 * a read-only replica.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $force         Replace stored values instead of only filling gaps.
	 * @param bool $dry_run       Report what would happen without writing.
	 * @return array{status: string, width: int|null, height: int|null} status is one of
	 *         `derived`, `would_derive`, `already_sized`, `unchanged`, `not_svg`,
	 *         `unreadable`, `failed`.
	 */
	public function backfill( int $attachment_id, bool $force = false, bool $dry_run = false ): array {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return [ 'status' => 'not_svg', 'width' => null, 'height' => null ];
		}

		// Unfiltered: `wp_get_attachment_metadata()` runs the stored array through
		// filters, and this method writes what it read back. A plugin that
		// rewrites paths or adds keys on read would have its projection persisted.
		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		$metadata = is_array( $metadata ) ? $metadata : [];

		$stored_width  = self::storedAxis( $metadata, 'width' );
		$stored_height = self::storedAxis( $metadata, 'height' );

		if ( ! $force && null !== $stored_width && null !== $stored_height ) {
			return [ 'status' => 'already_sized', 'width' => $stored_width, 'height' => $stored_height ];
		}

		$file = get_attached_file( $attachment_id );
		$size = is_string( $file ) ? self::fromFile( $file ) : null;

		if ( null === $size ) {
			return [ 'status' => 'unreadable', 'width' => null, 'height' => null ];
		}

		$width  = $force ? $size['width'] : ( $stored_width ?? $size['width'] );
		$height = $force ? $size['height'] : ( $stored_height ?? $size['height'] );

		if ( $dry_run ) {
			return [ 'status' => 'would_derive', 'width' => $width, 'height' => $height ];
		}

		if ( $stored_width === $width && $stored_height === $height ) {
			return [ 'status' => 'unchanged', 'width' => $width, 'height' => $height ];
		}

		$metadata['width']  = $width;
		$metadata['height'] = $height;

		if ( ! wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
			// `wp_update_attachment_metadata()` also returns false when the value
			// did not change, so a false is not proof of failure. Ask the database.
			$after = wp_get_attachment_metadata( $attachment_id, true );

			if ( ! is_array( $after )
				|| self::storedAxis( $after, 'width' ) !== $width
				|| self::storedAxis( $after, 'height' ) !== $height ) {
				return [ 'status' => 'failed', 'width' => $width, 'height' => $height ];
			}
		}

		return [ 'status' => 'derived', 'width' => $width, 'height' => $height ];
	}

	/**
	 * Fills in the dimensions at upload time.
	 *
	 * Hook above priority 10 so this observes what other plugins in this space
	 * wrote rather than racing them.
	 *
	 * @param array<string, mixed> $metadata      Generated attachment metadata.
	 * @param int                  $attachment_id Attachment post ID.
	 * @return array<string, mixed> The metadata, with each missing axis filled independently.
	 */
	public function filterGeneratedMetadata( array $metadata, int $attachment_id ): array {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return $metadata;
		}

		$stored_width  = self::storedAxis( $metadata, 'width' );
		$stored_height = self::storedAxis( $metadata, 'height' );

		if ( null !== $stored_width && null !== $stored_height ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		$size = is_string( $file ) ? self::fromFile( $file ) : null;

		if ( null === $size ) {
			return $metadata;
		}

		$metadata['width']  = $stored_width ?? $size['width'];
		$metadata['height'] = $stored_height ?? $size['height'];

		return $metadata;
	}

	/**
	 * One axis of stored metadata, when it is a usable size.
	 *
	 * `0` and `1` both count as absent. `0` is `intval( '' )` from a reader that
	 * found no attribute; `1` is the bogus value core reports for SVG
	 * ([#26256](https://core.trac.wordpress.org/ticket/26256)), which the read
	 * path in `Helpers` discards anyway. Neither is an answer worth preserving,
	 * so filling over them is not the overwrite the class forbids.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @param string               $axis     `width` or `height`.
	 * @return int|null
	 */
	private static function storedAxis( array $metadata, string $axis ): ?int {
		if ( ! isset( $metadata[ $axis ] ) || ! is_numeric( $metadata[ $axis ] ) ) {
			return null;
		}

		$value = (int) $metadata[ $axis ];

		return $value > 1 ? $value : null;
	}

	/**
	 * Guards a derived pair before anyone can store it.
	 *
	 * `1` is rejected on both axes rather than stored: it is indistinguishable
	 * from the bogus 1px WordPress reports for SVG (core
	 * [#26256](https://core.trac.wordpress.org/ticket/26256)), which the read
	 * path in `Helpers` discards on sight — so storing it would write a number
	 * the rest of the stack refuses to use.
	 *
	 * @param int $width  Derived width.
	 * @param int $height Derived height.
	 * @return array{width: int, height: int}|null
	 */
	private static function sane( int $width, int $height ): ?array {
		if ( $width <= 1 || $height <= 1 ) {
			return null;
		}

		return [ 'width' => $width, 'height' => $height ];
	}

	/**
	 * Normalises markup to UTF-8, or refuses.
	 *
	 * A BOM is authoritative where present. Without one, the bytes are assumed to
	 * be an ASCII-compatible encoding, which every SVG in practice is — and an
	 * XML declaration naming something else is a refusal rather than a guess.
	 *
	 * @param string $markup Raw bytes.
	 * @return string|null Null when the encoding cannot be established or converted.
	 */
	private static function toUtf8( string $markup ): ?string {
		if ( '' === $markup ) {
			return null;
		}

		$boms = [
			"\xEF\xBB\xBF"     => 'UTF-8',
			"\xFF\xFE\x00\x00" => 'UTF-32LE',
			"\x00\x00\xFE\xFF" => 'UTF-32BE',
			"\xFF\xFE"         => 'UTF-16LE',
			"\xFE\xFF"         => 'UTF-16BE',
		];

		foreach ( $boms as $bom => $encoding ) {
			if ( 0 !== strncmp( $markup, $bom, strlen( $bom ) ) ) {
				continue;
			}

			$body = substr( $markup, strlen( $bom ) );

			if ( 'UTF-8' === $encoding ) {
				return $body;
			}

			return self::convert( $body, $encoding );
		}

		// A UTF-16 file without a BOM still announces itself: its ASCII bytes are
		// interleaved with NULs, which no ASCII-compatible encoding produces.
		if ( "\x00" === substr( $markup, 0, 1 ) ) {
			return self::convert( $markup, 'UTF-16BE' );
		}

		if ( strlen( $markup ) > 1 && "\x00" === substr( $markup, 1, 1 ) ) {
			return self::convert( $markup, 'UTF-16LE' );
		}

		return $markup;
	}

	/**
	 * Converts to UTF-8, or refuses when the runtime cannot.
	 *
	 * @param string $body     Raw bytes without a BOM.
	 * @param string $encoding Source encoding name.
	 * @return string|null
	 */
	private static function convert( string $body, string $encoding ): ?string {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return null;
		}

		// A truncated multi-byte tail is expected here: the caller feeds this
		// growing chunks of a file, so the buffer routinely ends mid-character.
		$converted = @mb_convert_encoding( $body, 'UTF-8', $encoding );

		return is_string( $converted ) && '' !== $converted ? $converted : null;
	}

	/**
	 * Extracts the root element's start tag, or refuses.
	 *
	 * Walks the prolog by its own rules — XML declarations and processing
	 * instructions, comments, and a DOCTYPE with an internal subset — instead of
	 * searching for the first `<svg`. That search is what let a `<svg` inside a
	 * processing instruction, an entity declaration or a CDATA section be read as
	 * the root, and the wrong dimensions then get written to the database and
	 * treated as authoritative by the next sweep.
	 *
	 * @param string $markup UTF-8 markup.
	 * @return string|null A parseable `<svg … />`, or null when the root is not
	 *         reached, not an `svg`, or not yet complete in this buffer.
	 */
	private static function rootStartTag( string $markup ): ?string {
		$length = strlen( $markup );
		$pos    = 0;

		while ( $pos < $length ) {
			$pos = self::skipWhitespace( $markup, $pos );

			if ( $pos >= $length ) {
				return null;
			}

			if ( '<' !== $markup[ $pos ] ) {
				// Character data before the root element is not well-formed XML,
				// and guessing what the author meant is exactly what this refuses.
				return null;
			}

			if ( 0 === substr_compare( $markup, '<!--', $pos, 4 ) ) {
				$end = strpos( $markup, '-->', $pos + 4 );

				if ( false === $end ) {
					return null;
				}

				$pos = $end + 3;
				continue;
			}

			if ( 0 === substr_compare( $markup, '<?', $pos, 2 ) ) {
				$end = strpos( $markup, '?>', $pos + 2 );

				if ( false === $end ) {
					return null;
				}

				$pos = $end + 2;
				continue;
			}

			if ( 0 === substr_compare( $markup, '<!DOCTYPE', $pos, 9, true ) ) {
				$end = self::endOfDoctype( $markup, $pos );

				if ( null === $end ) {
					return null;
				}

				$pos = $end;
				continue;
			}

			// Anything else that starts with `<!` this far out is not something
			// whose end can be found reliably — a CDATA section is not legal here.
			if ( 0 === substr_compare( $markup, '<!', $pos, 2 ) ) {
				return null;
			}

			break;
		}

		if ( $pos >= $length ) {
			return null;
		}

		return self::readStartTag( $markup, $pos );
	}

	/**
	 * The offset of the next non-whitespace byte.
	 *
	 * @param string $markup UTF-8 markup.
	 * @param int    $pos    Starting offset.
	 * @return int
	 */
	private static function skipWhitespace( string $markup, int $pos ): int {
		$length = strlen( $markup );

		while ( $pos < $length && 1 === preg_match( '/\s/', $markup[ $pos ] ) ) {
			++$pos;
		}

		return $pos;
	}

	/**
	 * The offset just past a DOCTYPE declaration, internal subset included.
	 *
	 * @param string $markup UTF-8 markup.
	 * @param int    $pos    Offset of `<!DOCTYPE`.
	 * @return int|null Null when the declaration does not end inside this buffer.
	 */
	private static function endOfDoctype( string $markup, int $pos ): ?int {
		$length = strlen( $markup );
		$quote  = '';
		$i      = $pos + 9;

		for ( ; $i < $length; $i++ ) {
			$char = $markup[ $i ];

			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '[' === $char ) {
				$close = self::endOfInternalSubset( $markup, $i );

				if ( null === $close ) {
					return null;
				}

				$i = $close;
				continue;
			}

			if ( '>' === $char ) {
				return $i + 1;
			}
		}

		return null;
	}

	/**
	 * The offset of the `]` closing a DOCTYPE internal subset.
	 *
	 * Quote-aware and comment-aware, because a declaration inside the subset can
	 * legally contain both `]` and `>` inside a quoted entity value — which is
	 * precisely how an `<svg` hid there.
	 *
	 * @param string $markup UTF-8 markup.
	 * @param int    $pos    Offset of `[`.
	 * @return int|null
	 */
	private static function endOfInternalSubset( string $markup, int $pos ): ?int {
		$length = strlen( $markup );
		$quote  = '';

		for ( $i = $pos + 1; $i < $length; $i++ ) {
			$char = $markup[ $i ];

			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( 0 === substr_compare( $markup, '<!--', $i, 4 ) ) {
				$end = strpos( $markup, '-->', $i + 4 );

				if ( false === $end ) {
					return null;
				}

				$i = $end + 2;
				continue;
			}

			if ( ']' === $char ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Reads an element start tag at an offset and returns it self-closed.
	 *
	 * @param string $markup UTF-8 markup.
	 * @param int    $pos    Offset of `<`.
	 * @return string|null Null when the name is not `svg`, or the tag does not
	 *         close inside this buffer.
	 */
	private static function readStartTag( string $markup, int $pos ): ?string {
		$length = strlen( $markup );
		$name   = '';

		for ( $i = $pos + 1; $i < $length; $i++ ) {
			$char = $markup[ $i ];

			if ( '>' === $char || '/' === $char || 1 === preg_match( '/\s/', $char ) ) {
				break;
			}

			$name .= $char;
		}

		if ( '' === $name ) {
			return null;
		}

		// `svg`, or a namespace-prefixed `x:svg`. The prefix is validated against
		// the SVG namespace once the tag parses.
		if ( 'svg' !== $name && ! str_ends_with( $name, ':svg' ) ) {
			return null;
		}

		$quote = '';

		for ( $i = $pos + 1; $i < $length; $i++ ) {
			$char = $markup[ $i ];

			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '>' === $char ) {
				return rtrim( substr( $markup, $pos, $i - $pos ), '/' ) . '/>';
			}
		}

		return null;
	}

	/**
	 * Parses an isolated start tag.
	 *
	 * Entity references are escaped to literal text rather than resolved: the
	 * DOCTYPE that declared them is deliberately not coming with us, and
	 * resolving one is the XXE vector this shape exists to avoid. No attribute
	 * that can carry an entity is ever read for a dimension.
	 *
	 * @param string $tag A self-closed start tag.
	 * @return \SimpleXMLElement|null Null on a parse failure, or a prefixed root
	 *         whose prefix is not the SVG namespace.
	 */
	private static function parseTag( string $tag ): ?\SimpleXMLElement {
		$tag = (string) preg_replace(
			'/&(?!(?:amp|lt|gt|quot|apos|#[0-9]{1,7}|#x[0-9a-fA-F]{1,6});)/',
			'&amp;',
			$tag
		);

		$previous = libxml_use_internal_errors( true );
		$element  = simplexml_load_string( $tag, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $element ) {
			return null;
		}

		if ( ! str_starts_with( $element->getName(), 'svg' ) && 'svg' !== $element->getName() ) {
			return null;
		}

		// An unprefixed <svg> is taken at its word; a prefixed one has to prove
		// the prefix means SVG, since `<x:svg>` in another namespace is a
		// different element that merely shares a local name.
		if ( str_contains( $tag, ':svg' ) ) {
			$namespaces = $element->getNamespaces();
			$namespace  = reset( $namespaces );

			if ( 'http://www.w3.org/2000/svg' !== $namespace ) {
				return null;
			}
		}

		return $element;
	}

	/**
	 * Parses an absolute CSS length into whole pixels.
	 *
	 * Accepts the full SVG number grammar (sign, decimal point, exponent) and
	 * every CSS absolute unit. Relative units carry no intrinsic size and are
	 * refused so the caller falls through to `viewBox`.
	 *
	 * @param string $value Raw attribute value.
	 * @return int|null Null when the value is absent, relative, or not a length.
	 */
	private static function absoluteLength( string $value ): ?int {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$pattern = '/^([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)([a-zA-Z]*)$/';

		if ( 1 !== preg_match( $pattern, $value, $matches ) ) {
			return null;
		}

		$unit = strtolower( $matches[2] );

		if ( '' !== $unit && ! isset( self::UNITS[ $unit ] ) ) {
			return null;
		}

		$pixels = (float) $matches[1] * ( '' === $unit ? 1.0 : self::UNITS[ $unit ] );

		return self::wholePixels( $pixels );
	}

	/**
	 * The aspect ratio and coordinate size of a `viewBox`.
	 *
	 * The list is `min-x min-y width height`, separated by whitespace or commas,
	 * and the offsets may be negative — only the last two values are the size.
	 *
	 * @param string $view_box Raw `viewBox` attribute value.
	 * @return array{width: int, height: int, aspect: float}|null
	 */
	private static function viewBoxRatio( string $view_box ): ?array {
		$view_box = trim( $view_box );

		if ( '' === $view_box ) {
			return null;
		}

		$parts = preg_split( '/[\s,]+/', $view_box );

		if ( ! is_array( $parts ) || 4 !== count( $parts ) ) {
			return null;
		}

		foreach ( $parts as $part ) {
			if ( ! is_numeric( $part ) ) {
				return null;
			}
		}

		$width  = (float) $parts[2];
		$height = (float) $parts[3];

		if ( $width <= 0.0 || $height <= 0.0 || ! is_finite( $width ) || ! is_finite( $height ) ) {
			return null;
		}

		$rounded_width  = self::wholePixels( $width );
		$rounded_height = self::wholePixels( $height );

		if ( null === $rounded_width || null === $rounded_height ) {
			return null;
		}

		return [
			'width'  => $rounded_width,
			'height' => $rounded_height,
			'aspect' => $width / $height,
		];
	}

	/**
	 * Rounds a pixel measurement to a storable integer, or refuses.
	 *
	 * @param float $pixels Measurement in CSS pixels.
	 * @return int|null Null when the value is not finite, not positive, or too
	 *         large to be a real image.
	 */
	private static function wholePixels( float $pixels ): ?int {
		if ( ! is_finite( $pixels ) || $pixels <= 0.0 || $pixels > 1000000.0 ) {
			return null;
		}

		$rounded = (int) round( $pixels );

		return $rounded > 0 ? $rounded : null;
	}
}
