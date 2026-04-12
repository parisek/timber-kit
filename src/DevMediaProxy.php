<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Rewrites missing local uploads URLs to a configured upstream origin.
 */
final class DevMediaProxy {

	/** @var bool Prevent duplicate hook registration. */
	private static bool $registered = false;

	/** @var string Local uploads base URL for current runtime. */
	private static string $uploads_base_url = '';

	/** @var string Local uploads base dir for current runtime. */
	private static string $uploads_base_dir = '';

	/** @var string Upstream uploads base URL for current runtime. */
	private static string $origin_base_url = '';

	/**
	 * In-request cache for remote variant probes.
	 *
	 * @var array<string, bool>
	 */
	private static array $remote_variant_exists_cache = [];

	/**
	 * Register WordPress filters for missing media URL rewriting.
	 *
	 * @param string $origin_base_url Upstream uploads base URL.
	 * @return void
	 */
	public static function register( string $origin_base_url ): void {
		$origin_base_url = self::normalize_base_url( $origin_base_url );
		if ( '' === $origin_base_url ) {
			return;
		}

		$upload_info = wp_get_upload_dir();
		$uploads_base_url = self::normalize_base_url( (string) ( $upload_info['baseurl'] ?? '' ) );
		$uploads_base_dir = self::normalize_base_dir( (string) ( $upload_info['basedir'] ?? '' ) );

		if ( '' === $uploads_base_url || '' === $uploads_base_dir ) {
			return;
		}

		self::$uploads_base_url = $uploads_base_url;
		self::$uploads_base_dir = $uploads_base_dir;
		self::$origin_base_url  = $origin_base_url;

		if ( self::$registered ) {
			return;
		}

		add_filter( 'wp_get_attachment_url', array( self::class, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( self::class, 'filter_image_src' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'filter_srcset' ), 10, 5 );
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'filter_image_attributes' ), 99, 3 );
		add_filter( 'wp_prepare_attachment_for_js', array( self::class, 'filter_attachment_for_js' ), 10, 3 );
		add_filter( 'timber_kit_resizer_missing_source_variants', array( self::class, 'filter_resizer_missing_source_variants' ), 10, 5 );

		self::$registered = true;
	}

	/**
	 * Reset runtime state for isolated unit tests.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$registered       = false;
		self::$uploads_base_url = '';
		self::$uploads_base_dir = '';
		self::$origin_base_url  = '';
		self::$remote_variant_exists_cache = [];
	}

	/**
	 * Rewrite a local uploads URL to origin when the file does not exist locally.
	 *
	 * @param string $url URL to inspect.
	 * @param string $uploads_base_url Local uploads base URL.
	 * @param string $uploads_base_dir Local uploads base dir.
	 * @param string $origin_base_url Upstream uploads base URL.
	 * @return string
	 */
	public static function rewriteIfMissing( string $url, string $uploads_base_url, string $uploads_base_dir, string $origin_base_url ): string {
		$uploads_base_url = self::normalize_base_url( $uploads_base_url );
		$uploads_base_dir = self::normalize_base_dir( $uploads_base_dir );
		$origin_base_url  = self::normalize_base_url( $origin_base_url );

		if ( '' === $url || '' === $uploads_base_url || '' === $uploads_base_dir || '' === $origin_base_url ) {
			return $url;
		}

		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['path'] ) || ! is_string( $parts['path'] ) ) {
			return $url;
		}

		$uploads_parts = parse_url( $uploads_base_url );
		if ( false === $uploads_parts || ! isset( $uploads_parts['path'] ) || ! is_string( $uploads_parts['path'] ) ) {
			return $url;
		}

		$url_without_query = self::build_url_without_query( $parts );
		$uploads_prefix    = $uploads_base_url . '/';
		if ( ! str_starts_with( $url_without_query, $uploads_prefix ) ) {
			return $url;
		}

		$uploads_path_prefix = rtrim( $uploads_parts['path'], '/' ) . '/';
		if ( ! str_starts_with( $parts['path'], $uploads_path_prefix ) ) {
			return $url;
		}

		$relative_path = substr( $parts['path'], strlen( $uploads_path_prefix ) );
		if ( '' === $relative_path ) {
			return $url;
		}

		$local_path = $uploads_base_dir . '/' . ltrim( $relative_path, '/' );
		if ( is_file( $local_path ) ) {
			return $url;
		}

		$origin_file_base_url = self::resolve_origin_file_base_url( $origin_base_url, $uploads_base_url );
		$query_suffix         = isset( $parts['query'] ) && is_string( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment             = isset( $parts['fragment'] ) && is_string( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $origin_file_base_url . '/' . ltrim( $relative_path, '/' ) . $query_suffix . $fragment;
	}

	/**
	 * Rewrite single attachment URL.
	 *
	 * @param string $url Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function filter_attachment_url( string $url, int $attachment_id ): string {
		unset( $attachment_id );
		return self::rewrite_runtime_url( $url );
	}

	/**
	 * Rewrite first element of wp_get_attachment_image_src response.
	 *
	 * @param mixed $image Image response.
	 * @param mixed $attachment_id Attachment ID.
	 * @param mixed $size Requested size.
	 * @param mixed $icon Icon flag.
	 * @return mixed
	 */
	public static function filter_image_src( mixed $image, mixed $attachment_id, mixed $size, mixed $icon ): mixed {
		unset( $attachment_id, $size, $icon );
		if ( is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ) {
			$image[0] = self::rewrite_runtime_url( $image[0] );
		}

		return $image;
	}

	/**
	 * Rewrite each srcset source URL.
	 *
	 * @param mixed $sources Srcset sources.
	 * @param mixed $size_array Requested dimensions.
	 * @param mixed $image_src Base image src.
	 * @param mixed $image_meta Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function filter_srcset( mixed $sources, mixed $size_array, mixed $image_src, mixed $image_meta, mixed $attachment_id ): mixed {
		unset( $size_array, $image_src, $image_meta, $attachment_id );
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $key => $source ) {
			if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$sources[ $key ]['url'] = self::rewrite_runtime_url( $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Rewrite src and srcset in image attributes array.
	 *
	 * @param mixed $attr Attributes.
	 * @param mixed $attachment Attachment object.
	 * @param mixed $size Requested size.
	 * @return mixed
	 */
	public static function filter_image_attributes( mixed $attr, mixed $attachment, mixed $size ): mixed {
		unset( $attachment, $size );
		if ( ! is_array( $attr ) ) {
			return $attr;
		}

		if ( isset( $attr['src'] ) && is_string( $attr['src'] ) ) {
			$attr['src'] = self::rewrite_runtime_url( $attr['src'] );
		}

		if ( isset( $attr['srcset'] ) && is_string( $attr['srcset'] ) ) {
			$attr['srcset'] = self::rewrite_srcset_string( $attr['srcset'] );
		}

		return $attr;
	}

	/**
	 * Rewrite attachment data used by Media Library JS.
	 *
	 * @param mixed $response Attachment response.
	 * @param mixed $attachment Attachment object.
	 * @param mixed $meta Attachment metadata.
	 * @return mixed
	 */
	public static function filter_attachment_for_js( mixed $response, mixed $attachment, mixed $meta ): mixed {
		unset( $attachment, $meta );
		if ( ! is_array( $response ) ) {
			return $response;
		}

		if ( isset( $response['url'] ) && is_string( $response['url'] ) ) {
			$response['url'] = self::rewrite_runtime_url( $response['url'] );
		}

		if ( isset( $response['icon'] ) && is_string( $response['icon'] ) ) {
			$response['icon'] = self::rewrite_runtime_url( $response['icon'] );
		}

		if ( isset( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as $size_key => $size_data ) {
				if ( is_array( $size_data ) && isset( $size_data['url'] ) && is_string( $size_data['url'] ) ) {
					$response['sizes'][ $size_key ]['url'] = self::rewrite_runtime_url( $size_data['url'] );
				}
			}
		}

		return $response;
	}

	/**
	 * Provide remote cache/image variants to Resizer when local source files are missing.
	 *
	 * @param mixed  $images Existing images from previous filters.
	 * @param array  $variants Normalized Resizer variants.
	 * @param string $filename Sanitized base filename.
	 * @param array  $default_image Default image metadata.
	 * @param array  $context Additional Resizer context.
	 * @return mixed
	 */
	public static function filter_resizer_missing_source_variants( mixed $images, array $variants, string $filename, array $default_image, array $context ): mixed {
		if ( is_array( $images ) ) {
			return $images;
		}

		if ( self::$origin_base_url === '' ) {
			return $images;
		}

		$uploads_base_url = isset( $context['uploads_base_url'] ) && is_string( $context['uploads_base_url'] ) ? $context['uploads_base_url'] : self::$uploads_base_url;
		$target_format = isset( $context['target_format'] ) && is_string( $context['target_format'] ) ? $context['target_format'] : 'avif';
		$image_cache_dir = isset( $context['image_cache_dir'] ) && is_string( $context['image_cache_dir'] ) ? $context['image_cache_dir'] : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/cache/image' : '/cache/image' );

		$remote_images = [];
		foreach ( $variants as $variant ) {
			$remote_variant = self::build_remote_resizer_variant( $variant, $filename, $default_image, $target_format, $image_cache_dir, $uploads_base_url );
			if ( self::remote_variant_exists( $remote_variant['src'] ) ) {
				$remote_images[] = $remote_variant;
			}
		}

		return $remote_images;
	}

	/**
	 * Rewrite a single URL using current runtime configuration.
	 *
	 * @param string $url URL to rewrite.
	 * @return string
	 */
	private static function rewrite_runtime_url( string $url ): string {
		return self::rewriteIfMissing(
			$url,
			self::$uploads_base_url,
			self::$uploads_base_dir,
			self::$origin_base_url
		);
	}

	/**
	 * Rewrite each URL in a srcset string.
	 *
	 * @param string $srcset Srcset value.
	 * @return string
	 */
	private static function rewrite_srcset_string( string $srcset ): string {
		$parts = array_map( 'trim', explode( ',', $srcset ) );
		foreach ( $parts as $index => $part ) {
			if ( 1 === preg_match( '/^(\S+)(\s.+)?$/', $part, $matches ) ) {
				$parts[ $index ] = self::rewrite_runtime_url( $matches[1] ) . ( $matches[2] ?? '' );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Normalize URL-like base strings.
	 *
	 * @param string $base_url Base URL.
	 * @return string
	 */
	private static function normalize_base_url( string $base_url ): string {
		return rtrim( trim( $base_url ), '/' );
	}

	/**
	 * Resolve the effective origin uploads base URL.
	 *
	 * If the configured origin is just a domain, reuse the local uploads path.
	 *
	 * @param string $origin_base_url Configured origin.
	 * @param string $uploads_base_url Local uploads base URL.
	 * @return string
	 */
	private static function resolve_origin_file_base_url( string $origin_base_url, string $uploads_base_url ): string {
		$origin_parts  = parse_url( $origin_base_url );
		$uploads_parts = parse_url( $uploads_base_url );

		if ( false === $origin_parts || false === $uploads_parts ) {
			return $origin_base_url;
		}

		$origin_path = $origin_parts['path'] ?? '';
		if ( ! is_string( $origin_path ) || '' === trim( $origin_path, '/' ) ) {
			return $origin_base_url . ( is_string( $uploads_parts['path'] ?? null ) ? rtrim( (string) $uploads_parts['path'], '/' ) : '' );
		}

		return $origin_base_url;
	}

	/**
	 * Normalize base directory strings.
	 *
	 * @param string $base_dir Base directory.
	 * @return string
	 */
	private static function normalize_base_dir( string $base_dir ): string {
		return rtrim( trim( $base_dir ), DIRECTORY_SEPARATOR );
	}

	/**
	 * Build a remote Resizer variant URL and metadata.
	 *
	 * @param array  $variant Normalized Resizer variant.
	 * @param string $filename Sanitized filename.
	 * @param array  $default_image Default image metadata.
	 * @param string $target_format Target format.
	 * @param string $image_cache_dir Cache directory.
	 * @param string $uploads_base_url Local uploads base URL.
	 * @return array<string, mixed>
	 */
	private static function build_remote_resizer_variant( array $variant, string $filename, array $default_image, string $target_format, string $image_cache_dir, string $uploads_base_url ): array {
		$target_dirname = $variant['width'] . 'x' . $variant['height'] . '-' . $variant['image_style'];
		$remote_cache_base_url = self::get_remote_cache_base_url( self::$origin_base_url, $uploads_base_url, $image_cache_dir );
		$filetype = wp_check_filetype( $filename . '.' . $target_format );
		$actual_mime = $filetype['type'] ?? 'image/' . $target_format;

		return [
			'src' => $remote_cache_base_url . '/' . $target_dirname . '/' . $filename . '.' . $target_format,
			'type' => $actual_mime,
			'width' => $variant['width'],
			'height' => $variant['height'],
			'media' => ! empty( $variant['media'] ) ? '(min-width: ' . $variant['media'] . 'px)' : '',
			'alt' => $default_image['alt'] ?? '',
			'caption' => $default_image['caption'] ?? '',
			'description' => $default_image['description'] ?? '',
		];
	}

	/**
	 * Resolve the remote cache/image base URL for Resizer variants.
	 *
	 * @param string $origin_url Configured origin.
	 * @param string $uploads_base_url Local uploads base URL.
	 * @param string $image_cache_dir Cache directory path.
	 * @return string
	 */
	private static function get_remote_cache_base_url( string $origin_url, string $uploads_base_url, string $image_cache_dir ): string {
		$origin_url = rtrim( $origin_url, '/' );
		$uploads_base_url = rtrim( $uploads_base_url, '/' );

		$content_url_parts = parse_url( content_url() );
		$content_path = isset( $content_url_parts['path'] ) && is_string( $content_url_parts['path'] ) ? rtrim( $content_url_parts['path'], '/' ) : '';
		$cache_relative_dir = $image_cache_dir;
		if ( defined( 'WP_CONTENT_DIR' ) && strpos( $cache_relative_dir, WP_CONTENT_DIR ) === 0 ) {
			$cache_relative_dir = substr( $cache_relative_dir, strlen( WP_CONTENT_DIR ) );
		} elseif ( '' !== $content_path ) {
			$content_dir_name = basename( $content_path );
			$content_marker = '/' . trim( $content_dir_name, '/' ) . '/';
			$content_marker_pos = strpos( str_replace( '\\', '/', $cache_relative_dir ), $content_marker );
			if ( false !== $content_marker_pos ) {
				$cache_relative_dir = substr( str_replace( '\\', '/', $cache_relative_dir ), $content_marker_pos + strlen( $content_marker ) - 1 );
			}
		}
		$cache_relative_dir = '/' . ltrim( (string) $cache_relative_dir, '/\\' );

		$uploads_parts = parse_url( $uploads_base_url );
		$origin_parts = parse_url( $origin_url );

		if ( false === $origin_parts || ! isset( $origin_parts['scheme'], $origin_parts['host'] ) ) {
			return $origin_url . $content_path . $cache_relative_dir;
		}

		$remote_site_base = $origin_parts['scheme'] . '://' . $origin_parts['host'];
		if ( isset( $origin_parts['port'] ) ) {
			$remote_site_base .= ':' . $origin_parts['port'];
		}
		if ( isset( $origin_parts['user'] ) && is_string( $origin_parts['user'] ) && $origin_parts['user'] !== '' ) {
			$credentials = $origin_parts['user'];
			if ( isset( $origin_parts['pass'] ) && is_string( $origin_parts['pass'] ) ) {
				$credentials .= ':' . $origin_parts['pass'];
			}
			$remote_site_base = $origin_parts['scheme'] . '://' . $credentials . '@' . $origin_parts['host'] . ( isset( $origin_parts['port'] ) ? ':' . $origin_parts['port'] : '' );
		}

		$origin_path = isset( $origin_parts['path'] ) && is_string( $origin_parts['path'] ) ? rtrim( $origin_parts['path'], '/' ) : '';
		$uploads_path = isset( $uploads_parts['path'] ) && is_string( $uploads_parts['path'] ) ? rtrim( $uploads_parts['path'], '/' ) : '';

		if ( '' !== $origin_path && '' !== $uploads_path && str_ends_with( $origin_path, $uploads_path ) ) {
			$remote_site_base .= substr( $origin_path, 0, -strlen( $uploads_path ) );
		} elseif ( '' !== $origin_path && $origin_path !== $uploads_path ) {
			$remote_site_base .= $origin_path;
		}

		return rtrim( $remote_site_base, '/' ) . $content_path . $cache_relative_dir;
	}

	/**
	 * Check whether a remote variant URL exists.
	 *
	 * @param string $url Remote variant URL.
	 * @return bool
	 */
	private static function remote_variant_exists( string $url ): bool {
		if ( isset( self::$remote_variant_exists_cache[ $url ] ) ) {
			return self::$remote_variant_exists_cache[ $url ];
		}

		$probe_enabled = (bool) apply_filters( 'timber_kit_resizer_probe_remote_variants', true );
		if ( ! $probe_enabled ) {
			self::$remote_variant_exists_cache[ $url ] = true;
			return true;
		}

		$timeout = (float) apply_filters( 'timber_kit_resizer_remote_variant_probe_timeout', 2.0 );
		$response = wp_remote_head(
			$url,
			[
				'timeout' => $timeout,
				'redirection' => 3,
			]
		);

		if ( is_wp_error( $response ) ) {
			self::$remote_variant_exists_cache[ $url ] = false;
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$exists = $status_code >= 200 && $status_code < 400;
		self::$remote_variant_exists_cache[ $url ] = $exists;

		return $exists;
	}

	/**
	 * Build URL string without query/fragment for prefix checks.
	 *
	 * @param array<string, mixed> $parts Parsed URL parts.
	 * @return string
	 */
	private static function build_url_without_query( array $parts ): string {
		$scheme   = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$user     = isset( $parts['user'] ) && is_string( $parts['user'] ) ? $parts['user'] : '';
		$pass     = isset( $parts['pass'] ) && is_string( $parts['pass'] ) ? ':' . $parts['pass'] : '';
		$auth     = '' !== $user ? $user . $pass . '@' : '';
		$host     = isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
		$port     = isset( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
		$path     = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '';

		return $scheme . $auth . $host . $port . $path;
	}
}
