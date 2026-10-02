<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: does a singular page still carry a BreadcrumbList?
 *
 * `$seo_suppress_plugin_breadcrumb` removes the SEO plugin's list on the
 * assumption that the theme renders its own. When the theme does not, the
 * page loses the markup and nothing says so: the page answers 200 and stays
 * valid. This check tests the assumption on a real page.
 *
 * StarterBase registers it only while the suppression is active, so a site
 * that keeps the plugin's list gets no extra loopback request.
 *
 * It requests the newest published post of the first public post type that
 * has one, never the home page: a home page can carry no breadcrumb by design.
 * The request sends no cookies, so it sees what an anonymous visitor sees.
 * It does not follow redirects, so a redirect to a login page is reported as
 * unverified, not as a page without a breadcrumb.
 *
 * Only JSON-LD blocks and microdata count. The word in visible text or in an
 * ordinary script is no markup a crawler reads.
 */
final class BreadcrumbListRendered implements HealthCheck {

	private const JSON_LD_BLOCK = '#<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is';

	private const MICRODATA = '#\bitemtype\s*=\s*["\']https?://schema\.org/BreadcrumbList/?["\']#i';

	public function id(): string {
		return 'breadcrumb_list_rendered';
	}

	public function label(): string {
		return __( 'Pages carry a BreadcrumbList', 'timber-kit' );
	}

	public function category(): string {
		return 'seo';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$url = $this->sampleUrl();

		if ( null === $url ) {
			return Result::good(
				__( 'No published post or page to check yet. The BreadcrumbList check runs when one exists.', 'timber-kit' )
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return Result::recommended(
				sprintf(
					/* translators: %s: page URL. */
					__( 'Could not verify that %s carries a BreadcrumbList. The loopback request failed. Basic auth, a firewall or a cache in front of the site can cause this.', 'timber-kit' ),
					$url
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return Result::recommended(
				sprintf(
					/* translators: 1: page URL, 2: HTTP status code. */
					__( 'Could not verify that %1$s carries a BreadcrumbList. The server answered HTTP %2$d.', 'timber-kit' ),
					$url,
					$code
				)
			);
		}

		if ( $this->hasBreadcrumbList( (string) wp_remote_retrieve_body( $response ) ) ) {
			return Result::good(
				sprintf(
					/* translators: %s: page URL. */
					__( '%s carries a BreadcrumbList.', 'timber-kit' ),
					$url
				)
			);
		}

		return Result::recommended(
			sprintf(
				/* translators: %s: page URL. */
				__( '%s carries no BreadcrumbList. timber-kit removes the SEO plugin\'s list, so the page has none. Fix it in one of two ways: render a breadcrumb in the theme, or set $seo_suppress_plugin_breadcrumb = false in the project Base class to keep the SEO plugin\'s list.', 'timber-kit' ),
				$url
			)
		);
	}

	/**
	 * Permalink of the newest published post of the first public post type
	 * that has one. The static front page and the posts page are excluded.
	 */
	private function sampleUrl(): ?string {
		$excluded = array_values(
			array_filter(
				array(
					(int) get_option( 'page_on_front' ),
					(int) get_option( 'page_for_posts' ),
				)
			)
		);

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'has_password' => false,
					'post__not_in' => $excluded,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'numberposts'  => 1,
					'fields'       => 'ids',
				)
			);

			if ( array() === $ids ) {
				continue;
			}

			$url = get_permalink( $ids[0] );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return null;
	}

	private function hasBreadcrumbList( string $body ): bool {
		if ( 1 === preg_match( self::MICRODATA, $body ) ) {
			return true;
		}

		if ( false === preg_match_all( self::JSON_LD_BLOCK, $body, $blocks ) ) {
			return false;
		}

		foreach ( $blocks[1] as $block ) {
			if ( str_contains( $block, 'BreadcrumbList' ) ) {
				return true;
			}
		}

		return false;
	}
}
