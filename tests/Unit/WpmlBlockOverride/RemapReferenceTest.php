<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Tests\Unit\WpmlBlockOverrideTestCase;

/**
 * Covers remapReference(): which ACF field types get their reference id(s)
 * remapped to the target language, and with which WPML element type.
 *
 * wpml_object_id is mocked to echo "id:element_type:lang" so assertions can
 * verify both that a remap happened and that the correct element type was used.
 */
class RemapReferenceTest extends WpmlBlockOverrideTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$args ) {
				if ( $tag === 'wpml_object_id' ) {
					// args: [ element_type, return_original, lang ]
					return $value . ':' . $args[0] . ':' . $args[2];
				}
				return $value;
			}
		);
	}

	private static function remap( mixed $value, array $field, string $lang = 'en' ): mixed {
		return self::callPrivate( 'remapReference', [ $value, $field, $lang ] );
	}

	// ── attachment-backed ────────────────────────────────────

	public function test_image_remapped_as_attachment(): void {
		$this->assertSame( '999:attachment:en', self::remap( 999, [ 'type' => 'image' ] ) );
	}

	public function test_gallery_remaps_each_id_as_attachment(): void {
		$this->assertSame(
			[ '11:attachment:en', '22:attachment:en' ],
			self::remap( [ 11, 22 ], [ 'type' => 'gallery' ] )
		);
	}

	// ── post-backed references ───────────────────────────────

	public function test_post_object_remapped_with_resolved_post_type(): void {
		Functions\when( 'get_post_type' )->justReturn( 'flat' );

		$this->assertSame( '42:flat:en', self::remap( 42, [ 'type' => 'post_object' ] ) );
	}

	public function test_relationship_remaps_each_id_with_post_type(): void {
		Functions\when( 'get_post_type' )->justReturn( 'flat' );

		$this->assertSame(
			[ '42:flat:en', '43:flat:en' ],
			self::remap( [ 42, 43 ], [ 'type' => 'relationship' ] )
		);
	}

	public function test_page_link_numeric_remapped_as_post(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		$this->assertSame( '5:page:en', self::remap( 5, [ 'type' => 'page_link' ] ) );
	}

	public function test_page_link_url_passes_through_unchanged(): void {
		// A page_link can hold a raw URL; non-numeric values are not remapped.
		$url = 'https://example.com/about/';
		$this->assertSame( $url, self::remap( $url, [ 'type' => 'page_link' ] ) );
	}

	public function test_post_type_falls_back_to_post_when_unresolved(): void {
		Functions\when( 'get_post_type' )->justReturn( false );

		$this->assertSame( '42:post:en', self::remap( 42, [ 'type' => 'post_object' ] ) );
	}

	// ── term-backed ──────────────────────────────────────────

	public function test_taxonomy_remapped_with_field_taxonomy(): void {
		$this->assertSame(
			'7:category:en',
			self::remap( 7, [ 'type' => 'taxonomy', 'taxonomy' => 'category' ] )
		);
	}

	public function test_taxonomy_without_taxonomy_setting_passes_through(): void {
		$this->assertSame( 7, self::remap( 7, [ 'type' => 'taxonomy' ] ) );
	}

	// ── not remapped ─────────────────────────────────────────

	public function test_user_field_passes_through(): void {
		// WPML doesn't translate users.
		$this->assertSame( 3, self::remap( 3, [ 'type' => 'user' ] ) );
	}

	public function test_link_field_passes_through(): void {
		$link = [ 'title' => 'Go', 'url' => 'https://example.com', 'target' => '' ];
		$this->assertSame( $link, self::remap( $link, [ 'type' => 'link' ] ) );
	}

	public function test_text_field_passes_through(): void {
		$this->assertSame( 'hello', self::remap( 'hello', [ 'type' => 'text' ] ) );
	}

	// ── edge values ──────────────────────────────────────────

	public function test_zero_and_empty_ids_pass_through(): void {
		$this->assertSame( 0, self::remap( 0, [ 'type' => 'image' ] ) );
		$this->assertSame( '', self::remap( '', [ 'type' => 'post_object' ] ) );
	}

	public function test_non_numeric_entry_in_array_is_left_alone(): void {
		Functions\when( 'get_post_type' )->justReturn( 'flat' );

		$this->assertSame(
			[ '42:flat:en', 'not-an-id' ],
			self::remap( [ 42, 'not-an-id' ], [ 'type' => 'relationship' ] )
		);
	}
}
