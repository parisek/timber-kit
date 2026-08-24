<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmup;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmup\SignalCollector;

/**
 * Covers the WordPress-facing signal collection.
 *
 * Everything here runs in the deferred refresh job, never in the purge
 * request, which is why database reads are acceptable at all.
 */
class SignalCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test' . $path
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_menu_keys_are_canonical(): void {
		Functions\when( 'wp_get_nav_menus' )->justReturn( array( (object) array( 'term_id' => 7 ) ) );
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			array(
				(object) array( 'url' => 'https://example.test/kontakt' ),
				(object) array( 'url' => 'https://example.test/o-nas/#tym' ),
			)
		);

		$keys = SignalCollector::menuKeys();

		$this->assertArrayHasKey( 'https://example.test/kontakt/', $keys );
		$this->assertArrayHasKey( 'https://example.test/o-nas/', $keys );
	}

	public function test_menu_items_without_a_url_are_skipped(): void {
		Functions\when( 'wp_get_nav_menus' )->justReturn( array( (object) array( 'term_id' => 7 ) ) );
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			array(
				(object) array( 'url' => '' ),
				(object) array( 'url' => '#' ),
			)
		);

		$this->assertSame( array(), SignalCollector::menuKeys() );
	}

	public function test_no_menus_yields_no_keys(): void {
		Functions\when( 'wp_get_nav_menus' )->justReturn( array() );

		$this->assertSame( array(), SignalCollector::menuKeys() );
	}

	public function test_front_pages_without_wpml_is_just_home(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);

		$pages = SignalCollector::frontPages();

		$this->assertSame( array( 'https://example.test/' => '' ), $pages );
	}

	public function test_front_pages_covers_every_active_language(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'wpml_active_languages' === $hook ) {
					return array(
						'cs' => array( 'language_code' => 'cs', 'url' => 'https://example.test/' ),
						'sk' => array( 'language_code' => 'sk', 'url' => 'https://example.test/sk/' ),
					);
				}

				return $value;
			}
		);

		$pages = SignalCollector::frontPages();

		$this->assertSame(
			array(
				'https://example.test/'    => 'cs',
				'https://example.test/sk/' => 'sk',
			),
			$pages
		);
	}

	public function test_front_pages_drops_foreign_hosts(): void {
		// WPML domain-per-language mode. Breeze would reject these in
		// preload_url() anyway, so dropping them here is the honest result,
		// not an error worth logging.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'wpml_active_languages' === $hook ) {
					return array(
						'cs' => array( 'language_code' => 'cs', 'url' => 'https://example.test/' ),
						'sk' => array( 'language_code' => 'sk', 'url' => 'https://example.sk/' ),
					);
				}

				return $value;
			}
		);

		$this->assertSame( array( 'https://example.test/' => 'cs' ), SignalCollector::frontPages() );
	}

	// breeze_get_option() is mocked here via Functions\when(), which — unlike
	// Functions\expect() — patches the function definition for the rest of
	// the process. StarterBase::setupBreezeWarmupSitemap() branches on
	// function_exists('breeze_get_option') to detect Breeze's absence, so
	// leaking this mock would falsely make Breeze look installed in later,
	// unrelated tests. Run in a separate process to keep the leak contained.
	#[RunInSeparateProcess]
	public function test_manual_keys_come_from_breeze_settings(): void {
		Functions\when( 'breeze_get_option' )->justReturn(
			array( 'breeze-preload-cache-urls' => array( 'https://example.test/akce' ) )
		);

		$this->assertArrayHasKey( 'https://example.test/akce/', SignalCollector::manualKeys() );
	}

	#[RunInSeparateProcess]
	public function test_manual_keys_tolerate_missing_settings(): void {
		Functions\when( 'breeze_get_option' )->justReturn( false );

		$this->assertSame( array(), SignalCollector::manualKeys() );
	}
}
