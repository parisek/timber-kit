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
	 * Register the WPForms settings filters.
	 *
	 * Hooks both `option_wpforms_settings` (DB row exists) and
	 * `default_option_wpforms_settings` (DB row missing). Without the second
	 * filter, fresh installs that have never saved WPForms settings would
	 * bypass the bridge entirely because WordPress short-circuits
	 * `get_option()` to the default-value path before firing `option_*`.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'option_wpforms_settings', array( self::class, 'applyOverrides' ), 11 );
		add_filter( 'default_option_wpforms_settings', array( self::class, 'applyOverrides' ), 11 );

		if ( is_admin() ) {
			// WPForms calls `wpforms_admin_hide_unrelated_notices()` on
			// `admin_print_scripts` (priority 10) and wipes the entire
			// `admin_notices` callback list on its own admin screens. Defer
			// our registration to `in_admin_header`, which fires after that
			// strip and immediately before `admin_notices` is dispatched, so
			// the override hint survives and renders on WPForms pages.
			add_action(
				'in_admin_header',
				static function (): void {
					add_action( 'admin_notices', array( self::class, 'maybeRenderAdminNotice' ) );
				}
			);
		}
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
	 * Render an admin notice on WPForms screens listing active overrides.
	 *
	 * Scoped to admin screens whose ID contains `wpforms` so the notice only
	 * appears where it is actionable (Settings → CAPTCHA, Integrations, etc.).
	 *
	 * @return void
	 */
	public static function maybeRenderAdminNotice(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || ! str_contains( $screen->id, 'wpforms' ) ) {
			return;
		}

		$overrides = self::collectActiveOverrides();
		if ( array() === $overrides ) {
			return;
		}

		$items = '';
		foreach ( $overrides as $key => $const ) {
			$items .= sprintf(
				'<li><code>%s</code> ← <code>%s</code></li>',
				esc_html( $key ),
				esc_html( $const )
			);
		}

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong></p><ul>%s</ul><p>%s</p></div>',
			esc_html__( 'WPForms settings overridden via wp-config.php', 'timber-kit' ),
			$items, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			esc_html__( 'These values come from PHP constants at runtime. Changes saved on this screen are stored in the database but ignored until the constant is removed.', 'timber-kit' )
		);
	}

	/**
	 * Collect setting keys whose values are currently overridden by constants.
	 *
	 * Combines the saved option keys and the always-bridged captcha keys, then
	 * filters down to the ones whose corresponding constant is defined.
	 *
	 * @return array<string, string> Map of setting key → constant name.
	 */
	public static function collectActiveOverrides(): array {
		$saved = function_exists( 'get_option' ) ? get_option( 'wpforms_settings', array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();

		$candidates = array_unique(
			array_merge(
				array_map( 'strval', array_keys( $saved ) ),
				self::ALWAYS_BRIDGED_KEYS
			)
		);

		$active = array();
		foreach ( $candidates as $key ) {
			$const = self::constantName( $key );
			if ( defined( $const ) ) {
				$active[ $key ] = $const;
			}
		}

		ksort( $active );

		return $active;
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
