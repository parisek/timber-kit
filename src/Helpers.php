<?php

declare(strict_types=1);

/**
 * Helpers — Static utility methods for formatting ACF data for Twig templates.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

use Timber\Term;
use Timber\Timber;
use Timber\ImageHelper;

/**
 * Collection of static helpers that normalise ACF field values into plain
 * arrays suitable for consumption in Twig templates.
 */
class Helpers {

	/**
	 * Pure-core shaping for a single ACF "array" return-format attachment.
	 *
	 * Extracted from {@see formatImage()}'s associative-array branch so it can
	 * be exercised in isolation by property tests. No WP/ACF calls, no global
	 * state. Returns null for degenerate input (null, empty array) instead of
	 * an empty dict, so callers can decide whether to skip the item.
	 *
	 * Missing keys yield null silently via null-coalescing; this fixes a real
	 * source of `Undefined index` notices the in-line array branch used to emit
	 * for malformed ACF arrays. Well-formed inputs are unaffected.
	 *
	 * @param array<string,mixed>|null $raw  ACF attachment array as returned by
	 *                                       `acf_get_attachment()` or stored in an
	 *                                       array-return-format ACF field.
	 * @return array{id:int|null,src:string|null,type:string|null,width:int|null,height:int|null,alt:string|null,caption:string|null,description:string|null}|null
	 */
	public static function formatImageFrom( ?array $raw ): ?array {
		if ( null === $raw || [] === $raw ) {
			return null;
		}
		// SVG width/height-1px guard preserved from the original array branch:
		// https://core.trac.wordpress.org/ticket/26256
		// Numeric guard + int cast normalises ACF's variable scalar types
		// (sometimes int, sometimes numeric-string) into the documented
		// `int|null` contract — and dodges PHP 8 string-to-number comparison
		// surprises on non-numeric values.
		$width  = ( isset( $raw['width'] )  && is_numeric( $raw['width'] )  && (int) $raw['width']  > 1 ) ? (int) $raw['width']  : null;
		$height = ( isset( $raw['height'] ) && is_numeric( $raw['height'] ) && (int) $raw['height'] > 1 ) ? (int) $raw['height'] : null;
		$id     = ( isset( $raw['ID'] )     && is_numeric( $raw['ID'] ) )                              ? (int) $raw['ID']     : null;

		return [
			'id'          => $id,
			'src'         => isset( $raw['url'] )         ? (string) $raw['url']         : null,
			'type'        => isset( $raw['mime_type'] )   ? (string) $raw['mime_type']   : null,
			'width'       => $width,
			'height'      => $height,
			'alt'         => isset( $raw['alt'] )         ? (string) $raw['alt']         : null,
			'caption'     => isset( $raw['caption'] )     ? (string) $raw['caption']     : null,
			'description' => isset( $raw['description'] ) ? (string) $raw['description'] : null,
		];
	}

	/**
	 * Normalise an ACF image field value into a flat array (or list of arrays).
	 *
	 * Accepts an image in any of the formats ACF may return: a Timber image
	 * object, an associative array, a numeric attachment ID, a URL string, or
	 * an indexed list of any of the above (e.g. a gallery field).  SVG
	 * dimensions that WordPress misreports as 1 px are coerced to null.
	 *
	 * @param object|array|int|string $image    Image value as returned by ACF.
	 * @param int|null                $post_id  Post ID the field belongs to (unused, kept for API parity).
	 * @param array|null              $field    ACF field definition array (unused, kept for API parity).
	 * @return array Indexed list of image data arrays. An empty array is
	 *               returned when the input cannot be resolved.
	 */
	public static function formatImage( $image, $post_id = null, $field = null ) {

		$data = [];

		// Gallery / multi-value field: recurse for each item and collect non-empty results.
		if ( is_countable( $image ) && ! Helpers::isAssoc( $image ) ) {
			$items = [];
			foreach ( $image as $item ) {
				$resolved = Helpers::formatImage( $item );
				if ( $resolved ) {
					$items[] = $resolved;
				}
			}
			return $items;
		}

		if ( is_object( $image ) ) {
			// Object branch (typically a Timber image): different property names
			// (`ID`, `src`, `post_mime_type`) so we shape it inline rather than
			// going through formatImageFrom().
			// fixed weird bug when image/svg+xml is sometimes width 1px / height 1px
			// https://core.trac.wordpress.org/ticket/26256
			$width  = ( ! empty( $image->width )  && $image->width  > 1 ) ? $image->width  : null;
			$height = ( ! empty( $image->height ) && $image->height > 1 ) ? $image->height : null;
			$data[] = [
				'id'          => $image->ID,
				'src'         => $image->src,
				'type'        => $image->post_mime_type,
				'width'       => $width,
				'height'      => $height,
				'alt'         => $image->alt,
				'caption'     => $image->caption,
				'description' => $image->description,
			];
		} elseif ( is_array( $image ) ) {
			$item = self::formatImageFrom( $image );
			if ( null !== $item ) {
				$data[] = $item;
			}
		} elseif ( is_numeric( $image ) ) {
			$resolved = acf_get_attachment( $image );
			if ( $resolved ) {
				$item = self::formatImageFrom( $resolved );
				if ( null !== $item ) {
					$data[] = $item;
				}
			}
		} elseif ( filter_var( $image, FILTER_VALIDATE_URL ) ) {
			$attachment_id = attachment_url_to_postid( $image );
			$resolved      = acf_get_attachment( $attachment_id );
			if ( $resolved ) {
				$item = self::formatImageFrom( $resolved );
				if ( null !== $item ) {
					$data[] = $item;
				}
			}
		}

		return $data;
	}

	/**
	 * Normalise an ACF file field value into a flat array.
	 *
	 * Accepts a file in any format ACF may return: a Timber post object, an
	 * associative array, a numeric attachment ID, or a URL string.  For PDF
	 * attachments a `preview` key is populated with the result of
	 * {@see formatImage()} using the PDF's generated thumbnail.
	 *
	 * @param object|array|int|string $file     File value as returned by ACF.
	 * @param int|null                $post_id  Post ID the field belongs to (unused, kept for API parity).
	 * @param array|null              $field    ACF field definition array (unused, kept for API parity).
	 * @return array{id: int|null, src: string, type: string, subtype: string, filename: string, filesize: string, alt: string, caption: string, description: string, preview: array, codecs: string|null}|string
	 *               Associative file data array, or an empty string when the
	 *               file cannot be resolved.
	 */
	public static function formatFile( $file, $post_id = null, $field = null ) {
		$attachment = null;

		if ( is_object( $file ) ) {
			$attachment = [
				'ID' => $file->ID ?? null,
				'url' => $file->src ?? '',
				'mime_type' => $file->post_mime_type ?? '',
				'subtype' => $file->subtype ?? '',
				'filename' => $file->filename ?? '',
				'filesize' => $file->filesize ?? '',
				'alt' => $file->alt ?? '',
				'caption' => $file->caption ?? '',
				'description' => $file->description ?? '',
			];
		} elseif ( is_array( $file ) ) {
			$attachment = $file;
		} elseif ( is_numeric( $file ) ) {
			$attachment = acf_get_attachment( $file );
		} elseif ( is_string( $file ) && filter_var( $file, FILTER_VALIDATE_URL ) ) {
			$id = attachment_url_to_postid( $file );
			if ( $id ) {
				$attachment = acf_get_attachment( $id );
			}
		}

		if ( empty( $attachment ) || ! is_array( $attachment ) ) {
			return '';
		}

		$raw_size = $attachment['filesize'] ?? '';
		$filesize_formatted = is_numeric( $raw_size ) ? size_format( $raw_size ) : '';

		$preview = [];
		if ( ( $attachment['mime_type'] ?? '' ) === 'application/pdf' && ! empty( $attachment['ID'] ) ) {
			$image_src_data = wp_get_attachment_image_src( (int) $attachment['ID'], 'full', false );
			if ( $image_src_data ) {
				list( $img_src, $img_width, $img_height ) = $image_src_data;
				$preview = Helpers::formatImage( [
					'id' => null, // this image is not tracked in WP media library just as file metadata value
					'url' => $img_src,
					'mime_type' => 'image/jpeg',
					'width' => $img_width,
					'height' => $img_height,
					'alt' => $attachment['alt'] ?? '',
					'caption' => $attachment['caption'] ?? '',
					'description' => $attachment['description'] ?? '',
				] );
			}
		}

		return [
			'id' => $attachment['ID'] ?? null,
			'src' => $attachment['url'] ?? '',
			'type' => $attachment['mime_type'] ?? '',
			'subtype' => $attachment['subtype'] ?? '',
			'filename' => $attachment['filename'] ?? '',
			'filesize' => $filesize_formatted,
			'alt' => $attachment['alt'] ?? '',
			'caption' => $attachment['caption'] ?? '',
			'description' => $attachment['description'] ?? '',
			'preview' => $preview, // empty array if not a PDF or no preview
			'codecs' => ( ! empty( $attachment['ID'] ) && str_starts_with( (string) ( $attachment['mime_type'] ?? '' ), 'video/' ) ) ? self::videoCodecs( (int) $attachment['ID'] ) : null,
		];
	}

	/**
	 * Normalise an ACF video (file) field value into a single flat array.
	 *
	 * Delegates to {@see formatImage()} (video attachments share the same
	 * structure) and unwraps the outer indexed list so the caller receives a
	 * single associative array instead of a list.
	 *
	 * @param object|array|int|string $file     Video value as returned by ACF.
	 * @param int|null                $post_id  Post ID the field belongs to (unused, kept for API parity).
	 * @param array|null              $field    ACF field definition array (unused, kept for API parity).
	 * @return array{id: int|null, src: string, type: string, width: int|null, height: int|null, alt: string, caption: string, description: string, codecs: string|null}|false|null
	 *               Single video data array, false if the list was empty, or
	 *               null when the input is not countable.
	 */
	public static function formatVideo( $file, $post_id = null, $field = null ) {
		// use formatImage for simplicity as video has similar structure
		$video = self::formatImage( $file, $post_id, $field );
		// disable nested array
		$video = is_countable( $video ) ? reset( $video ) : null;
		if ( is_array( $video ) && [] !== $video ) {
			$video['codecs'] = ( ! empty( $video['id'] ) && str_starts_with( (string) ( $video['type'] ?? '' ), 'video/' ) ) ? self::videoCodecs( (int) $video['id'] ) : null;
		}
		return $video;
	}

	/**
	 * Resolve a video attachment's bare RFC 6381 codecs string.
	 *
	 * Returns e.g. `av01.0.01M.08` for AV1 MP4 attachments (derived from the
	 * file's `av1C` box), or null when no codecs string applies (non-AV1 MP4,
	 * WebM, unresolvable file). Deliberately separate from the mime type: the
	 * mime stays a plain comparable value and the codecs value stays
	 * independently inspectable; templates compose the attribute themselves —
	 * `type='video/mp4; codecs="…"'`, single-quoted because the composed
	 * value embeds double quotes.
	 *
	 * The computed value is cached in attachment meta on first use (`none`
	 * sentinel for negative results). This v1 cache is intentionally simple:
	 * replacing the underlying file does not invalidate the meta
	 * automatically, so callers must clear `_timber_kit_video_codecs` when
	 * regenerating attachments in place.
	 *
	 * @param int|array<string,mixed> $attachment Attachment ID or ACF file-field array.
	 */
	public static function videoCodecs( int|array $attachment ): ?string {
		$attachment_id = is_int( $attachment ) ? $attachment : ( isset( $attachment['ID'] ) && is_numeric( $attachment['ID'] ) ? (int) $attachment['ID'] : 0 );
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$cached = get_post_meta( $attachment_id, '_timber_kit_video_codecs', true );
		if ( is_string( $cached ) && '' !== $cached ) {
			return 'none' === $cached ? null : $cached;
		}

		$path = get_attached_file( $attachment_id );
		$codecs = is_string( $path ) ? VideoCodecs::codecsString( $path ) : null;

		update_post_meta( $attachment_id, '_timber_kit_video_codecs', $codecs ?? 'none' );

		return $codecs;
	}

	/**
	 * Normalise ordered ACF video variants into `<source>` dictionaries.
	 *
	 * `type` is the variant's plain mime (default `video/mp4`); `codecs` is
	 * the bare RFC 6381 string from {@see videoCodecs()} or null. Templates
	 * compose the attribute: `type='{{ type }}{% if codecs %}; codecs="{{ codecs }}"{% endif %}'`.
	 *
	 * @param array<int, array<string,mixed>|null|false> $variants Ordered ACF file arrays.
	 * @return array<int, array{src: string, type: string, codecs: string|null}>
	 */
	public static function formatVideoSources( array $variants ): array {
		$sources = [];

		foreach ( $variants as $variant ) {
			if ( empty( $variant ) || ! is_array( $variant ) || empty( $variant['url'] ) ) {
				continue;
			}

			$mime = isset( $variant['mime_type'] ) && '' !== (string) $variant['mime_type'] ? (string) $variant['mime_type'] : 'video/mp4';

			$sources[] = [
				'src' => (string) $variant['url'],
				'type' => $mime,
				'codecs' => self::videoCodecs( $variant ),
			];
		}

		return $sources;
	}

	/**
	 * Add an ordered video source cascade to a formatted repeater row.
	 *
	 * @param array<string,mixed> $row Formatted repeater row.
	 * @return array<string,mixed>
	 */
	private static function appendVideoSources( array $row ): array {
		if ( array_key_exists( 'sources', $row ) || empty( $row['video'] ) || ! is_array( $row['video'] ) || empty( $row['video']['src'] ) ) {
			return $row;
		}

		$sources = [];
		foreach ( [ 'video_preview_av1', 'video_preview', 'video' ] as $key ) {
			if ( empty( $row[ $key ] ) || ! is_array( $row[ $key ] ) || empty( $row[ $key ]['src'] ) ) {
				continue;
			}

			$sources[] = [
				'src' => (string) $row[ $key ]['src'],
				'type' => isset( $row[ $key ]['type'] ) && '' !== (string) $row[ $key ]['type'] ? (string) $row[ $key ]['type'] : 'video/mp4',
				'codecs' => isset( $row[ $key ]['codecs'] ) && is_string( $row[ $key ]['codecs'] ) ? $row[ $key ]['codecs'] : null,
			];
		}

		if ( [] !== $sources ) {
			$row['sources'] = $sources;
		}

		return $row;
	}

	/**
	 * Normalise a list of Timber Term objects into a flat array structure.
	 *
	 * Each term is represented as an associative array. Children are resolved
	 * via `Timber::get_terms()` to honour any custom sort order (e.g. the
	 * Taxonomy Terms Order plugin) and recursively formatted.  Terms whose
	 * archive URL contains `?taxonomy=` (i.e. WordPress falls back to a query
	 * string) are given an empty `url`.
	 *
	 * @param iterable $terms List of Timber\Term objects.
	 * @return array<int, array{id: int, title: string, url: string, count: int, children: array}>
	 *               Indexed list of term data arrays. `count` is the term's
	 *               object count (from `WP_Term->count`). Each entry may also
	 *               contain nested `children` in the same format.
	 */
	public static function formatTerms( $terms ) {

		$items = [];

		if ( is_countable( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof Term ) {
					$link = ( strpos( $term->link(), '?taxonomy=' ) === FALSE ) ? $term->link() : '';
					// we need this approach to respect sorting of nested taxonomy terms
					// like when using plugin https://cs.wordpress.org/plugins/taxonomy-terms-order/
					$children = [];
					if ( $term->children ) {
						$children = Timber::get_terms( [
							'taxonomy' => $term->taxonomy,
							'child_of' => $term->ID
						] );
					}
					$items[] = [
						'id' => $term->ID,
						'title' => $term->title,
						'url' => $link,
						'count' => (int) $term->count,
						'children' => Helpers::formatTerms( $children ),
					];
				}
			}
		}

		return $items;
	}

	/**
	 * Resize an image into multiple variants and generate WebP alternatives.
	 *
	 * For each entry in `$variants` the image is resized via
	 * `ImageHelper::resize()` and, when the resized file exists on disk and
	 * the source is not already WebP, a WebP copy is generated via
	 * `ImageHelper::img_to_webp()`.  Variants are sorted descending by their
	 * `media` breakpoint value.  SVG images are returned as-is without any
	 * processing.  A fallback entry pointing to the original `src` is always
	 * appended last.
	 *
	 * @deprecated Use ImageHelper::resize() directly. Kept for backward compatibility only.
	 *
	 * @param array                    $image    Image data array with at least a `src` key, as
	 *                                           returned by {@see formatImage()}.  An indexed list
	 *                                           is also accepted; the last element is used.
	 * @param array<int, array{0: int|string, 1: int|string, 2: int|string, 3: string}> $variants
	 *                                           Each entry is a four-element indexed array:
	 *                                           `[width, height, min-width breakpoint in px, crop position]`.
	 *                                           Empty values default to 0 / `'crop'`.
	 * @return array<int, array{src: string, type: string, width: int, height: int, media?: string, alt?: string, caption?: string, description?: string}>
	 *               Indexed list of image variant arrays.  Each entry contains
	 *               at minimum `src`, `type`, `width`, `height`, and `media`.
	 *               The final fallback entry also carries `alt`, `caption`, and
	 *               `description`.  Returns an empty array when `$image` has no
	 *               valid `src`.
	 */
	public static function resizeImage( $image, $variants ) {

		$theme = wp_get_theme();
		$theme_name = $theme->get( 'TextDomain' );

		$images = [];

		if ( is_countable( $image ) ) {
			$image = end( $image );
		}

		// if empty src something not working correctly return empty array
		if ( ! isset( $image['src'] ) || empty( $image['src'] ) ) {
			return $images;
		}

		$default_image = [
			'src' => $image['src'],
			'type' => isset( $image['type'] ) ? $image['type'] : '',
			'width' => isset( $image['width'] ) ? $image['width'] : '',
			'height' => isset( $image['height'] ) ? $image['height'] : '',
			'alt' => isset( $image['alt'] ) ? $image['alt'] : '',
			'caption' => isset( $image['caption'] ) ? $image['caption'] : '',
			'description' => isset( $image['description'] ) ? $image['description'] : '',
		];

		// if SVG return original image without processing
		if ( isset( $image['type'] ) && $image['type'] === 'image/svg+xml' ) {
			$images[] = $default_image;
			return $images;
		}

		foreach ( $variants as $key => $variant ) {
			$variants[ $key ] = [
				'width' => ( isset( $variant[0] ) && ! empty( $variant[0] ) ) ? intval( $variant[0] ) : 0,
				'height' => ( isset( $variant[1] ) && ! empty( $variant[1] ) ) ? intval( $variant[1] ) : 0,
				'media' => ( isset( $variant[2] ) && ! empty( $variant[2] ) ) ? intval( $variant[2] ) : 0,
				'crop' => ( isset( $variant[3] ) && ! empty( $variant[3] ) ) ? $variant[3] : 'crop',
			];
		}

		// sort array by media value
		usort( $variants, function ( $a, $b ) {
			return $b['media'] - $a['media'];
		} );

		foreach ( $variants as $variant ) {

			if ( ! in_array( $variant['crop'], [ 'center', 'top', 'bottom', 'left', 'right' ] ) ) {
				$variant['crop'] = 'center';
			}

			$resize_src_url = ImageHelper::resize( $default_image['src'], $variant['width'], $variant['height'], $variant['crop'] );
			if ( ! empty( $resize_src_url ) ) {
				// we need this approach as Timber does not support generate webp images from already resized images
				// https://github.com/timber/timber/issues/1978
				$upload_dir = wp_upload_dir();
				// Resolves issues with wrong relative URLs with WPML
				// Without this we cannot generate unique images from non default languages
				// https://github.com/timber/timber/issues/2117
				if ( strpos( $upload_dir['relative'], 'http' ) === 0 ) {
					$upload_dir['relative'] = str_replace( content_url(), '/wp-content', $upload_dir['relative'] );
				}
				// Check if image is in WordPress uploads folder
				// If not we could use images in theme folder
				if ( strpos( $default_image['src'], $upload_dir['relative'] ) === FALSE && strpos( $default_image['src'], $theme_name ) !== FALSE ) {
					$resize_src_path = get_template_directory() . str_replace( get_template_directory_uri(), '', $resize_src_url );
				} else {
					$location = str_replace( $upload_dir['relative'], '/wp-content/cache/image', $upload_dir['basedir'] );
					$resize_src_path = $location . '/' . basename( $resize_src_url );
				}

				if ( file_exists( $resize_src_path ) && $default_image['type'] !== 'image/webp' ) {
					$webp_src = ImageHelper::img_to_webp( $resize_src_path, 100 );
					if ( ! empty( $webp_src ) ) {
						$images[] = [
							'src' => $webp_src,
							'type' => 'image/webp',
							'width' => $variant['width'],
							'height' => $variant['height'],
							'media' => ( ! empty( $variant['media'] ) ) ? '(min-width: ' . $variant['media'] . 'px)' : '',
						];
					}
				}

				$images[] = [
					'src' => $resize_src_url,
					'type' => $default_image['type'],
					'width' => $variant['width'],
					'height' => $variant['height'],
					'media' => ( ! empty( $variant['media'] ) ) ? '(min-width: ' . $variant['media'] . 'px)' : '',
				];
			}
		}

		// add last as fallback image
		$images[] = $default_image;

		return $images;
	}

	/**
	 * Determine whether an array is associative (keyed) rather than indexed.
	 *
	 * Compares the array's keys against a zero-based integer sequence.  An
	 * empty array is considered indexed (returns false).
	 *
	 * @param array $array Array to test.
	 * @return bool True if the array has non-sequential or non-integer keys, false otherwise.
	 */
	public static function isAssoc( array $array ) {
		$keys = array_keys( $array );
		return array_keys( $keys ) !== $keys;
	}

	/**
	 * Normalise a Timber pagination object into a Bootstrap-compatible array.
	 *
	 * Extracts `current`, `total`, `pages`, `first`, `last`, `next`, and
	 * `previous` from the Timber pagination object.  The `first` and `last`
	 * entries are derived from the resolved page list and carry a `disabled`
	 * flag when the page is the currently active one.  `next` and `previous`
	 * are always present in the output and marked as disabled when no link is
	 * available.
	 *
	 * @param object $pagination Timber pagination object, typically from `$post->pagination()`.
	 * @return array{
	 *     current?: int,
	 *     total?: int,
	 *     pages?: array<int, array{url: string, title: string, current: bool}>,
	 *     first?: array{url: string, title: string, disabled: bool},
	 *     last?: array{url: string, title: string, disabled: bool},
	 *     next: array{url: string, title: string, disabled: bool},
	 *     previous: array{url: string, title: string, disabled: bool}
	 * }
	 */
	public static function pagination( object $pagination ) {
		$content = [];

		if ( isset( $pagination->current ) ) {
			$content['current'] = (int) $pagination->current;
		}
		if ( isset( $pagination->total ) ) {
			$content['total'] = (int) $pagination->total;
		}

		if ( isset( $pagination->pages ) && count( $pagination->pages ) ) {
			foreach ( $pagination->pages as $page ) {
				$content['pages'][] = [
					'url' => ( isset( $page['link'] ) ) ? $page['link'] : home_url( $_SERVER['REQUEST_URI'] ),
					'title' => $page['title'],
					'current' => $page['current'],
				];
			}
			$first = reset( $content['pages'] );
			$content['first'] = [
				'url' => $first['url'],
				'title' => 'First',
				'disabled' => ( $first['title'] != $pagination->current ) ? false : true,
			];
			$last = end( $content['pages'] );
			$content['last'] = [
				'url' => $last['url'],
				'title' => 'Last',
				'disabled' => ( $last['title'] != $pagination->current ) ? false : true,
			];
		}

		if ( isset( $pagination->next ) ) {
			$content['next'] = [
				'url' => ( isset( $pagination->next['link'] ) ) ? $pagination->next['link'] : '',
				'title' => 'Next',
				'disabled' => ( isset( $pagination->next['link'] ) ) ? false : true,
			];
		} else {
			$content['next'] = [
				'url' => '',
				'title' => 'Next',
				'disabled' => true,
			];
		}

		if ( isset( $pagination->prev ) ) {
			$content['previous'] = [
				'url' => ( isset( $pagination->prev['link'] ) ) ? $pagination->prev['link'] : '',
				'title' => 'Previous',
				'disabled' => ( isset( $pagination->prev['link'] ) ) ? false : true,
			];
		} else {
			$content['previous'] = [
				'url' => '',
				'title' => 'Previous',
				'disabled' => true,
			];
		}

		return $content;
	}

	/**
	 * Sanitize HTML content coming from an editor.
	 *
	 * Removes TinyMCE artifacts such as bookmark spans and bogus line breaks.
	 * Security-sensitive tag and attribute filtering is intentionally left to
	 * `wp_kses()` with {@see getEditorAllowedHtml()}, rather than broad regex
	 * stripping that could corrupt visible editor content.
	 *
	 * @param mixed $value Raw editor value.
	 * @return mixed Sanitized editor value.
	 */
	public static function sanitizeEditorContent( $value ) {

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$without_bookmarks = preg_replace( '/<span[^>]*data-mce-type=(["\'])bookmark\1[^>]*>[\s\S]*?<\/span>/iu', '', $value );
		if ( null !== $without_bookmarks ) {
			$value = $without_bookmarks;
		}
		$without_bogus_breaks = preg_replace( '/<br[^>]*data-mce-bogus=(["\'])1\1[^>]*>/iu', '', $value );
		if ( null !== $without_bogus_breaks ) {
			$value = $without_bogus_breaks;
		}

		return $value;
	}

	/**
	 * Get the allowed HTML map for editor content sanitized via wp_kses().
	 *
	 * Keeps common editorial markup and selected attributes such as `class`,
	 * while excluding inline styles and risky embedded content.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function getEditorAllowedHtml() {
		return [
			'p' => [ 'class' => true ],
			'br' => [],
			'strong' => [ 'class' => true ],
			'b' => [ 'class' => true ],
			'em' => [ 'class' => true ],
			'i' => [ 'class' => true ],
			'u' => [ 'class' => true ],
			's' => [ 'class' => true ],
			'sub' => [ 'class' => true ],
			'sup' => [ 'class' => true ],
			'ul' => [ 'class' => true ],
			'ol' => [ 'class' => true ],
			'li' => [ 'class' => true ],
			'h1' => [ 'class' => true ],
			'h2' => [ 'class' => true ],
			'h3' => [ 'class' => true ],
			'h4' => [ 'class' => true ],
			'h5' => [ 'class' => true ],
			'h6' => [ 'class' => true ],
			'blockquote' => [ 'class' => true, 'cite' => true ],
			'hr' => [ 'class' => true ],
			'span' => [ 'class' => true ],
			'a' => [ 'class' => true, 'href' => true, 'rel' => true, 'title' => true ],
			'img' => [ 'class' => true, 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'srcset' => true, 'sizes' => true, 'loading' => true ],
			'figure' => [ 'class' => true ],
			'figcaption' => [ 'class' => true ],
			'code' => [ 'class' => true ],
			'pre' => [ 'class' => true ],
		];
	}

	/**
	 * Check whether HTML content from an editor is visually empty.
	 *
	 * TinyMCE / ACF WYSIWYG may save invisible artifacts such as bookmark spans,
	 * bogus line breaks, non-breaking spaces, or zero-width characters. This
	 * helper removes those before performing an emptiness check.
	 *
	 * @param mixed $value Raw editor value.
	 * @param bool  $is_sanitized True when TinyMCE artifacts were already removed by the caller.
	 * @return bool True when the content is visually empty.
	 */
	public static function isEditorContentEmpty( $value, bool $is_sanitized = false ) {

		if ( ! is_string( $value ) ) {
			return empty( $value );
		}

		if ( ! $is_sanitized ) {
			$value = self::sanitizeEditorContent( $value );
		}

		if ( preg_match( '/<(img|hr|video|audio|svg|canvas)\b/i', $value ) ) {
			return false;
		}

		$plain = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
		$plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$stripped_plain = preg_replace( '/[\x{00A0}\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\s]+/u', '', $plain );
		if ( null !== $stripped_plain ) {
			$plain = $stripped_plain;
		}

		return $plain === '';
	}

	/**
	 * Check whether textarea-like content is visually empty without applying
	 * editor-specific artifact sanitization.
	 *
	 * This only treats whitespace, non-breaking spaces, `<br>` tags, and empty
	 * paragraph wrappers as empty. Arbitrary HTML/code examples remain intact.
	 *
	 * @param mixed $value Raw textarea value.
	 * @return bool True when the content is visually empty.
	 */
	public static function isTextareaContentEmpty( $value ) {

		if ( ! is_string( $value ) ) {
			return empty( $value );
		}

		$normalized = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$without_breaks = preg_replace( '/<br\s*\/?>/iu', '', $normalized );
		if ( null !== $without_breaks ) {
			$normalized = $without_breaks;
		}
		$without_empty_paragraphs = preg_replace( '/<p\b[^>]*>(?:[\x{00A0}\x{200B}-\x{200F}\x{FEFF}\s]|&nbsp;|&#160;|<br\s*\/?>)*<\/p>/iu', '', $normalized );
		if ( null !== $without_empty_paragraphs ) {
			$normalized = $without_empty_paragraphs;
		}
		$stripped_normalized = preg_replace( '/[\x{00A0}\x{200B}-\x{200F}\x{FEFF}\s]+/u', '', $normalized );
		if ( null !== $stripped_normalized ) {
			$normalized = $stripped_normalized;
		}

		return $normalized === '';
	}

	/**
	 * Retrieve and format all ACF fields attached to a post, term, or options page.
	 *
	 * Resolves the post ID from a WP_Post / Timber post object, a term object,
	 * a numeric ID, a string (options page key), or — when null is passed —
	 * from `get_queried_object_id()` (which also covers Gutenberg block
	 * contexts).  Each field value is passed through {@see fieldFormatter()}.
	 * Fields with an empty formatted value are omitted from the result.
	 *
	 * Resolution goes through `get_field_objects()`, which honours the field
	 * group's location rule. A group scoped to something the current request
	 * does not establish — a `nav_menu_item` rule under WP-CLI with another
	 * theme active, so no menu location is registered — surfaces no fields at
	 * all, and reads identically to "the data is not there". `get_field()` by
	 * name still resolves in that situation; prefer it when probing.
	 *
	 * @param object|int|string|null $post       Post object, term object, numeric post ID,
	 *                                            options-page string key, or null to use the
	 *                                            current queried object.
	 * @param bool                   $is_preview True when rendering inside a Gutenberg block
	 *                                            preview. Suppresses shortcode execution for
	 *                                            certain form plugins, and keeps the raw
	 *                                            definition of an unfilled repeater /
	 *                                            flexible_content so a placeholder can render.
	 * @return array<string, mixed> Associative array keyed by ACF field name with formatted values.
	 */
	public static function formatFields( $post = null, $is_preview = false ) {

		$post_id = null;

		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			$post_id = $post->ID;
		} elseif ( is_object( $post ) && ! empty( $post->term_id ) ) {
			$post_id = $post->term_id;
		} elseif ( is_numeric( $post ) ) {
			$post_id = $post;
		} elseif ( is_string( $post ) ) { // like page options values
			$post_id = $post;
		} else {
			// this will get also queried object id for gutenberg block
			// format like block_f85ccf81c4271662c50f0d92f2da2d1
			$post_id = get_queried_object_id();
		}

		// ACF's default screen detection for special post-id forms is
		// incomplete — `get_field_objects()` silently drops field groups that
		// the location matcher can't reach from the resolved screen. Two known
		// gaps:
		//
		// 1. `nav_menu_item` posts decode to `{type: post, id}`, dropping the
		//    `nav_menu_item` + `nav_menu` keys location rules need.
		// 2. Options-page string ids (`'option'`, `'options'`, custom keys)
		//    decode to `{type: option, id: 'option'}`, dropping the
		//    `options_page` key location rules need. ACF surfaces *some*
		//    matching groups via local-store walks but reliably drops others
		//    when multiple groups target the same options page.
		//
		// Build the right screen ourselves for each context so per-item /
		// per-options-page ACF groups surface through formatFields uniformly.
		if ( self::isNavMenuItemPostId( $post, $post_id ) ) {
			$fields = self::getFieldObjectsForNavMenuItem( (int) $post_id );
		} elseif ( self::isOptionsPostId( $post_id ) ) {
			$fields = self::getFieldObjectsForOptions( $post_id );
		} else {
			$fields = get_field_objects( $post_id );
		}

		// if we are inside gutenberg block we need to get real $post_id for formatters to work properly
		if ( str_starts_with( (string) $post_id, 'block_' ) ) {
			global $post;

			if ( isset( $post ) && isset( $post->ID ) ) {
				$post_id = $post->ID;
			}
		}

		$content = [];
		if ( ! empty( $fields ) ) {
			foreach ( $fields as $key => $field ) {
				$value = self::fieldFormatter( $field, $post_id, $is_preview );

				if ( ! empty( $value ) ) {
					$content[ $key ] = $value;
				}
			}
		}

		return $content;
	}

	/**
	 * Whether the resolved post id refers to a `nav_menu_item` post.
	 *
	 * Detection prefers the in-memory object (avoids an extra DB hit) and
	 * falls back to `get_post_type()` when only a numeric id is available.
	 *
	 * @param mixed       $post     Original input passed to {@see formatFields()}.
	 * @param mixed       $post_id  Resolved post id.
	 */
	private static function isNavMenuItemPostId( $post, $post_id ): bool {
		// Term objects (`formatFields($category)`) must never enter the
		// menu-item path — otherwise a numeric `term_id` that happens to equal
		// an unrelated `nav_menu_item` post id would route the term through
		// the wrong resolver. Two complementary guards:
		//   1. Explicit `WP_Term` instance check covers WordPress-shaped
		//      term objects regardless of which extra properties they carry
		//      (a term with a coincidental `post_type` meta wouldn't slip
		//      through a duck-typed check).
		//   2. Property-presence fallback (`term_id` without `post_type`)
		//      covers term-like plain objects from `(object)` casts and
		//      third-party shims that don't extend `WP_Term`.
		if ( is_object( $post ) ) {
			if ( $post instanceof \WP_Term ) {
				return false;
			}
			if ( isset( $post->term_id ) && ! isset( $post->post_type ) ) {
				return false;
			}
			if ( isset( $post->post_type ) && $post->post_type === 'nav_menu_item' ) {
				return true;
			}
		}
		if ( is_numeric( $post_id ) && function_exists( 'get_post_type' ) ) {
			return get_post_type( (int) $post_id ) === 'nav_menu_item';
		}
		return false;
	}

	/**
	 * Resolve ACF field objects for a `nav_menu_item` post by building the
	 * location screen ACF needs (`nav_menu_item` + `nav_menu`) ourselves and
	 * delegating the walk to {@see getFieldObjectsByScreen()}.
	 *
	 * No fallback to `get_field_objects()`: once `isNavMenuItemPostId()` has
	 * identified the input as a menu item, the default resolver would hit
	 * the same upstream gap we're working around and is therefore skipped.
	 * `false`/`[]` from this helper means "no ACF groups registered for this
	 * menu item" — `formatFields()` then yields an empty field map without a
	 * second resolution pass.
	 *
	 * @param int $post_id Menu-item post id.
	 * @return array<string, array<string, mixed>>|false  False when ACF (or
	 *         `get_field`) isn't loaded or no field group matches the item.
	 */
	private static function getFieldObjectsForNavMenuItem( int $post_id ) {
		$menu_id = 0;
		if ( function_exists( 'wp_get_post_terms' ) ) {
			$menus = wp_get_post_terms( $post_id, 'nav_menu' );
			if ( is_array( $menus ) && ! empty( $menus ) && isset( $menus[0]->term_id ) ) {
				$menu_id = (int) $menus[0]->term_id;
			}
		}

		return self::getFieldObjectsByScreen(
			[
				'nav_menu_item' => $post_id,
				'nav_menu' => $menu_id,
			],
			$post_id
		);
	}

	/**
	 * Whether the resolved post id refers to an ACF options page.
	 *
	 * Reuses ACF's own `acf_decode_post_id()` to classify the string so any
	 * variant ACF recognises (`'option'`, `'options'`, custom post-id keys
	 * declared in `acf_add_options_page([...'post_id' => ...])`) is handled.
	 *
	 * @param mixed $post_id Resolved post id.
	 */
	private static function isOptionsPostId( $post_id ): bool {
		if ( ! is_string( $post_id ) || str_starts_with( $post_id, 'block_' ) ) {
			return false;
		}
		if ( ! function_exists( 'acf_decode_post_id' ) ) {
			return false;
		}
		$decoded = acf_decode_post_id( $post_id );
		return is_array( $decoded ) && ( $decoded['type'] ?? '' ) === 'option';
	}

	/**
	 * Resolve ACF field objects for the options page(s) that match the
	 * caller's post-id namespace.
	 *
	 * Iterates `acf_get_options_pages()`, filtering to pages whose registered
	 * `post_id` namespace matches the caller's `$post_id`. For each matching
	 * page we ask ACF for its field groups via an explicit `options_page`
	 * screen, walk each group's top-level fields and read their values
	 * through `get_field()` against the original `$post_id`. Returns a
	 * `name => field` map identical to `get_field_objects()`.
	 *
	 * Namespace matching: `acf_add_options_page(['post_id' => 'company_settings'])`
	 * stores its fields under `company_settings`, not under the default
	 * `option` namespace — so `formatFields('option')` must not surface them,
	 * and `formatFields('company_settings')` must not pick up unrelated
	 * default-namespace pages. Both sides are routed through
	 * `decodeOptionsNamespace()`, which canonicalizes the default-namespace
	 * `option` / `options` alias to a single id (`'options'`). ACF Pro's
	 * own `acf_decode_post_id()` does NOT collapse the alias on its own —
	 * see {@see decodeOptionsNamespace()} for details.
	 *
	 * Multiple pages sharing the same namespace are still unioned. Fields
	 * with the same `name` across same-namespace pages collide on
	 * last-writer-wins.
	 *
	 * No fallback to `get_field_objects()` for the same reason as
	 * `getFieldObjectsForNavMenuItem()`: the default resolver hits the
	 * same upstream gap and is skipped on purpose.
	 *
	 * @param string $post_id The string id originally passed to {@see formatFields()}.
	 * @return array<string, array<string, mixed>>|false  False when ACF (or
	 *         `get_field`) isn't loaded or no field group matches a
	 *         same-namespace options page.
	 */
	private static function getFieldObjectsForOptions( string $post_id ) {
		if ( ! function_exists( 'acf_get_options_pages' ) || ! function_exists( 'acf_decode_post_id' ) ) {
			return false;
		}

		$pages = acf_get_options_pages();
		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return false;
		}

		$caller_namespace = self::decodeOptionsNamespace( $post_id );
		if ( $caller_namespace === null ) {
			return false;
		}

		$fields = [];
		foreach ( $pages as $page ) {
			$menu_slug = $page['menu_slug'] ?? null;
			if ( ! $menu_slug ) {
				continue;
			}
			$page_namespace = self::decodeOptionsNamespace( $page['post_id'] ?? 'options' );
			if ( $page_namespace !== $caller_namespace ) {
				continue;
			}
			$page_fields = self::getFieldObjectsByScreen(
				[ 'options_page' => $menu_slug ],
				$post_id
			);
			if ( is_array( $page_fields ) ) {
				$fields = array_merge( $fields, $page_fields );
			}
		}

		return $fields ?: false;
	}

	/**
	 * Canonical options-namespace id for a post-id string, or `null` if the
	 * string doesn't decode to an options namespace.
	 *
	 * Used to compare a caller's `$post_id` against each registered options
	 * page's `post_id` after collapsing the `option` / `options` alias to a
	 * single canonical id.
	 *
	 * ACF Pro's `acf_decode_post_id()` does NOT collapse the alias on its own:
	 * `acf_decode_post_id('option')` returns `id='option'` while
	 * `acf_decode_post_id('options')` returns `id='options'`. Both refer to
	 * the default options namespace, so the comparison must canonicalize them
	 * to the same string. Without this, `formatFields('option')` (singular)
	 * silently returns an empty array when the registered options page uses
	 * the ACF default `post_id` of `'options'` (plural) — which is every
	 * page registered without an explicit `post_id` argument.
	 *
	 * @param mixed $post_id Caller's `$post_id` argument or a page's
	 *                      `$page['post_id']` field.
	 * @return string|null
	 */
	private static function decodeOptionsNamespace( $post_id ): ?string {
		$decoded = acf_decode_post_id( (string) $post_id );
		if ( ! is_array( $decoded ) || ( $decoded['type'] ?? '' ) !== 'option' ) {
			return null;
		}
		$id = $decoded['id'] ?? null;
		if ( ! is_string( $id ) || $id === '' ) {
			return null;
		}
		// Collapse the 'option' / 'options' alias — both refer to the default
		// options namespace. ACF Pro's acf_decode_post_id() keeps them
		// separate; canonicalize to the plural form (the default `post_id`
		// that ACF uses for an options page registered without an explicit
		// `post_id`, so this matches what `acf_get_options_pages()` reports).
		if ( $id === 'option' ) {
			$id = 'options';
		}
		return $id;
	}

	/**
	 * Resolve ACF field objects for an explicit location-rule screen.
	 *
	 * Walks `acf_get_field_groups($screen)` → `acf_get_fields($group)` and
	 * reads each top-level field's value via `get_field($name, $value_post_id)`.
	 * Returns a `name => field` map identical to the shape `get_field_objects()`
	 * produces, so callers' downstream `fieldFormatter()` loop works
	 * unchanged.
	 *
	 * Shared by {@see getFieldObjectsForNavMenuItem()} and
	 * {@see getFieldObjectsForOptions()}: both contexts build their own screen
	 * (`nav_menu_item` + `nav_menu` vs. `options_page`) and delegate the walk
	 * here. The split keeps context detection / screen construction in the
	 * caller and the ACF group→field→value mechanics in one place.
	 *
	 * @param array      $screen        Screen array passed to `acf_get_field_groups()`.
	 * @param int|string $value_post_id Post id used for `get_field()` value reads.
	 * @return array<string, array<string, mixed>>|false  False when ACF isn't
	 *         loaded or no matching field group surfaces fields.
	 */
	private static function getFieldObjectsByScreen( array $screen, $value_post_id ) {
		if ( ! function_exists( 'acf_get_field_groups' )
			|| ! function_exists( 'acf_get_fields' )
			|| ! function_exists( 'get_field' )
		) {
			return false;
		}

		$groups = acf_get_field_groups( $screen );
		if ( ! is_array( $groups ) || empty( $groups ) ) {
			return false;
		}

		$fields = [];
		foreach ( $groups as $group ) {
			$group_fields = acf_get_fields( $group );
			if ( ! is_array( $group_fields ) ) {
				continue;
			}
			foreach ( $group_fields as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$field['value'] = get_field( $field['name'], $value_post_id );
				$fields[ $field['name'] ] = $field;
			}
		}

		return $fields ?: false;
	}

	/**
	 * Format a single ACF field array into a template-ready value.
	 *
	 * Dispatches to type-specific formatting logic:
	 * - `link` → {@see formatLink()}
	 * - `wysiwyg` → TinyMCE artifact cleanup, visually-empty detection, shortcodes expanded
	 * - `textarea` → shortcodes expanded without editor-specific sanitization
	 * - `image` → {@see formatImage()}
	 * - `gallery` → each item formatted by type (image / file / video)
	 * - `file` → {@see formatFile()}
	 * - `post_object` → Contact Form 7 / WPForms shortcodes rendered (or kept
	 *   as raw strings during preview)
	 * - `oembed` → iframe `src` attribute extracted
	 * - `repeater` / `group` → sub-fields recursively formatted
	 * - `flexible_content` → layout sub-fields recursively formatted
	 *
	 * After type-specific handling the `field_formatter_{type}` WordPress
	 * filter is applied, allowing custom overrides per field type.
	 *
	 * @param array|mixed  $field      ACF field definition array containing at minimum
	 *                                  `type` and `value` keys.
	 * @param int|string|null $post_id  Post ID used by nested formatters (may be a block ID string).
	 * @param bool         $is_preview True when rendering inside a Gutenberg block preview.
	 * @return mixed Formatted field value, or false when `$field` is empty.
	 */
	public static function fieldFormatter( $field, $post_id = null, $is_preview = false ) {

		// we need to allow post_id null when we are using it during preview block without saving
		if ( empty( $field ) ) {
			return FALSE;
		}

		if ( ! isset( $field['type'] ) ) {
			return $field;
		}

		// A surfaced ACF field object with no saved value (options-page group
		// present in the local store but never filled — `value` key missing
		// or null) must read as "empty", not leak the raw field-definition
		// array into templates — `{{ content.x|typography }}` fatals on
		// arrays. Mirrors the pre-1.8 behaviour where valueless option fields
		// simply never surfaced. Repeater/flexible keep their documented
		// null-value pass-through, but only under preview (see below).
		if ( ! array_key_exists( 'value', $field ) ) {
			return FALSE;
		}

		if ( ! isset( $field['value'] ) ) {
			// Only a block preview has a use for the definition: an unsaved block
			// needs `sub_fields` / `layouts` to render its placeholder. On an
			// ordinary read the same array masquerades as a populated list —
			// `x|length > 0` passes on the definition's own keys, so a template
			// opens its wrapper for rows that do not exist.
			if ( $is_preview && in_array( $field['type'], array( 'repeater', 'flexible_content' ), true ) ) {
				return $field;
			}

			return FALSE;
		}

		if ( $field['type'] === 'link' ) {

			$field['value'] = self::formatLink( $field['value'], $post_id, $field );

		} elseif ( $field['type'] === 'wysiwyg' ) {

			$field['value'] = self::sanitizeEditorContent( $field['value'] );

			if ( self::isEditorContentEmpty( $field['value'], true ) ) {
				$field['value'] = '';
			}

			$field['value'] = do_shortcode( $field['value'] );

		} elseif ( $field['type'] === 'textarea' ) {

			if ( self::isTextareaContentEmpty( $field['value'] ) ) {
				$field['value'] = '';
			} else {
				$field['value'] = do_shortcode( $field['value'] );
			}

		} elseif ( $field['type'] === 'image' ) {

			$data = self::formatImage( $field['value'], $post_id, $field );
			if ( $data ) {
				$field['value'] = $data;
			}

		} elseif ( $field['type'] === 'gallery' ) {

			if ( is_countable( $field['value'] ) ) {
				foreach ( $field['value'] as &$item ) {
					if ( $item['type'] === 'image' ) {
						$data = self::formatImage( $item, $post_id, $field );
						if ( $data ) {
							$item = $data;
						}
					} else if ( $item['type'] === 'application' ) {
						$data = self::formatFile( $item, $post_id, $field );
						if ( $data ) {
							$item = $data;
						}
					} else if ( $item['type'] === 'video' ) {
						$data = self::formatVideo( $item, $post_id, $field );
						if ( $data ) {
							$item = $data;
						}
					}
				}
			}

		} elseif ( $field['type'] === 'file' ) {

			$data = self::formatFile( $field['value'], $post_id, $field );
			if ( $data ) {
				$field['value'] = $data;
			}

		} elseif ( $field['type'] === 'post_object' ) {

			if ( $field['value'] instanceof \WP_Post ) {
				if ( $field['value']->post_type === 'wpcf7_contact_form' ) {
					// during preview we need to return only shortcode as preview is not working
					if ( $is_preview ) {
						$field['value'] = '[contact-form-7 id="' . $field['value']->ID . '" title=""]';
					} else {
						$field['value'] = do_shortcode( '[contact-form-7 id="' . $field['value']->ID . '" title=""]' );
					}
				} elseif ( $field['value']->post_type === 'wpforms' ) {
					if ( $is_preview ) {
						// during preview we need to return only shortcode as preview is not working
						$field['value'] = '[wpforms id="' . $field['value']->ID . '"]';
					} else {
						$field['value'] = do_shortcode( '[wpforms id="' . $field['value']->ID . '"]' );
					}
				}
			}

		} elseif ( $field['type'] === 'oembed' ) {

			// parse iframe src only
			$field['value'] = preg_match( '/src="(.+?)"/', $field['value'], $matches ) ? $matches[1] : '';

		} elseif ( in_array( $field['type'], [ 'repeater', 'group' ] ) ) {

			// create array with sub_fields by name
			$sub_fields = [];
			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				foreach ( $field['sub_fields'] as $sub_field ) {
					$sub_fields[ $sub_field['name'] ] = $sub_field;
				}
			}

			// we need to combine sub field configuration to sub field value
			if ( is_countable( $field['value'] ) ) {
				foreach ( $field['value'] as $key => &$value ) {
					// group field could be associative array
					if ( is_countable( $field['value'] ) && ! Helpers::isAssoc( $field['value'] ) ) {
						foreach ( $value as $sub_key => &$sub_value ) {
							if ( isset( $sub_fields[ $sub_key ] ) ) {
								$sub_fields[ $sub_key ]['value'] = $sub_value;
								$sub_value = self::fieldFormatter( $sub_fields[ $sub_key ], $post_id, $is_preview );
							}
						}
						if ( 'repeater' === $field['type'] && is_array( $value ) ) {
							$value = self::appendVideoSources( $value );
						}
					} else {
						if ( isset( $sub_fields[ $key ] ) ) {
							$sub_fields[ $key ]['value'] = $value;
							$value = self::fieldFormatter( $sub_fields[ $key ], $post_id, $is_preview );
						}
					}
				}
			}

		} elseif ( in_array( $field['type'], [ 'flexible_content' ] ) ) {

			// create array with layouts and sub_fields by name
			$layouts = [];
			if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					$sub_fields = [];
					if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						foreach ( $layout['sub_fields'] as $sub_field ) {
							$sub_fields[ $sub_field['name'] ] = $sub_field;
						}
					}
					$layouts[ $layout['name'] ] = $sub_fields;
				}
			}

			// we need to combine layout field configuration to layout field value
			if ( is_countable( $field['value'] ) ) {
				foreach ( $field['value'] as $key => &$value ) {
					// group field could be associative array
					if ( is_countable( $value ) ) {
						foreach ( $value as $layout_key => &$layout_value ) {
							if ( isset( $layouts[ $value['acf_fc_layout'] ][ $layout_key ] ) ) {
								$layouts[ $value['acf_fc_layout'] ][ $layout_key ]['value'] = $layout_value;
								$layout_value = self::fieldFormatter( $layouts[ $value['acf_fc_layout'] ][ $layout_key ], $post_id, $is_preview );
							}
						}
					} else {
						if ( isset( $layouts[ $key ] ) ) {
							$layouts[ $key ]['value'] = $value;
							$value = self::fieldFormatter( $layouts[ $key ], $post_id, $is_preview );
						}
					}
				}
			}

		}

		// allow to alter formatter for specific field type
		$field = apply_filters( 'field_formatter_' . $field['type'], $field, $post_id );

		return $field['value'];
	}

	/**
	 * Normalise an ACF link field value and optionally translate it via WPML.
	 *
	 * - Moves `target` into `attributes['target']` and removes it from the
	 *   root level when empty.
	 * - Sanitises `title` with `wp_kses()`, allowing only inline tags
	 *   (`<strong>`, `<b>`, `<i>`, `<em>`, `<br>`).
	 * - When WPML is active and the field has `wpml_cf_preferences === 2`,
	 *   resolves the URL to the translated permalink of the target post,
	 *   preserving any original query string and fragment.
	 * - External / `_blank` links are returned without WPML URL translation.
	 *
	 * @param array|mixed  $value    ACF link field value (associative array with `url`, `title`,
	 *                               and optionally `target`).  Non-array values are returned as-is.
	 * @param int|null     $post_id  Post ID the field belongs to (unused directly; kept for API parity).
	 * @param array        $field    ACF field definition array; `wpml_cf_preferences` is read from here.
	 * @return array|mixed Normalised link array, or the original value unchanged when it is not an array.
	 */
	public static function formatLink( $value, $post_id, $field ) {

		if ( ! is_array( $value ) ) {
			return $value;
		}

		// copy target to attributes field
		if ( isset( $value['target'] ) ) {
			if ( ! empty( $value['target'] ) ) {
				$value['attributes']['target'] = $value['target'];
			} else {
				unset( $value['target'] );
			}
		}

		// allow certain HTML tags for the link title
		if ( isset( $value['title'] ) ) {
			// decode HTML entities first as string is already encoded
			$value['title'] = html_entity_decode( $value['title'] );
			// allow only certain tags
			$value['title'] = wp_kses( $value['title'], [
				'strong' => [],
				'b' => [],
				'i' => [],
				'em' => [],
				'br' => [],
			] );
		}

		// apply only for internal links
		if ( isset( $value['attributes']['target'] ) && $value['attributes']['target'] === '_blank' ) {
			return $value;
		}

		// apply only if WPML is enabled and on links which are set to translatable
		if ( ! isset( $field['wpml_cf_preferences'] ) || $field['wpml_cf_preferences'] !== 2 ) {
			return $value;
		}

		if ( isset( $value['url'] ) ) {

			$parsed_url = parse_url( $value['url'] );
			$post_id = url_to_postid( $value['url'] );
			// if we are in non default language we could get 0 for valid URLs
			// then we need to extract only slug from URL
			if ( $post_id === 0 ) {
				$url = self::extract_slug_from_url( $value['url'] );
				$post_id = url_to_postid( $url );
			}

			if ( $post_id > 0 ) {

				$post_type = get_post_type( $post_id );
				$translated_url = apply_filters( 'wpml_object_id', $post_id, $post_type );
				$translated_url = get_permalink( $translated_url );

				// Add query if it's there
				if ( isset( $parsed_url['query'] ) ) {
					$translated_url .= '?' . $parsed_url['query'];
				}

				// Add fragment if it's there
				if ( isset( $parsed_url['fragment'] ) ) {
					$translated_url .= '#' . $parsed_url['fragment'];
				}

				// replace with translated url
				$value['url'] = $translated_url;
			}
		}

		return $value;
	}

	/**
	 * Remap an ACF reference field's id(s) to the target language so a translated
	 * context points at the translated entity, not the source-language one.
	 *
	 * The WPML element type passed to `wpml_object_id` depends on the ACF field
	 * type:
	 *   - image / file / gallery → 'attachment'
	 *   - post_object / relationship / page_link → the referenced post's own post
	 *     type (post_object can target mixed types, so it's resolved per id)
	 *   - taxonomy → the field's `taxonomy` setting
	 *
	 * Other types (text, user, link, …) are returned unchanged — `user` because
	 * WPML doesn't translate users, `link` because it stores a URL structure that
	 * {@see formatLink()} handles through its own translation path. Non-numeric
	 * entries (e.g. a `page_link` holding a raw URL) also pass through untouched.
	 *
	 * Shared formatting-layer primitive: {@see \Parisek\TimberKit\WpmlBlockOverride}
	 * delegates here for Copy-field sync, and any field formatter can reuse it
	 * instead of re-implementing wpml_object_id-per-type.
	 *
	 * @param mixed                $value       A single id, a list of ids, or any
	 *                                          non-reference value (passed through).
	 * @param array<string, mixed> $field       ACF field array; `type` (and
	 *                                          `taxonomy` for taxonomy fields) drive
	 *                                          element-type resolution.
	 * @param string               $target_lang WPML language code to remap into.
	 * @return mixed The remapped id(s), or `$value` unchanged when not a reference.
	 */
	public static function remapWpmlReference( $value, array $field, string $target_lang ) {
		$type = $field['type'] ?? '';

		if ( in_array( $type, [ 'image', 'file', 'gallery' ], true ) ) {
			return self::remapWpmlObjectIds( $value, $target_lang, static fn( int $id ): string => 'attachment' );
		}

		if ( in_array( $type, [ 'post_object', 'relationship', 'page_link' ], true ) ) {
			return self::remapWpmlObjectIds( $value, $target_lang, static fn( int $id ): string => get_post_type( $id ) ?: 'post' );
		}

		if ( $type === 'taxonomy' ) {
			$taxonomy = $field['taxonomy'] ?? '';
			if ( $taxonomy === '' ) {
				return $value;
			}
			return self::remapWpmlObjectIds( $value, $target_lang, static fn( int $id ): string => $taxonomy );
		}

		return $value;
	}

	/**
	 * Remap a single WPML object id or a list of them to `$target_lang` via the
	 * `wpml_object_id` filter. `$element_type_for` resolves the WPML element type
	 * for each id. Non-numeric / non-positive values pass through unchanged.
	 *
	 * @param mixed                $value
	 * @param callable(int):string $element_type_for
	 * @return mixed
	 */
	private static function remapWpmlObjectIds( $value, string $target_lang, callable $element_type_for ) {
		if ( is_array( $value ) ) {
			return array_map(
				static fn( $id ) => self::remapWpmlObjectId( $id, $target_lang, $element_type_for ),
				$value
			);
		}
		return self::remapWpmlObjectId( $value, $target_lang, $element_type_for );
	}

	/**
	 * Remap one WPML object id to `$target_lang`, keeping the original when it is
	 * non-numeric, non-positive, or has no translation (`return_original = true`).
	 *
	 * @param mixed                $value
	 * @param callable(int):string $element_type_for
	 * @return mixed
	 */
	private static function remapWpmlObjectId( $value, string $target_lang, callable $element_type_for ) {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}
		$id = (int) $value;
		if ( $id <= 0 ) {
			return $value;
		}
		return apply_filters( 'wpml_object_id', $id, $element_type_for( $id ), true, $target_lang );
	}

	/**
	 * Convert a Timber menu (or menu name) into a nested flat array structure.
	 *
	 * Recursively processes menu items and their children.  WordPress default
	 * CSS classes (`menu-item*`, `current_page*`, `page_item*`, `page-item*`)
	 * are stripped from each item's class list.  When WPML (sitepress) is
	 * active, item descriptions are registered as translatable strings and
	 * replaced with their translated equivalents.  Any ACF fields attached to
	 * the menu item (via {@see formatFields()}) are merged into the item array.
	 *
	 * @param \Timber\Menu|string $menu_or_name  A Timber Menu object or a registered menu name/slug.
	 * @param \Timber\MenuItem|null $parent_item When null the root items of the menu are processed;
	 *                                            otherwise the children of this item are processed
	 *                                            (used for recursive calls).
	 * @return array<int, array{id: int, title: string, url: string, description: string, attributes: array{target: string|null, class: string}, in_active_trail: bool, is_active: bool, below: array}>
	 *               Indexed list of menu item arrays with nested `below` lists.
	 */
	public static function formatMenu( $menu_or_name, $parent_item = null ) {

		// If a menu name (string) was passed, fetch the menu object once.
		$menu = is_string( $menu_or_name ) ? Timber::get_menu( $menu_or_name ) : $menu_or_name;

		// Decide which items to process: root items or a parent's children.
		$source_items = [];
		if ( $parent_item === null ) {
			if ( isset( $menu->items ) ) {
				$source_items = $menu->items;
			}
		} else {
			if ( isset( $parent_item->children ) ) {
				$source_items = $parent_item->children;
			}
		}

		$items = [];
		foreach ( $source_items as $item ) {

			$attributes = [];
			$attributes['target'] = $item->target ?: null;
			if ( isset( $item->classes ) && is_array( $item->classes ) ) {
				// remove from array WordPress defaults classes
				$item->classes = array_filter( $item->classes, function ( $class ) {
					return strpos( $class, 'menu-item' ) === FALSE
						&& strpos( $class, 'current_page' ) === FALSE
						&& strpos( $class, 'page_item' ) === FALSE
						&& strpos( $class, 'page-item' ) === FALSE;
				} );
				$attributes['class'] = implode( ' ', $item->classes );
			}

			// we cannot directly translate description, so we register it here manually
			$description = $item->description;
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) && ! empty( $description ) ) {
				$default_language = apply_filters( 'wpml_default_language', null );
				icl_register_string( $menu->name . ' menu', 'Menu Item Description ' . $item->ID, $description, false, $default_language );
				$description = icl_t( $menu->name . ' menu', 'Menu Item Description ' . $item->ID, $description );
			}

			$acf_fields = (array) Helpers::formatFields( $item );

			$items[] = [
				'id' => $item->ID,
				'title' => $item->name,
				'url' => $item->url,
				'description' => $description,
				'attributes' => $attributes,
				'in_active_trail' => $item->current_item_ancestor,
				'is_active' => $item->current,
				'below' => self::formatMenu( $menu, $item ),
			] + $acf_fields;
		}

		return $items;
	}

	/**
	 * Build a language-switcher array from WPML's active languages.
	 *
	 * Returns an empty array when WPML is not installed (the `ICL_SITEPRESS_VERSION`
	 * constant is absent).  For languages where the translated content is
	 * missing (`language['missing'] === true`) the `url` is set to an empty
	 * string so templates can disable the link.
	 *
	 * @return array<int, array{id: string, title: string, url: string, home_url: string, is_active: bool}>
	 *               Indexed list of language items, or an empty array when
	 *               WPML is not active.
	 */
	public static function formatLanguageSwitcher() {

		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return [];
		}

		global $sitepress;

		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => FALSE ] );

		$items = [];
		if ( ! empty( $languages ) && is_countable( $languages ) ) {
			foreach ( $languages as $language ) {
				$url = esc_url( $language['url'] );
				if ( isset( $language['missing'] ) && $language['missing'] ) {
					$url = '';
				}
				$home_url = esc_url( $sitepress->language_url( $language['language_code'] ) );
				$items[] = [
					'id' => esc_html( $language['language_code'] ),
					'title' => esc_html( $language['native_name'] ),
					'url' => $url,
					'home_url' => $home_url,
					'is_active' => (bool) $language['active'],
				];
			}
		}

		return $items;
	}

	/**
	 * Extract the path (slug) from a URL, stripping the WPML language prefix if present.
	 *
	 * Useful as a fallback for `url_to_postid()` when WPML is active with
	 * language URL prefixes (e.g. `/cs/my-page`): WordPress may return 0 for
	 * a valid URL in a non-default language, so stripping the prefix first
	 * allows a second lookup against the default-language slug.
	 *
	 * @param string $url Absolute URL to process.
	 * @return string URL path without domain and without leading language prefix,
	 *                always starting with `/` (or an empty string for the site root).
	 */
	public static function extract_slug_from_url( $url ) {

		// Remove domain from URL to get just the path
		$parsed_url = parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

		// Remove trailing slash for consistency
		$path = rtrim( $path, '/' );

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
			// Get all active language codes
			$active_languages = apply_filters( 'wpml_active_languages', null );
			$active_languages = array_keys( $active_languages );

			// Remove language prefix if present
			foreach ( $active_languages as $lang ) {
				$prefix = '/' . $lang;
				if ( strpos( $path, $prefix . '/' ) === 0 ) {
					$path = substr( $path, strlen( $prefix ) );
					break;
				} elseif ( $path === $prefix ) {
					$path = '';
					break;
				}
			}
		}

		// Ensure path starts with a single slash
		if ( $path !== '' && $path[0] !== '/' ) {
			$path = '/' . $path;
		}

		return $path;
	}

	/**
	 * Estimate reading time in minutes for a post or arbitrary HTML/text.
	 *
	 * When `$wpm` is null the helper auto-detects a reading speed from the post's
	 * WPML language (per-post first, then site-wide), falling back to the WP locale.
	 * Slavic languages get a lower default because their words are longer and more
	 * inflected, so equivalent prose contains fewer countable tokens.
	 *
	 * Pass an explicit `$wpm` to bypass detection entirely. Override the language
	 * map via the `timber_kit_read_time_wpm_per_language` filter, or the final
	 * minutes via `timber_kit_read_time_minutes`.
	 *
	 * @param int|string|null $source           Post ID, raw HTML/text, or null to use the global post.
	 * @param int|null        $wpm              Words per minute. `null` enables language auto-detection.
	 * @param int             $secondsPerImage  Reading-time budget per `<img>` tag in the content.
	 * @return int Minutes, minimum 1.
	 */
	public static function readTime( int|string|null $source = null, ?int $wpm = null, int $secondsPerImage = 12 ): int {
		$post_ref = null;
		if ( $source === null ) {
			$post_ref = function_exists( 'get_post' ) ? get_post() : null;
			$content  = $post_ref instanceof \WP_Post ? (string) $post_ref->post_content : '';
		} elseif ( is_int( $source ) ) {
			$post_ref = function_exists( 'get_post' ) ? get_post( $source ) : null;
			$content  = $post_ref instanceof \WP_Post ? (string) $post_ref->post_content : '';
		} else {
			$content = $source;
		}

		if ( $wpm === null ) {
			$wpm = self::detectReadTimeWpm( $post_ref );
		}
		if ( $wpm < 1 ) {
			$wpm = 200;
		}
		if ( $secondsPerImage < 0 ) {
			$secondsPerImage = 0;
		}

		$images = (int) preg_match_all( '/<img\b/i', $content );
		$text   = wp_strip_all_tags( $content );
		preg_match_all( '/\p{L}+/u', $text, $matches );
		$words = count( $matches[0] );

		$minutes = (int) ceil( ( $words / $wpm ) + ( $images * $secondsPerImage / 60 ) );
		$minutes = max( 1, $minutes );

		$filtered = apply_filters( 'timber_kit_read_time_minutes', $minutes, $words, $images, $post_ref );

		return max( 1, (int) $filtered );
	}

	/**
	 * Pick a reading speed (WPM) for the given post based on its language.
	 *
	 * @param \WP_Post|null $post Post whose language drives the lookup, or null.
	 * @return int Words per minute.
	 */
	private static function detectReadTimeWpm( ?\WP_Post $post ): int {
		$map = [
			'cs' => 170,
			'sk' => 170,
			'pl' => 170,
			'de' => 190,
			'en' => 220,
			'fr' => 220,
			'es' => 220,
			'it' => 220,
		];

		$filtered = apply_filters( 'timber_kit_read_time_wpm_per_language', $map, $post );
		if ( is_array( $filtered ) ) {
			$map = $filtered;
		}

		$language = self::getLanguage( $post );

		foreach ( [ $language, substr( $language, 0, 2 ) ] as $key ) {
			if ( $key !== '' && isset( $map[ $key ] ) && is_int( $map[ $key ] ) && $map[ $key ] > 0 ) {
				return $map[ $key ];
			}
		}

		return 200;
	}

	/**
	 * Resolve a normalized (lowercased, trimmed) language code for the current context.
	 *
	 * The return value may include a region or script subtag (e.g. `pt-br`,
	 * `zh-hans`) when WPML provides one — see the WPML normalization note below
	 * for details.
	 *
	 * Lookup order:
	 *   1. Per-post WPML metadata when a post is supplied (`wpml_post_language_details`).
	 *   2. Current WPML site language (`wpml_current_language`).
	 *   3. First two characters of `get_locale()` as a final fallback (always 2 letters).
	 *
	 * Intended as the single source of truth for language detection across the kit,
	 * so any helper that needs to vary behavior by language (read time, breadcrumbs,
	 * SEO meta, hreflang, …) can call this without duplicating the WPML probe logic.
	 *
	 * WPML values are returned verbatim except for case + whitespace normalization,
	 * so locale-region codes like `pt-br` or `zh-hans` survive for hreflang/SEO use
	 * cases. Consumers that only care about the base language (e.g. read-time WPM
	 * map lookups) should take the first two characters of the result.
	 *
	 * @param \WP_Post|int|null $post Post or post ID whose language to detect, or null for the current site language.
	 * @return string Language code, lowercased (e.g. `cs`, `en`, `pt-br`).
	 */
	public static function getLanguage( \WP_Post|int|null $post = null ): string {
		if ( is_int( $post ) ) {
			$post = function_exists( 'get_post' ) ? get_post( $post ) : null;
		}

		if ( $post instanceof \WP_Post ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			if ( is_array( $details ) && isset( $details['language_code'] ) && is_string( $details['language_code'] ) ) {
				$code = strtolower( trim( $details['language_code'] ) );
				if ( $code !== '' ) {
					return $code;
				}
			}
		}

		$current = apply_filters( 'wpml_current_language', null );
		if ( is_string( $current ) ) {
			$code = strtolower( trim( $current ) );
			if ( $code !== '' ) {
				return $code;
			}
		}

		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';

		return strtolower( substr( $locale, 0, 2 ) );
	}

	/**
	 * Format an announcement-bar ACF group into a Twig/Alpine-ready shape.
	 *
	 * Replaces the per-project private get_announcement(). Expects the raw
	 * group value (typically `formatFields('option')['announcement']`):
	 * `enabled` (bool), `text` (string), `dates.date_from` / `dates.date_to`
	 * (ACF date_picker "U" timestamps, i.e. midnight UTC). The timestamps are
	 * re-anchored to `wp_timezone()` day bounds — 00:00:00 for `date_from`,
	 * 23:59:59 for `date_to` — so the bar starts and stops at the editor's
	 * wall-clock day, and returned in milliseconds for JS consumption.
	 *
	 * @param array<string, mixed>|null $value Raw announcement group value, or null when the field is absent.
	 * @return array{text: string, date_from: int, date_to: int} Disabled or absent input yields `['text' => '', 'date_from' => 0, 'date_to' => 0]`.
	 */
	public static function formatAnnouncement( ?array $value ): array {
		$enabled = ! empty( $value['enabled'] );
		$dates = ( isset( $value['dates'] ) && is_array( $value['dates'] ) ) ? $value['dates'] : [];

		$day_bound = static function ( mixed $ts, bool $end_of_day ): int {
			if ( empty( $ts ) ) {
				return 0;
			}
			$ymd = ( new \DateTime( '@' . (int) $ts ) )->format( 'Y-m-d' );
			$time = $end_of_day ? '23:59:59' : '00:00:00';
			return ( new \DateTime( "$ymd $time", wp_timezone() ) )->getTimestamp() * 1000;
		};

		return [
			'text' => $enabled ? (string) ( $value['text'] ?? '' ) : '',
			'date_from' => $enabled ? $day_bound( $dates['date_from'] ?? null, false ) : 0,
			'date_to' => $enabled ? $day_bound( $dates['date_to'] ?? null, true ) : 0,
		];
	}

	/**
	 * Merge custom labels onto a registered post type.
	 *
	 * Replaces the per-project boilerplate that renames the built-in `post`
	 * type to a domain term (Články, Novinky, Reference, …). Safe to call
	 * from a template controller at any point: applies immediately when
	 * `init` already fired, otherwise defers to `init` at a late priority so
	 * default-priority post type registrations run first.
	 *
	 * @param string $post_type Post type name (e.g. `post`).
	 * @param array<string, string> $labels Label overrides, keyed by WP_Post_Type_Labels property (`name`, `singular_name`, `add_new`, …). Keys not passed keep their registered value.
	 * @return void
	 */
	public static function relabelPostType( string $post_type, array $labels ): void {
		$apply = static function () use ( $post_type, $labels ): void {
			$object = get_post_type_object( $post_type );
			if ( null === $object ) {
				return;
			}
			foreach ( $labels as $key => $value ) {
				$object->labels->{$key} = $value;
			}
			// The top-level label mirrors labels.name everywhere WP shows it
			// (admin menu, admin bar), so keep the two in sync.
			if ( isset( $labels['name'] ) ) {
				$object->label = $labels['name'];
			}
		};

		if ( did_action( 'init' ) ) {
			$apply();
		} else {
			add_action( 'init', $apply, 999 );
		}
	}

	/**
	 * Hide meta fields on a taxonomy's add/edit screens and list table.
	 *
	 * Covers the recurring "editors should only pick a name" admin cleanup:
	 * hides the given fields' form rows via CSS on both the add and edit
	 * screens, and (by default) drops the matching list table columns.
	 *
	 * @param string $taxonomy Taxonomy name (default `category`).
	 * @param string[] $fields Field slugs to hide — matched against `.term-{field}-wrap` form rows and same-named list columns. Default description, slug, and parent.
	 * @param bool $hide_columns Whether to also drop matching list table columns.
	 * @return void
	 */
	public static function hideTaxonomyMetaFields( string $taxonomy = 'category', array $fields = [ 'description', 'slug', 'parent' ], bool $hide_columns = true ): void {
		if ( $hide_columns ) {
			add_filter( "manage_edit-{$taxonomy}_columns", static function ( array $columns ) use ( $fields ): array {
				foreach ( $fields as $field ) {
					unset( $columns[ $field ] );
				}
				return $columns;
			} );
		}

		$print_css = static function () use ( $fields ): void {
			$selectors = array_map(
				static fn( string $field ): string => ".term-{$field}-wrap",
				$fields
			);
			echo '<style>' . implode( ', ', $selectors ) . ' { display: none; }</style>';
		};
		add_action( "{$taxonomy}_edit_form", $print_css );
		add_action( "{$taxonomy}_add_form", $print_css );
	}
}
