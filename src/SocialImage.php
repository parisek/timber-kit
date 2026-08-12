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

		$spec['width'] = self::positiveInt( $spec['width'], (int) $defaults['width'] );
		$spec['height'] = self::positiveInt( $spec['height'], (int) $defaults['height'] );
		$spec['quality'] = max( 1, min( 100, self::positiveInt( $spec['quality'], (int) $defaults['quality'] ) ) );
		$spec['crop'] = is_string( $spec['crop'] ) && $spec['crop'] !== '' ? $spec['crop'] : (string) $defaults['crop'];
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

		foreach ( $variants as $variant ) {
			if ( is_array( $variant ) && self::isUsable( $variant, $spec ) ) {
				return $variant;
			}
		}

		return null;
	}

	/**
	 * Whether a produced variant is the requested cut in a readable format.
	 *
	 * The dimension check is what rejects the untouched original: `resizer()`
	 * appends the source as its last entry and returns it alone when it cannot
	 * process the image, and that entry keeps the source's own dimensions.
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
	 * @param mixed  $format   Requested format.
	 * @param string $fallback Format to use when the request is unreadable.
	 * @return string
	 */
	private static function scraperFormat( $format, string $fallback ): string {
		if ( ! is_string( $format ) ) {
			return $fallback;
		}

		$format = strtolower( trim( $format ) );

		return in_array( $format, self::scraperFormats(), true ) ? $format : $fallback;
	}

	/**
	 * @param mixed $value    Candidate value.
	 * @param int   $fallback Value to use when the candidate is not positive.
	 * @return int
	 */
	private static function positiveInt( $value, int $fallback ): int {
		$value = is_numeric( $value ) ? (int) $value : 0;

		return $value > 0 ? $value : $fallback;
	}
}
