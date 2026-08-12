<?php

declare(strict_types=1);

/**
 * Binds SocialImage to whichever SEO plugin renders the og:image tag.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

/**
 * Hands an SEO plugin the preview image resolved for the current post.
 *
 * Separate from `SocialImage` on purpose. That class stays free of any
 * plugin's vocabulary: it takes an image and returns a cut. This one knows
 * exactly one thing per plugin — which filter to hang on and what shape to
 * hand back — so adding Yoast or Rank Math later is a sibling entry rather
 * than a rewrite of anything above it.
 *
 * Each plugin's own tag rendering is left alone. The bridge only supplies the
 * image, and only when it has one; otherwise the plugin's own resolution
 * stands. A working card from the site-wide default beats a wrong one.
 */
class SocialImageBridge {

	/**
	 * Supported plugins, keyed by the value the `StarterBase` flag takes.
	 *
	 * The callback registers that plugin's hooks. Entries are deliberately tiny:
	 * everything above the hook name is shared, so a new plugin costs one method.
	 *
	 * @var array<string, string>
	 */
	private const array PLUGINS = [
		'aioseo' => 'registerAioseo',
	];

	/**
	 * Plugin keys this bridge understands.
	 *
	 * Public so a caller configuring the flag can discover the accepted values
	 * without reading the source, and so a wrong value can be reported with the
	 * right ones next to it.
	 *
	 * @return array<int, string>
	 */
	public static function supported(): array {
		return array_keys( self::PLUGINS );
	}

	/**
	 * Wire the bridge for one plugin.
	 *
	 * An unknown key registers nothing rather than throwing: a typo in a theme's
	 * configuration should cost the feature, not the request.
	 *
	 * @param string $plugin One of `supported()`.
	 * @return void
	 */
	public static function register( string $plugin ): void {
		$method = self::PLUGINS[ strtolower( trim( $plugin ) ) ] ?? null;

		if ( null === $method ) {
			return;
		}

		self::{$method}();
	}

	/**
	 * All in One SEO.
	 *
	 * `aioseo_opengraph_default_image` is the plugin's own sanctioned seam —
	 * its inline note reads "Allow users to control the default image per post
	 * type", which is exactly this. AIOSEO resolves the image from one global
	 * source setting plus a per-post override, with no per-post-type layer, so
	 * without this every post of a type shares one image.
	 *
	 * @return void
	 */
	private static function registerAioseo(): void {
		add_filter( 'aioseo_opengraph_default_image', [ self::class, 'filterOpengraphImage' ], 10, 2 );
	}

	/**
	 * Supply the post's preview image to the SEO plugin.
	 *
	 * Returns the `wp_get_attachment_image_src()` tuple shape rather than a bare
	 * URL: AIOSEO reads index 1 and 2 for `og:image:width` / `og:image:height`
	 * and falls back to the globally configured dimensions when handed a string,
	 * which would then describe a different image than the one it serves.
	 *
	 * @param string|array|mixed $image Whatever the plugin resolved.
	 * @param array|mixed        $args  `[ WP_Post|null $post, string $object_type ]`.
	 * @return string|array|mixed
	 */
	public static function filterOpengraphImage( $image, $args ) {
		$post = is_array( $args ) ? ( $args[0] ?? null ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return $image;
		}

		$preview = SocialImage::forPost( $post );

		if ( null === $preview ) {
			return $image;
		}

		return [ $preview['src'], $preview['width'], $preview['height'] ];
	}
}
