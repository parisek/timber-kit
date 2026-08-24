<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\CuratedUrls;

/**
 * A curated entry is resolved, never trusted.
 */
final class CuratedUrlsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test' . $path
		);
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		// No WPML in these cases: the filter hands the id straight back.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null, ...$rest ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_relative_path_resolves_against_this_site(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$keys = CuratedUrls::keys( array( '/blog/' ) );

		$this->assertSame( array( 'https://example.test/blog/' ), array_keys( $keys ) );
	}

	public function test_a_post_comes_back_as_its_permalink_not_as_written(): void {
		// The written form and the canonical one differ on purpose: what gets
		// stored has to be what the site serves, not what someone typed.
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/cs/blog/' );

		$keys = CuratedUrls::keys( array( '/blog' ) );

		$this->assertSame( array( 'https://example.test/cs/blog/' ), array_keys( $keys ) );
	}

	public function test_an_entry_that_names_nothing_is_dropped(): void {
		// url_to_postid() finds nothing AND the host is foreign: there is no
		// reading of this entry under which it belongs to this site.
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$keys = CuratedUrls::keys( array( 'https://somewhere-else.test/blog/' ) );

		$this->assertSame( array(), $keys );
	}

	public function test_a_language_domain_is_not_foreign(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) {
				if ( 'wpml_active_languages' === $hook ) {
					return array( 'de' => array( 'url' => 'https://example.de/' ) );
				}
				return $value;
			}
		);

		$keys = CuratedUrls::keys( array( 'https://example.de/blog/' ) );

		$this->assertSame( array( 'https://example.de/blog/' ), array_keys( $keys ) );
	}

	public function test_a_scheme_relative_entry_is_refused_rather_than_guessed(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$this->assertSame( array(), CuratedUrls::keys( array( '//evil.test/blog/' ) ) );
	}

	public function test_blank_and_non_string_entries_are_skipped(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$keys = CuratedUrls::keys( array( '', '   ', 42, null, array( 'x' ), '/ok/' ) );

		$this->assertSame( array( 'https://example.test/ok/' ), array_keys( $keys ) );
	}

	public function test_a_dead_entry_is_dropped_by_the_reachability_probe(): void {
		Functions\when( 'wp_remote_head' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );

		$this->assertSame(
			array(),
			CuratedUrls::filterReachable( array( 'https://example.test/gone/' => true ) )
		);
	}

	public function test_a_redirect_counts_as_alive(): void {
		Functions\when( 'wp_remote_head' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 301 );

		$this->assertSame(
			array( 'https://example.test/moved/' => true ),
			CuratedUrls::filterReachable( array( 'https://example.test/moved/' => true ) )
		);
	}

	public function test_a_failed_probe_keeps_the_entry(): void {
		// Stale beats empty: one flaky lookup must not silently clear a list.
		Functions\when( 'wp_remote_head' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertSame(
			array( 'https://example.test/flaky/' => true ),
			CuratedUrls::filterReachable( array( 'https://example.test/flaky/' => true ) )
		);
	}
}
