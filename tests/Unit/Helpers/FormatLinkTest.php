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
}
