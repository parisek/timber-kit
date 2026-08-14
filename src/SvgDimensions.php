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
 * and Illustrator default) yields an empty string, which it stores as `0`. This
 * closes that specific gap.
 *
 * Two rules keep it safe beside any other plugin in this space, and both are
 * load-bearing rather than defensive habit:
 *
 * - It writes to `_wp_attachment_metadata`'s own `width`/`height` keys — core's
 *   structure, the one `wp_get_attachment_image_src()` reads — so the answer is
 *   shared with every consumer rather than hidden in a private key.
 * - It only ever fills a value that is missing or zero. Whatever another plugin
 *   resolved stands, and `--force` on the CLI command is the single deliberate
 *   exception.
 *
 * A zero counts as missing on purpose: it is not a considered answer, it is
 * `intval( '' )` from a reader that found no attribute to read.
 */
class SvgDimensions {

	/**
	 * Reads the intrinsic size out of SVG markup.
	 *
	 * Attribute pair first, `viewBox` second — an explicit `width`/`height` is the
	 * author's stated intent, while `viewBox` describes the coordinate system and
	 * is only a proxy for it. When both exist they usually agree; when they do
	 * not, the attributes are what a browser lays out.
	 *
	 * Only absolute lengths count. A `width="100%"` is a relative length, so it
	 * says nothing about intrinsic size and must fall through to `viewBox` rather
	 * than be read as 100 pixels.
	 *
	 * @param string $markup Raw SVG document.
	 * @return array{width: int, height: int}|null Null when the markup carries no
	 *         usable size, or does not parse.
	 */
	public static function fromMarkup( string $markup ): ?array {
		$root = self::rootStartTag( $markup );

		if ( null === $root ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );

		// Only the root element is ever parsed, which is what makes this reliable
		// rather than merely tidy. Parsing whole documents lost three real uploads
		// to libxml's 10 MB AttValue limit: an embedded
		// <image href="data:image/png;base64,..."> aborts the parse and takes the
		// root's own width/height down with it, even though they sit in the first
		// hundred bytes. It also means a hostile DOCTYPE never reaches the parser
		// at all — an SVG is attacker-supplied content wherever uploads are open,
		// and here the safety is a property of the shape rather than a flag.
		$element = simplexml_load_string( $root, \SimpleXMLElement::class, LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $element ) {
			return null;
		}

		$attributes = $element->attributes();

		if ( null !== $attributes ) {
			$width  = self::absoluteLength( (string) ( $attributes->width ?? '' ) );
			$height = self::absoluteLength( (string) ( $attributes->height ?? '' ) );

			if ( null !== $width && null !== $height ) {
				return [ 'width' => $width, 'height' => $height ];
			}
		}

		return self::fromViewBox( null !== $attributes ? (string) ( $attributes->viewBox ?? '' ) : '' );
	}

	/**
	 * Reads the intrinsic size out of an SVG file.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array{width: int, height: int}|null
	 */
	public static function fromFile( string $path ): ?array {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$markup = file_get_contents( $path );

		if ( false === $markup ) {
			return null;
		}

		return self::fromMarkup( $markup );
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
	 * @param bool $force         Re-derive even when a non-zero value is stored.
	 * @param bool $dry_run       Report what would happen without writing.
	 * @return array{status: string, width: int|null, height: int|null} status is one of
	 *         `derived`, `would_derive`, `already_sized`, `not_svg`, `unreadable`, `failed`.
	 */
	public function backfill( int $attachment_id, bool $force = false, bool $dry_run = false ): array {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return [ 'status' => 'not_svg', 'width' => null, 'height' => null ];
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : [];

		$stored = self::storedDimensions( $metadata );

		if ( ! $force && null !== $stored ) {
			return [
				'status' => 'already_sized',
				'width'  => $stored['width'],
				'height' => $stored['height'],
			];
		}

		$file = get_attached_file( $attachment_id );
		$size = is_string( $file ) ? self::fromFile( $file ) : null;

		if ( null === $size ) {
			return [ 'status' => 'unreadable', 'width' => null, 'height' => null ];
		}

		if ( $dry_run ) {
			return [ 'status' => 'would_derive', 'width' => $size['width'], 'height' => $size['height'] ];
		}

		$metadata['width']  = $size['width'];
		$metadata['height'] = $size['height'];

		if ( ! wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
			return [ 'status' => 'failed', 'width' => $size['width'], 'height' => $size['height'] ];
		}

		return [ 'status' => 'derived', 'width' => $size['width'], 'height' => $size['height'] ];
	}

	/**
	 * Fills in the dimensions at upload time.
	 *
	 * Hook above priority 10 so this observes what other plugins in this space
	 * wrote rather than racing them.
	 *
	 * @param array<string, mixed> $metadata      Generated attachment metadata.
	 * @param int                  $attachment_id Attachment post ID.
	 * @return array<string, mixed> The metadata, with dimensions added only when they were missing.
	 */
	public function filterGeneratedMetadata( array $metadata, int $attachment_id ): array {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return $metadata;
		}

		if ( null !== self::storedDimensions( $metadata ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		$size = is_string( $file ) ? self::fromFile( $file ) : null;

		if ( null === $size ) {
			return $metadata;
		}

		$metadata['width']  = $size['width'];
		$metadata['height'] = $size['height'];

		return $metadata;
	}

	/**
	 * The usable size already stored in attachment metadata, if any.
	 *
	 * A zero is treated as absent rather than as an answer: it is what a reader
	 * that found no attribute stores, not a size anything can lay out.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return array{width: int, height: int}|null
	 */
	private static function storedDimensions( array $metadata ): ?array {
		if ( ! isset( $metadata['width'], $metadata['height'] ) ) {
			return null;
		}

		if ( ! is_numeric( $metadata['width'] ) || ! is_numeric( $metadata['height'] ) ) {
			return null;
		}

		$width  = (int) $metadata['width'];
		$height = (int) $metadata['height'];

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return [ 'width' => $width, 'height' => $height ];
	}

	/**
	 * Extracts the `<svg …>` start tag as a standalone, self-closing document.
	 *
	 * Hand-scanned rather than matched with a regular expression because an
	 * attribute value may legitimately contain `>` — stopping at the first one
	 * would truncate the tag mid-attribute and lose the dimensions.
	 *
	 * @param string $markup Raw SVG document.
	 * @return string|null A parseable `<svg … />`, or null when there is no root.
	 */
	private static function rootStartTag( string $markup ): ?string {
		$offset = 0;

		while ( true ) {
			$start = strpos( $markup, '<svg', $offset );

			if ( false === $start ) {
				return null;
			}

			// `<svgfoo` is a different element; the name has to end here.
			$next = substr( $markup, $start + 4, 1 );

			if ( '' === $next || ! ( '>' === $next || '/' === $next || 1 === preg_match( '/\s/', $next ) ) ) {
				$offset = $start + 4;
				continue;
			}

			// A comment or CDATA section can mention `<svg` without containing one.
			$comment = strrpos( substr( $markup, 0, $start ), '<!--' );

			if ( false !== $comment && false === strpos( substr( $markup, $comment, $start - $comment ), '-->' ) ) {
				$offset = $start + 4;
				continue;
			}

			break;
		}

		$length = strlen( $markup );
		$quote  = '';

		for ( $i = $start + 4; $i < $length; $i++ ) {
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
				$tag = rtrim( substr( $markup, $start, $i - $start ), '/' ) . '/>';

				// Anything that is not a predefined or numeric reference is an
				// entity the DOCTYPE declared, and the DOCTYPE is deliberately not
				// coming with us. Escaping the ampersand turns the reference into
				// literal text instead of resolving it — resolving is the XXE
				// vector this shape exists to avoid, and every attribute that can
				// carry one (a namespace URI, a title) is never read here. Doubles
				// as tolerance for a bare `&`, which is invalid XML and common.
				return (string) preg_replace(
					'/&(?!(?:amp|lt|gt|quot|apos|#[0-9]+|#x[0-9a-fA-F]+);)/',
					'&amp;',
					$tag
				);
			}
		}

		return null;
	}

	/**
	 * Parses an absolute CSS length into whole pixels.
	 *
	 * Unitless and `px` are the only forms that map to pixels without knowing the
	 * rendering context. A percentage is relative to the containing block, so it
	 * carries no intrinsic size at all.
	 *
	 * @param string $value Raw attribute value.
	 * @return int|null Null when the value is absent, relative, or not a length.
	 */
	private static function absoluteLength( string $value ): ?int {
		$value = trim( $value );

		if ( '' === $value || 1 !== preg_match( '/^([0-9]*\.?[0-9]+)(px)?$/i', $value, $matches ) ) {
			return null;
		}

		$pixels = (int) round( (float) $matches[1] );

		return $pixels > 0 ? $pixels : null;
	}

	/**
	 * Parses the size half of a `viewBox`.
	 *
	 * The list is `min-x min-y width height`, separated by whitespace or commas,
	 * and the offsets may be negative — only the last two values are the size.
	 *
	 * @param string $view_box Raw `viewBox` attribute value.
	 * @return array{width: int, height: int}|null
	 */
	private static function fromViewBox( string $view_box ): ?array {
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

		$width  = (int) round( (float) $parts[2] );
		$height = (int) round( (float) $parts[3] );

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return [ 'width' => $width, 'height' => $height ];
	}
}
