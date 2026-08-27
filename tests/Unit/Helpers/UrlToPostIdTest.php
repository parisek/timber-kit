<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Helpers;

/**
 * The second lookup is the whole point of this helper.
 */
final class UrlToPostIdTest extends TestCase {

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
		// extract_slug_from_url() asks whether WPML is active before it strips
		// a prefix; without this the fallback path cannot even be reached.
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) {
				if ( 'wpml_active_languages' === $hook ) {
					return array( 'en' => array(), 'cs' => array() );
				}
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_plain_hit_is_returned(): void {
		Functions\when( 'url_to_postid' )->justReturn( 7 );

		$this->assertSame( 7, Helpers::urlToPostId( 'https://example.test/about/' ) );
	}

	public function test_a_prefixed_url_is_retried_without_its_language(): void {
		// This is the case url_to_postid() gets wrong on a WPML site: the
		// rewrite rules it consults belong to the default language, so a valid
		// /cs/ URL answers 0 on the first ask and the entry silently vanishes.
		$calls = 0;
		Functions\when( 'url_to_postid' )->alias(
			static function ( string $url ) use ( &$calls ): int {
				++$calls;
				return 1 === $calls ? 0 : 11;
			}
		);

		$this->assertSame( 11, Helpers::urlToPostId( 'https://example.test/cs/o-nas/' ) );
		$this->assertSame( 2, $calls, 'the fallback lookup must actually happen' );
	}

	public function test_nothing_found_is_zero_not_a_guess(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$this->assertSame( 0, Helpers::urlToPostId( 'https://example.test/nope/' ) );
	}

	public function test_an_empty_url_short_circuits(): void {
		Functions\when( 'url_to_postid' )->justReturn( 99 );

		$this->assertSame( 0, Helpers::urlToPostId( '   ' ) );
	}

	public function test_the_translation_targets_the_language_the_url_names(): void {
		// The defect this pins: with two arguments, wpml_object_id translates
		// towards the CURRENT language. On a request that has none of its own
		// -- cron, WP-CLI -- that is the site default, so an Italian URL whose
		// lookup already succeeded came back as its English sibling. Measured
		// on a live five-language site: url_to_postid() resolved the Italian
		// page correctly and the translation then replaced it.
		Functions\when( 'url_to_postid' )->justReturn( 5 );

		$asked = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) use ( &$asked ) {
				if ( 'wpml_active_languages' === $hook ) {
					return array( 'en' => array(), 'it' => array() );
				}
				if ( 'wpml_default_language' === $hook ) {
					return 'en';
				}
				if ( 'wpml_object_id' === $hook ) {
					$asked = $rest[2] ?? null;
					return 62;
				}
				return $value;
			}
		);

		$this->assertSame( 62, Helpers::urlToPostId( 'https://example.test/it/glossario/' ) );
		$this->assertSame( 'it', $asked, 'the target language must come from the URL, not the request' );
	}

	public function test_an_unprefixed_url_targets_the_default_language(): void {
		Functions\when( 'url_to_postid' )->justReturn( 5 );

		$asked = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) use ( &$asked ) {
				if ( 'wpml_active_languages' === $hook ) {
					return array( 'en' => array(), 'it' => array() );
				}
				if ( 'wpml_default_language' === $hook ) {
					return 'en';
				}
				if ( 'wpml_object_id' === $hook ) {
					$asked = $rest[2] ?? null;
					return 5;
				}
				return $value;
			}
		);

		Helpers::urlToPostId( 'https://example.test/about/' );

		$this->assertSame( 'en', $asked );
	}

	public function test_the_id_is_translated_for_the_rendered_language(): void {
		Functions\when( 'url_to_postid' )->justReturn( 5 );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) {
				return 'wpml_object_id' === $hook ? 55 : $value;
			}
		);

		$this->assertSame( 55, Helpers::urlToPostId( 'https://example.test/about/' ) );
	}
}
