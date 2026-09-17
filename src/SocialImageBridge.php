<?php

declare(strict_types=1);

/**
 * Binds SocialImage to whichever SEO plugin renders the og:image tag.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

use Parisek\TimberKit\Seo\Plugin;

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
	 * AIOSEO image sources that carry an image an editor actually picked.
	 *
	 * `custom_image` is an upload in the plugin's panel, `custom` a custom
	 * field the editor named.
	 */
	private const EDITOR_SOURCES = [ 'custom_image', 'custom' ];

	/**
	 * AIOSEO image sources the plugin resolves by itself.
	 *
	 * AIOSEO's Yoast importer writes one of these onto every post it imports,
	 * so on a migrated site they say nothing about intent. `content` on block
	 * content resolves to no image at all. `auto` is the plugin's "first
	 * available image".
	 */
	private const AUTOMATIC_SOURCES = [ 'featured', 'content', 'attach', 'author', 'auto' ];


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
		return 'aioseo' === Plugin::active();
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

		if ( ! $post instanceof \WP_Post ) {
			return $image;
		}

		$meta = self::postMeta( $post );

		// Unreadable metadata is not evidence that the editor chose nothing.
		if ( null === $meta ) {
			return $image;
		}

		$source = self::effectiveSource( self::imageType( $meta, 'og_image_type' ), self::globalSource( 'facebook' ) );

		if ( ! self::shouldSupply( $source, has_post_thumbnail( $post ) ) ) {
			return $image;
		}

		return self::toTuple( SocialImage::forPost( $post ), $image );
	}

	/**
	 * Whether the bridge supplies the preview for this image source.
	 *
	 * Pure. No source or `default`: always, as the bridge always did. An
	 * editor's source: never. `featured`: only where the post has no featured
	 * image, because that is the one state in which AIOSEO falls back. The other
	 * automatic sources: always — they take whatever turns up in the body, an
	 * attachment or the author's avatar, so the mapped preview is the better
	 * picture wherever one resolves, and toTuple() keeps the plugin's image where
	 * none does. Anything else is a source this code cannot name, and a source it
	 * cannot name is treated as a choice.
	 *
	 * The featured branch asks the post, not the resolved URL. Comparing the URL
	 * against the plugin's own fallbacks reads a featured image that happens to be
	 * the site's default social image as a fallback, and replaces it.
	 *
	 * @param string|null $image_type   The post's image-source override.
	 * @param bool        $has_featured Whether the post has a featured image.
	 * @return bool
	 */
	public static function shouldSupply( ?string $image_type, bool $has_featured ): bool {
		if ( null === $image_type || '' === $image_type || 'default' === $image_type ) {
			return true;
		}

		if ( 'featured' === $image_type ) {
			return ! $has_featured;
		}

		return self::isAutomatic( $image_type );
	}

	/**
	 * The image source AIOSEO actually uses for a post.
	 *
	 * Pure. A post left on `default` takes the global source, exactly as
	 * `Facebook::getImage()` does. Deciding on the per-post value alone would
	 * read a site-wide `featured` as "no source" and let the mapped field
	 * replace the featured image.
	 *
	 * @param string|null $post_source   The post's `og_image_type`.
	 * @param string|null $global_source The global `defaultImageSourcePosts`.
	 * @return string
	 */
	public static function effectiveSource( ?string $post_source, ?string $global_source ): string {
		if ( null !== $post_source && '' !== $post_source && 'default' !== $post_source ) {
			return $post_source;
		}

		return null !== $global_source && '' !== $global_source ? $global_source : 'default';
	}

	/**
	 * AIOSEO's global image source for posts, or null when unreadable.
	 *
	 * Per network: Facebook and Twitter carry their own setting, and a post left
	 * on `default` inherits the one for the network being rendered.
	 *
	 * @param string $network `facebook` or `twitter`.
	 * @return string|null
	 */
	private static function globalSource( string $network ): ?string {
		try {
			$aioseo = function_exists( 'aioseo' ) ? aioseo() : null;
			$social = is_object( $aioseo ) && isset( $aioseo->options->social ) ? $aioseo->options->social : null;
			$source = is_object( $social ) ? ( $social->{$network}->general->defaultImageSourcePosts ?? null ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $source ) ? $source : null;
	}

	/**
	 * Whether a source is one the plugin resolves by itself.
	 *
	 * @param string|null $image_type The post's image-source override.
	 * @return bool
	 */
	public static function isAutomatic( ?string $image_type ): bool {
		return in_array( $image_type, self::AUTOMATIC_SOURCES, true );
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

		if ( self::standsAsideOnTwitter( $post ) ) {
			return $meta;
		}

		// Otherwise the card takes the Open Graph image, as resolved through the
		// og:image filter above. That is AIOSEO's own Twitter fallback, and it is
		// what keeps the two cards on one picture: a separate preview here would
		// put the field image on X next to the editor's image on Facebook.
		$opengraph = self::opengraphImage( $post );

		if ( null === $opengraph ) {
			return $meta;
		}

		return self::withTwitterImage( $meta, [ 'src' => $opengraph ] );
	}

	/**
	 * The Open Graph image URL AIOSEO resolves for a post, or null.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @return string|null
	 */
	private static function opengraphImage( \WP_Post $post ): ?string {
		try {
			$aioseo = function_exists( 'aioseo' ) ? aioseo() : null;

			$facebook = is_object( $aioseo ) && isset( $aioseo->social->facebook ) && is_object( $aioseo->social->facebook ) ? $aioseo->social->facebook : null;

			if ( null === $facebook || ! is_callable( [ $facebook, 'getImage' ] ) ) {
				return null;
			}

			$image = $facebook->getImage( $post );
		} catch ( \Throwable $e ) {
			return null;
		}

		$url = is_array( $image ) ? ( $image[0] ?? '' ) : $image;

		return is_string( $url ) && '' !== $url ? $url : null;
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
		return in_array( $image_type, self::EDITOR_SOURCES, true );
	}

	/**
	 * A post's AIOSEO image-source override, if the plugin can tell us.
	 *
	 * @param object|null $meta AIOSEO's per-post metadata, or null.
	 * @param string      $key  Meta property, `og_image_type` or `twitter_image_type`.
	 * @return string|null
	 */
	private static function imageType( ?object $meta, string $key ): ?string {
		return isset( $meta->{$key} ) && is_string( $meta->{$key} ) ? $meta->{$key} : null;
	}

	/**
	 * Whether the bridge should leave this post's `twitter:image` alone.
	 *
	 * Three reasons to stand aside, in order. Unreadable metadata: the contract
	 * is never to override an editor's choice, and an unreadable state is not
	 * evidence that they made none. "Use Data from Facebook Tab": AIOSEO already
	 * returns the Open Graph image for Twitter, so the tag carries whatever the
	 * og:image filter decided, deferral included — deciding again here would run
	 * that decision without the deferral and undo it. And an effective Twitter
	 * source that is not one AIOSEO resolves by itself: the editor picked the
	 * card's image, or picked a source this code cannot name.
	 *
	 * The source is the effective one. Twitter carries its own global setting, so
	 * reading the per-post value alone takes a post left on `default` for "no
	 * choice" on a site whose global Twitter source is `custom`.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @return bool
	 */
	private static function standsAsideOnTwitter( \WP_Post $post ): bool {
		// One read decides every question. Reading again leaves a gap where the
		// second lookup fails and its null reads as "the editor chose nothing",
		// which is the opposite of what an unreadable state means here.
		$meta = self::postMeta( $post );

		if ( null === $meta || ! empty( $meta->twitter_use_og ) ) {
			return true;
		}

		$source = self::effectiveSource( self::imageType( $meta, 'twitter_image_type' ), self::globalSource( 'twitter' ) );

		return ! self::shouldSupply( $source, has_post_thumbnail( $post ) );
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

		if ( ! is_callable( [ $aioseo->meta->metaData, 'getMetaData' ] ) ) {
			return null;
		}

		// is_callable() over method_exists(): the latter is true for a private
		// method the call would then fail on, and false for one reachable only
		// through __call(). The try/catch covers what neither can see — this
		// walks another plugin's internals, and anything thrown there should
		// cost the feature, not the page.
		try {
			$meta = $aioseo->meta->metaData->getMetaData( $post );
		} catch ( \Throwable $e ) {
			return null;
		}

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
