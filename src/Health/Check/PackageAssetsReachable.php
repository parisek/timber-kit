<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: can an anonymous visitor fetch a static file that this
 * package serves from the theme's vendor/ directory?
 *
 * StarterBase registers it only when $admin_resizable_sidebar is on, and
 * passes the stylesheet URL in, because packageAssetUrl() is protected.
 *
 * Two server states make the URL answer 403, and the editor shows neither:
 * 1. the theme .htaccess denies vendor/ with no allow rule for static files;
 * 2. the web server user cannot enter a directory on the vendor/ path
 *    (mode 750 with an ACL entry group::---). Apache then answers 403 with
 *    "Server unable to read htaccess file" in the body.
 *
 * The check requests the URL instead of reading .htaccess: that tests the
 * outcome on every host, nginx included, and also catches cause 2.
 *
 * It sends HEAD first. Any answer other than 200 is repeated as GET: a host
 * may reject HEAD (405/501), core does not follow redirects on HEAD, and only
 * a GET carries the 403 body that tells the two causes apart. The GET costs
 * one extra request, on the failure path only.
 */
final class PackageAssetsReachable implements HealthCheck {

	private const FILESYSTEM_MARKER = 'Server unable to read htaccess file';

	private const ALLOW_RULE = 'RewriteRule ^vendor/.+\.(css|js|mjs|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif)$ - [L]';

	public function __construct( private readonly string $url ) {
	}

	public function id(): string {
		return 'package_assets_reachable';
	}

	public function label(): string {
		return __( 'timber-kit editor assets are reachable', 'timber-kit' );
	}

	public function category(): string {
		return 'timber-kit';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$args     = array( 'timeout' => 5 );
		$response = wp_remote_head( $this->url, $args );

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->good();
		}

		$response = wp_remote_get( $this->url, $args );

		if ( is_wp_error( $response ) ) {
			return Result::recommended(
				sprintf(
					/* translators: %s: asset URL. */
					__( 'Could not verify that %s is reachable. The loopback request failed. Basic auth, a firewall or a cache in front of the site can cause this.', 'timber-kit' ),
					$this->url
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return $this->good();
		}

		if ( 403 === $code || 404 === $code ) {
			return $this->blocked( $code, 403 === $code ? (string) wp_remote_retrieve_body( $response ) : '' );
		}

		return Result::recommended(
			sprintf(
				/* translators: 1: asset URL, 2: HTTP status code. */
				__( 'Could not verify that %1$s is reachable. The server answered HTTP %2$d.', 'timber-kit' ),
				$this->url,
				$code
			)
		);
	}

	private function good(): Result {
		return Result::good(
			__( 'The resizable editor sidebar assets load from the package vendor/ directory.', 'timber-kit' )
		);
	}

	private function blocked( int $code, string $body ): Result {
		$summary = sprintf(
			/* translators: 1: asset URL, 2: HTTP status code. */
			__( '%1$s answered HTTP %2$d, so the block editor sidebar is not resizable. Two causes produce this. Cause 1: the theme .htaccess denies vendor/ and has no allow rule for static files above that deny. Cause 2: the web server user cannot enter a directory on the vendor/ path, for example mode 750 with an ACL entry group::---.', 'timber-kit' ),
			$this->url,
			$code
		);

		if ( '' !== $body ) {
			$summary .= ' ' . ( str_contains( $body, self::FILESYSTEM_MARKER )
				? __( 'The response points to the directory permissions (cause 2).', 'timber-kit' )
				: __( 'The response points to the .htaccess rule (cause 1).', 'timber-kit' ) );
		}

		$actions = '<p>' . __( 'Fix for cause 1: add this rule to the theme .htaccess, above the rule that denies vendor/:', 'timber-kit' ) . '</p>'
			. '<pre><code>' . self::ALLOW_RULE . '</code></pre>'
			. '<p>' . __( 'Fix for cause 2: give the web server user traverse permission on vendor/ and on each directory down to the package. In the theme directory, for example:', 'timber-kit' ) . '</p>'
			. '<pre><code>setfacl -m g::--x vendor vendor/parisek vendor/parisek/timber-kit</code></pre>'
			. '<p>' . __( 'Or use chmod g+x on the same directories. PHP and config files under vendor/ stay denied.', 'timber-kit' ) . '</p>';

		return Result::recommended( $summary, $actions );
	}
}
