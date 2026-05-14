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

	public function test_wysiwyg_tinymce_artifacts_excluded_automatically(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );
		Functions\when( 'get_field_objects' )->justReturn( [
			'perex' => [
				'type'  => 'wysiwyg',
				'value' => '<p><span data-mce-type="bookmark">&#xfeff;</span><br data-mce-bogus="1"></p>',
			],
			'title' => [ 'type' => 'text', 'value' => 'Present' ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayNotHasKey( 'perex', $result );
	}

	public function test_textarea_artifact_like_content_is_not_excluded_automatically(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );
		Functions\when( 'get_field_objects' )->justReturn( [
			'code_sample' => [
				'type'  => 'textarea',
				'value' => '<span data-mce-type="bookmark">&#xfeff;</span><br data-mce-bogus="1">',
			],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayHasKey( 'code_sample', $result );
		$this->assertSame( '<span data-mce-type="bookmark">&#xfeff;</span><br data-mce-bogus="1">', $result['code_sample'] );
	}

	public function test_textarea_with_only_breaks_and_spaces_is_excluded(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );
		Functions\when( 'get_field_objects' )->justReturn( [
			'notes' => [
				'type'  => 'textarea',
				'value' => "<p>\n&nbsp;<br></p>",
			],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayNotHasKey( 'notes', $result );
	}

	public function test_wysiwyg_shortcode_markup_output_is_not_excluded(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return str_replace( '[image-only]', '<img src="https://example.com/a.jpg" alt="">', $content );
		} );
		Functions\when( 'get_field_objects' )->justReturn( [
			'gallery' => [
				'type'  => 'wysiwyg',
				'value' => '[image-only]',
			],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayHasKey( 'gallery', $result );
		$this->assertSame( '<img src="https://example.com/a.jpg" alt="">', $result['gallery'] );
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

	public function test_nav_menu_item_resolves_fields_via_explicit_screen(): void {
		// ACF's location matcher can't infer the nav_menu_item/nav_menu screen
		// from a bare post id, so formatFields builds it explicitly. This test
		// asserts that the explicit path produces field values which the
		// default `get_field_objects()` call would miss.

		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias( function ( $id ) {
			return $id === 225 ? 'nav_menu_item' : 'post';
		} );
		Functions\when( 'wp_get_post_terms' )->alias( function ( $post_id, $taxonomy ) {
			if ( $taxonomy === 'nav_menu' && $post_id === 225 ) {
				return [ (object) [ 'term_id' => 2 ] ];
			}
			return [];
		} );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			if ( isset( $screen['nav_menu_item'] ) && (int) $screen['nav_menu_item'] === 225 ) {
				return [ [ 'key' => 'group_nav_menu_item_featured', 'title' => 'Featured Card' ] ];
			}
			return [];
		} );
		Functions\when( 'acf_get_fields' )->alias( function ( $group ) {
			if ( ( $group['key'] ?? '' ) === 'group_nav_menu_item_featured' ) {
				return [
					[ 'key' => 'field_featured_title', 'name' => 'featured_title', 'type' => 'text' ],
					[ 'key' => 'field_featured_url',   'name' => 'featured_url',   'type' => 'text' ],
				];
			}
			return [];
		} );
		Functions\when( 'get_field' )->alias( function ( $name, $id ) {
			if ( $id === 225 && $name === 'featured_title' ) return 'PMax report';
			if ( $id === 225 && $name === 'featured_url' )   return '#pmax';
			return null;
		} );

		$result = Helpers::formatFields( 225 );

		$this->assertSame( 'PMax report', $result['featured_title'] );
		$this->assertSame( '#pmax', $result['featured_url'] );
	}

	public function test_nav_menu_item_returns_empty_when_no_matching_group(): void {
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias( fn ( $id ) => 'nav_menu_item' );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ (object) [ 'term_id' => 2 ] ] );
		Functions\when( 'acf_get_field_groups' )->justReturn( [] );
		Functions\when( 'acf_get_fields' )->justReturn( [] );
		Functions\when( 'get_field' )->justReturn( null );

		$result = Helpers::formatFields( 999 );

		$this->assertSame( [], $result );
	}

	public function test_options_post_id_resolves_via_acf_get_options_pages(): void {
		// Same shape as nav_menu_item gap — `get_field_objects('option')`
		// silently drops some matching options groups. The explicit
		// options_page path picks them all up.

		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			return $id === 'option' ? [ 'type' => 'option', 'id' => 'option' ] : [ 'type' => 'post', 'id' => $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'settings' => [ 'menu_slug' => 'settings' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			return ( $screen['options_page'] ?? '' ) === 'settings'
				? [
					[ 'key' => 'group_options_footer', 'name' => 'footer_group' ],
					[ 'key' => 'group_options_header', 'name' => 'header_group' ],
				]
				: [];
		} );
		Functions\when( 'acf_get_fields' )->alias( function ( $group ) {
			return [ [ 'key' => "field_{$group['key']}", 'name' => str_replace( 'group_options_', '', $group['key'] ), 'type' => 'text' ] ];
		} );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => "$name@$id" );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( 'footer@option', $result['footer'] );
		$this->assertSame( 'header@option', $result['header'] );
	}

	public function test_options_post_id_returns_empty_when_no_pages_registered(): void {
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'option' ] );
		Functions\when( 'acf_get_options_pages' )->justReturn( [] );
		Functions\when( 'acf_get_field_groups' )->justReturn( [] );
		Functions\when( 'acf_get_fields' )->justReturn( [] );
		Functions\when( 'get_field' )->justReturn( null );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( [], $result );
	}

	public function test_block_prefix_string_does_not_hit_options_path(): void {
		// `'block_*'` strings must not be misclassified as options page ids.
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'block', 'id' => 'block_abc' ] );
		Functions\when( 'acf_get_options_pages' )->alias( function () {
			throw new \RuntimeException( 'block_* must skip options resolution entirely' );
		} );
		Functions\when( 'get_field_objects' )->alias( fn ( $id ) => $id === 'block_abc'
			? [ 'foo' => [ 'type' => 'text', 'value' => 'bar' ] ]
			: false );

		$result = Helpers::formatFields( 'block_abc' );

		$this->assertSame( 'bar', $result['foo'] );
	}

	public function test_nav_menu_item_detects_via_post_type_property(): void {
		// When a Timber\MenuItem-shaped object is passed (with a post_type
		// property), we should not need get_post_type() at all.
		$item = (object) [ 'ID' => 42, 'post_type' => 'nav_menu_item' ];

		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias( function () {
			throw new \RuntimeException( 'get_post_type() should not be reached when the object carries post_type' );
		} );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ (object) [ 'term_id' => 7 ] ] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			return ( ( $screen['nav_menu_item'] ?? 0 ) === 42 )
				? [ [ 'key' => 'g' ] ]
				: [];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'badge', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => "$name-of-$id" );

		$result = Helpers::formatFields( $item );

		$this->assertSame( 'badge-of-42', $result['badge'] );
	}
}
