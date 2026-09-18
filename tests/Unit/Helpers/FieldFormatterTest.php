<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FieldFormatterTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		// fieldFormatter always calls apply_filters at the end
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			return $args[0] ?? null;
		} );
	}

	// --- Empty / edge cases ---

	public function test_empty_field_returns_false(): void {
		$this->assertFalse( Helpers::fieldFormatter( null ) );
		$this->assertFalse( Helpers::fieldFormatter( '' ) );
		$this->assertFalse( Helpers::fieldFormatter( [] ) );
		$this->assertFalse( Helpers::fieldFormatter( false ) );
	}

	public function test_field_without_type_or_value_returns_as_is(): void {
		$field = [ 'name' => 'test', 'something' => 'else' ];
		$this->assertSame( $field, Helpers::fieldFormatter( $field ) );
	}

	public function test_typed_field_without_value_returns_false(): void {
		// Options-page field object surfaced without a saved value (regression:
		// the raw ACF field-definition array leaked into templates and fatalled
		// on string filters like |typography).
		$field = [
			'type'   => 'text',
			'name'   => 'form_title',
			'_name'  => 'form_title',
			'_valid' => 1,
		];
		$this->assertFalse( Helpers::fieldFormatter( $field ) );
	}

	public function test_repeater_and_flexible_with_missing_value_key_return_false(): void {
		// The null pass-through contract is for an EXPLICIT value => null
		// (block previews); a field object with no value key at all is a
		// never-filled surfaced field and must read as empty for all types.
		$this->assertFalse( Helpers::fieldFormatter( [ 'type' => 'repeater', 'sub_fields' => [] ] ) );
		$this->assertFalse( Helpers::fieldFormatter( [ 'type' => 'flexible_content', 'layouts' => [] ] ) );
	}

	public function test_scalar_field_with_null_value_returns_false(): void {
		// Unfilled options-page fields surface with value => null; scalar and
		// group types must read as empty (only repeater/flexible keep the
		// null pass-through for block previews).
		$this->assertFalse( Helpers::fieldFormatter( [ 'type' => 'text', 'value' => null ] ) );
		$this->assertFalse( Helpers::fieldFormatter( [ 'type' => 'group', 'value' => null, 'sub_fields' => [] ] ) );
	}

	// --- Oembed ---

	public function test_oembed_extracts_iframe_src(): void {
		$field = [
			'type'  => 'oembed',
			'value' => '<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315"></iframe>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( 'https://www.youtube.com/embed/abc123', $result );
	}

	public function test_oembed_no_iframe_returns_empty(): void {
		$field = [
			'type'  => 'oembed',
			'value' => '<div>no iframe here</div>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '', $result );
	}

	// --- Image ---

	public function test_image_formats_via_format_image(): void {
		$field = [
			'type'  => 'image',
			'value' => [
				'ID'          => 42,
				'url'         => 'https://example.com/photo.jpg',
				'mime_type'   => 'image/jpeg',
				'width'       => 800,
				'height'      => 600,
				'alt'         => 'Photo',
				'caption'     => '',
				'description' => '',
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/photo.jpg', $result[0]['src'] );
	}

	// --- File ---

	public function test_file_formats_via_format_file(): void {
		Functions\when( 'size_format' )->justReturn( '1 KB' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$field = [
			'type'  => 'file',
			'value' => [
				'ID'          => 10,
				'url'         => 'https://example.com/doc.docx',
				'mime_type'   => 'application/msword',
				'subtype'     => 'msword',
				'filename'    => 'doc.docx',
				'filesize'    => 1024,
				'alt'         => '',
				'caption'     => '',
				'description' => '',
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
		$this->assertSame( 'https://example.com/doc.docx', $result['src'] );
	}

	// --- Gallery ---

	public function test_gallery_formats_each_item(): void {
		$field = [
			'type'  => 'gallery',
			'value' => [
				[
					'type'        => 'image',
					'ID'          => 1,
					'url'         => 'https://example.com/a.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 100,
					'height'      => 100,
					'alt'         => 'A',
					'caption'     => '',
					'description' => '',
				],
				[
					'type'        => 'image',
					'ID'          => 2,
					'url'         => 'https://example.com/b.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 200,
					'height'      => 200,
					'alt'         => 'B',
					'caption'     => '',
					'description' => '',
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	public function test_gallery_with_mixed_types(): void {
		Functions\when( 'size_format' )->justReturn( '2 MB' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 3, '_timber_kit_video_codecs', true )
			->andReturn( 'none' );
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$field = [
			'type'  => 'gallery',
			'value' => [
				[
					'type'        => 'image',
					'ID'          => 1,
					'url'         => 'https://example.com/photo.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 800,
					'height'      => 600,
					'alt'         => 'Photo',
					'caption'     => '',
					'description' => '',
				],
				[
					'type'        => 'application',
					'ID'          => 2,
					'url'         => 'https://example.com/document.pdf',
					'mime_type'   => 'application/pdf',
					'subtype'     => 'pdf',
					'filename'    => 'document.pdf',
					'filesize'    => 2048000,
					'alt'         => '',
					'caption'     => '',
					'description' => '',
				],
				[
					'type'        => 'video',
					'ID'          => 3,
					'url'         => 'https://example.com/clip.mp4',
					'mime_type'   => 'video/mp4',
					'width'       => 1920,
					'height'      => 1080,
					'alt'         => '',
					'caption'     => '',
					'description' => '',
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		// image item gets wrapped in nested array by formatImage
		$this->assertIsArray( $result[0] );
		// application item gets formatted by formatFile
		$this->assertSame( 'https://example.com/document.pdf', $result[1]['src'] );
		$this->assertSame( 'pdf', $result[1]['subtype'] );
		// video item gets formatted by formatVideo (unwrapped from nested array)
		$this->assertSame( 3, $result[2]['id'] );
		$this->assertSame( 'video/mp4', $result[2]['type'] );
		$this->assertNull( $result[2]['codecs'] );
	}

	// --- Wysiwyg ---

	public function test_wysiwyg_calls_do_shortcode(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return str_replace( '[hello]', 'HELLO', $content );
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => '<p>[hello] world</p>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '<p>HELLO world</p>', $result );
	}

	public function test_textarea_calls_do_shortcode(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'textarea',
			'value' => 'Some text content',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( 'Some text content', $result );
	}

	public function test_textarea_does_not_apply_editor_artifact_sanitization(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'textarea',
			'value' => '<span data-mce-type="bookmark">&#xfeff;</span><br data-mce-bogus="1">',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( $field['value'], $result );
	}

	public function test_textarea_with_only_breaks_and_spaces_is_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'textarea',
			'value' => "<p>\n&nbsp;<br></p>",
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '', $result );
	}

	public function test_textarea_with_empty_paragraph_attributes_is_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'textarea',
			'value' => '<p class="foo">&nbsp;<br></p>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '', $result );
	}

	public function test_wysiwyg_with_tinymce_artifacts_is_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => '<p><span data-mce-type="bookmark" style="overflow:hidden;line-height:0px">&#xfeff;</span><br data-mce-bogus="1"></p>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '', $result );
	}

	public function test_wysiwyg_with_multiline_tinymce_bookmark_is_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => "<p><span data-mce-type=\"bookmark\">\n&#xfeff;\n</span><br data-mce-bogus=\"1\"></p>",
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '', $result );
	}

	public function test_wysiwyg_with_only_spaces_and_zero_width_chars_is_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => '<p>&nbsp;' . html_entity_decode( '&#x200B;', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</p>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '', $result );
	}

	public function test_wysiwyg_with_visible_text_is_not_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => '<p>&nbsp;Ahoj</p>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '<p>&nbsp;Ahoj</p>', $result );
	}

	public function test_wysiwyg_with_image_only_content_is_not_treated_as_empty(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => '<figure><img src="https://example.com/a.jpg" alt=""></figure>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( $field['value'], $result );
	}

	public function test_wysiwyg_keeps_literal_style_text_in_visible_content(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$field = [
			'type'  => 'wysiwyg',
			'value' => '<p>Use <code>style="color:red"</code> in this example</p>',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( $field['value'], $result );
	}

	public function test_wysiwyg_with_invalid_utf8_does_not_fail_empty_check(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $content ) {
			return $content;
		} );

		$invalid_utf8 = "<p>\xC3\x28</p>";
		$field = [
			'type'  => 'wysiwyg',
			'value' => $invalid_utf8,
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( $invalid_utf8, $result );
	}

	// --- Post Object ---

	public function test_post_object_cf7_render(): void {
		Functions\when( 'do_shortcode' )->alias( function ( $shortcode ) {
			return '<form>' . $shortcode . '</form>';
		} );

		$post = new \stdClass();
		$post->ID = 100;
		$post->post_type = 'wpcf7_contact_form';
		// Make it pass instanceof WP_Post check — we need a real WP_Post-like object
		// Since WP_Post doesn't exist in test env, we'll verify the branch logic differently

		$field = [
			'type'  => 'post_object',
			'value' => $post,
		];

		// Without WP_Post class, instanceof check fails — value passes through unchanged
		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( $post, $result );
	}

	public function test_post_object_cf7_with_wp_post_render(): void {
		$this->define_wp_post_if_needed();

		Functions\when( 'do_shortcode' )->alias( function ( $shortcode ) {
			return '<form>' . $shortcode . '</form>';
		} );

		$post = new \WP_Post( (object) [
			'ID'        => 100,
			'post_type' => 'wpcf7_contact_form',
		] );

		$field = [
			'type'  => 'post_object',
			'value' => $post,
		];

		$result = Helpers::fieldFormatter( $field, 1, false );

		$this->assertStringContainsString( 'contact-form-7', $result );
		$this->assertStringContainsString( '<form>', $result );
		$this->assertStringContainsString( 'id="100"', $result );
	}

	public function test_post_object_cf7_preview_returns_shortcode(): void {
		$this->define_wp_post_if_needed();

		$post = new \WP_Post( (object) [
			'ID'        => 100,
			'post_type' => 'wpcf7_contact_form',
		] );

		$field = [
			'type'  => 'post_object',
			'value' => $post,
		];

		$result = Helpers::fieldFormatter( $field, 1, true );

		$this->assertSame( '[contact-form-7 id="100" title=""]', $result );
	}

	public function test_post_object_wpforms_render(): void {
		$this->define_wp_post_if_needed();

		Functions\when( 'do_shortcode' )->alias( function ( $shortcode ) {
			return '<div class="wpforms">' . $shortcode . '</div>';
		} );

		$post = new \WP_Post( (object) [
			'ID'        => 200,
			'post_type' => 'wpforms',
		] );

		$field = [
			'type'  => 'post_object',
			'value' => $post,
		];

		$result = Helpers::fieldFormatter( $field, 1, false );

		$this->assertStringContainsString( 'wpforms', $result );
		$this->assertStringContainsString( 'id="200"', $result );
	}

	public function test_post_object_wpforms_preview_returns_shortcode(): void {
		$this->define_wp_post_if_needed();

		$post = new \WP_Post( (object) [
			'ID'        => 200,
			'post_type' => 'wpforms',
		] );

		$field = [
			'type'  => 'post_object',
			'value' => $post,
		];

		$result = Helpers::fieldFormatter( $field, 1, true );

		$this->assertSame( '[wpforms id="200"]', $result );
	}

	public function test_deferred_embed_returns_the_shortcode_without_rendering(): void {
		// The whole point: a context build must not spend the render, and must
		// not let the form plugin discover a form on a page that has none.
		$this->define_wp_post_if_needed();

		$rendered = 0;
		Functions\when( 'do_shortcode' )->alias(
			function ( $shortcode ) use ( &$rendered ) {
				++$rendered;
				return '<div class="wpforms">' . $shortcode . '</div>';
			}
		);

		$post = new \WP_Post( (object) [ 'ID' => 200, 'post_type' => 'wpforms' ] );

		$result = Helpers::fieldFormatter(
			[ 'type' => 'post_object', 'value' => $post ],
			1,
			false,
			false
		);

		$this->assertSame( '[wpforms id="200"]', $result );
		$this->assertSame( 0, $rendered, 'do_shortcode ran, so the plugin still discovered the form' );
	}

	public function test_deferring_the_embed_keeps_the_value_cacheable(): void {
		// A rendered form carries a nonce, so fieldFormatter counts it dynamic
		// and nothing may store it. Deferring is therefore not only cheaper --
		// it is what lets the rest of an options page be storable at all.
		$this->define_wp_post_if_needed();
		Functions\when( 'do_shortcode' )->alias( static fn ( $s ) => '<form>' . $s . '</form>' );

		$post  = new \WP_Post( (object) [ 'ID' => 200, 'post_type' => 'wpforms' ] );
		$field = [ 'type' => 'post_object', 'value' => $post ];

		$before = Helpers::dynamicFormatCount();
		Helpers::fieldFormatter( $field, 1, false, true );
		$rendered_moved = Helpers::dynamicFormatCount() - $before;

		$before = Helpers::dynamicFormatCount();
		Helpers::fieldFormatter( $field, 1, false, false );
		$deferred_moved = Helpers::dynamicFormatCount() - $before;

		$this->assertSame( 1, $rendered_moved, 'a rendered form must count as dynamic' );
		$this->assertSame( 0, $deferred_moved, 'a deferred form is just a string and must not' );
	}

	public function test_a_cf7_embed_defers_the_same_way(): void {
		$this->define_wp_post_if_needed();

		$rendered = 0;
		Functions\when( 'do_shortcode' )->alias(
			function ( $s ) use ( &$rendered ) {
				++$rendered;
				return $s;
			}
		);

		$post = new \WP_Post( (object) [ 'ID' => 42, 'post_type' => 'wpcf7_contact_form' ] );

		$result = Helpers::fieldFormatter(
			[ 'type' => 'post_object', 'value' => $post ],
			1,
			false,
			false
		);

		$this->assertSame( '[contact-form-7 id="42" title=""]', $result );
		$this->assertSame( 0, $rendered );
	}

	public function test_the_default_still_renders(): void {
		// The parameter is opt-out. A caller that knows nothing about it keeps
		// the behaviour it had.
		$this->define_wp_post_if_needed();
		Functions\when( 'do_shortcode' )->alias( static fn ( $s ) => '<form>' . $s . '</form>' );

		$post = new \WP_Post( (object) [ 'ID' => 200, 'post_type' => 'wpforms' ] );

		$this->assertStringContainsString(
			'<form>',
			Helpers::fieldFormatter( [ 'type' => 'post_object', 'value' => $post ], 1 )
		);
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

	// --- Link ---

	public function test_link_delegates_to_format_link(): void {
		Functions\when( 'html_entity_decode' )->alias( function ( $str ) {
			return html_entity_decode( $str );
		} );
		Functions\when( 'wp_kses' )->alias( function ( $str ) {
			return $str;
		} );

		$field = [
			'type'  => 'link',
			'value' => [
				'title'  => 'Click me',
				'url'    => 'https://example.com',
				'target' => '_blank',
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertIsArray( $result );
		$this->assertSame( 'Click me', $result['title'] );
		$this->assertSame( 'https://example.com', $result['url'] );
		$this->assertSame( '_blank', $result['attributes']['target'] );
	}

	// --- apply_filters hook ---

	public function test_apply_filters_called_with_field_type(): void {
		$filterCalled = false;
		$filterName = '';

		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) use ( &$filterCalled, &$filterName ) {
			$filterCalled = true;
			$filterName = $filter;
			return $args[0] ?? null;
		} );

		$field = [
			'type'  => 'text',
			'value' => 'Hello',
		];

		Helpers::fieldFormatter( $field );

		$this->assertTrue( $filterCalled );
		$this->assertSame( 'field_formatter_text', $filterName );
	}

	// --- Unknown type passes through ---

	public function test_unknown_type_returns_value(): void {
		$field = [
			'type'  => 'color_picker',
			'value' => '#ff0000',
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( '#ff0000', $result );
	}

	// ===================================================================
	// Iteration 4: Recursive types (repeater, group, flexible_content)
	// ===================================================================

	public function test_repeater_with_text_sub_fields(): void {
		$field = [
			'type'       => 'repeater',
			'sub_fields' => [
				[ 'name' => 'title', 'type' => 'text' ],
				[ 'name' => 'desc', 'type' => 'text' ],
			],
			'value'      => [
				[ 'title' => 'Row 1 Title', 'desc' => 'Row 1 Desc' ],
				[ 'title' => 'Row 2 Title', 'desc' => 'Row 2 Desc' ],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertCount( 2, $result );
		$this->assertSame( 'Row 1 Title', $result[0]['title'] );
		$this->assertSame( 'Row 1 Desc', $result[0]['desc'] );
		$this->assertSame( 'Row 2 Title', $result[1]['title'] );
	}

	public function test_repeater_with_nested_image(): void {
		$field = [
			'type'       => 'repeater',
			'sub_fields' => [
				[ 'name' => 'label', 'type' => 'text' ],
				[ 'name' => 'photo', 'type' => 'image' ],
			],
			'value'      => [
				[
					'label' => 'Item 1',
					'photo' => [
						'ID'          => 42,
						'url'         => 'https://example.com/img.jpg',
						'mime_type'   => 'image/jpeg',
						'width'       => 800,
						'height'      => 600,
						'alt'         => 'Alt',
						'caption'     => '',
						'description' => '',
					],
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( 'Item 1', $result[0]['label'] );
		// Image sub_field gets formatted via formatImage
		$this->assertIsArray( $result[0]['photo'] );
		$this->assertSame( 42, $result[0]['photo'][0]['id'] );
	}

	public function test_repeater_with_video_row_appends_sources_after_sub_field_formatting(): void {
		$field = [
			'type'       => 'repeater',
			'sub_fields' => [
				[ 'name' => 'video_preview_av1', 'type' => 'file' ],
				[ 'name' => 'video_preview', 'type' => 'file' ],
				[ 'name' => 'video', 'type' => 'file' ],
			],
			'value'      => [
				[
					'video_preview_av1' => [
						'ID'          => 31,
						'url'         => 'https://example.com/preview-av1.mp4',
						'mime_type'   => 'video/mp4',
						'subtype'     => 'mp4',
						'filename'    => 'preview-av1.mp4',
						'filesize'    => 1024,
						'alt'         => '',
						'caption'     => '',
						'description' => '',
					],
					'video_preview'     => [
						'ID'          => 32,
						'url'         => 'https://example.com/preview.mp4',
						'mime_type'   => 'video/mp4',
						'subtype'     => 'mp4',
						'filename'    => 'preview.mp4',
						'filesize'    => 1024,
						'alt'         => '',
						'caption'     => '',
						'description' => '',
					],
					'video'             => [
						'ID'          => 33,
						'url'         => 'https://example.com/full.mp4',
						'mime_type'   => 'video/mp4',
						'subtype'     => 'mp4',
						'filename'    => 'full.mp4',
						'filesize'    => 1024,
						'alt'         => '',
						'caption'     => '',
						'description' => '',
					],
				],
			],
		];

		Functions\when( 'size_format' )->justReturn( '1 KB' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\expect( 'get_post_meta' )
			->times( 3 )
			->andReturn( 'av01.0.00M.08', 'none', 'none' );

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame(
			[
				[
					'src' => 'https://example.com/preview-av1.mp4',
					'type' => 'video/mp4',
					'codecs' => 'av01.0.00M.08',
				],
				[
					'src' => 'https://example.com/preview.mp4',
					'type' => 'video/mp4',
					'codecs' => null,
				],
				[
					'src' => 'https://example.com/full.mp4',
					'type' => 'video/mp4',
					'codecs' => null,
				],
			],
			$result[0]['sources']
		);
	}

	public function test_group_with_assoc_value(): void {
		// Group with associative value (isAssoc returns true) — uses the else branch
		$field = [
			'type'       => 'group',
			'sub_fields' => [
				[ 'name' => 'heading', 'type' => 'text' ],
				[ 'name' => 'content', 'type' => 'text' ],
			],
			'value'      => [
				'heading' => 'Hello',
				'content' => 'World',
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( 'Hello', $result['heading'] );
		$this->assertSame( 'World', $result['content'] );
	}

	public function test_group_assoc_value_does_not_append_video_sources(): void {
		$field = [
			'type'       => 'group',
			'sub_fields' => [
				[ 'name' => 'video_preview_av1', 'type' => 'text' ],
				[ 'name' => 'video', 'type' => 'text' ],
			],
			'value'      => [
				'video_preview_av1' => [
					'src' => 'https://example.com/preview-av1.mp4',
					'type' => 'video/mp4',
					'codecs' => 'av01.0.00M.08',
				],
				'video'             => [
					'src' => 'https://example.com/full.mp4',
					'type' => 'video/mp4',
					'codecs' => null,
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertArrayNotHasKey( 'sources', $result );
	}

	public function test_group_with_sequential_rows(): void {
		// Group with sequential array (isAssoc returns false) — uses the if branch
		$field = [
			'type'       => 'group',
			'sub_fields' => [
				[ 'name' => 'name', 'type' => 'text' ],
			],
			'value'      => [
				[ 'name' => 'Alice' ],
				[ 'name' => 'Bob' ],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertCount( 2, $result );
		$this->assertSame( 'Alice', $result[0]['name'] );
		$this->assertSame( 'Bob', $result[1]['name'] );
	}

	public function test_repeater_null_value_returns_field_as_is_in_preview(): void {
		// Block preview needs the definition (sub_fields) to render a placeholder.
		$field = [
			'type'       => 'repeater',
			'sub_fields' => [
				[ 'name' => 'title', 'type' => 'text' ],
			],
			'value'      => null,
		];

		$result = Helpers::fieldFormatter( $field, null, true );

		$this->assertSame( $field, $result );
	}

	public function test_repeater_null_value_returns_false_outside_preview(): void {
		// Front-end reads must see "empty", not a definition array masquerading
		// as a populated list of rows.
		$field = [
			'type'       => 'repeater',
			'sub_fields' => [
				[ 'name' => 'title', 'type' => 'text' ],
			],
			'value'      => null,
		];

		$this->assertFalse( Helpers::fieldFormatter( $field ) );
	}

	public function test_flexible_content_basic(): void {
		$field = [
			'type'    => 'flexible_content',
			'layouts' => [
				[
					'name'       => 'text_block',
					'sub_fields' => [
						[ 'name' => 'heading', 'type' => 'text' ],
						[ 'name' => 'body', 'type' => 'text' ],
					],
				],
				[
					'name'       => 'image_block',
					'sub_fields' => [
						[ 'name' => 'photo', 'type' => 'image' ],
					],
				],
			],
			'value'   => [
				[
					'acf_fc_layout' => 'text_block',
					'heading'       => 'Title',
					'body'          => 'Content',
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertCount( 1, $result );
		$this->assertSame( 'text_block', $result[0]['acf_fc_layout'] );
		$this->assertSame( 'Title', $result[0]['heading'] );
		$this->assertSame( 'Content', $result[0]['body'] );
	}

	public function test_flexible_content_with_nested_image(): void {
		$field = [
			'type'    => 'flexible_content',
			'layouts' => [
				[
					'name'       => 'hero',
					'sub_fields' => [
						[ 'name' => 'image', 'type' => 'image' ],
						[ 'name' => 'title', 'type' => 'text' ],
					],
				],
			],
			'value'   => [
				[
					'acf_fc_layout' => 'hero',
					'image'         => [
						'ID'          => 99,
						'url'         => 'https://example.com/hero.jpg',
						'mime_type'   => 'image/jpeg',
						'width'       => 1920,
						'height'      => 1080,
						'alt'         => 'Hero',
						'caption'     => '',
						'description' => '',
					],
					'title'         => 'Welcome',
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertSame( 'Welcome', $result[0]['title'] );
		$this->assertIsArray( $result[0]['image'] );
		$this->assertSame( 99, $result[0]['image'][0]['id'] );
	}

	public function test_flexible_content_multiple_layouts(): void {
		$field = [
			'type'    => 'flexible_content',
			'layouts' => [
				[
					'name'       => 'text_block',
					'sub_fields' => [
						[ 'name' => 'text', 'type' => 'text' ],
					],
				],
				[
					'name'       => 'cta_block',
					'sub_fields' => [
						[ 'name' => 'label', 'type' => 'text' ],
					],
				],
			],
			'value'   => [
				[
					'acf_fc_layout' => 'text_block',
					'text'          => 'Hello',
				],
				[
					'acf_fc_layout' => 'cta_block',
					'label'         => 'Click',
				],
				[
					'acf_fc_layout' => 'text_block',
					'text'          => 'Goodbye',
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertCount( 3, $result );
		$this->assertSame( 'Hello', $result[0]['text'] );
		$this->assertSame( 'Click', $result[1]['label'] );
		$this->assertSame( 'Goodbye', $result[2]['text'] );
	}

	public function test_repeater_with_link_sub_field(): void {
		Functions\when( 'html_entity_decode' )->alias( 'html_entity_decode' );
		Functions\when( 'wp_kses' )->alias( function ( $str ) {
			return $str;
		} );

		$field = [
			'type'       => 'repeater',
			'sub_fields' => [
				[ 'name' => 'label', 'type' => 'text' ],
				[ 'name' => 'link', 'type' => 'link' ],
			],
			'value'      => [
				[
					'label' => 'Service 1',
					'link'  => [
						'title'  => 'Read more',
						'url'    => 'https://example.com/service-1',
						'target' => '_blank',
					],
				],
				[
					'label' => 'Service 2',
					'link'  => [
						'title'  => 'Details',
						'url'    => 'https://example.com/service-2',
						'target' => '',
					],
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertCount( 2, $result );
		$this->assertSame( 'Service 1', $result[0]['label'] );
		// Link sub_field gets formatted via formatLink
		$this->assertIsArray( $result[0]['link'] );
		$this->assertSame( 'Read more', $result[0]['link']['title'] );
		$this->assertSame( '_blank', $result[0]['link']['attributes']['target'] );
		// Second link has empty target — should be unset
		$this->assertSame( 'Details', $result[1]['link']['title'] );
		$this->assertArrayNotHasKey( 'target', $result[1]['link'] );
	}

	public function test_flexible_content_with_link_sub_field(): void {
		Functions\when( 'html_entity_decode' )->alias( 'html_entity_decode' );
		Functions\when( 'wp_kses' )->alias( function ( $str ) {
			return $str;
		} );

		$field = [
			'type'    => 'flexible_content',
			'layouts' => [
				[
					'name'       => 'cta_block',
					'sub_fields' => [
						[ 'name' => 'heading', 'type' => 'text' ],
						[ 'name' => 'button', 'type' => 'link' ],
					],
				],
			],
			'value'   => [
				[
					'acf_fc_layout' => 'cta_block',
					'heading'       => 'Get started',
					'button'        => [
						'title'  => 'Sign up',
						'url'    => 'https://example.com/signup',
						'target' => '_blank',
					],
				],
			],
		];

		$result = Helpers::fieldFormatter( $field );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Get started', $result[0]['heading'] );
		$this->assertIsArray( $result[0]['button'] );
		$this->assertSame( 'Sign up', $result[0]['button']['title'] );
		$this->assertSame( 'https://example.com/signup', $result[0]['button']['url'] );
		$this->assertSame( '_blank', $result[0]['button']['attributes']['target'] );
	}

	public function test_flexible_content_null_value_returns_field_as_is_in_preview(): void {
		// Block preview needs the definition (layouts) to render a placeholder.
		$field = [
			'type'    => 'flexible_content',
			'layouts' => [
				[
					'name'       => 'block',
					'sub_fields' => [
						[ 'name' => 'title', 'type' => 'text' ],
					],
				],
			],
			'value'   => null,
		];

		$result = Helpers::fieldFormatter( $field, null, true );

		$this->assertSame( $field, $result );
	}

	public function test_flexible_content_null_value_returns_false_outside_preview(): void {
		// Front-end reads must see "empty", not a definition array.
		$field = [
			'type'    => 'flexible_content',
			'layouts' => [
				[
					'name'       => 'block',
					'sub_fields' => [
						[ 'name' => 'title', 'type' => 'text' ],
					],
				],
			],
			'value'   => null,
		];

		$this->assertFalse( Helpers::fieldFormatter( $field ) );
	}
}
