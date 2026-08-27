<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Parisek\TimberKit\MenuFieldsCache;
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

	private int $lastTtl = -1;

	/**
	 * Raw stored meta the gate inspects, keyed by menu item id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store        = [];
		$this->itemWork = 0;
		$this->lastTtl  = -1;
		$this->meta     = [];
		Helpers::flushMenuFields();

		// The gate reads raw meta before it stores anything formatted from it.
		// Empty is the shortcode-free answer; the tests that plant a shortcode
		// fill $this->meta instead.
		Functions\when( 'get_post_meta' )->alias(
			fn ( $id, $key = '', $single = false ) => $this->meta[ (int) $id ] ?? []
		);
		Functions\when( 'get_term_meta' )->justReturn( [] );

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
			function ( $key, $value, $group = '', $expire = 0 ) {
				$this->store[ $group . '/' . $key ] = $value;
				$this->lastTtl                      = (int) $expire;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Helpers::flushMenuFields();
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
		$this->itemWork = 0;

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertGreaterThan( 0, $this->itemWork, 'A renamed page moves the links inside the menu.' );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function menuItemsCarryField( array $field, mixed $value ): void {
		Functions\when( 'acf_get_field_groups' )->justReturn( [ [ 'key' => 'g' ] ] );
		Functions\when( 'acf_get_fields' )->justReturn( [ $field ] );
		Functions\when( 'get_field' )->justReturn( $value );
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function storedPayload(): array {
		$this->assertCount( 1, $this->store, 'Expected exactly one stored entry.' );

		return (array) reset( $this->store );
	}

	public function test_the_stored_payload_carries_no_page_dependent_keys(): void {
		// The invariant the split exists for, asserted where it actually lives.
		// Asserting the rendered output cannot catch a regression here: the item
		// array is built as [...] + $acf_fields and the left operand wins, so a
		// payload carrying these keys would render correctly and poison nothing
		// until the merge operator changed.
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11, true, true ) ] ) );

		foreach ( $this->storedPayload() as $slot => $fields ) {
			$this->assertArrayNotHasKey( 'is_active', (array) $fields, "slot {$slot}" );
			$this->assertArrayNotHasKey( 'in_active_trail', (array) $fields, "slot {$slot}" );
		}
	}

	public function test_a_ttl_is_set(): void {
		// The key carries a content version, so an entry goes unreachable rather
		// than stale. The lifetime bounds accumulation of the generations it
		// leaves behind, which nothing else deletes.
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertGreaterThan( 0, $this->lastTtl );
	}

	public function test_shortcode_rendered_output_is_never_stored(): void {
		// do_shortcode() output is a function of the request, not of the stored
		// field: a callback may read the global post or the current query. It
		// serializes perfectly, which is exactly why an object check would miss
		// it.
		$this->menuItemsCarryField( [ 'key' => 'f', 'name' => 'body', 'type' => 'wysiwyg' ], '<p>x [sc]</p>' );
		Functions\when( 'do_shortcode' )->alias(
			static fn ( $v ) => str_replace( '[sc]', '<b>rendered</b>', (string) $v )
		);

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertSame( [], $this->store );
	}

	public function test_an_unregistered_shortcode_is_refused_although_nothing_changed(): void {
		// The case that decided the gate's direction. `[sc]` is not registered,
		// so do_shortcode() hands it straight back and the render is a fixed
		// point — a gate that watched the output would call this static and
		// store the literal `[sc]`. Then a plugin registers the shortcode and
		// every page serves the frozen source text instead of its output, with
		// nothing to log and nothing to notice.
		$this->menuItemsCarryField( [ 'key' => 'f', 'name' => 'body', 'type' => 'wysiwyg' ], '<p>x [sc]</p>' );
		Functions\when( 'do_shortcode' )->alias( static fn ( $v ) => $v );

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertSame( [], $this->store );
	}

	public function test_a_shortcode_acf_supplies_but_no_meta_row_holds_is_refused(): void {
		// The hole that sank the previous gate, which read raw post meta. ACF
		// does not have to read meta at all: `acf/pre_load_value` short-circuits
		// the database, `default_value` fills in for an absent row, and
		// `acf/load_value` replaces what was loaded. So a menu item whose meta
		// is provably bracket-free can still put `[contextual]` through
		// `do_shortcode()` — and the old gate would have stored that request's
		// rendered output onto every other page.
		//
		// Meta is left EMPTY on purpose. That is the whole point of the test.
		$this->meta[11] = [];
		$this->menuItemsCarryField( [ 'key' => 'f', 'name' => 'body', 'type' => 'wysiwyg' ], '[contextual]' );
		Functions\when( 'do_shortcode' )->alias( static fn ( $v ) => 'Buy now' );

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertSame( [], $this->store );
	}

	public function test_a_registered_field_formatter_filter_refuses_every_menu(): void {
		// No static proof of a formatter callback's purity is available, so one
		// registered callback ends it — for a menu whose own stored values are
		// provably shortcode-free, which is what makes the refusal visible.
		// Written into the registry directly: Brain Monkey stubs `add_filter()`
		// and never touches `$wp_filter`, which is what production reads.
		$GLOBALS['wp_filter']['field_formatter_text'] = [ 10 => [ 'cb' => [] ] ];

		try {
			Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );
		} finally {
			// A global outlives the test that set it, and the next one would
			// then measure this filter rather than its own subject.
			unset( $GLOBALS['wp_filter']['field_formatter_text'] );
		}

		$this->assertSame( [], $this->store );
	}

	public function test_a_field_holding_an_object_is_never_stored(): void {
		$this->menuItemsCarryField( [ 'key' => 'f', 'name' => 'rel', 'type' => 'relationship' ], (object) [ 'ID' => 5 ] );

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertSame( [], $this->store );
	}

	public function test_a_walk_that_throws_stores_nothing(): void {
		$calls = 0;
		Functions\when( 'wp_get_post_terms' )->alias(
			function () use ( &$calls ) {
				if ( ++$calls === 2 ) {
					throw new \RuntimeException( 'boom' );
				}
				return [ (object) [ 'term_id' => 7 ] ];
			}
		);

		try {
			Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ), $this->makeItem( 12 ) ] ) );
			$this->fail( 'The exception must escape.' );
		} catch ( \RuntimeException ) {
			// expected
		}

		$this->assertSame( [], $this->store, 'A half-finished walk must not be stored as complete.' );
	}

	public function test_a_reentrant_walk_does_not_lose_the_write(): void {
		// An inner call for the same menu used to unset the outer walk's state,
		// after which the outer walk recorded nothing and stored nothing —
		// correct output, silently no cache.
		MenuFieldsCache::open( 7 );
		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );
		$this->assertSame( [], $this->store, 'The inner close must not write.' );

		MenuFieldsCache::close( 7 );

		$this->assertCount( 1, $this->store, 'The outermost close must write what the walk recorded.' );
	}

	public function test_content_without_shortcodes_is_still_stored(): void {
		// Running do_shortcode() is not dynamism; a shortcode that changed the
		// value is. Most editor content holds none and comes back identical,
		// and treating the call itself as the signal rejected the largest menu
		// on the measurement site.
		$this->menuItemsCarryField( [ 'key' => 'f', 'name' => 'body', 'type' => 'wysiwyg' ], '<p>plain</p>' );
		Functions\when( 'do_shortcode' )->alias( static fn ( $v ) => $v );

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );

		$this->assertNotSame( [], $this->store );
	}
	public function test_stored_entries_can_be_flushed_on_demand(): void {
		// The lever an operator needs when an entry is believed wrong and the
		// next content change is too late. Without it the only option is
		// wp_cache_flush(), which empties every group on the host.
		$flushed = [];
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->alias(
			function ( $group ) use ( &$flushed ) {
				$flushed[] = $group;
				foreach ( array_keys( $this->store ) as $k ) {
					if ( str_starts_with( (string) $k, $group . '/' ) ) {
						unset( $this->store[ $k ] );
					}
				}
				return true;
			}
		);

		Helpers::formatMenu( $this->makeMenu( [ $this->makeItem( 11 ) ] ) );
		$this->assertNotSame( [], $this->store, 'nothing was stored, so the flush proves nothing' );

		$this->assertTrue( Helpers::flushStoredMenuFields() );
		$this->assertSame( [], $this->store );
		$this->assertSame( [ 'timber-kit-menu' ], $flushed );
	}

	public function test_a_backend_without_group_flush_says_so_instead_of_looking_successful(): void {
		Functions\when( 'wp_cache_supports' )->justReturn( false );

		$this->assertFalse( Helpers::flushStoredMenuFields() );
	}

}
