<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FormatFieldsTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		// fieldFormatter calls apply_filters at the end
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			return $args[0] ?? null;
		} );
	}

	public function test_with_post_object(): void {
		$post = (object) [ 'ID' => 42 ];

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 42 ) {
				return [
					'title' => [ 'type' => 'text', 'value' => 'Hello' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $post );

		$this->assertSame( 'Hello', $result['title'] );
	}

	public function test_with_term_object(): void {
		$term = (object) [ 'term_id' => 15 ];

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 15 ) {
				return [
					'color' => [ 'type' => 'color_picker', 'value' => '#ff0000' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $term );

		$this->assertSame( '#ff0000', $result['color'] );
	}

	public function test_with_numeric_id(): void {
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 99 ) {
				return [
					'name' => [ 'type' => 'text', 'value' => 'Test' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( 99 );

		$this->assertSame( 'Test', $result['name'] );
	}

	public function test_with_string_options_page(): void {
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'options' ) {
				return [
					'site_logo' => [ 'type' => 'text', 'value' => 'logo.svg' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( 'options' );

		$this->assertSame( 'logo.svg', $result['site_logo'] );
	}

	public function test_with_null_falls_back_to_queried_object(): void {
		Functions\when( 'get_queried_object_id' )->justReturn( 77 );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 77 ) {
				return [
					'title' => [ 'type' => 'text', 'value' => 'Queried' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( null );

		$this->assertSame( 'Queried', $result['title'] );
	}

	public function test_empty_fields_returns_empty_array(): void {
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 1 );

		$result = Helpers::formatFields( null );

		$this->assertSame( [], $result );
	}

	public function test_fields_formatted_through_field_formatter(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'embed' => [
				'type'  => 'oembed',
				'value' => '<iframe src="https://youtube.com/embed/abc"></iframe>',
			],
			'color' => [
				'type'  => 'color_picker',
				'value' => '#00ff00',
			],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		// oembed extracts iframe src
		$this->assertSame( 'https://youtube.com/embed/abc', $result['embed'] );
		// color_picker passes through
		$this->assertSame( '#00ff00', $result['color'] );
	}

	public function test_empty_field_value_excluded(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'title' => [ 'type' => 'text', 'value' => 'Present' ],
			'empty' => [ 'type' => 'text', 'value' => '' ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayNotHasKey( 'empty', $result );
	}

	public function test_with_string_option_singular(): void {
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'option' ) {
				return [
					'site_logo'  => [ 'type' => 'image', 'value' => [
						'ID'          => 10,
						'url'         => 'https://example.com/logo.svg',
						'mime_type'   => 'image/svg+xml',
						'width'       => 200,
						'height'      => 50,
						'alt'         => 'Logo',
						'caption'     => '',
						'description' => '',
					] ],
					'site_title' => [ 'type' => 'text', 'value' => 'My Site' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( 'option' );

		$this->assertArrayHasKey( 'site_logo', $result );
		$this->assertSame( 'My Site', $result['site_title'] );
	}

	public function test_is_preview_passed_to_field_formatter(): void {
		$this->define_wp_post_if_needed();

		Functions\when( 'do_shortcode' )->alias( function ( $shortcode ) {
			return '<form>' . $shortcode . '</form>';
		} );

		Functions\when( 'get_field_objects' )->justReturn( [
			'form' => [
				'type'  => 'post_object',
				'value' => new \WP_Post( (object) [
					'ID'        => 50,
					'post_type' => 'wpcf7_contact_form',
				] ),
			],
			'title' => [ 'type' => 'text', 'value' => 'Contact' ],
		] );

		// Preview mode: CF7 should return raw shortcode, not do_shortcode()
		$result = Helpers::formatFields( (object) [ 'ID' => 1 ], true );

		$this->assertSame( 'Contact', $result['title'] );
		$this->assertSame( '[contact-form-7 id="50" title=""]', $result['form'] );
	}

	public function test_is_preview_false_renders_shortcode(): void {
		$this->define_wp_post_if_needed();

		Functions\when( 'do_shortcode' )->alias( function ( $shortcode ) {
			return '<form>' . $shortcode . '</form>';
		} );

		Functions\when( 'get_field_objects' )->justReturn( [
			'form' => [
				'type'  => 'post_object',
				'value' => new \WP_Post( (object) [
					'ID'        => 50,
					'post_type' => 'wpcf7_contact_form',
				] ),
			],
		] );

		// Normal render: CF7 should call do_shortcode()
		$result = Helpers::formatFields( (object) [ 'ID' => 1 ], false );

		$this->assertStringContainsString( '<form>', $result['form'] );
		$this->assertStringContainsString( 'contact-form-7', $result['form'] );
	}

	private function define_wp_post_if_needed(): void {
		if ( ! class_exists( '\WP_Post' ) ) {
			eval( '
				class WP_Post {
					public $ID;
					public $post_type;
					public function __construct( $post ) {
						foreach ( get_object_vars( $post ) as $key => $value ) {
							$this->$key = $value;
						}
					}
				}
			' );
		}
	}

	public function test_block_prefix_uses_global_post(): void {
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( str_starts_with( (string) $id, 'block_' ) ) {
				return [
					'heading' => [
						'type'  => 'text',
						'value' => 'Block heading',
					],
				];
			}
			return false;
		} );

		// Simulate a block ID string being passed
		$result = Helpers::formatFields( 'block_abc123' );

		$this->assertSame( 'Block heading', $result['heading'] );
	}
}
