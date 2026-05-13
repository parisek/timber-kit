<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class GetLanguageTest extends HelpersTestCase {

	public function test_falls_back_to_locale_when_wpml_is_absent(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value ) {
			return $value;
		} );
		Functions\when( 'get_locale' )->justReturn( 'cs_CZ' );

		$this->assertSame( 'cs', Helpers::getLanguage() );
	}

	public function test_uses_wpml_current_language_when_set(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value ) {
			if ( $filter === 'wpml_current_language' ) {
				return 'de';
			}
			return $value;
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$this->assertSame( 'de', Helpers::getLanguage() );
	}

	public function test_prefers_per_post_language_over_current(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value, $post_id = null ) {
			if ( $filter === 'wpml_post_language_details' && $post_id === 42 ) {
				return [ 'language_code' => 'sk' ];
			}
			if ( $filter === 'wpml_current_language' ) {
				return 'en';
			}
			return $value;
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$post = new \WP_Post( [ 'ID' => 42 ] );

		$this->assertSame( 'sk', Helpers::getLanguage( $post ) );
	}

	public function test_resolves_int_post_id_via_get_post(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value, $post_id = null ) {
			if ( $filter === 'wpml_post_language_details' && $post_id === 7 ) {
				return [ 'language_code' => 'pl' ];
			}
			return $value;
		} );
		Functions\when( 'get_post' )->alias( function ( $id ) {
			return $id === 7 ? new \WP_Post( [ 'ID' => 7 ] ) : null;
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$this->assertSame( 'pl', Helpers::getLanguage( 7 ) );
	}

	public function test_invalid_wpml_payload_falls_through(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value ) {
			if ( $filter === 'wpml_post_language_details' ) {
				return [ 'language_code' => '' ];
			}
			if ( $filter === 'wpml_current_language' ) {
				return null;
			}
			return $value;
		} );
		Functions\when( 'get_locale' )->justReturn( 'fr_FR' );

		$post = new \WP_Post( [ 'ID' => 1 ] );

		$this->assertSame( 'fr', Helpers::getLanguage( $post ) );
	}
}
