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
			return in_array( $id, [ 'option', 'options' ], true )
				? [ 'type' => 'option', 'id' => 'option' ]
				: [ 'type' => 'post', 'id' => $id ];
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

	public function test_options_singular_alias_matches_plural_post_id(): void {
		// Regression for the option/options alias bug discovered during the
		// neoli → timber-kit migration (see portadesign/neoli#17).
		//
		// `acf_add_options_page()` without an explicit `post_id` defaults to
		// `post_id => 'options'` (plural). The previous existing fixture for
		// this code path stubbed `acf_decode_post_id` to normalize BOTH input
		// forms to `id => 'option'` (singular) — which papered over a real-
		// world gap: in ACF Pro 6.x, `acf_decode_post_id` does NOT collapse
		// the alias. Concretely:
		//
		//   acf_decode_post_id('option')  => ['type' => 'option', 'id' => 'option']
		//   acf_decode_post_id('options') => ['type' => 'option', 'id' => 'options']
		//
		// Result: `formatFields('option')` and the default-`'options'` options
		// page have non-matching namespaces, `decodeOptionsNamespace` returns
		// two different strings, and `getFieldObjectsForOptions` falls through
		// without surfacing any field group.
		//
		// `decodeOptionsNamespace` must canonicalize the alias so both forms
		// resolve to the same namespace. This test stubs `acf_decode_post_id`
		// with the REAL ACF Pro 6.x semantics (no normalization) and asserts
		// that `formatFields('option')` still finds the registered page.

		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			// Mirror real ACF Pro 6.x: do NOT collapse the alias.
			return [ 'type' => 'option', 'id' => (string) $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			// Default `post_id` is `'options'` (plural) for an options page
			// registered without an explicit `post_id` argument.
			'settings' => [ 'menu_slug' => 'settings', 'post_id' => 'options' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			return ( $screen['options_page'] ?? '' ) === 'settings'
				? [ [ 'key' => 'group_options_footer', 'name' => 'footer_group' ] ]
				: [];
		} );
		Functions\when( 'acf_get_fields' )->alias( function ( $group ) {
			return [ [ 'key' => 'field_footer', 'name' => 'footer', 'type' => 'text' ] ];
		} );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => "$name@$id" );

		// Caller passes singular `'option'`; page registers default plural
		// `'options'`. The alias collapse in `decodeOptionsNamespace` must
		// make these resolve to the same namespace.
		$result = Helpers::formatFields( 'option' );

		$this->assertArrayHasKey( 'footer', $result, 'formatFields("option") must find the default-post_id="options" page' );
		$this->assertSame( 'footer@option', $result['footer'] );
	}

	public function test_options_wpml_prefixed_namespace_is_preserved(): void {
		// Regression guard against an overly aggressive alias collapse: the
		// `option`/`options` canonicalization in `decodeOptionsNamespace`
		// must NOT touch WPML's language-prefixed options ids like
		// `'options_en'`, `'options_cs'`, etc. WPML's ACF integration stores
		// per-language values under those prefixed namespaces, and a page
		// registered with `post_id => 'options_en'` must still surface only
		// when the caller asks for the same prefix.
		//
		// Behavior asserted here:
		//   * `formatFields('options_en')` matches a page with `post_id => 'options_en'`
		//   * `formatFields('options_en')` does NOT match a default-namespace
		//     page (`post_id => 'options'`)

		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			// Real ACF Pro 6.x: type=option, id=verbatim. No normalization.
			return [ 'type' => 'option', 'id' => (string) $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'general'    => [ 'menu_slug' => 'general',    'post_id' => 'options' ],
			'general_en' => [ 'menu_slug' => 'general-en', 'post_id' => 'options_en' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			$slug = $screen['options_page'] ?? '';
			if ( $slug === 'general' ) {
				return [ [ 'key' => 'group_default', 'name' => 'default_group' ] ];
			}
			if ( $slug === 'general-en' ) {
				return [ [ 'key' => 'group_en', 'name' => 'en_group' ] ];
			}
			return [];
		} );
		Functions\when( 'acf_get_fields' )->alias( function ( $group ) {
			$name = $group['key'] === 'group_en' ? 'site_logo_en' : 'site_logo';
			return [ [ 'key' => "field_{$group['key']}", 'name' => $name, 'type' => 'text' ] ];
		} );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => "$name@$id" );

		$result = Helpers::formatFields( 'options_en' );

		$this->assertArrayHasKey( 'site_logo_en', $result, 'WPML-prefixed call must surface the matching page' );
		$this->assertArrayNotHasKey( 'site_logo', $result, 'WPML-prefixed call must NOT leak the default-namespace page' );
		$this->assertSame( 'site_logo_en@options_en', $result['site_logo_en'] );
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

	public function test_term_object_with_term_id_does_not_dispatch_to_nav_menu_item_path(): void {
		// Regression guard. A term object whose `term_id` coincidentally
		// equals a `nav_menu_item` post id must NOT be routed through the
		// menu-item path — otherwise the term would silently load the wrong
		// field set in production.
		$term = (object) [ 'term_id' => 225 ];

		Functions\when( 'get_post_type' )->alias( function () {
			throw new \RuntimeException( 'get_post_type() must not be called for term objects' );
		} );
		Functions\when( 'wp_get_post_terms' )->alias( function () {
			throw new \RuntimeException( 'nav_menu_item path must not run for term objects' );
		} );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			return $id === 225
				? [ 'category_color' => [ 'type' => 'color_picker', 'value' => '#abc' ] ]
				: false;
		} );

		$result = Helpers::formatFields( $term );

		$this->assertSame( '#abc', $result['category_color'] );
	}

	public function test_wp_term_instance_with_coincidental_post_type_does_not_dispatch_to_nav_menu_item_path(): void {
		// Stronger regression guard than the plain-stdClass case above.
		// A real `WP_Term` instance that happens to carry a `post_type`
		// property (third-party term-meta shim, hydration glitch, etc.)
		// must still bail out before the menu-item path — the duck-typed
		// `isset($post->post_type)` check on its own would let it through
		// and pull the wrong field set.
		$term = new \WP_Term( [
			'term_id'   => 225,
			'taxonomy'  => 'category',
			'post_type' => 'nav_menu_item',
		] );

		Functions\when( 'get_post_type' )->alias( function () {
			throw new \RuntimeException( 'get_post_type() must not be called for WP_Term instances' );
		} );
		Functions\when( 'wp_get_post_terms' )->alias( function () {
			throw new \RuntimeException( 'nav_menu_item path must not run for WP_Term instances' );
		} );
		Functions\when( 'acf_get_field_groups' )->alias( function () {
			throw new \RuntimeException( 'menu-item resolver must not run for WP_Term instances' );
		} );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			return $id === 225
				? [ 'category_color' => [ 'type' => 'color_picker', 'value' => '#abc' ] ]
				: false;
		} );

		$result = Helpers::formatFields( $term );

		$this->assertSame( '#abc', $result['category_color'] );
	}

	public function test_nav_menu_item_orphaned_no_parent_menu_uses_zero_menu_id(): void {
		// Pin behavior: an orphaned menu item (no parent nav_menu term) still
		// resolves through the menu-item path with `nav_menu => 0`. Guards
		// against a future "early return on missing menu" refactor that
		// would silently break orphaned items.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias( fn ( $id ) => $id === 300 ? 'nav_menu_item' : 'post' );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$captured_screen = null;
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) use ( &$captured_screen ) {
			$captured_screen = $screen;
			return [ [ 'key' => 'g' ] ];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'badge', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->justReturn( 'BADGE' );

		$result = Helpers::formatFields( 300 );

		$this->assertSame( 'BADGE', $result['badge'] );
		$this->assertSame( [ 'nav_menu_item' => 300, 'nav_menu' => 0 ], $captured_screen );
	}

	public function test_nav_menu_item_wp_get_post_terms_wp_error_falls_back_to_zero_menu_id(): void {
		// `wp_get_post_terms()` can return WP_Error on DB/taxonomy failure.
		// The `is_array()` guard rejects it; the helper must continue with
		// `nav_menu => 0` rather than crash.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias( fn ( $id ) => 'nav_menu_item' );
		Functions\when( 'wp_get_post_terms' )->justReturn(
			(object) [ 'errors' => [ 'invalid_taxonomy' => [ 'Invalid taxonomy.' ] ] ]
		);

		$captured_screen = null;
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) use ( &$captured_screen ) {
			$captured_screen = $screen;
			return [ [ 'key' => 'g' ] ];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'label', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->justReturn( 'X' );

		$result = Helpers::formatFields( 42 );

		$this->assertSame( 'X', $result['label'] );
		$this->assertSame( 0, $captured_screen['nav_menu'] );
	}

	public function test_nav_menu_item_skips_groups_where_acf_get_fields_returns_non_array(): void {
		// If `acf_get_fields()` returns false for one group (corrupt or
		// incomplete registration), the helper must skip it and still
		// surface fields from healthy sibling groups.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias( fn ( $id ) => 'nav_menu_item' );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ (object) [ 'term_id' => 1 ] ] );
		Functions\when( 'acf_get_field_groups' )->justReturn( [
			[ 'key' => 'broken' ],
			[ 'key' => 'healthy' ],
		] );
		Functions\when( 'acf_get_fields' )->alias( function ( $group ) {
			return $group['key'] === 'broken'
				? false
				: [ [ 'key' => 'f', 'name' => 'ok', 'type' => 'text' ] ];
		} );
		Functions\when( 'get_field' )->justReturn( 'value' );

		$result = Helpers::formatFields( 7 );

		$this->assertSame( 'value', $result['ok'] );
	}

	public function test_options_acf_get_field_groups_returning_non_array_does_not_crash(): void {
		// Regression guard for the fatal TypeError that would occur on
		// PHP 8 if `acf_get_field_groups()` returned non-array for an
		// options-page screen and the foreach were unguarded.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'option' ] );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'a' => [ 'menu_slug' => 'page-a' ],
		] );
		Functions\when( 'acf_get_field_groups' )->justReturn( false );
		Functions\when( 'acf_get_fields' )->justReturn( [] );
		Functions\when( 'get_field' )->justReturn( null );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( [], $result );
	}

	public function test_options_page_missing_menu_slug_is_skipped_other_pages_still_resolve(): void {
		// Pages without `menu_slug` are silently skipped; valid sibling
		// pages must still resolve. Pins the `$page['menu_slug'] ?? null`
		// guard's behavior.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'option' ] );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'broken' => [],
			'valid'  => [ 'menu_slug' => 'real-page' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			return ( $screen['options_page'] ?? '' ) === 'real-page'
				? [ [ 'key' => 'g' ] ]
				: [];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'item', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->justReturn( 'VALUE' );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( 'VALUE', $result['item'] );
	}

	public function test_user_post_id_string_falls_through_to_default_get_field_objects(): void {
		// Negative case: ACF's `user_N` / `taxonomy_N` post-id strings must
		// not be routed through the options helper. `acf_decode_post_id()`
		// returns type=user/taxonomy, so `isOptionsPostId()` returns false
		// and `get_field_objects()` runs unchanged.
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			return $id === 'user_42'
				? [ 'type' => 'user', 'id' => 42 ]
				: [ 'type' => 'post', 'id' => $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->alias( function () {
			throw new \RuntimeException( 'user_* strings must not enter the options-resolution path' );
		} );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			return $id === 'user_42'
				? [ 'bio' => [ 'type' => 'text', 'value' => 'About me' ] ]
				: false;
		} );

		$result = Helpers::formatFields( 'user_42' );

		$this->assertSame( 'About me', $result['bio'] );
	}

	public function test_options_custom_post_id_namespace_routes_through_helper(): void {
		// Custom-namespace options page (`acf_add_options_page(['post_id' =>
		// 'company_settings'])`) is correctly detected by `acf_decode_post_id`
		// returning type=option and routed through the explicit-screen
		// helper. `get_field()` must receive the caller's post id, not the
		// default `'option'`.
		Functions\when( 'get_field_objects' )->alias( function () {
			throw new \RuntimeException( 'custom post_id namespace must route through helper' );
		} );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			return $id === 'company_settings'
				? [ 'type' => 'option', 'id' => 'company_settings' ]
				: [ 'type' => 'post', 'id' => $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'company' => [ 'menu_slug' => 'company-settings', 'post_id' => 'company_settings' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			return ( $screen['options_page'] ?? '' ) === 'company-settings'
				? [ [ 'key' => 'g' ] ]
				: [];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'tax_id', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( function ( $name, $id ) {
			return $id === 'company_settings' ? 'CZ12345678' : null;
		} );

		$result = Helpers::formatFields( 'company_settings' );

		$this->assertSame( 'CZ12345678', $result['tax_id'] );
	}

	public function test_options_pages_duplicate_field_name_last_writer_wins(): void {
		// Pin the documented last-writer-wins collision behavior for fields
		// that share a `name` across multiple options pages in the same
		// namespace.
		$call_count = 0;
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'option', 'id' => 'option' ] );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'a' => [ 'menu_slug' => 'page-a' ],
			'b' => [ 'menu_slug' => 'page-b' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			$slug = $screen['options_page'] ?? '';
			return [ [ 'key' => "g-$slug" ] ];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'logo', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( function () use ( &$call_count ) {
			$call_count++;
			return $call_count === 1 ? 'logo-from-page-a' : 'logo-from-page-b';
		} );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( 'logo-from-page-b', $result['logo'] );
	}

	public function test_options_namespace_filter_isolates_custom_pages_from_default_caller(): void {
		// formatFields('option') must NOT surface fields from a page
		// registered under a custom post_id namespace
		// (`acf_add_options_page(['post_id' => 'company_settings'])`). Those
		// fields live under 'company_settings', not under 'option'.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			if ( in_array( $id, [ 'option', 'options' ], true ) ) {
				return [ 'type' => 'option', 'id' => 'option' ];
			}
			if ( $id === 'company_settings' ) {
				return [ 'type' => 'option', 'id' => 'company_settings' ];
			}
			return [ 'type' => 'post', 'id' => $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'theme'   => [ 'menu_slug' => 'theme-options' ],
			'company' => [ 'menu_slug' => 'company-settings', 'post_id' => 'company_settings' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			$slug = $screen['options_page'] ?? '';
			if ( $slug === 'theme-options' ) {
				return [ [ 'key' => 'g_theme' ] ];
			}
			if ( $slug === 'company-settings' ) {
				throw new \RuntimeException( 'custom-namespace page must be skipped for default caller' );
			}
			return [];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'site_logo', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => "$name@$id" );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( [ 'site_logo' => 'site_logo@option' ], $result );
		$this->assertArrayNotHasKey( 'tax_id', $result );
	}

	public function test_options_namespace_filter_isolates_default_pages_from_custom_caller(): void {
		// formatFields('company_settings') must NOT pick up fields from
		// pages registered under the default 'option' namespace.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			if ( in_array( $id, [ 'option', 'options' ], true ) ) {
				return [ 'type' => 'option', 'id' => 'option' ];
			}
			if ( $id === 'company_settings' ) {
				return [ 'type' => 'option', 'id' => 'company_settings' ];
			}
			return [ 'type' => 'post', 'id' => $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'theme'   => [ 'menu_slug' => 'theme-options' ],
			'company' => [ 'menu_slug' => 'company-settings', 'post_id' => 'company_settings' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			$slug = $screen['options_page'] ?? '';
			if ( $slug === 'company-settings' ) {
				return [ [ 'key' => 'g_company' ] ];
			}
			if ( $slug === 'theme-options' ) {
				throw new \RuntimeException( 'default-namespace page must be skipped for custom caller' );
			}
			return [];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'tax_id', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => $id === 'company_settings' ? 'CZ12345678' : null );

		$result = Helpers::formatFields( 'company_settings' );

		$this->assertSame( [ 'tax_id' => 'CZ12345678' ], $result );
		$this->assertArrayNotHasKey( 'site_logo', $result );
	}

	public function test_options_namespace_filter_aliases_option_and_options(): void {
		// A page registered with the plural form `post_id => 'options'` must
		// still match a `formatFields('option')` caller. The aliasing happens
		// inside `decodeOptionsNamespace()` — ACF Pro's own `acf_decode_post_id()`
		// does NOT collapse the alias on its own (see
		// `test_options_singular_alias_matches_plural_post_id` for a stub that
		// mirrors real ACF Pro 6.x behavior). This older fixture stubs
		// `acf_decode_post_id` with a loose normalization that pre-dates the
		// understanding of ACF's real behavior; the assertion still holds
		// because both code paths produce the same answer.
		Functions\when( 'get_field_objects' )->justReturn( false );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			return in_array( $id, [ 'option', 'options' ], true )
				? [ 'type' => 'option', 'id' => 'option' ]
				: [ 'type' => 'post', 'id' => $id ];
		} );
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			'plural' => [ 'menu_slug' => 'general', 'post_id' => 'options' ],
		] );
		Functions\when( 'acf_get_field_groups' )->alias( function ( $screen ) {
			return ( $screen['options_page'] ?? '' ) === 'general'
				? [ [ 'key' => 'g' ] ]
				: [];
		} );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'f', 'name' => 'site_title', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( fn ( $name, $id ) => "$name@$id" );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( 'site_title@option', $result['site_title'] );
	}
}
