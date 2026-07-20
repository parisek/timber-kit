<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Covers the `$wpml_theme_domain_authoritative` flag (default on): hook wiring
 * inside registerMiscHooks() and the option_icl_sitepress_settings callback
 * that excludes the theme's text-domain from WPML String Translation.
 *
 * Why it matters: WPML ST scans the theme's compiled `.mo`, registers its
 * strings, and compiles its own overriding
 * `wp-content/languages/wpml/<domain>-<locale>.mo` — which WPML's
 * Just-In-Time MO loader serves instead of the theme's own `.mo` at runtime.
 * That silently desyncs the theme's `.po` source of truth. Excluding the
 * theme's domain from ST's `wpml_st_auto_reg_excluded_contexts` keeps the
 * theme `.po`/`.mo` as the single source.
 */
class WpmlThemeDomainAuthoritativeTest extends StarterBaseTestCase {

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

	public function test_filter_registered_by_default(): void {
		$callbacks = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, ...$rest ) use ( &$callbacks ) {
			$callbacks[ $hook ] = $callback;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->invokeRegisterMiscHooks( $instance );

		$this->assertArrayHasKey( 'option_icl_sitepress_settings', $callbacks );
		$this->assertSame( [ $instance, 'wpml_exclude_theme_domain_from_st' ], $callbacks['option_icl_sitepress_settings'] );
	}

	public function test_filter_not_registered_when_flag_opted_out(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$this->invokeRegisterMiscHooks( $this->bareInstance( false ) );

		$this->assertNotContains( 'option_icl_sitepress_settings', $filters );
	}

	public function test_adds_theme_domain_to_empty_settings(): void {
		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( [] );

		$this->assertSame( [ 'my-theme' ], $result['st']['wpml_st_auto_reg_excluded_contexts'] );
	}

	public function test_preserves_existing_excluded_contexts_and_appends_theme_domain(): void {
		$settings = [
			'st' => [
				'wpml_st_auto_reg_excluded_contexts' => [ 'some-plugin' ],
			],
		];

		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( $settings );

		$this->assertSame( [ 'some-plugin', 'my-theme' ], $result['st']['wpml_st_auto_reg_excluded_contexts'] );
	}

	public function test_does_not_duplicate_theme_domain_when_already_excluded(): void {
		$settings = [
			'st' => [
				'wpml_st_auto_reg_excluded_contexts' => [ 'my-theme' ],
			],
		];

		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( $settings );

		$this->assertSame( [ 'my-theme' ], $result['st']['wpml_st_auto_reg_excluded_contexts'] );
	}

	public function test_preserves_other_settings_keys(): void {
		$settings = [
			'st'          => [],
			'other_key'   => 'untouched',
			'setup_wizard' => true,
		];

		$result = $this->bareInstance( true, 'my-theme' )->wpml_exclude_theme_domain_from_st( $settings );

		$this->assertSame( 'untouched', $result['other_key'] );
		$this->assertTrue( $result['setup_wizard'] );
	}

	public function test_non_array_settings_returned_unchanged(): void {
		$this->assertSame( 'not-an-array', $this->bareInstance( true )->wpml_exclude_theme_domain_from_st( 'not-an-array' ) );
		$this->assertFalse( $this->bareInstance( true )->wpml_exclude_theme_domain_from_st( false ) );
	}
}
