<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze;

/**
 * Keeps Breeze from replaying headers the web server already sends.
 *
 * Breeze pings the site's own home URL, saves every allowed header it sees
 * under `breeze_custom_headers` in `wp-content/breeze-config/breeze-config.php`,
 * and sends that list again on each cache hit. When Apache or nginx adds
 * `X-Frame-Options` and friends, the ping sees them, and a cached page then
 * carries each one twice (`SAMEORIGIN, SAMEORIGIN`, or two lines).
 *
 * Two parts fix it:
 * - {@see self::filter_allowed()} removes the server's headers from the list
 *   Breeze may save (`breeze_custom_headers_allow`).
 * - The filter acts only when Breeze writes its config. A purge does not
 *   write it. A site that already holds the old list keeps replaying it.
 *   {@see self::maybe_schedule_rebuild()} notices a changed list of names
 *   and rebuilds the config once, after the response is sent.
 *
 * The rebuild is best effort. It runs only on a front-end request: never from
 * WP-CLI, cron, wp-admin, the login page, AJAX or REST, so a deploy
 * in maintenance mode cannot make Breeze save an empty list. It records the
 * list as done only after the new config no longer holds those headers. A
 * transient lock spaces the retries.
 *
 * `StarterBase::$breeze_skip_server_headers` turns it on. The names are
 * {@see self::DEFAULT_NAMES} when the theme sends no security headers itself
 * (`$security_headers` off), and none when it does.
 */
final class ServerHeaders {

	/**
	 * The headers `StarterBase::security_headers()` manages, without
	 * `Permissions-Policy`. A site whose theme does not send them leaves them
	 * to the web server, and the server is the second source Breeze would
	 * replay. `Permissions-Policy` stays out because plugins send it from PHP
	 * (WPForms captcha does), and on a cache hit the replay is its only way
	 * to the visitor. A test keeps this list equal to the managed set.
	 *
	 * @var string[]
	 */
	public const DEFAULT_NAMES = array(
		'content-security-policy',
		'referrer-policy',
		'strict-transport-security',
		'x-content-type-options',
		'x-frame-options',
		'x-xss-protection',
	);

	/** @var string Option holding the fingerprint of the list the config was last rebuilt for. */
	public const OPTION = 'timber_kit_breeze_server_headers';

	/** @var string Transient that spaces the rebuild attempts. */
	public const LOCK = 'timber_kit_breeze_server_headers_lock';

	/** @var int Seconds between rebuild attempts. */
	public const LOCK_TTL = 300;

	/** @var bool Prevent duplicate hook registration. */
	private static bool $registered = false;

	/**
	 * @param string[] $names Header names the web server sends.
	 * @return void
	 */
	public static function register( array $names ): void {
		$names = self::normalize( $names );

		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		// An empty list adds no filter. It still checks for a stored
		// fingerprint, so a site that clears the list gets Breeze's own
		// unfiltered config back.
		if ( array() !== $names ) {
			add_filter(
				'breeze_custom_headers_allow',
				static fn ( $allowed ): array => self::filter_allowed( $allowed, $names )
			);
		}

		add_action(
			'init',
			static function () use ( $names ): void {
				self::maybe_schedule_rebuild( $names );
			},
			20
		);
	}

	/**
	 * @param mixed    $allowed Header names Breeze may save.
	 * @param string[] $names   Header names the web server sends.
	 * @return string[]
	 */
	public static function filter_allowed( $allowed, array $names ): array {
		$drop = self::normalize( $names );

		return array_values(
			array_filter(
				(array) $allowed,
				static fn ( $name ): bool => ! in_array( strtolower( (string) $name ), $drop, true )
			)
		);
	}

	/**
	 * Order and case of the names do not change the fingerprint.
	 *
	 * @param string[] $names Header names.
	 * @return string
	 */
	public static function fingerprint( array $names ): string {
		$names = self::normalize( $names );
		sort( $names );

		return md5( implode( ',', $names ) );
	}

	/**
	 * Whether the config text stores any of the headers under
	 * `breeze_custom_headers`. A name elsewhere in the file does not count.
	 *
	 * @param string   $config Contents of breeze-config.php.
	 * @param string[] $names  Header names.
	 * @return bool
	 */
	public static function config_lists_headers( string $config, array $names ): bool {
		if ( ! preg_match( "/'breeze_custom_headers'\s*=>\s*array\s*\((.*?)\n\s*\)/s", $config, $block ) ) {
			return false;
		}

		foreach ( self::normalize( $names ) as $name ) {
			if ( preg_match( "/'" . preg_quote( $name, '/' ) . "'\s*=>/i", $block[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $names Header names the web server sends.
	 * @return void
	 */
	public static function maybe_schedule_rebuild( array $names ): void {
		if ( ! self::is_front_end_request() ) {
			return;
		}

		$stored = (string) get_option( self::OPTION, '' );

		// Nothing stored and nothing listed: the feature was never on.
		if ( array() === self::normalize( $names ) ? '' === $stored : self::fingerprint( $names ) === $stored ) {
			return;
		}

		if ( false !== get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, self::LOCK_TTL );

		add_action(
			'shutdown',
			static function () use ( $names ): void {
				self::rebuild( $names );
			}
		);
	}

	/**
	 * @param string[] $names Header names the web server sends.
	 * @return void
	 */
	public static function rebuild( array $names ): void {
		// WordPress defines REST_REQUEST after `init`, so this is the first
		// point where a REST request can be told apart.
		if ( ! self::is_front_end_request() ) {
			return;
		}

		if ( ! is_callable( array( 'Breeze_ConfigCache', 'write_config_cache' ) ) ) {
			return;
		}

		$path    = WP_CONTENT_DIR . '/breeze-config/breeze-config.php';
		$started = time();
		$before  = is_readable( $path ) ? (int) filemtime( $path ) : 0;

		call_user_func( array( 'Breeze_ConfigCache', 'write_config_cache' ) );

		clearstatcache( true, $path );

		// No readable file means no evidence the rebuild worked. Keep the
		// fingerprint unset so the next attempt runs after the lock expires.
		if ( ! is_readable( $path ) ) {
			return;
		}

		if ( array() === self::normalize( $names ) ) {
			if ( (int) filemtime( $path ) >= $started && (int) filemtime( $path ) > $before ) {
				delete_option( self::OPTION );
			}

			return;
		}

		if ( self::config_lists_headers( (string) file_get_contents( $path ), $names ) ) {
			return;
		}

		update_option( self::OPTION, self::fingerprint( $names ), true );
	}

	/**
	 * @return bool False for WP-CLI, cron, wp-admin, the login page, AJAX and REST.
	 */
	private static function is_front_end_request(): bool {
		return ! ( ( defined( 'WP_CLI' ) && WP_CLI )
			|| defined( 'DOING_CRON' )
			|| is_admin()
			|| 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' )
			|| wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
	}

	/**
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}

	/**
	 * @param string[] $names Header names.
	 * @return string[]
	 */
	private static function normalize( array $names ): array {
		return array_values( array_unique( array_filter( array_map( static fn ( $n ): string => strtolower( trim( (string) $n ) ), $names ) ) ) );
	}
}
