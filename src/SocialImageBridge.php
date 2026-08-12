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
	 * Supported plugins: how to notice one, and how to wire it.
	 *
	 * Only `SocialImage::forPost()` is genuinely shared above this — each
	 * plugin's hooks and the shape it expects back are its own, so a second
	 * entry is its own pair of methods rather than a line in a table. Saying so
	 * is more useful than an abstraction built for a consumer that does not
	 * exist yet.
	 *
	 * @var array<string, array{detect: string, register: string}>
	 */
	private const array PLUGINS = [
		'aioseo' => [ 'detect' => 'aioseoActive', 'register' => 'registerAioseo' ],
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
	public static function register( string|bool $plugin ): void {
		$key = self::resolve( $plugin );

		if ( null === $key ) {
			return;
		}

		self::{ self::PLUGINS[ $key ]['register'] }();
	}

	/**
	 * Which plugin to wire for a given flag value.
	 *
	 * `true` asks the package to work it out — a site runs one SEO plugin, so
	 * naming it is configuration that can be derived. A string forces one, for
	 * the rare site running two where detection would pick the wrong one.
	 * `false` or an empty string wires nothing.
	 *
	 * An unsupported name resolves to null rather than throwing: a typo in a
	 * theme should cost the feature, not the request.
	 *
	 * @param string|bool $plugin Flag value.
	 * @return string|null Supported plugin key, or null.
	 */
	public static function resolve( string|bool $plugin ): ?string {
		if ( true === $plugin ) {
			return self::detect();
		}

		if ( false === $plugin ) {
			return null;
		}

		$key = strtolower( trim( $plugin ) );

		if ( '' === $key ) {
			return null;
		}

		if ( 'auto' === $key ) {
			return self::detect();
		}

		return isset( self::PLUGINS[ $key ] ) ? $key : null;
	}

	/**
	 * The supported SEO plugin active on this site, if any.
	 *
	 * First match wins. That is enough while one plugin is supported, and stays
	 * enough afterwards: two SEO plugins on one site is a misconfiguration, not
	 * a case to arbitrate.
	 *
	 * @return string|null
	 */
	public static function detect(): ?string {
		foreach ( self::PLUGINS as $key => $plugin ) {
			if ( self::{ $plugin['detect'] }() ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * @return bool
	 */
	private static function aioseoActive(): bool {
		return function_exists( 'aioseo' );
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
		// Twitter resolves its image on a separate path with no filter of its
		// own, so without this the feature only half works and the rest has to
		// be clicked together in the admin.
		add_filter( 'aioseo_twitter_tags', [ self::class, 'filterTwitterTags' ] );
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

		if ( ! $post instanceof \WP_Post || self::standsAside( $post, 'og_image_type' ) ) {
			return $image;
		}

		return self::toTuple( SocialImage::forPost( $post ), $image );
	}

	/**
	 * Supply the post's preview image to Twitter's card.
	 *
	 * `twitter:image` is a bare URL — Twitter has no width/height pair to fill,
	 * unlike Open Graph.
	 *
	 * @param array<string, mixed>|mixed $meta Twitter meta about to be rendered.
	 * @return array<string, mixed>|mixed
	 */
	public static function filterTwitterTags( $meta ) {
		if ( ! is_array( $meta ) || ! is_singular() ) {
			return $meta;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return $meta;
		}

		// With "Use Data from Facebook Tab" on, AIOSEO returns the Open Graph
		// image for Twitter too — so the tag already carries whatever the
		// og:image filter decided, deferral included. Touching it here would
		// run that decision a second time without the deferral and undo it.
		// The setting is per post but defaults from the global one, so on a
		// site with it enabled this is every post, not an edge case.
		if ( self::usesOpengraphData( $post ) || self::standsAside( $post, 'twitter_image_type' ) ) {
			return $meta;
		}

		return self::withTwitterImage( $meta, SocialImage::forPost( $post ) );
	}

	/**
	 * Put a resolved preview into Twitter meta, or leave it as it was.
	 *
	 * Pure, and separate from the filter for the same reason `toTuple()` is.
	 *
	 * @param array<string, mixed>      $meta    Twitter meta.
	 * @param array<string, mixed>|null $preview Resolved preview, or null.
	 * @return array<string, mixed>
	 */
	public static function withTwitterImage( array $meta, ?array $preview ): array {
		if ( null === $preview || empty( $preview['src'] ) ) {
			return $meta;
		}

		$meta['twitter:image'] = $preview['src'];

		return $meta;
	}

	/**
	 * Whether the editor chose this post's social image by hand.
	 *
	 * AIOSEO's filter is named for the *default* image but fires at the end of
	 * resolution, so it also sees an image an editor picked in the plugin's own
	 * panel. Overwriting that is the plugin equivalent of ignoring the editor,
	 * and it is silent — the panel still shows their choice.
	 *
	 * @param string|null $image_type The post's image-source override.
	 * @return bool
	 */
	public static function defersToEditor( ?string $image_type ): bool {
		return is_string( $image_type ) && '' !== $image_type && 'default' !== $image_type;
	}

	/**
	 * A post's AIOSEO image-source override, if the plugin can tell us.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @param string   $key  Meta property, `og_image_type` or `twitter_image_type`.
	 * @return string|null
	 */
	private static function imageType( \WP_Post $post, string $key ): ?string {
		$meta = self::postMeta( $post );

		return isset( $meta->{$key} ) && is_string( $meta->{$key} ) ? $meta->{$key} : null;
	}

	/**
	 * Whether the bridge should leave this post's tag alone.
	 *
	 * Deferring is the safe answer when the plugin's metadata cannot be read at
	 * all: the contract is never to override an editor's choice, and an
	 * unreadable state is not evidence that they made none.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @param string   $key  Meta property, `og_image_type` or `twitter_image_type`.
	 * @return bool
	 */
	private static function standsAside( \WP_Post $post, string $key ): bool {
		if ( null === self::postMeta( $post ) ) {
			return true;
		}

		return self::defersToEditor( self::imageType( $post, $key ) );
	}

	/**
	 * Whether this post's Twitter card reuses the Open Graph image.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @return bool
	 */
	private static function usesOpengraphData( \WP_Post $post ): bool {
		$meta = self::postMeta( $post );

		return null !== $meta && ! empty( $meta->twitter_use_og );
	}

	/**
	 * AIOSEO's per-post metadata, or null when it cannot be reached.
	 *
	 * Every hop is checked rather than assumed: this walks another plugin's
	 * internals, and a partial bootstrap or a refactor upstream should cost the
	 * feature, not the request.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @return object|null
	 */
	private static function postMeta( \WP_Post $post ): ?object {
		if ( ! function_exists( 'aioseo' ) ) {
			return null;
		}

		$aioseo = aioseo();

		if ( ! is_object( $aioseo ) || ! isset( $aioseo->meta->metaData ) || ! is_object( $aioseo->meta->metaData ) ) {
			return null;
		}

		if ( ! method_exists( $aioseo->meta->metaData, 'getMetaData' ) ) {
			return null;
		}

		$meta = $aioseo->meta->metaData->getMetaData( $post );

		return is_object( $meta ) ? $meta : null;
	}

	/**
	 * Shape a resolved preview for the plugin, or keep what it had.
	 *
	 * Pure, and separate from the filter so the shaping is testable without
	 * going through the encoder.
	 *
	 * @param array<string, mixed>|null $preview  Resolved preview, or null.
	 * @param string|array|mixed        $fallback What the plugin resolved.
	 * @return string|array|mixed
	 */
	public static function toTuple( ?array $preview, $fallback ) {
		if ( null === $preview || empty( $preview['src'] ) ) {
			return $fallback;
		}

		return [ $preview['src'], $preview['width'] ?? '', $preview['height'] ?? '' ];
	}
}
