<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\CacheSignature;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Covers the cross-request half of a formatted menu.
 *
 * The split under test: a menu item's ACF fields are the same on every URL and
 * are stored; `is_active` and `in_active_trail` are not and are recomputed by
 * the walk. Getting that wrong does not break a page — it highlights the wrong
 * item on every page but one, which no status check can see.
 */
class FormatMenuCacheTest extends HelpersTestCase {

	/** @var array<string, mixed> */
	private array $store = [];

	private int $itemWork = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->store        = [];
		$this->itemWork = 0;
		Helpers::flushMenuFields();
		CacheSignature::flush();

		Functions\when( 'get_field_objects' )->justReturn( [] );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		// The items must travel the real nav-menu-item path, or the counter
		// below measures a branch nobody takes. getFieldObjectsByScreen()
		// checks all three ACF functions exist before it looks anything up.
		Functions\when( 'get_post_type' )->justReturn( 'nav_menu_item' );
		// The observable for "did the per-item work run". Deliberately not the
		// acf_get_field_groups() count: a sibling change memoizes that per
		// request, so it would read zero on a second render that really did
		// rebuild. This runs once per item on the miss path and is memoized by
		// nothing.
		Functions\when( 'wp_get_post_terms' )->alias(
			function () {
				++$this->itemWork;
				return [ (object) [ 'term_id' => 7 ] ];
			}
		);
		Functions\when( 'acf_get_fields' )->justReturn( [] );
		Functions\when( 'get_field' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) );
		Functions\when( 'acf_get_field_groups' )->justReturn( [] );

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_locale' )->justReturn( 'cs_CZ' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_cache_get_last_changed' )->alias(
			static fn ( string $group ) => $group . ':1'
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->alias(
			fn ( $key, $group = '' ) => $this->store[ $group . '/' . $key ] ?? false
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group = '' ) {
				$this->store[ $group . '/' . $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Helpers::flushMenuFields();
		CacheSignature::flush();
		parent::tearDown();
	}

	private function makeItem( int $id, bool $current = false, bool $ancestor = false ): object {
		$item                       = new \stdClass();
		$item->ID                   = $id;
		$item->name                 = 'Item ' . $id;
		$item->url                  = '/item-' . $id . '/';
		$item->description          = '';
		$item->target               = '';
		$item->classes              = [];
		$item->current_item_ancestor = $ancestor;
		$item->current              = $current;
		$item->children             = [];

		return $item;
	}

	/**
	 * @param array<int, object> $items
	 */
	private function makeMenu( array $items ): object {
		$menu              = new \stdClass();
		$menu->id          = 7;
		$menu->term_id     = 7;
		$menu->name        = 'Main';
		$menu->slug        = 'main';
		$menu->description = '';
		$menu->items       = $items;

		return $menu;
	}

	public function test_a_second_render_does_not_ask_acf_again(): void {
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ), $this->makeItem( 12 ) ] ) );
		$first = $this->itemWork;
		$this->assertGreaterThan( 0, $first, 'The first render must do the work.' );

		Helpers::flushMenuFields();
		$this->itemWork = 0;
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ), $this->makeItem( 12 ) ] ) );

		$this->assertSame( 0, $this->itemWork, 'The second render must be served from the payload.' );
	}

	public function test_the_active_item_is_recomputed_on_a_cache_hit(): void {
		// The whole point of the split. Render one page with nothing active,
		// then another where item 12 is — the stored payload must not carry the
		// first page's answer into the second.
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ), $this->makeItem( 12 ) ] ) );
		Helpers::flushMenuFields();

		$second = Helpers::formatMenu(
			$this->makeMenu( [ $this->makeItem( 11 ), $this->makeItem( 12, true, true ) ] )
		);

		$items = iterator_to_array( $second );
		$this->assertFalse( $items[0]['is_active'] );
		$this->assertTrue( $items[1]['is_active'], 'A cache hit must not freeze the highlighted item.' );
		$this->assertTrue( $items[1]['in_active_trail'] );
	}

	public function test_nothing_is_stored_without_a_persistent_object_cache(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertSame( [], $this->store, 'Paying for a write that can never be read is worse than not trying.' );
	}

	public function test_another_role_does_not_read_the_first_entry(): void {
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );
		Helpers::flushMenuFields();

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'roles' => [ 'editor' ] ] );
		CacheSignature::flush();
		$this->itemWork = 0;

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertGreaterThan( 0, $this->itemWork, 'A menu may hide items from anonymous visitors.' );
	}

	public function test_a_changed_post_does_not_read_the_old_entry(): void {
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );
		Helpers::flushMenuFields();

		Functions\when( 'wp_cache_get_last_changed' )->alias(
			static fn ( string $group ) => 'posts' === $group ? 'posts:2' : $group . ':1'
		);
		CacheSignature::flush();
		$this->itemWork = 0;

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertGreaterThan( 0, $this->itemWork, 'A renamed page moves the links inside the menu.' );
	}
}
