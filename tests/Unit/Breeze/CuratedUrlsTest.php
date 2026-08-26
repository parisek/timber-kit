<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze;

use Brain\Monkey;
use Parisek\TimberKit\Helpers;
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
		// The post-id memo keys by blog, so every test that reaches
		// urlToPostId() needs this defined.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Helpers::flushResolvedPostIds();
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
		$this->assertTrue( $keys['https://example.test/blog/'], 'unresolved entries still owe a probe' );
	}

	public function test_a_post_needs_no_probe(): void {
		// The regression this guards: probing every key spends a request per
		// entry per refresh, and drops a page that answers 405 to HEAD.
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/blog/' );

		$keys = CuratedUrls::keys( array( '/blog/' ) );

		$this->assertFalse( $keys['https://example.test/blog/'] );
		$this->assertSame(
			array( 'https://example.test/blog/' => true ),
			CuratedUrls::filterReachable( $keys ),
			'a key that owes no probe survives filterReachable untouched'
		);
	}

	public function test_a_foreign_url_cannot_borrow_a_local_path(): void {
		// url_to_postid() misses on the full URL and would hit on the bare
		// path. Without the host gate in Helpers::urlToPostId() this entry
		// became this site's own /about/ page.
		$calls = 0;
		Functions\when( 'url_to_postid' )->alias(
			static function () use ( &$calls ): int {
				++$calls;
				return 1 === $calls ? 0 : 99;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/about/' );

		$this->assertSame( array(), CuratedUrls::keys( array( 'https://foreign.test/about/' ) ) );
	}

	public function test_a_port_is_part_of_the_host(): void {
		// example.test:8080 is a different service from example.test, and
		// treating them as one turns the probe into a request this site can be
		// made to send anywhere on that machine.
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$this->assertSame( array(), CuratedUrls::keys( array( 'https://example.test:8080/blog/' ) ) );
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

	public function test_the_list_is_filterable_from_outside_the_theme(): void {
		// The reason this filter exists: a project may keep its config in an
		// mu-plugin, and without it the curated list would be the one warmup
		// setting unreachable from there.
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) {
				return 'timberkit_warmup_curated_urls' === $hook ? array( '/added/' ) : $value;
			}
		);

		$this->assertSame(
			array( 'https://example.test/added/' ),
			array_keys( CuratedUrls::keys( array( '/original/' ) ) )
		);
	}

	public function test_the_cap_is_filterable_and_truncates(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) {
				return 'timberkit_warmup_curated_max_entries' === $hook ? 1 : $value;
			}
		);

		$keys = CuratedUrls::keys( array( '/one/', '/two/', '/three/' ) );

		$this->assertSame( array( 'https://example.test/one/' ), array_keys( $keys ) );
	}

	/**
	 * The probe judges existence and nothing else.
	 *
	 * A term archive behind a WAF that refuses HEAD, or one that answers 401 to
	 * a logged-out warmer, is a live page. Dropping it was the exact failure
	 * `resolve()`'s own comment claimed to avoid — that comment guarded the
	 * post-ID path and left this one open.
	 *
	 * @param int $code Status the probe sees.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'aliveCodes' )]
	public function test_only_a_gone_page_is_dropped( int $code ): void {
		Functions\when( 'wp_remote_head' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );

		$this->assertSame(
			array( 'https://example.test/x/' => true ),
			CuratedUrls::filterReachable( array( 'https://example.test/x/' => true ) ),
			"status {$code} says nothing about whether the page exists"
		);
	}

	/** @return array<string, array{int}> */
	public static function aliveCodes(): array {
		return array(
			'method not allowed' => array( 405 ),
			'unauthorised'       => array( 401 ),
			'forbidden'          => array( 403 ),
			'server error'       => array( 500 ),
			'redirect'           => array( 301 ),
			'ok'                 => array( 200 ),
		);
	}

	/** @param int $code Status the probe sees. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'goneCodes' )]
	public function test_a_gone_page_is_dropped( int $code ): void {
		Functions\when( 'wp_remote_head' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );

		$this->assertSame(
			array(),
			CuratedUrls::filterReachable( array( 'https://example.test/x/' => true ) )
		);
	}

	/** @return array<string, array{int}> */
	public static function goneCodes(): array {
		return array( 'not found' => array( 404 ), 'gone' => array( 410 ) );
	}
}
