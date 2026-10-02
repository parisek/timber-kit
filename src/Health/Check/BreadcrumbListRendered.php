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
 * Only markup counts: a JSON-LD node typed BreadcrumbList, or a microdata
 * item of that type. The word in visible text, in an ordinary script or in a
 * JSON-LD string value is no list a crawler reads.
 */
final class BreadcrumbListRendered implements HealthCheck {

	private const JSON_LD_BLOCK = '#<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is';

	/** A start tag. Quoted values may contain `>`. */
	private const START_TAG = '#<[a-z][a-z0-9-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';

	/** One attribute: name, then an optional double-quoted, single-quoted or bare value. */
	private const ATTRIBUTE = '#([^\s"\'=<>/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#';

	private const ITEMTYPE_TOKEN = '#^https?://schema\.org/BreadcrumbList/?$#';

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
		return $this->hasMicrodataList( $body ) || $this->hasJsonLdList( $body );
	}

	/**
	 * Microdata: an element with `itemscope` whose `itemtype` token list holds
	 * the schema.org BreadcrumbList URL. `itemtype` may list several absolute
	 * URLs; without `itemscope` it creates no item.
	 *
	 * A small tokenizer reads the start tags, so the package needs no DOM
	 * extension.
	 */
	private function hasMicrodataList( string $body ): bool {
		if ( false === stripos( $body, 'itemtype' ) || false === preg_match_all( self::START_TAG, $body, $tags ) ) {
			return false;
		}

		foreach ( $tags[0] as $tag ) {
			if ( false === stripos( $tag, 'itemtype' ) ) {
				continue;
			}

			$attributes = $this->attributes( $tag );

			if ( ! array_key_exists( 'itemscope', $attributes ) || ! isset( $attributes['itemtype'] ) ) {
				continue;
			}

			$tokens = preg_split( '/\s+/', trim( $attributes['itemtype'] ) );
			foreach ( false === $tokens ? array() : $tokens as $token ) {
				if ( 1 === preg_match( self::ITEMTYPE_TOKEN, $token ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Attributes of one start tag, keyed by lower-case name. A bare attribute
	 * maps to an empty string. The first occurrence of a name wins, as in HTML.
	 *
	 * @return array<string, string>
	 */
	private function attributes( string $tag ): array {
		$inner = (string) preg_replace( '#^<[a-z][a-z0-9-]*|/?>$#i', '', $tag );

		if ( false === preg_match_all( self::ATTRIBUTE, $inner, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$attributes = array();
		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );
			if ( ! array_key_exists( $name, $attributes ) ) {
				$attributes[ $name ] = ( $match[2] ?? '' ) . ( $match[3] ?? '' ) . ( $match[4] ?? '' );
			}
		}

		return $attributes;
	}

	/**
	 * JSON-LD: a node whose `@type` is BreadcrumbList, or a list that holds it.
	 * The word in a string value, such as a description, is no list. A block
	 * that is not valid JSON is skipped; the other blocks still count.
	 */
	private function hasJsonLdList( string $body ): bool {
		if ( false === preg_match_all( self::JSON_LD_BLOCK, $body, $blocks ) ) {
			return false;
		}

		foreach ( $blocks[1] as $block ) {
			$data = json_decode( trim( $block ), true );
			if ( is_array( $data ) && $this->holdsBreadcrumbList( $data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walks every nested array, so a list of nodes, `@graph` and a node
	 * embedded in a property (such as `WebPage.breadcrumb`) are all found.
	 *
	 * @param array<mixed> $node Decoded JSON value.
	 */
	private function holdsBreadcrumbList( array $node ): bool {
		if ( in_array( 'BreadcrumbList', (array) ( $node['@type'] ?? array() ), true ) ) {
			return true;
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) && $this->holdsBreadcrumbList( $value ) ) {
				return true;
			}
		}

		return false;
	}
}
