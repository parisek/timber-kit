<?php

declare(strict_types=1);

/**
 * Social preview image — a scraper-safe cut of an image, for og:image and friends.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

/**
 * Produces the one image variant a link-preview scraper can actually use.
 *
 * Three facts make this worth a class rather than a call site:
 *
 *   - The resizer writes AVIF by default. Facebook, LinkedIn and X read JPEG,
 *     PNG, GIF and WebP; they do not read AVIF. An AVIF `og:image` is a preview
 *     card with no image at all, which is worse than the site-wide default it
 *     replaced, and nothing about it looks broken until someone shares a link.
 *   - The platforms document 1200x630. A source-ratio crop leaves the framing
 *     to each scraper, and each one crops differently.
 *   - The original must never be served. Editor uploads run to several thousand
 *     pixels and many megabytes, over Facebook's 8 MB ceiling.
 *
 * Policy lives here; the mechanism stays in `Resizer`, which this composes.
 *
 * `get()` takes an image; `forPost()` finds one, from a post-type → field map.
 * Handing the result to a particular SEO plugin is `SocialImageBridge`, kept
 * separate so no plugin's vocabulary reaches this class.
 */
class SocialImage {

	/**
	 * Output formats a preview scraper can read.
	 *
	 * The reason this class exists — see the class docblock. Filterable for the
	 * day a platform grows AVIF support, so a project need not wait for a
	 * release to take advantage of it.
	 *
	 * @var array<int, string>
	 */
	private const array SCRAPER_FORMATS = [ 'jpeg', 'jpg', 'png', 'gif', 'webp' ];

	/**
	 * Crop styles that produce exactly the requested pixels.
	 *
	 * Narrower than the styles `Resizer` accepts, and deliberately so. The
	 * resizer reports the width and height that were *asked for*, not the ones
	 * it wrote, and two of its branches do not produce the exact cut: an
	 * unrecognised style resizes without cropping, and `smart-crop` falls back
	 * to a plain resize when the source is smaller than the target. Either way
	 * the entry claims 1200x630 while the file is something else, and the whole
	 * value of this class is that its answer can be trusted. Restricting the
	 * style to the branch that crops to exact dimensions is the one check that
	 * does not depend on the resizer's own reporting.
	 *
	 * @var array<int, string>
	 */
	private const array EXACT_CROP_STYLES = [ 'center', 'crop', 'top', 'bottom', 'left', 'right' ];

	/**
	 * The cut the platforms document: 1200x630, a 1.91:1 card.
	 *
	 * Quality 85 rather than the resizer's 100: a preview card is a thumbnail
	 * in someone else's feed, and the difference is invisible there while the
	 * bytes are not.
	 *
	 * @var array<string, mixed>
	 */
	private const array DEFAULTS = [
		'width' => 1200,
		'height' => 630,
		'crop' => 'center',
		'quality' => 85,
		'format' => 'jpeg',
	];

	/**
	 * Build the variant spec for a preview image.
	 *
	 * Pure — no image is read or written. Exposed separately so a caller can
	 * see what would be cut, and so the policy is testable without an encoder.
	 *
	 * @param array<string, mixed> $options Any subset of `width`, `height`,
	 *                                      `crop`, `quality`, `format`.
	 * @return array<string, mixed> A `Resizer` associative variant spec.
	 */
	public static function spec( array $options = [] ): array {
		/**
		 * Filter the preview-image defaults for the whole project.
		 *
		 * @param array<string, mixed> $defaults Width, height, crop, quality, format.
		 */
		$defaults = apply_filters( 'timber_kit_social_image_defaults', self::DEFAULTS );
		$defaults = is_array( $defaults ) ? array_merge( self::DEFAULTS, $defaults ) : self::DEFAULTS;

		// Unknown keys are dropped rather than passed through: this spec goes to
		// the resizer, and a typo'd key silently doing nothing there is harder to
		// notice than one that never arrives.
		$options = array_intersect_key( $options, self::DEFAULTS );
		$spec = array_merge( $defaults, $options );

		$spec['width'] = self::positiveInt( $spec['width'], $defaults['width'], (int) self::DEFAULTS['width'] );
		$spec['height'] = self::positiveInt( $spec['height'], $defaults['height'], (int) self::DEFAULTS['height'] );
		$spec['quality'] = min( 100, self::positiveInt( $spec['quality'], $defaults['quality'], (int) self::DEFAULTS['quality'] ) );
		$spec['crop'] = self::exactCropStyle( $spec['crop'], (string) $defaults['crop'] );
		$spec['format'] = self::scraperFormat( $spec['format'], (string) $defaults['format'] );

		return $spec;
	}

	/**
	 * Cut a preview image, or return null when one cannot be produced.
	 *
	 * Null is a working outcome, not a failure to paper over: the caller then
	 * falls back to whatever it had, which is a preview card that works. A
	 * variant that is not demonstrably the requested cut in a readable format
	 * is worse than no answer, because it looks like an answer.
	 *
	 * @param array|mixed          $image    Timber/WordPress image data, as
	 *                                       `Helpers::formatImage()` returns it.
	 * @param array<string, mixed> $options  See `spec()`.
	 * @param Resizer|null         $resizer  Pre-configured resizer; one is built
	 *                                       when omitted. Mainly a seam for
	 *                                       tests and for callers that already
	 *                                       hold an instance.
	 * @return array<string, mixed>|null The variant, in the usual `Resizer`
	 *                                   entry shape, or null.
	 */
	public static function get( $image, array $options = [], ?Resizer $resizer = null ): ?array {
		if ( empty( $image ) || ! is_array( $image ) ) {
			return null;
		}

		$spec = self::spec( $options );
		$resizer = $resizer ?? new Resizer();

		$variants = $resizer->resizer( $image, [ $spec ] );
		$source_src = self::sourceUrl( $image );

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) || ! self::isUsable( $variant, $spec ) ) {
				continue;
			}

			// resizer() appends the source untouched and returns it alone when
			// it cannot process the image. Today that entry carries no `type`
			// and so fails isUsable() anyway, but that is a fact about another
			// class's return shape, not a decision this one made: the day
			// prepareDefaultImage() gains a `type`, a source that happens to
			// already be 1200x630 JPEG would start being served as a preview.
			// Compare the URLs and the guarantee stops depending on that.
			if ( $source_src !== '' && $variant['src'] === $source_src ) {
				continue;
			}

			return $variant;
		}

		return null;
	}

	/**
	 * The URL `Resizer` would treat as the source.
	 *
	 * Mirrors its own resolution: an indexed list is a set of sizes and the last
	 * entry is the source. Reading `src` off the outer array would return
	 * nothing for that shape, quietly disarming the source check in `get()` for
	 * every caller who passes a list — which `Helpers::formatImage()` produces.
	 *
	 * @param array<mixed, mixed> $image Image data in either shape.
	 * @return string Empty when no URL can be resolved.
	 */
	private static function sourceUrl( array $image ): string {
		if ( isset( $image[0] ) ) {
			$last = end( $image );
			$image = is_array( $last ) ? $last : [];
		}

		return is_string( $image['src'] ?? null ) ? $image['src'] : '';
	}

	/**
	 * Cut the preview image for a post.
	 *
	 * The half every project was writing by hand. The only project-specific
	 * facts are a post type and a field name, so those are configuration and
	 * the rest lives here.
	 *
	 * Resolution order: the fields the map names for this post type, first
	 * non-empty wins; then the featured image. A post type absent from the map
	 * therefore behaves exactly as before the map existed, which is what makes
	 * an empty map a no-op rather than a regression.
	 *
	 * @param \WP_Post|mixed       $post    Post to resolve an image for.
	 * @param array<string, mixed> $options See `spec()`.
	 * @param Resizer|null         $resizer See `get()`.
	 * @return array<string, mixed>|null
	 */
	public static function forPost( $post, array $options = [], ?Resizer $resizer = null ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		// Every candidate is tried until one yields a usable cut, not until one
		// merely looks like an image. Resolving and cutting are separate steps,
		// and a value can pass the first and fail the second — an SVG, a
		// missing file, a format the backend cannot decode. Stopping at the
		// first plausible candidate would throw away the rest of the chain and
		// the featured image for a picture that was never going to work.
		foreach ( self::imageCandidates( $post ) as $candidate ) {
			$preview = self::get( $candidate, $options, $resizer );

			if ( null !== $preview ) {
				return $preview;
			}
		}

		return null;
	}

	/**
	 * The images a post's preview could be cut from, best first.
	 *
	 * @param \WP_Post $post Post to resolve.
	 * @return array<int, array<string, mixed>>
	 */
	private static function imageCandidates( \WP_Post $post ): array {
		/**
		 * Filter the post-type → preview-image field map.
		 *
		 * @param array<string, mixed> $map Post type => field name or list of names.
		 */
		$map = apply_filters( 'timber_kit_social_image_fields', [] );
		$names = self::fieldNamesFor( (string) get_post_type( $post ), is_array( $map ) ? $map : [] );
		$candidates = [];

		if ( [] !== $names ) {
			/**
			 * Filter how a post's fields are read.
			 *
			 * Returning an array skips `Helpers::formatFields()` entirely, for
			 * projects that keep this data somewhere other than ACF.
			 *
			 * @param array<string, mixed>|null $fields Null to use the default reader.
			 * @param \WP_Post                  $post   Post being resolved.
			 */
			$fields = apply_filters( 'timber_kit_social_image_post_fields', null, $post );
			$fields = is_array( $fields ) ? $fields : Helpers::formatFields( $post );

			foreach ( $names as $name ) {
				if ( empty( $fields[ $name ] ) ) {
					continue;
				}

				// Non-empty is not the same as an image: a gallery, a repeater
				// or a group is all three of those and none of them is one.
				$image = self::asImage( $fields[ $name ] );

				if ( null !== $image ) {
					$candidates[] = $image;
				}
			}
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		$featured = $thumbnail_id > 0 ? self::asImage( $thumbnail_id ) : null;

		if ( null !== $featured ) {
			$candidates[] = $featured;
		}

		return $candidates;
	}

	/**
	 * The field names to try for a post type, in order.
	 *
	 * Pure, and public because it is the whole of the map's semantics: a string
	 * is a one-item chain, a list is tried in order, anything else is not a
	 * field name and is dropped rather than passed on to fail later.
	 *
	 * @param string              $post_type Post type to look up.
	 * @param array<string, mixed> $map      Post type => field name or list of names.
	 * @return array<int, string>
	 */
	public static function fieldNamesFor( string $post_type, array $map ): array {
		$entry = $map[ $post_type ] ?? null;

		if ( is_string( $entry ) ) {
			$entry = [ $entry ];
		}

		if ( ! is_array( $entry ) ) {
			return [];
		}

		$names = [];
		foreach ( $entry as $name ) {
			if ( is_string( $name ) && trim( $name ) !== '' ) {
				$names[] = trim( $name );
			}
		}

		return $names;
	}

	/**
	 * Coerce a field value or attachment id into the image shape `get()` takes.
	 *
	 * @param mixed $value Field value, attachment id, or already-formatted image.
	 * @return array<string, mixed>|null
	 */
	private static function asImage( $value ): ?array {
		// A field read through formatFields() is already formatted, and running
		// it through formatImage() again would look for ACF's raw `url` key,
		// find nothing, and return null. That is the shape the common case
		// actually takes: formatImage() normalises even a single image into an
		// indexed list, so `src` sits at [0], not at the root. An attachment id
		// or a raw ACF array still needs the trip.
		$image = self::formattedImage( $value ) ?? Helpers::formatImage( $value );

		return self::formattedImage( $image );
	}

	/**
	 * The image record inside an already-formatted value, or null.
	 *
	 * Accepts both shapes `formatImage()` produces: the indexed list, where the
	 * last entry is the source, and a bare record.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<string, mixed>|null
	 */
	private static function formattedImage( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( isset( $value[0] ) && is_array( $value[0] ) ) {
			$value = end( $value );

			if ( ! is_array( $value ) ) {
				return null;
			}
		}

		return self::isImageRecord( $value ) ? $value : null;
	}

	/**
	 * Whether a formatted record describes an image.
	 *
	 * `src` alone does not say so: `Helpers::formatFile()` and `formatVideo()`
	 * produce records with the same key, and a mapped file or video field would
	 * otherwise be handed to the resizer as a picture. A record with no `type`
	 * at all is accepted — that is a formatter that could not read the mime,
	 * not evidence against, and the resizer refuses what it cannot decode.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 * @return bool
	 */
	private static function isImageRecord( array $record ): bool {
		if ( empty( $record['src'] ) ) {
			return false;
		}

		$type = $record['type'] ?? null;

		return ! is_string( $type ) || $type === '' || str_starts_with( strtolower( $type ), 'image/' );
	}

	/**
	 * Whether a produced variant is the requested cut in a readable format.
	 *
	 * The dimension check rejects the untouched original in the ordinary case,
	 * since `resizer()` appends the source as its last entry and that entry
	 * keeps the source's own dimensions. It cannot catch a source that already
	 * happens to be the requested size — `get()` compares the URLs for that.
	 *
	 * @param array<string, mixed> $variant Variant as returned by `Resizer`.
	 * @param array<string, mixed> $spec    Spec the variant was cut from.
	 * @return bool
	 */
	public static function isUsable( array $variant, array $spec ): bool {
		if ( empty( $variant['src'] ) || ! is_string( $variant['src'] ) ) {
			return false;
		}

		if ( (int) ( $variant['width'] ?? 0 ) !== (int) $spec['width'] ) {
			return false;
		}

		if ( (int) ( $variant['height'] ?? 0 ) !== (int) $spec['height'] ) {
			return false;
		}

		$mime = is_string( $variant['type'] ?? null ) ? strtolower( $variant['type'] ) : '';
		$subtype = str_starts_with( $mime, 'image/' ) ? substr( $mime, 6 ) : '';

		return in_array( $subtype, self::scraperFormats(), true );
	}

	/**
	 * @return array<int, string>
	 */
	private static function scraperFormats(): array {
		/**
		 * Filter the formats considered readable by preview scrapers.
		 *
		 * @param array<int, string> $formats Lowercase format names, no `image/` prefix.
		 */
		$formats = apply_filters( 'timber_kit_social_image_formats', self::SCRAPER_FORMATS );

		return is_array( $formats ) && $formats !== [] ? array_map( 'strtolower', $formats ) : self::SCRAPER_FORMATS;
	}

	/**
	 * Resolve a requested format to one a scraper can read.
	 *
	 * The fallback is itself checked: a project that points
	 * `timber_kit_social_image_defaults` at an unreadable format would otherwise
	 * turn the guarantee off for every call, which is exactly the failure this
	 * class exists to prevent. The package default is the last resort.
	 *
	 * @param mixed  $format   Requested format.
	 * @param string $fallback Format to use when the request is unreadable.
	 * @return string
	 */
	private static function scraperFormat( $format, string $fallback ): string {
		$readable = self::scraperFormats();

		if ( is_string( $format ) ) {
			$format = strtolower( trim( $format ) );
			if ( in_array( $format, $readable, true ) ) {
				return $format;
			}
		}

		$fallback = strtolower( trim( $fallback ) );
		if ( in_array( $fallback, $readable, true ) ) {
			return $fallback;
		}

		// The package default is a candidate like the others, not an escape
		// hatch: a project that filtered `jpeg` out of the readable list would
		// otherwise get a spec the class then rejects its own output for.
		$package = (string) self::DEFAULTS['format'];

		return in_array( $package, $readable, true ) ? $package : (string) reset( $readable );
	}

	/**
	 * Resolve a requested crop style to one that yields exact dimensions.
	 *
	 * Same last-resort chain as the format: a project-wide default that does not
	 * crop exactly would silently weaken every call.
	 *
	 * @param mixed  $crop     Requested crop style.
	 * @param string $fallback Style to use when the request is not exact.
	 * @return string
	 */
	private static function exactCropStyle( $crop, string $fallback ): string {
		if ( is_string( $crop ) ) {
			$crop = strtolower( trim( $crop ) );
			if ( in_array( $crop, self::EXACT_CROP_STYLES, true ) ) {
				return $crop;
			}
		}

		$fallback = strtolower( trim( $fallback ) );
		if ( in_array( $fallback, self::EXACT_CROP_STYLES, true ) ) {
			return $fallback;
		}

		return (string) self::DEFAULTS['crop'];
	}

	/**
	 * First positive integer of request, filtered default, package default.
	 *
	 * The filtered default is a candidate, not a floor: a project pointing
	 * `timber_kit_social_image_defaults` at a zero or nonsense width would
	 * otherwise put that straight into the spec, and a zero dimension routes
	 * the resizer into proportional resizing while it still reports the
	 * dimensions it was handed — the same "claims a cut it did not make" trap
	 * the crop allow-list closes.
	 *
	 * @param mixed $value    Requested value.
	 * @param mixed $filtered Project-wide default.
	 * @param int   $package  Package default, already known positive.
	 * @return int
	 */
	private static function positiveInt( $value, $filtered, int $package ): int {
		foreach ( [ $value, $filtered ] as $candidate ) {
			$candidate = is_numeric( $candidate ) ? (int) $candidate : 0;
			if ( $candidate > 0 ) {
				return $candidate;
			}
		}

		return $package;
	}
}
