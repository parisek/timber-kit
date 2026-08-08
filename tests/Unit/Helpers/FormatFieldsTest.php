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
		// Regression for #103: ACF addresses a taxonomy term as "term_<id>",
		// never a bare integer — a bare int reads as a post id to ACF.
		$term = (object) [ 'term_id' => 15 ];

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'term_15' ) {
				return [
					'color' => [ 'type' => 'color_picker', 'value' => '#ff0000' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $term );

		$this->assertSame( '#ff0000', $result['color'] );
	}

	public function test_with_wp_term_instance_resolves_term_prefixed_id(): void {
		// #103: a real WP_Term instance must resolve identically to the
		// duck-typed plain-object case above.
		$term = new \WP_Term( [ 'term_id' => 15, 'taxonomy' => 'category' ] );

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'term_15' ) {
				return [
					'color' => [ 'type' => 'color_picker', 'value' => '#ff0000' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $term );

		$this->assertSame( '#ff0000', $result['color'] );
	}

	public function test_with_timber_style_term_object_resolves_term_prefixed_id(): void {
		// #103: Timber\Term does not extend WP_Term in Timber 2 — it wraps
		// one via CoreEntity — so a Timber-shaped plain object (ID, term_id,
		// taxonomy, no post_type) must still be detected as a term, not fall
		// through to the bare ->ID branch.
		$term = (object) [ 'ID' => 15, 'term_id' => 15, 'taxonomy' => 'category' ];

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'term_15' ) {
				return [
					'color' => [ 'type' => 'color_picker', 'value' => '#ff0000' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $term );

		$this->assertSame( '#ff0000', $result['color'] );
	}

	public function test_with_user_object_resolves_user_prefixed_id(): void {
		// #103: users have the identical bug — ACF addresses a user as
		// "user_<id>", never a bare integer.
		$user = new \WP_User( [ 'ID' => 8 ] );

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'user_8' ) {
				return [
					'bio_color' => [ 'type' => 'color_picker', 'value' => '#00ff00' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $user );

		$this->assertSame( '#00ff00', $result['bio_color'] );
	}

	public function test_with_timber_style_user_object_resolves_user_prefixed_id(): void {
		// #103: a Timber\User-shaped plain object (object_type === 'user'),
		// mirroring how Timber\User discriminates itself from Timber\Post —
		// both expose ->ID, so object_type is the reliable signal.
		$user = (object) [ 'ID' => 8, 'object_type' => 'user' ];

		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( $id === 'user_8' ) {
				return [
					'bio_color' => [ 'type' => 'color_picker', 'value' => '#00ff00' ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( $user );

		$this->assertSame( '#00ff00', $result['bio_color'] );
	}

	public function test_normal_post_still_resolves_to_bare_id(): void {
		// #103 regression guard: the fix must not change post resolution.
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

	public function test_options_string_still_routes_to_options_resolver(): void {
		// #103 regression guard: string options-page ids must not be
		// reinterpreted as term/user objects — they were never objects to
		// begin with, but pin the behaviour alongside the other guards.
		Functions\when( 'acf_get_options_pages' )->justReturn( [
			[ 'post_id' => 'options', 'menu_slug' => 'theme-options' ],
		] );
		Functions\when( 'acf_decode_post_id' )->alias( function ( $id ) {
			return in_array( $id, [ 'option', 'options' ], true ) ? [ 'type' => 'option', 'id' => $id ] : false;
		} );
		Functions\when( 'acf_get_field_groups' )->justReturn( [ [ 'key' => 'group_options' ] ] );
		Functions\when( 'acf_get_fields' )->justReturn( [
			[ 'key' => 'field_site_logo', 'name' => 'site_logo', 'type' => 'text' ],
		] );
		Functions\when( 'get_field' )->alias( function ( $name, $id ) {
			return $name === 'site_logo' && $id === 'option' ? 'logo.svg' : null;
		} );

		$result = Helpers::formatFields( 'option' );

		$this->assertSame( 'logo.svg', $result['site_logo'] );
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
		// Defensive stub: acf_decode_post_id() may already be "defined" for
		// Brain\Monkey's function_exists() once any test in the suite mocks
		// it (process-wide patch), so isOptionsPostId()'s function_exists()
		// gate can no longer be relied on to short-circuit here. Force it to
		// a non-array result so isOptionsPostId() returns false and this
		// test still exercises the plain get_field_objects() fallback.
		Functions\when( 'acf_decode_post_id' )->justReturn( false );
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

	/**
	 * Regression: an unchecked `true_false` field must survive as an
	 * explicit `false`, not be dropped as if the field did not exist.
	 * A consumer that reads the switch via `array_key_exists()` (a common
	 * `?? true` "default on" pattern) cannot otherwise tell "explicitly off"
	 * from "field not present" — see CHANGELOG for the measured fallout.
	 */
	public function test_true_false_switch_off_is_kept_as_explicit_false(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'is_enabled' => [ 'type' => 'true_false', 'value' => false ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayHasKey( 'is_enabled', $result );
		$this->assertSame( false, $result['is_enabled'] );
	}

	/**
	 * Companion to the true_false case: a `number` field explicitly set to
	 * `0` (or ACF's string-typed `"0"`) must also survive — `0` is not
	 * "field absent" any more than `false` is.
	 */
	public function test_numeric_zero_value_is_kept(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'count'      => [ 'type' => 'number', 'value' => 0 ],
			'raw_string' => [ 'type' => 'text', 'value' => '0' ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertSame( 0, $result['count'] );
		$this->assertSame( '0', $result['raw_string'] );
	}

	/**
	 * A field with no saved value at all (missing `value` key entirely —
	 * the shape `fieldFormatter()` treats as "no such field") must still be
	 * dropped, even though `fieldFormatter()`'s sentinel return is also the
	 * literal `false` that a real true_false-off value formats to. This is
	 * the disambiguation the fix relies on: only a field that genuinely
	 * carried a non-null `value` key produces a kept `false`.
	 */
	public function test_field_missing_value_key_is_dropped_not_kept_as_false(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'ghost' => [ 'type' => 'true_false' ], // no 'value' key at all
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayNotHasKey( 'ghost', $result );
	}

	/**
	 * The `false`-keeping exception is scoped to `true_false` by field
	 * *type*, not by "does the field carry a `value` key" — ACF sets
	 * `value => false` on an empty `repeater` too (see the sentinel
	 * pass-through in {@see fieldFormatter()}), so a key-presence test
	 * would wrongly keep it. If the predicate were inverted back to a bare
	 * key-presence check, this would fail: `array_key_exists( 'value',
	 * $field )` is true here, only the type restriction saves it.
	 */
	public function test_empty_repeater_false_value_is_dropped_not_kept(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'items' => [ 'type' => 'repeater', 'value' => false, 'sub_fields' => [] ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayNotHasKey( 'items', $result );
	}

	/**
	 * Same disambiguation, for an empty `relationship` field — ACF also
	 * represents "nothing selected" as `value => false` here. If the
	 * predicate were inverted back to a bare key-presence check, this would
	 * fail the same way as the repeater case above.
	 */
	public function test_empty_relationship_false_value_is_dropped_not_kept(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'related' => [ 'type' => 'relationship', 'value' => false ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayNotHasKey( 'related', $result );
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
		// Defensive stub — see test_with_string_options_page() above for why.
		Functions\when( 'acf_decode_post_id' )->justReturn( false );
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

	/**
	 * `get_field_objects()` is called with the raw `block_<hash>` id — the
	 * swap to the real post id (§ `str_starts_with( (string) $post_id,
	 * 'block_' )` in `formatFields()`) only takes effect for what gets
	 * passed *downstream*, to `fieldFormatter()` and, through it, to the
	 * `field_formatter_{type}` filter as that filter's second argument.
	 * That is the only externally observable signal the swap happened —
	 * asserting on the *result* alone (as an earlier version of this test
	 * did) does not prove the swap ran, because a formatted `text` value
	 * does not depend on `$post_id` at all; the test would stay green even
	 * if the swap block were deleted. Spy on `apply_filters` to capture the
	 * `$post_id` it actually received.
	 */
	public function test_block_prefix_swaps_to_real_post_id_before_field_formatter(): void {
		$this->define_wp_post_if_needed();
		global $post;
		$post = new \WP_Post( (object) [ 'ID' => 99, 'post_type' => 'page' ] );

		$captured_post_id = 'not captured';
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) use ( &$captured_post_id ) {
			if ( $filter === 'field_formatter_text' ) {
				$captured_post_id = $args[1] ?? 'missing';
			}
			return $args[0] ?? null;
		} );

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
		// The real signal that the swap ran: fieldFormatter() (and the
		// filter it applies) received the real post id, not the raw block
		// id string that was used to call get_field_objects().
		$this->assertSame( 99, $captured_post_id );
	}

	/**
	 * Regression for the bug that motivated this whole fix, exercised
	 * through the path it actually happened on: an ACF block, whose
	 * `formatFields()` call resolves through the `block_<hash>` id-swap,
	 * not through a plain post object. This proves two things together,
	 * both required for the original bug to be considered fixed on the
	 * block path specifically:
	 *
	 * 1. The block-id-to-real-post-id swap actually ran (observed the same
	 *    way as {@see test_block_prefix_swaps_to_real_post_id_before_field_
	 *    formatter()} above — via the `$post_id` the `field_formatter_{type}`
	 *    filter receives).
	 * 2. `true_false`-off still survives once fields are sourced from a
	 *    `block_<hash>` lookup rather than a plain post object.
	 *
	 * `isFormattedFieldPresent()` in fact only inspects the field
	 * definition from `$fields` (captured before the swap), so (2) does
	 * not actually depend on (1) — but a test that only asserted (2) would
	 * stay green even if the swap block were deleted entirely, which is
	 * exactly the false confidence the previous version of this test gave.
	 * Asserting the captured post id closes that gap.
	 */
	public function test_true_false_switch_off_survives_block_id_resolution(): void {
		$this->define_wp_post_if_needed();
		global $post;
		$post = new \WP_Post( (object) [ 'ID' => 77, 'post_type' => 'page' ] );

		$captured_post_id = 'not captured';
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) use ( &$captured_post_id ) {
			if ( $filter === 'field_formatter_true_false' ) {
				$captured_post_id = $args[1] ?? 'missing';
			}
			return $args[0] ?? null;
		} );

		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			if ( str_starts_with( (string) $id, 'block_' ) ) {
				return [
					'is_enabled' => [ 'type' => 'true_false', 'value' => false ],
				];
			}
			return false;
		} );

		$result = Helpers::formatFields( 'block_deadbeef' );

		$this->assertArrayHasKey( 'is_enabled', $result );
		$this->assertSame( false, $result['is_enabled'] );
		$this->assertSame( 77, $captured_post_id );
	}

	/**
	 * CHANGELOG.md documents that a `false` produced by the public
	 * `field_formatter_{type}` filter follows the same type-based rule as a
	 * raw ACF value — kept only for `true_false`, dropped otherwise, even
	 * when the raw value it overwrote was non-empty. This is untested
	 * elsewhere: `setUp()`'s default `apply_filters` stub is a pass-through
	 * that never rewrites `value`, so nothing exercises a filter that
	 * actually flips a non-empty value to `false`.
	 *
	 * If `isFormattedFieldPresent()` ever went back to deciding on "did the
	 * source field carry a `value` key" instead of on declared type, this
	 * would fail: the source field here carries a non-null `value` key
	 * (`'Chosen'`), so a key-presence predicate would wrongly keep it.
	 */
	public function test_filter_forced_false_is_dropped_for_non_true_false_type(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'field_formatter_select' ) {
				$field = $args[0];
				$field['value'] = false;
				return $field;
			}
			return $args[0] ?? null;
		} );

		Functions\when( 'get_field_objects' )->justReturn( [
			'choice' => [ 'type' => 'select', 'value' => 'Chosen' ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayNotHasKey( 'choice', $result );
	}

	/**
	 * Converse of the case above, and the more consequential half of the
	 * CHANGELOG's `field_formatter_{type}` promise: a filter that turns a
	 * *non-falsy* `true_false` value into `false` must still survive as an
	 * explicit `false`, exactly like a raw ACF off-switch does. An
	 * implementation that keyed the survive/drop decision on the raw
	 * source value (`$field['value']` before the filter ran) rather than
	 * the formatted return value would pass every other test in this file
	 * while breaking this one — the raw value here is `true`, non-falsy,
	 * so a source-keyed predicate would incorrectly see nothing to guard
	 * against and let the type check run against the wrong signal, or
	 * (in another plausible-but-wrong implementation) drop the field
	 * because the *raw* value never equalled `false`.
	 */
	public function test_filter_forced_false_on_true_false_type_stays_present(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'field_formatter_true_false' ) {
				$field = $args[0];
				$field['value'] = false;
				return $field;
			}
			return $args[0] ?? null;
		} );

		Functions\when( 'get_field_objects' )->justReturn( [
			'is_enabled' => [ 'type' => 'true_false', 'value' => true ],
		] );

		$result = Helpers::formatFields( (object) [ 'ID' => 1 ] );

		$this->assertArrayHasKey( 'is_enabled', $result );
		$this->assertSame( false, $result['is_enabled'] );
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
		// The resolved post id is now the string "term_225" — isOptionsPostId()
		// asks ACF to classify it; a real acf_decode_post_id() reports 'term',
		// not 'option', so the options-resolver path is correctly skipped.
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'term', 'id' => 225 ] );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			return $id === 'term_225'
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
		Functions\when( 'acf_decode_post_id' )->justReturn( [ 'type' => 'term', 'id' => 225 ] );
		Functions\when( 'get_field_objects' )->alias( function ( $id ) {
			return $id === 'term_225'
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

	/**
	 * Locks the $is_preview hand-off from formatFields() into fieldFormatter().
	 * Drop the argument at the call site and this goes red: the placeholder
	 * definition an unsaved block renders from would silently disappear.
	 */
	public function test_preview_keeps_unfilled_repeater_definition(): void {
		$field = [ 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'title', 'type' => 'text' ] ], 'value' => null ];

		Functions\when( 'get_field_objects' )->justReturn( [ 'rows' => $field ] );

		$this->assertSame( $field, Helpers::formatFields( 7, true )['rows'] );
	}

	public function test_unfilled_repeater_is_absent_outside_preview(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'rows' => [ 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'title', 'type' => 'text' ] ], 'value' => null ],
		] );

		$this->assertArrayNotHasKey( 'rows', Helpers::formatFields( 7 ) );
	}

	/**
	 * Same hand-off, one level down. fieldFormatter() recurses into sub-fields
	 * and must carry $is_preview with it; a nested repeater left unfilled inside
	 * a populated parent row is the case that exercises it.
	 */
	public function test_preview_propagates_into_nested_repeater(): void {
		$nested = [ 'name' => 'links', 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'url', 'type' => 'text' ] ] ];

		Functions\when( 'get_field_objects' )->justReturn( [
			'sections' => [
				'type'       => 'repeater',
				'sub_fields' => [ [ 'name' => 'heading', 'type' => 'text' ], $nested ],
				'value'      => [ [ 'heading' => 'Docs', 'links' => null ] ],
			],
		] );

		$row = Helpers::formatFields( 7, true )['sections'][0];

		$this->assertSame( 'Docs', $row['heading'] );
		$this->assertSame( 'repeater', $row['links']['type'] );
	}

	/**
	 * The nested counterpart is NOT pruned — formatFields() prunes only at the
	 * top level, so a null nested repeater lands in the row as false rather than
	 * being omitted. Documented here because it is easy to assume otherwise.
	 */
	public function test_nested_unfilled_repeater_is_false_outside_preview(): void {
		$nested = [ 'name' => 'links', 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'url', 'type' => 'text' ] ] ];

		Functions\when( 'get_field_objects' )->justReturn( [
			'sections' => [
				'type'       => 'repeater',
				'sub_fields' => [ [ 'name' => 'heading', 'type' => 'text' ], $nested ],
				'value'      => [ [ 'heading' => 'Docs', 'links' => null ] ],
			],
		] );

		$row = Helpers::formatFields( 7 )['sections'][0];

		$this->assertSame( 'Docs', $row['heading'] );
		$this->assertFalse( $row['links'] );
	}
}
