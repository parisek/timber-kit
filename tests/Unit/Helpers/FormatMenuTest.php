<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\Unit\HelpersTestCase;
use Parisek\TimberKit\Helpers;
use Brain\Monkey\Functions;

/**
 * Baseline coverage for Helpers::formatMenu().
 *
 * Written before the MenuData change so the item-building loop — the
 * regression-critical surface — has a characterization net. Menus are passed
 * as objects, never by name: formatMenu() accepts an object for
 * $menu_or_name, which avoids Timber::get_menu() (a static method Brain\Monkey
 * cannot intercept).
 */
class FormatMenuTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		// formatFields() runs per item; with no ACF present it must yield [].
		Functions\when( 'get_field_objects' )->justReturn( [] );
		// Other test files may have already made is_plugin_active() exist process-wide
		// (Brain\Monkey's function patching is global, not per-test); mock it explicitly
		// so this test doesn't depend on suite execution order.
		Functions\when( 'is_plugin_active' )->justReturn( false );
	}

	/**
	 * Build a menu-item stub shaped like Timber\MenuItem.
	 */
	private function makeItem( int $id, string $name, string $url, array $overrides = [] ): object {
		$item = new \stdClass();
		$item->ID = $id;
		$item->name = $name;
		$item->url = $url;
		$item->description = '';
		$item->target = '';
		$item->classes = [];
		$item->current_item_ancestor = false;
		$item->current = false;
		$item->children = [];

		foreach ( $overrides as $key => $value ) {
			$item->$key = $value;
		}

		return $item;
	}

	/**
	 * Build a menu stub shaped like Timber\Menu (a WP_Term wrapper).
	 */
	private function makeMenu( array $items, array $overrides = [] ): object {
		$menu = new \stdClass();
		$menu->id = 7;
		$menu->ID = 7;
		$menu->term_id = 7;
		$menu->name = 'Footer Channels';
		$menu->slug = 'footer-channels';
		$menu->description = '';
		$menu->items = $items;

		foreach ( $overrides as $key => $value ) {
			$menu->$key = $value;
		}

		return $menu;
	}

	public function test_returns_empty_array_for_menu_without_items(): void {
		$result = Helpers::formatMenu( $this->makeMenu( [] ) );

		$this->assertSame( [], $result );
	}

	public function test_formats_item_to_the_documented_shape(): void {
		$menu = $this->makeMenu( [ $this->makeItem( 11, 'Google Ads', '/cs/google-ads-agentura/' ) ] );

		$result = Helpers::formatMenu( $menu );

		$this->assertCount( 1, $result );
		$this->assertSame( 11, $result[0]['id'] );
		$this->assertSame( 'Google Ads', $result[0]['title'] );
		$this->assertSame( '/cs/google-ads-agentura/', $result[0]['url'] );
		$this->assertSame( '', $result[0]['description'] );
		$this->assertFalse( $result[0]['is_active'] );
		$this->assertFalse( $result[0]['in_active_trail'] );
		$this->assertSame( [], $result[0]['below'] );
	}

	public function test_strips_wordpress_default_classes_but_keeps_custom_ones(): void {
		$item = $this->makeItem( 11, 'Google Ads', '/a/', [
			'classes' => [ 'menu-item', 'menu-item-type-post_type', 'current_page_item', 'page-item-12', 'brand-highlight' ],
		] );

		$result = Helpers::formatMenu( $this->makeMenu( [ $item ] ) );

		$this->assertSame( 'brand-highlight', $result[0]['attributes']['class'] );
	}

	public function test_target_is_null_when_empty(): void {
		$result = Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11, 'A', '/a/' ) ] ) );

		$this->assertNull( $result[0]['attributes']['target'] );
	}

	public function test_recurses_into_children_via_below(): void {
		$child = $this->makeItem( 22, 'Sklik', '/cs/sklik-agentura/' );
		$parent = $this->makeItem( 11, 'Channels', '/cs/kanaly/', [ 'children' => [ $child ] ] );

		$result = Helpers::formatMenu( $this->makeMenu( [ $parent ] ) );

		$this->assertCount( 1, $result[0]['below'] );
		$this->assertSame( 22, $result[0]['below'][0]['id'] );
		$this->assertSame( 'Sklik', $result[0]['below'][0]['title'] );
	}

	public function test_merges_acf_fields_onto_the_item(): void {
		Functions\when( 'get_field_objects' )->justReturn( [
			'icon' => [ 'name' => 'icon', 'value' => 'ico-ads', 'type' => 'text' ],
		] );
		// fieldFormatter() resolves a `text` field to its value.
		$result = Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11, 'A', '/a/' ) ] ) );

		$this->assertArrayHasKey( 'icon', $result[0] );
	}

	public function test_exposes_menu_metadata(): void {
		$menu = $this->makeMenu( [ $this->makeItem( 11, 'A', '/a/' ) ], [
			'name'        => 'Kanály',
			'slug'        => 'footer-kanaly',
			'description' => 'Footer column',
		] );

		$result = Helpers::formatMenu( $menu );

		$this->assertSame( 'Kanály', $result->title );
		$this->assertSame( 'Kanály', $result->name );
		$this->assertSame( 'footer-kanaly', $result->slug );
		$this->assertSame( 'Footer column', $result->description );
		$this->assertSame( 7, $result->id );
	}

	public function test_items_are_reachable_both_ways(): void {
		$menu = $this->makeMenu( [ $this->makeItem( 11, 'A', '/a/' ) ] );

		$result = Helpers::formatMenu( $menu );

		$this->assertSame( 11, $result->items[0]['id'] );
		$this->assertSame( 11, $result[0]['id'] );
	}

	public function test_below_stays_a_plain_array(): void {
		$child = $this->makeItem( 22, 'Child', '/c/' );
		$parent = $this->makeItem( 11, 'Parent', '/p/', [ 'children' => [ $child ] ] );

		$result = Helpers::formatMenu( $this->makeMenu( [ $parent ] ) );

		// Sub-levels are not menus — wrapping them would change item.below
		// for every consumer and add an object per node.
		$this->assertIsArray( $result[0]['below'] );
	}

	public function test_empty_menu_still_returns_a_plain_array(): void {
		$result = Helpers::formatMenu( $this->makeMenu( [] ) );

		$this->assertIsArray( $result );
		$this->assertFalse( (bool) $result );
	}
}
