<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: can an anonymous visitor fetch the static files that this
 * package serves from the theme's vendor/ directory?
 *
 * StarterBase registers it only when $admin_resizable_sidebar is on, and
 * passes the sidebar stylesheet and script URLs in, because
 * packageAssetUrl() is protected. The check reports good only when every
 * file answers 200, and names each file that does not.
 *
 * Two server states make a URL answer 403, and the editor shows neither:
 * 1. the theme .htaccess denies vendor/ with no allow rule for static files;
 * 2. the web server user cannot enter a directory on the vendor/ path
 *    (mode 750 with an ACL entry group::---). Apache then answers 403 with
 *    "Server unable to read htaccess file" in the body.
 *
 * The check requests the URLs instead of reading .htaccess: that tests the
 * outcome on every host, nginx included, and also catches cause 2.
 *
 * It sends HEAD first. Only a HEAD answer of 403, 404, 405 or 501 is
 * repeated as GET: a host may reject HEAD (405/501), and only a GET carries
 * the 403 body that tells the two causes apart. A transport error or any
 * other status on HEAD ends that file's probe as "could not verify", so a
 * host that times out costs one 5 second wait per file, not two. Neither
 * request follows redirects, so a redirect to a login page or a soft-404
 * handler is reported as unverified, not as a healthy file.
 *
 * With several files, a blocked file (403/404) wins: the result carries the
 * causes and fixes, and also names any file that could not be verified.
 * Otherwise any unverified file makes the result "could not verify".
 */
final class PackageAssetsReachable implements HealthCheck {

	private const FILESYSTEM_MARKER = 'Server unable to read htaccess file';

	private const ALLOW_RULE = 'RewriteRule ^vendor/.+\.(css|js|mjs|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif)$ - [L]';

	/** HEAD answers that a GET can still turn into a result. */
	private const GET_FALLBACK_CODES = array( 403, 404, 405, 501 );

	/** @var non-empty-list<string> */
	private readonly array $urls;

	public function __construct( string $url, string ...$urls ) {
		$this->urls = array( $url, ...array_values( $urls ) );
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
		$blocked    = array();
		$unverified = array();

		foreach ( $this->urls as $url ) {
			$probe = $this->probe( $url );

			if ( isset( $probe['blocked'] ) ) {
				$blocked[] = $probe['blocked'];
			} elseif ( isset( $probe['unverified'] ) ) {
				$unverified[] = $probe['unverified'];
			}
		}

		if ( array() !== $blocked ) {
			return $this->blocked( $blocked, $unverified );
		}

		if ( array() !== $unverified ) {
			return Result::recommended( implode( ' ', $unverified ) );
		}

		return Result::good(
			__( 'The resizable editor sidebar assets load from the package vendor/ directory.', 'timber-kit' )
		);
	}

	/**
	 * Probe one URL. An empty array means the file answered 200.
	 *
	 * @return array{blocked?: array{url: string, code: int, body: string}, unverified?: string}
	 */
	private function probe( string $url ): array {
		// No redirects: a redirect to a login page or a soft-404 handler would
		// end in HTTP 200 on an HTML document and read as a healthy file.
		$args     = array(
			'timeout'     => 5,
			'redirection' => 0,
		);
		$response = wp_remote_head( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'unverified' => $this->loopbackFailed( $url ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array();
		}

		if ( ! in_array( $code, self::GET_FALLBACK_CODES, true ) ) {
			return array( 'unverified' => $this->unexpectedStatus( $url, $code ) );
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'unverified' => $this->loopbackFailed( $url ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array();
		}

		if ( 403 === $code || 404 === $code ) {
			return array(
				'blocked' => array(
					'url'  => $url,
					'code' => $code,
					'body' => 403 === $code ? (string) wp_remote_retrieve_body( $response ) : '',
				),
			);
		}

		return array( 'unverified' => $this->unexpectedStatus( $url, $code ) );
	}

	private function loopbackFailed( string $url ): string {
		return sprintf(
			/* translators: %s: asset URL. */
			__( 'Could not verify that %s is reachable. The loopback request failed. Basic auth, a firewall or a cache in front of the site can cause this.', 'timber-kit' ),
			$url
		);
	}

	private function unexpectedStatus( string $url, int $code ): string {
		return sprintf(
			/* translators: 1: asset URL, 2: HTTP status code. */
			__( 'Could not verify that %1$s is reachable. The server answered HTTP %2$d.', 'timber-kit' ),
			$url,
			$code
		);
	}

	/**
	 * @param non-empty-list<array{url: string, code: int, body: string}> $blocked
	 * @param list<string>                                                $unverified
	 */
	private function blocked( array $blocked, array $unverified ): Result {
		$sentences = array();
		foreach ( $blocked as $file ) {
			$sentences[] = sprintf(
				/* translators: 1: asset URL, 2: HTTP status code. */
				__( '%1$s answered HTTP %2$d.', 'timber-kit' ),
				$file['url'],
				$file['code']
			);
		}

		$sentences[] = __( 'The block editor sidebar is not resizable. Two causes produce this. Cause 1: the theme .htaccess denies vendor/ and has no allow rule for static files above that deny. Cause 2: the web server user cannot enter a directory on the vendor/ path, for example mode 750 with an ACL entry group::---.', 'timber-kit' );

		// Any 403 body with the marker points to cause 2. Otherwise a
		// non-empty 403 body points to cause 1. No body means no hint.
		$bodies = array_filter( array_column( $blocked, 'body' ), static fn ( string $body ): bool => '' !== $body );
		if ( array() !== $bodies ) {
			$filesystem  = array() !== array_filter( $bodies, static fn ( string $body ): bool => str_contains( $body, self::FILESYSTEM_MARKER ) );
			$sentences[] = $filesystem
				? __( 'The response points to the directory permissions (cause 2).', 'timber-kit' )
				: __( 'The response points to the .htaccess rule (cause 1).', 'timber-kit' );
		}

		$summary = implode( ' ', array_merge( $sentences, $unverified ) );

		$actions = '<p>' . __( 'Fix for cause 1: add this rule to the theme .htaccess, above the rule that denies vendor/:', 'timber-kit' ) . '</p>'
			. '<pre><code>' . self::ALLOW_RULE . '</code></pre>'
			. '<p>' . __( 'Fix for cause 2: give the web server user traverse permission on vendor/ and on each directory down to the package. In the theme directory, for example:', 'timber-kit' ) . '</p>'
			. '<pre><code>setfacl -m g::--x vendor vendor/parisek vendor/parisek/timber-kit</code></pre>'
			. '<p>' . __( 'Or use chmod g+x on the same directories. PHP and config files under vendor/ stay denied.', 'timber-kit' ) . '</p>';

		return Result::recommended( $summary, $actions );
	}
}
