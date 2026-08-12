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
 * Deliberately stops at the image. Wiring it to a particular SEO plugin's hook
 * needs a post type and a field name, which are project facts, not package
 * ones. A project's own `og:image` filter callback is two lines on top of this.
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
		$source_src = is_string( $image['src'] ?? null ) ? $image['src'] : '';

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
