<?php

declare(strict_types=1);

namespace Tests\Integration\Seo;

use Tests\Integration\IntegrationTestCase;

/**
 * `$seo_not_found_title` against core's own `wp_get_document_title()`.
 *
 * The unit test proves the callback returns `''`. This proves the part it
 * relies on: that core, handed `''` on a 404, builds `Page not found - <site>`
 * by itself. The SEO plugin's caching callback is stood in for by a filter at
 * the priority one such plugin uses, returning what Timber's `wp_title( ' ' )`
 * handed it.
 */
class NotFoundTitleTest extends IntegrationTestCase {

	private const PADDED = '   Page not found';

	public function set_up(): void {
		parent::set_up();
		update_option( 'blogname', 'Example Site' );
		add_filter( 'pre_get_document_title', array( $this, 'cachedPluginTitle' ), 99999 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_get_document_title', array( $this, 'cachedPluginTitle' ), 99999 );
		parent::tear_down();
	}

	public function cachedPluginTitle(): string {
		return self::PADDED;
	}

	public function test_flag_on_hands_a_padded_404_title_back_to_core(): void {
		$this->bootKit( array( 'seo_not_found_title' => true ) );

		$this->go_to( home_url( '/?p=999999' ) );

		self::assertTrue( is_404() );
		// The separator is core's `-`, texturized to an en dash unless the kit's
		// own wptexturize switch has removed that filter.
		self::assertMatchesRegularExpression( '/^Page not found (-|&#8211;) Example Site$/', wp_get_document_title() );
	}

	public function test_flag_off_keeps_the_padded_title(): void {
		$this->bootKit( array( 'seo_not_found_title' => false ) );

		$this->go_to( home_url( '/?p=999999' ) );

		self::assertTrue( is_404() );
		self::assertSame( 'Page not found', trim( wp_get_document_title() ), 'the defect: no site name' );
		self::assertStringStartsWith( ' ', wp_get_document_title() );
	}

	public function test_flag_on_leaves_a_normal_page_title_alone(): void {
		$this->bootKit( array( 'seo_not_found_title' => true ) );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );

		$this->go_to( get_permalink( $post_id ) );

		self::assertFalse( is_404() );
		self::assertSame( self::PADDED, wp_get_document_title(), 'off a 404 the stand-in plugin title passes through, padded or not' );
	}
}
