<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * `post_id` on an $options_pages entry — the ACF storage namespace.
 *
 * The assertions all read the $args array handed to acf_add_options_page() /
 * acf_add_options_sub_page(), because that is the entire surface of the
 * feature: what ACF stores under is decided there and nowhere else.
 */
class AcfOptionsPagePostIdTest extends StarterBaseTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $top_level = [];

	/** @var array<int, array<string, mixed>> */
	private array $sub_pages = [];

	protected function setUp(): void {
		parent::setUp();
		$this->top_level = [];
		$this->sub_pages = [];

		Functions\when( 'acf_add_options_page' )->alias( function ( $args ) {
			$this->top_level[] = $args;
		} );
		Functions\when( 'acf_add_options_sub_page' )->alias( function ( $args ) {
			$this->sub_pages[] = $args;
		} );
	}

	/**
	 * Omitting the key must leave $args untouched, not pass an explicit
	 * 'options'. Every pre-existing consumer relies on ACF's own default
	 * applying, so the addition has to be invisible when unused.
	 */
	public function test_post_id_absent_from_args_when_not_declared(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ],
			],
		] );

		$base->acf_options_page();

		$this->assertCount( 1, $this->top_level );
		$this->assertArrayNotHasKey( 'post_id', $this->top_level[0] );
	}

	public function test_post_id_passed_through_for_top_level_page(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'post_id' => 'mytheme_settings' ],
			],
		] );

		$base->acf_options_page();

		$this->assertSame( 'mytheme_settings', $this->top_level[0]['post_id'] );
	}

	/**
	 * The inheritance rule. ACF defaults every page to 'options' independently
	 * (acf_validate_options_page), so without this a namespaced parent and its
	 * unmarked children would store into two different namespaces and
	 * formatFields() would return only the parent's half.
	 */
	public function test_sub_page_inherits_parent_post_id(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'post_id' => 'mytheme_settings' ],
				[ 'menu_slug' => 'footer', 'page_title' => 'Footer', 'parent_slug' => 'settings' ],
			],
		] );

		$base->acf_options_page();

		$this->assertCount( 1, $this->sub_pages );
		$this->assertSame( 'mytheme_settings', $this->sub_pages[0]['post_id'] );
	}

	/** Declaration order must not matter — top-level pages register first. */
	public function test_sub_page_inherits_when_declared_before_its_parent(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'footer', 'page_title' => 'Footer', 'parent_slug' => 'settings' ],
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'post_id' => 'mytheme_settings' ],
			],
		] );

		$base->acf_options_page();

		$this->assertSame( 'mytheme_settings', $this->sub_pages[0]['post_id'] );
	}

	/** A child's own declaration wins over the parent's — the opt-out. */
	public function test_sub_page_own_post_id_wins_over_parent(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'post_id' => 'mytheme_settings' ],
				[
					'menu_slug'   => 'footer',
					'page_title'  => 'Footer',
					'parent_slug' => 'settings',
					'post_id'     => 'mytheme_footer',
				],
			],
		] );

		$base->acf_options_page();

		$this->assertSame( 'mytheme_footer', $this->sub_pages[0]['post_id'] );
	}

	/** An un-namespaced parent leaves the child un-namespaced too. */
	public function test_sub_page_gets_no_post_id_when_parent_has_none(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ],
				[ 'menu_slug' => 'footer', 'page_title' => 'Footer', 'parent_slug' => 'settings' ],
			],
		] );

		$base->acf_options_page();

		$this->assertArrayNotHasKey( 'post_id', $this->sub_pages[0] );
	}

	/**
	 * A sub-page whose parent_slug points outside the list (a plugin's page,
	 * say) has no namespace to inherit and must not pick up an unrelated one.
	 */
	public function test_sub_page_with_foreign_parent_inherits_nothing(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'post_id' => 'mytheme_settings' ],
				[ 'menu_slug' => 'extra', 'page_title' => 'Extra', 'parent_slug' => 'some-plugin-page' ],
			],
		] );

		$base->acf_options_page();

		$this->assertArrayNotHasKey( 'post_id', $this->sub_pages[0] );
	}
}
