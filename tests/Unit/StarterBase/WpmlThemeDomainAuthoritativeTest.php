<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Covers the `$wpml_theme_domain_authoritative` flag (default on): hook wiring
 * inside registerMiscHooks() and the `icl_st_settings` callbacks that exclude
 * the theme's text-domain from WPML String Translation auto-registration.
 *
 * WPML ST reads its excluded domains from the `icl_st_settings` option
 * (`WPML_ST_Settings::SETTINGS_KEY`), a flat array. The list is not nested
 * under `icl_sitepress_settings['st']`, and nothing in WPML reads it from
 * there. That is the same in every String Translation release from 3.2 to 5.0.
 */
class WpmlThemeDomainAuthoritativeTest extends StarterBaseTestCase {

	private const KEY = 'wpml_st_auto_reg_excluded_contexts';

	private function invokeRegisterMiscHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerMiscHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance( ?bool $flag = null, string $theme_name = 'my-theme' ): StarterBase {
		$instance = ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
		if ( null !== $flag ) {
			$property = ( new \ReflectionClass( StarterBase::class ) )->getProperty( 'wpml_theme_domain_authoritative' );
			$property->setValue( $instance, $flag );
		}
		$instance->theme_name = $theme_name;
		return $instance;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function registeredCallbacks( StarterBase $instance ): array {
		$callbacks = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, ...$rest ) use ( &$callbacks ) {
			$callbacks[ $hook ] = $callback;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$this->invokeRegisterMiscHooks( $instance );

		return $callbacks;
	}

	public function test_st_settings_filters_registered_by_default(): void {
		$instance  = $this->bareInstance();
		$callbacks = $this->registeredCallbacks( $instance );

		$this->assertSame( [ $instance, 'wpml_exclude_theme_domain_from_st' ], $callbacks['option_icl_st_settings'] );
		$this->assertSame( [ $instance, 'wpml_exclude_theme_domain_from_st_default' ], $callbacks['default_option_icl_st_settings'] );
		$this->assertSame( [ $instance, 'wpml_keep_theme_domain_exclusion_runtime_only' ], $callbacks['pre_update_option_icl_st_settings'] );
	}

	public function test_sitepress_settings_are_no_longer_filtered(): void {
		$callbacks = $this->registeredCallbacks( $this->bareInstance() );

		$this->assertArrayNotHasKey( 'option_icl_sitepress_settings', $callbacks );
	}

	public function test_no_filter_registered_when_flag_opted_out(): void {
		$callbacks = $this->registeredCallbacks( $this->bareInstance( false ) );

		$this->assertArrayNotHasKey( 'option_icl_st_settings', $callbacks );
		$this->assertArrayNotHasKey( 'default_option_icl_st_settings', $callbacks );
		$this->assertArrayNotHasKey( 'pre_update_option_icl_st_settings', $callbacks );
	}

	public function test_adds_theme_domain_to_empty_settings(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( [] );

		$this->assertSame( [ 'my-theme' ], $result[ self::KEY ] );
	}

	public function test_preserves_existing_excluded_domains_and_appends_theme_domain(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( [ self::KEY => [ 'some-plugin' ] ] );

		$this->assertSame( [ 'some-plugin', 'my-theme' ], $result[ self::KEY ] );
	}

	public function test_does_not_duplicate_theme_domain_when_already_excluded(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( [ self::KEY => [ 'my-theme' ] ] );

		$this->assertSame( [ 'my-theme' ], $result[ self::KEY ] );
	}

	public function test_preserves_other_settings_keys(): void {
		$settings = [
			'strings_language' => 'cs',
			'pb_shortcode'     => [ 'x' ],
		];

		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( $settings );

		$this->assertSame( 'cs', $result['strings_language'] );
		$this->assertSame( [ 'x' ], $result['pb_shortcode'] );
	}

	public function test_non_array_stored_value_returned_unchanged(): void {
		$this->assertSame( 'not-an-array', $this->bareInstance( true )->wpml_exclude_theme_domain_from_st( 'not-an-array' ) );
	}

	public function test_excluded_domains_not_an_array_is_treated_as_empty(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( [ self::KEY => 'not-an-array' ] );

		$this->assertSame( [ 'my-theme' ], $result[ self::KEY ] );
	}

	public function test_empty_theme_name_does_not_inject_empty_string(): void {
		$result = $this->bareInstance( true, '' )->wpml_exclude_theme_domain_from_st( [ self::KEY => [ 'some-plugin' ] ] );

		$this->assertSame( [ 'some-plugin' ], $result[ self::KEY ] );
	}

	/**
	 * `icl_st_settings` does not exist until String Translation first saves a
	 * setting. get_option() then answers from the default filter, and the
	 * option_ filter never runs.
	 */
	public function test_missing_option_default_carries_theme_domain(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st_default( false );

		$this->assertSame( [ self::KEY => [ 'my-theme' ] ], $result );
	}

	public function test_missing_option_default_with_empty_theme_name_stays_untouched(): void {
		$this->assertFalse( $this->bareInstance( true, '' )->wpml_exclude_theme_domain_from_st_default( false ) );
	}

	/**
	 * String Translation writes back the whole array it read, so the injected
	 * domain would be persisted on its next save and survive the flag being
	 * switched off.
	 */
	public function test_save_strips_domain_that_was_only_injected(): void {
		$instance = $this->bareInstance( true, 'my-theme' );
		$read     = $instance->wpml_exclude_theme_domain_from_st( [ self::KEY => [ 'some-plugin' ] ] );

		$saved = $instance->wpml_keep_theme_domain_exclusion_runtime_only( $read );

		$this->assertSame( [ 'some-plugin' ], $saved[ self::KEY ] );
	}

	public function test_save_strips_domain_injected_through_missing_option_default(): void {
		$instance = $this->bareInstance( true, 'my-theme' );
		$read     = $instance->wpml_exclude_theme_domain_from_st_default( false );

		$saved = $instance->wpml_keep_theme_domain_exclusion_runtime_only( $read );

		$this->assertSame( [], $saved[ self::KEY ] );
	}

	public function test_save_keeps_domain_that_was_already_stored(): void {
		$instance = $this->bareInstance( true, 'my-theme' );
		$read     = $instance->wpml_exclude_theme_domain_from_st( [ self::KEY => [ 'my-theme', 'some-plugin' ] ] );

		$saved = $instance->wpml_keep_theme_domain_exclusion_runtime_only( $read );

		$this->assertSame( [ 'my-theme', 'some-plugin' ], $saved[ self::KEY ] );
	}

	/**
	 * While the flag is on it owns the theme domain's entry: a save that adds
	 * the domain for the first time is stripped as well, so turning the flag
	 * off restores the list as it was before. ST's own screen echoes the
	 * injected entry back on save, so a first-time add cannot be told apart
	 * from it.
	 */
	public function test_save_strips_first_time_add_of_theme_domain(): void {
		$instance = $this->bareInstance( true, 'my-theme' );
		$instance->wpml_exclude_theme_domain_from_st( [ self::KEY => [] ] );

		$saved = $instance->wpml_keep_theme_domain_exclusion_runtime_only( [ self::KEY => [ 'some-plugin', 'my-theme' ] ] );

		$this->assertSame( [ 'some-plugin' ], $saved[ self::KEY ] );
	}

	public function test_save_uses_the_latest_read(): void {
		$instance = $this->bareInstance( true, 'my-theme' );
		$instance->wpml_exclude_theme_domain_from_st( [ self::KEY => [ 'my-theme' ] ] );
		$read = $instance->wpml_exclude_theme_domain_from_st( [ self::KEY => [] ] );

		$saved = $instance->wpml_keep_theme_domain_exclusion_runtime_only( $read );

		$this->assertSame( [], $saved[ self::KEY ] );
	}

	/**
	 * WPML's Divi integration reads the option with `[]` as its default.
	 */
	public function test_missing_option_array_default_carries_theme_domain(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st_default( [] );

		$this->assertSame( [ self::KEY => [ 'my-theme' ] ], $result );
	}

	public function test_save_passes_non_array_value_through(): void {
		$this->assertSame( 'x', $this->bareInstance( true )->wpml_keep_theme_domain_exclusion_runtime_only( 'x' ) );
	}

	private function wpmlDir(): string {
		return ( defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages' ) . '/wpml';
	}

	public function test_mo_guard_registered_before_wpml_override_by_default(): void {
		$registered = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$registered ) {
			$registered[ $hook ] = [ $callback, $priority, $args ];
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->invokeRegisterMiscHooks( $instance );

		// pre_load_textdomain runs before override_load_textdomain, where String
		// Translation loads its compiled file, and a non-null answer ends the load.
		$this->assertSame( [ [ $instance, 'wpml_keep_theme_mo_authoritative' ], 10, 3 ], $registered['pre_load_textdomain'] );
		$this->assertArrayNotHasKey( 'override_load_textdomain', $registered );
	}

	public function test_mo_guard_not_registered_when_flag_opted_out(): void {
		$callbacks = $this->registeredCallbacks( $this->bareInstance( false ) );

		$this->assertArrayNotHasKey( 'pre_load_textdomain', $callbacks );
	}

	/**
	 * WordPress 6.5+ answers a string from the first file loaded for the
	 * domain, and String Translation loads its compiled file before the
	 * theme's. Skipping that file keeps the theme's `.mo` authoritative.
	 */
	public function test_mo_guard_skips_wpml_compiled_file_for_theme_domain(): void {
		$result = $this->bareInstance( true, 'my-theme' )
			->wpml_keep_theme_mo_authoritative( null, 'my-theme', $this->wpmlDir() . '/my-theme-cs_CZ.mo' );

		$this->assertTrue( $result );
	}

	public function test_mo_guard_leaves_theme_own_file_alone(): void {
		$result = $this->bareInstance( true, 'my-theme' )
			->wpml_keep_theme_mo_authoritative( null, 'my-theme', '/srv/wp-content/themes/my-theme/static/translations/cs_CZ.mo' );

		$this->assertNull( $result );
	}

	public function test_mo_guard_leaves_other_domains_alone(): void {
		$result = $this->bareInstance( true, 'my-theme' )
			->wpml_keep_theme_mo_authoritative( null, 'some-plugin', $this->wpmlDir() . '/some-plugin-cs_CZ.mo' );

		$this->assertNull( $result );
	}

	public function test_mo_guard_respects_an_earlier_override(): void {
		$result = $this->bareInstance( true, 'my-theme' )
			->wpml_keep_theme_mo_authoritative( false, 'my-theme', $this->wpmlDir() . '/my-theme-cs_CZ.mo' );

		$this->assertFalse( $result );
	}

	public function test_mo_guard_does_nothing_without_theme_name(): void {
		$result = $this->bareInstance( true, '' )
			->wpml_keep_theme_mo_authoritative( null, '', $this->wpmlDir() . '/-cs_CZ.mo' );

		$this->assertNull( $result );
	}

	public function test_mo_guard_does_not_match_a_sibling_directory_prefix(): void {
		$result = $this->bareInstance( true, 'my-theme' )
			->wpml_keep_theme_mo_authoritative( null, 'my-theme', $this->wpmlDir() . '-backup/my-theme-cs_CZ.mo' );

		$this->assertNull( $result );
	}
}
