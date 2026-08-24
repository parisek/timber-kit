<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\WarmupSitemap;

/**
 * Shared XML sitemap fixtures for WarmupSitemap tests.
 */
final class Fixtures {

	/**
	 * A `<urlset>` document with the given `<loc>` entries.
	 *
	 * @param string[] $locs
	 * @return string
	 */
	public static function urlset( array $locs ): string {
		$entries = '';
		foreach ( $locs as $loc ) {
			$entries .= sprintf( '<url><loc>%s</loc></url>', htmlspecialchars( $loc, ENT_XML1 ) );
		}

		return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
			. $entries . '</urlset>';
	}

	/**
	 * A `<sitemapindex>` document pointing at the given sub-sitemap URLs.
	 *
	 * @param string[] $locs
	 * @return string
	 */
	public static function sitemapIndex( array $locs ): string {
		$entries = '';
		foreach ( $locs as $loc ) {
			$entries .= sprintf( '<sitemap><loc>%s</loc></sitemap>', htmlspecialchars( $loc, ENT_XML1 ) );
		}

		return '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
			. $entries . '</sitemapindex>';
	}

	/**
	 * A `<urlset>` document whose entries carry a `<lastmod>`.
	 *
	 * @param array<string, string> $locs loc => lastmod (ISO-8601), empty string for none.
	 * @return string
	 */
	public static function urlsetWithLastmod( array $locs ): string {
		$entries = '';
		foreach ( $locs as $loc => $lastmod ) {
			$entries .= '<url><loc>' . htmlspecialchars( $loc, ENT_XML1 ) . '</loc>';
			if ( '' !== $lastmod ) {
				$entries .= '<lastmod>' . htmlspecialchars( $lastmod, ENT_XML1 ) . '</lastmod>';
			}
			$entries .= '</url>';
		}

		return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
			. $entries . '</urlset>';
	}

	/**
	 * A successful `wp_remote_get()`-shaped response array wrapping a body.
	 *
	 * @param string $body
	 * @param int    $code
	 * @return array<string, mixed>
	 */
	public static function response( string $body, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	/**
	 * A gzip-compressed `wp_remote_get()`-shaped response wrapping the body.
	 *
	 * @param string $body Uncompressed body.
	 * @param int    $code
	 * @return array<string, mixed>
	 */
	public static function gzipResponse( string $body, int $code = 200 ): array {
		return self::response( (string) gzencode( $body ), $code );
	}

	/**
	 * An XXE payload: a `<urlset>` document whose DOCTYPE declares an
	 * internal-subset external entity, referenced from a `<loc>`. Used to
	 * regression-test that `parseXml()` never expands it.
	 *
	 * @param string $entityUri e.g. `file:///etc/passwd`.
	 * @return string
	 */
	public static function xxeUrlset( string $entityUri ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "' . $entityUri . '">]>'
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>&xxe;</loc></url></urlset>';
	}
}
