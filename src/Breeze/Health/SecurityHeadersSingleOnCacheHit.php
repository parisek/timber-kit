<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze\Health;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: does a Breeze cache hit send a security header that a cache
 * miss sends once?
 *
 * Breeze fetches its own front page, saves an allow-list of response headers
 * in its config, and sends them with header() on every cache hit. When the web
 * server also sends the same header (an .htaccess mod_headers block, an nginx
 * add_header), the hit carries two copies and the miss carries one. The kit's
 * duplicate-header warning reads one response, which is the miss, so it cannot
 * see this.
 *
 * StarterBase registers the check only when Breeze is active. It does not
 * depend on $security_headers: the duplicate also appears when the kit sends
 * no header at all.
 *
 * The check sends three anonymous GET requests to the home URL. The first
 * carries a random query, so the page cache cannot answer it (the miss). The
 * second and third use the plain URL back to back, so the third can be a hit.
 * Breeze serves its cache to GET only, so HEAD would never reach a hit. No
 * request follows redirects.
 *
 * Detection and advice only. The check changes no Breeze setting and no
 * header.
 */
final class SecurityHeadersSingleOnCacheHit implements HealthCheck {

	/** @var array<string, string> Lower-case name => display name. */
	private const MANAGED = array(
		'x-frame-options'           => 'X-Frame-Options',
		'x-content-type-options'    => 'X-Content-Type-Options',
		'referrer-policy'           => 'Referrer-Policy',
		'content-security-policy'   => 'Content-Security-Policy',
		'permissions-policy'        => 'Permissions-Policy',
		'x-xss-protection'          => 'X-XSS-Protection',
		'strict-transport-security' => 'Strict-Transport-Security',
	);

	private const PROBE_ARG = 'timber-kit-cache-probe';

	public function id(): string {
		return 'security_headers_single_on_cache_hit';
	}

	public function label(): string {
		return __( 'Security headers are sent once on a cache hit', 'timber-kit' );
	}

	public function category(): string {
		return 'security';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$home = home_url( '/' );
		$args = array(
			'timeout'     => 5,
			'redirection' => 0,
		);

		$probe = $home . ( str_contains( $home, '?' ) ? '&' : '?' ) . self::PROBE_ARG . '=' . bin2hex( random_bytes( 8 ) );

		$miss = wp_remote_get( $probe, $args );
		$fail = $this->unusable( $miss, $probe );
		if ( null !== $fail ) {
			return $fail;
		}

		// The first plain request may itself be the miss that fills the cache.
		// Only the second one can be relied on to be a hit.
		$prime = wp_remote_get( $home, $args );
		$fail  = $this->unusable( $prime, $home );
		if ( null !== $fail ) {
			return $fail;
		}

		$hit  = wp_remote_get( $home, $args );
		$fail = $this->unusable( $hit, $home );
		if ( null !== $fail ) {
			return $fail;
		}

		/** @var array<string, mixed> $miss */
		/** @var array<string, mixed> $hit */
		if ( ! $this->isCacheHit( $hit ) ) {
			return Result::recommended(
				sprintf(
					/* translators: %s: home URL. */
					__( 'Could not verify security headers on a cache hit. The repeated request to %s did not show a cache hit: no x-cache header with HIT, no Age above 0, and no Breeze Cache-Provider header for a cached file. The page cache can be off for this URL, or a purge ran between the requests.', 'timber-kit' ),
					$home
				)
			);
		}

		$flagged = array();
		foreach ( self::MANAGED as $name => $label ) {
			$on_miss = count( $this->copies( $miss, $name ) );
			$on_hit  = count( $this->copies( $hit, $name ) );

			// A header that is already repeated on the miss has a second source
			// that is not the cache, for example PHP and the server sending two
			// different Permissions-Policy values. The cache did not add it, so
			// this check does not report it.
			if ( $on_miss <= 1 && $on_hit > 1 ) {
				$flagged[ $name ] = $label;
			}
		}

		if ( array() === $flagged ) {
			return Result::good(
				__( 'Each security header is sent once on a cache miss and once on a cache hit. Cache state changes over time, so one clean result is not proof.', 'timber-kit' )
			);
		}

		return $this->duplicated( $flagged );
	}

	/**
	 * A "could not verify" result for a failed or non-2xx response, or null
	 * when the response is usable.
	 *
	 * @param mixed $response Return value of wp_remote_get().
	 */
	private function unusable( mixed $response, string $url ): ?Result {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return Result::recommended(
				sprintf(
					/* translators: %s: requested URL. */
					__( 'Could not verify security headers on a cache hit. The loopback request to %s failed. Basic auth, a firewall or a proxy in front of the site can cause this.', 'timber-kit' ),
					$url
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			// A redirect or an error page does not carry the header set of the
			// page the cache stores.
			return Result::recommended(
				sprintf(
					/* translators: 1: requested URL, 2: HTTP status code. */
					__( 'Could not verify security headers on a cache hit. %1$s answered HTTP %2$d.', 'timber-kit' ),
					$url,
					$code
				)
			);
		}

		return null;
	}

	/**
	 * Hit evidence, any one of:
	 * - an x-cache header that contains HIT (Varnish, most proxies);
	 * - an Age header above 0 (a shared cache served a stored copy);
	 * - Breeze's own Cache-Provider header ending in "E". Breeze sends
	 *   CLOUDWAYS-CACHE-<device>E when it serves an existing cache file and
	 *   CLOUDWAYS-CACHE-<device>C when it writes a new one on a miss.
	 *
	 * @param array<string, mixed> $response
	 */
	private function isCacheHit( array $response ): bool {
		foreach ( $this->lines( $response, 'x-cache' ) as $value ) {
			if ( false !== stripos( $value, 'hit' ) ) {
				return true;
			}
		}

		foreach ( $this->lines( $response, 'age' ) as $value ) {
			if ( (int) $value > 0 ) {
				return true;
			}
		}

		foreach ( $this->lines( $response, 'cache-provider' ) as $value ) {
			if ( 1 === preg_match( '/^CLOUDWAYS-CACHE-[A-Z]?E$/i', trim( $value ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Header lines as sent. WP_HTTP gives a string for one line and an array
	 * for repeated lines.
	 *
	 * @param array<string, mixed> $response
	 * @return list<string>
	 */
	private function lines( array $response, string $name ): array {
		$raw   = wp_remote_retrieve_header( $response, $name );
		$lines = array();

		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Copies of one header, counting repeated lines and repeated values that
	 * share one line. Apache folds a second "Header append" into the same line
	 * as "SAMEORIGIN, SAMEORIGIN", so a line counts as N copies when it is the
	 * same comma-separated block written N times. A comma list that does not
	 * repeat ("geolocation=(), camera=()", "no-referrer, origin") stays one
	 * copy.
	 *
	 * @param array<string, mixed> $response
	 * @return list<string>
	 */
	private function copies( array $response, string $name ): array {
		$copies = array();

		foreach ( $this->lines( $response, $name ) as $line ) {
			foreach ( $this->unfold( $line ) as $copy ) {
				$copies[] = $copy;
			}
		}

		return $copies;
	}

	/**
	 * @return list<string>
	 */
	private function unfold( string $line ): array {
		$parts = array_map( 'trim', explode( ',', $line ) );
		$total = count( $parts );

		for ( $repeats = $total; $repeats > 1; $repeats-- ) {
			if ( 0 !== $total % $repeats ) {
				continue;
			}

			$size   = intdiv( $total, $repeats );
			$block  = array_slice( $parts, 0, $size );
			$repeat = true;

			for ( $i = 1; $i < $repeats; $i++ ) {
				if ( array_slice( $parts, $i * $size, $size ) !== $block ) {
					$repeat = false;
					break;
				}
			}

			if ( $repeat ) {
				return array_fill( 0, $repeats, implode( ', ', $block ) );
			}
		}

		return array( $line );
	}

	/**
	 * @param array<string, string> $flagged Lower-case name => display name.
	 */
	private function duplicated( array $flagged ): Result {
		$summary = sprintf(
			/* translators: %s: comma-separated list of HTTP header names. */
			__( 'These security headers are sent once on a cache miss and more than once on a cache hit: %s. Breeze is the likely source. It saves the response headers of the front page and sends them again on every cache hit. The web server then adds its own copy.', 'timber-kit' ),
			implode( ', ', $flagged )
		);

		$names   = "'" . implode( "', '", array_keys( $flagged ) ) . "'";
		$snippet = "add_filter( 'breeze_custom_headers_allow', static function ( array \$headers ): array {\n"
			. "\treturn array_values( array_diff( array_map( 'strtolower', \$headers ), array( {$names} ) ) );\n"
			. '} );';

		$actions = '<p>' . esc_html__( 'Remove these headers from the list that Breeze saves. Use the breeze_custom_headers_allow filter. Keep a header that only PHP sends: a cache hit does not run PHP, so Breeze is the only source of that header on a hit.', 'timber-kit' ) . '</p>'
			. '<pre><code>' . esc_html( $snippet ) . '</code></pre>'
			. '<p>' . esc_html__( 'The saved copy changes only when Breeze rebuilds its config, for example when you save the Breeze settings. Purge the cache after that.', 'timber-kit' ) . '</p>'
			. '<p>' . esc_html__( 'Permissions-Policy can legitimately carry two different values, one from PHP and one from the server. This check does not report it when the cache miss also carries two copies.', 'timber-kit' ) . '</p>';

		return Result::recommended( $summary, $actions );
	}
}
