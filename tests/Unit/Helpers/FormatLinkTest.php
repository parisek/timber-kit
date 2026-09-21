<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FormatLinkTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		// html_entity_decode and wp_kses are always called for title
		Functions\when( 'html_entity_decode' )->alias( 'html_entity_decode' );
		Functions\when( 'wp_kses' )->alias( function ( $string ) {
			return strip_tags( $string, '<strong><b><i><em><br>' );
		} );
	}

	public function test_non_array_returns_value(): void {
		$this->assertSame( 'string', Helpers::formatLink( 'string', 1, [] ) );
		$this->assertNull( Helpers::formatLink( null, 1, [] ) );
		$this->assertFalse( Helpers::formatLink( false, 1, [] ) );
	}

	public function test_target_blank_copies_to_attributes(): void {
		$value = [
			'title'  => 'External',
			'url'    => 'https://example.com',
			'target' => '_blank',
		];

		$result = Helpers::formatLink( $value, 1, [] );

		$this->assertSame( '_blank', $result['attributes']['target'] );
		// Returns early for _blank links (no WPML translation)
		$this->assertSame( 'https://example.com', $result['url'] );
	}

	public function test_empty_target_unsets_target(): void {
		$value = [
			'title'  => 'Internal',
			'url'    => 'https://example.com/page',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 0 ] );

		$this->assertArrayNotHasKey( 'target', $result );
	}

	public function test_title_html_entities_decoded(): void {
		$value = [
			'title'  => 'Hello &amp; <strong>World</strong>',
			'url'    => 'https://example.com',
			'target' => '_blank',
		];

		$result = Helpers::formatLink( $value, 1, [] );

		$this->assertSame( 'Hello & <strong>World</strong>', $result['title'] );
	}

	public function test_no_wpml_preferences_returns_early(): void {
		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/page',
			'target' => '',
		];

		// wpml_cf_preferences not set or != 2 -> returns early
		$result = Helpers::formatLink( $value, 1, [] );

		$this->assertSame( 'https://example.com/page', $result['url'] );
	}

	public function test_wpml_preferences_not_2_returns_early(): void {
		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/page',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 1 ] );

		$this->assertSame( 'https://example.com/page', $result['url'] );
	}

	public function test_wpml_translation_full_flow(): void {
		Functions\when( 'url_to_postid' )->justReturn( 10 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'wpml_object_id' ) {
				return 20; // translated post ID
			}
			return $args[0] ?? null;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cs/stranka' );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/page',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		$this->assertSame( 'https://example.com/cs/stranka', $result['url'] );
	}

	public function test_wpml_translation_preserves_query_and_fragment(): void {
		Functions\when( 'url_to_postid' )->justReturn( 10 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'wpml_object_id' ) {
				return 20;
			}
			return $args[0] ?? null;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cs/stranka' );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/page?foo=bar#section',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		$this->assertSame( 'https://example.com/cs/stranka?foo=bar#section', $result['url'] );
	}

	public function test_wpml_slug_fallback_when_url_to_postid_returns_zero(): void {
		// The path fallback is host-gated now, so it has to be able to learn
		// which hosts belong to this site.
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$callCount = 0;
		Functions\when( 'url_to_postid' )->alias( function () use ( &$callCount ) {
			$callCount++;
			// First call returns 0, second call (after slug extraction) returns 10
			return $callCount === 1 ? 0 : 10;
		} );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'wpml_object_id' ) {
				return 20;
			}
			return $args[0] ?? null;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cs/stranka' );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/cs/page',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		$this->assertSame( 'https://example.com/cs/stranka', $result['url'] );
		$this->assertSame( 2, $callCount );
	}

	public function test_wpml_url_to_postid_zero_both_times(): void {
		// The path fallback is host-gated now, so it has to be able to learn
		// which hosts belong to this site.
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/nonexistent',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		// URL unchanged when post not found
		$this->assertSame( 'https://example.com/nonexistent', $result['url'] );
	}

	public function test_repeated_url_resolves_once(): void {
		$calls = 0;
		Functions\when( 'url_to_postid' )->alias( function () use ( &$calls ) {
			$calls++;
			return 42;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cs/stranka/' );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/page/',
			'target' => '',
		];
		$field = [ 'wpml_cf_preferences' => 2 ];

		$first  = Helpers::formatLink( $value, 1, $field );
		$second = Helpers::formatLink( $value, 1, $field );

		$this->assertSame( 'https://example.com/cs/stranka/', $first['url'] );
		$this->assertSame( $first['url'], $second['url'] );
		$this->assertSame( 1, $calls, 'url_to_postid must run once for a repeated URL' );
	}

	public function test_unresolved_url_is_cached_too(): void {
		// A miss falls through to extract_slug_from_url(), which asks WPML.
		Functions\when( 'is_plugin_active' )->justReturn( false );
		// The slug fallback is gated on the URL being this site's, so the URL
		// below has to be one. Without home_url() the gate cannot answer and
		// the fallback never runs -- which would leave this asserting one
		// lookup instead of the two a miss actually costs.
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.com' . $path
		);
		$calls = 0;
		Functions\when( 'url_to_postid' )->alias( function () use ( &$calls ) {
			$calls++;
			return 0;
		} );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/gone/',
			'target' => '',
		];
		$field = [ 'wpml_cf_preferences' => 2 ];

		$first  = Helpers::formatLink( $value, 1, $field );
		$second = Helpers::formatLink( $value, 1, $field );

		// URL is left untouched, and the miss is not resolved a second time.
		$this->assertSame( 'https://example.com/gone/', $first['url'] );
		$this->assertSame( 'https://example.com/gone/', $second['url'] );
		// Two url_to_postid calls for ONE resolution: the direct one and the
		// slug fallback. The second formatLink() call adds none.
		$this->assertSame( 2, $calls );
	}

	public function test_same_url_in_two_languages_resolves_separately(): void {
		$lang = 'cs';
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) use ( &$lang ) {
			return 'wpml_current_language' === $hook ? $lang : $value;
		} );
		Functions\when( 'get_permalink' )->alias( function () use ( &$lang ) {
			return 'https://example.com/' . $lang . '/stranka/';
		} );

		$value = [
			'title'  => 'Link',
			'url'    => 'https://example.com/page/',
			'target' => '',
		];
		$field = [ 'wpml_cf_preferences' => 2 ];

		$cs = Helpers::formatLink( $value, 1, $field );
		$lang = 'en';
		$en = Helpers::formatLink( $value, 1, $field );

		$this->assertSame( 'https://example.com/cs/stranka/', $cs['url'] );
		$this->assertSame( 'https://example.com/en/stranka/', $en['url'] );
	}

	public function test_same_url_on_two_blogs_resolves_separately(): void {
		$blog = 1;
		Functions\when( 'get_current_blog_id' )->alias( function () use ( &$blog ) {
			return $blog;
		} );
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );
		Functions\when( 'get_permalink' )->alias( function () use ( &$blog ) {
			return 'https://blog' . $blog . '.example.com/stranka/';
		} );

		$value = [ 'title' => 'Link', 'url' => 'https://example.com/page/', 'target' => '' ];
		$field = [ 'wpml_cf_preferences' => 2 ];

		$first = Helpers::formatLink( $value, 1, $field );
		$blog  = 2;
		$second = Helpers::formatLink( $value, 1, $field );

		// switch_to_blog() changes the correct answer for an unchanged URL.
		$this->assertSame( 'https://blog1.example.com/stranka/', $first['url'] );
		$this->assertSame( 'https://blog2.example.com/stranka/', $second['url'] );
	}

	public function test_flush_lets_a_changed_permalink_through(): void {
		$permalink = 'https://example.com/cs/stary-slug/';
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );
		Functions\when( 'get_permalink' )->alias( function () use ( &$permalink ) {
			return $permalink;
		} );

		$value = [ 'title' => 'Link', 'url' => 'https://example.com/page/', 'target' => '' ];
		$field = [ 'wpml_cf_preferences' => 2 ];

		$before = Helpers::formatLink( $value, 1, $field );

		// A long-running process renames the slug. Without the flush the memo
		// would keep handing out the permalink from before the rename.
		$permalink = 'https://example.com/cs/novy-slug/';
		$this->assertSame( $before['url'], Helpers::formatLink( $value, 1, $field )['url'] );

		Helpers::flushTranslatedLinkUrls();

		$this->assertSame( 'https://example.com/cs/novy-slug/', Helpers::formatLink( $value, 1, $field )['url'] );
	}

	public function test_false_permalink_leaves_url_untouched(): void {
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );
		// get_permalink() returns false for an id it cannot resolve.
		Functions\when( 'get_permalink' )->justReturn( false );

		$value = [ 'title' => 'Link', 'url' => 'https://example.com/page/?a=1', 'target' => '' ];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		// Previously this concatenated the query onto false, yielding "?a=1".
		$this->assertSame( 'https://example.com/page/?a=1', $result['url'] );
	}
	public function test_route_beneath_a_post_is_left_alone(): void {
		// `/blog/page/2/` resolves to the blog page, and a permalink cannot
		// carry the pagination segment — the rewrite used to return `/blog/`
		// and delete the page number.
		Functions\when( 'url_to_postid' )->justReturn( 10 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cs/blog/' );

		$value = [
			'title'  => 'Older posts',
			'url'    => 'https://example.com/cs/blog/page/2/',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		$this->assertSame( 'https://example.com/cs/blog/page/2/', $result['url'] );
	}

	public function test_route_check_keeps_query_and_fragment_out_of_it(): void {
		// A query and a fragment are restored by resolveLinkUrl() and must not
		// make a plain permalink look like a route.
		Functions\when( 'url_to_postid' )->justReturn( 10 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/cs/blog/' );

		$value = [
			'title'  => 'Blog',
			'url'    => 'https://example.com/blog/?utm_source=x#top',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		$this->assertSame( 'https://example.com/cs/blog/?utm_source=x#top', $result['url'] );
	}

	public function test_a_link_to_the_front_page_is_not_a_route(): void {
		// The front page's path is empty and every path sits under it. Without
		// the guard this test's link would read as a route beneath it.
		Functions\when( 'url_to_postid' )->justReturn( 10 );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			return 'wpml_current_language' === $hook ? 'cs' : $value;
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/' );

		$value = [
			'title'  => 'Home',
			'url'    => 'https://example.com/',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		$this->assertSame( 'https://example.com/', $result['url'] );
	}

	public function test_route_check_reads_the_permalink_in_the_urls_own_language(): void {
		// The stored URL names Italian; the ambient language is Czech. The
		// check has to compare against the Italian path, or the two sides
		// describe different posts and every cross-language link reads as a
		// route.
		Functions\when( 'url_to_postid' )->justReturn( 10 );
		$language = 'cs';
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null, ...$rest ) use ( &$language ) {
			if ( 'wpml_current_language' === $hook ) {
				return $language;
			}
			if ( 'wpml_active_languages' === $hook ) {
				return [
					'en' => [ 'code' => 'en', 'url' => 'https://example.com/' ],
					'cs' => [ 'code' => 'cs', 'url' => 'https://example.com/cs/' ],
					'it' => [ 'code' => 'it', 'url' => 'https://example.com/it/' ],
				];
			}
			return $value;
		} );
		Functions\when( 'do_action' )->alias( function ( $hook, $to = null ) use ( &$language ) {
			if ( 'wpml_switch_language' === $hook && is_string( $to ) ) {
				$language = $to;
			}
		} );
		Functions\when( 'get_permalink' )->alias( function () use ( &$language ) {
			return 'it' === $language
				? 'https://example.com/it/contatto/'
				: 'https://example.com/cs/kontakt/';
		} );

		$value = [
			'title'  => 'Contact',
			'url'    => 'https://example.com/it/contatto/',
			'target' => '',
		];

		$result = Helpers::formatLink( $value, 1, [ 'wpml_cf_preferences' => 2 ] );

		// Not a route, so preference 2 keeps doing what it does: the link is
		// translated into the language being rendered.
		$this->assertSame( 'https://example.com/cs/kontakt/', $result['url'] );
		$this->assertSame( 'cs', $language, 'the language switch must be restored' );
	}
}
