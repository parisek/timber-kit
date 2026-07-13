<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

/**
 * Shared XML sitemap fixtures for BreezeWarmupSitemap tests.
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
}
