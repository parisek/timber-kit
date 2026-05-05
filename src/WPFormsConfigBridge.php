<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Bridge `wp-config.php` constants to WPForms settings.
 *
 * Lets environments override individual entries of the `wpforms_settings`
 * option via PHP constants, so per-env values such as test Cloudflare
 * Turnstile keys can live in `wp-config.php` instead of the WP admin.
 *
 * Naming convention: a setting key `turnstile-site-key` is bridged from a
 * constant `WPFORMS_TURNSTILE_SITE_KEY`. Hyphens become underscores and the
 * whole name is uppercased.
 *
 * Activation is gated by `StarterBase` to projects where WPForms is loaded;
 * the filter is otherwise inert because no other consumer reads the option.
 */
final class WPFormsConfigBridge {

	/** @var bool Prevent duplicate hook registration. */
	private static bool $registered = false;

	/**
	 * Captcha-related keys bridged even when absent from the saved option.
	 *
	 * Walking the existing settings array does not handle the fresh-install
	 * case where the captcha pane has never been saved. These keys are the
	 * common per-env values, so they are always evaluated.
	 *
	 * @var string[]
	 */
	private const ALWAYS_BRIDGED_KEYS = array(
		'captcha-provider',
		'turnstile-site-key',
		'turnstile-secret-key',
		'recaptcha-type',
		'recaptcha-site-key',
		'recaptcha-secret-key',
		'hcaptcha-site-key',
		'hcaptcha-secret-key',
	);

	/**
	 * Register the WPForms settings filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'option_wpforms_settings', array( self::class, 'applyOverrides' ), 11 );
	}

	/**
	 * Override `wpforms_settings` entries with matching `WPFORMS_*` constants.
	 *
	 * Walks the saved settings array first so any persisted key can be
	 * swapped, then evaluates a small allow-list of captcha keys so brand-new
	 * installs without any saved settings still pick up wp-config defines.
	 *
	 * @param mixed $settings Raw option value as returned by WordPress.
	 * @return mixed Settings array with constant overrides applied.
	 */
	public static function applyOverrides( mixed $settings ): mixed {
		$settings = is_array( $settings ) ? $settings : array();

		foreach ( array_keys( $settings ) as $key ) {
			$const = self::constantName( (string) $key );
			if ( defined( $const ) ) {
				$settings[ $key ] = constant( $const );
			}
		}

		foreach ( self::ALWAYS_BRIDGED_KEYS as $key ) {
			$const = self::constantName( $key );
			if ( defined( $const ) ) {
				$settings[ $key ] = constant( $const );
			}
		}

		return $settings;
	}

	/**
	 * Map a WPForms setting key to its bridged constant name.
	 *
	 * @param string $key Setting key, e.g. `turnstile-site-key`.
	 * @return string Constant name, e.g. `WPFORMS_TURNSTILE_SITE_KEY`.
	 */
	private static function constantName( string $key ): string {
		return 'WPFORMS_' . strtoupper( str_replace( '-', '_', $key ) );
	}

	/**
	 * Reset internal state so tests can re-register the bridge.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}
}
