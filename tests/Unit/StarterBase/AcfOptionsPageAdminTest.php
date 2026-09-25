<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Admin-side behaviour of the ACF options pages: the menu title, the box
 * state that ACF never reads back, and the opt-in collapsed page.
 *
 * ACF renders the boxes of every options page on the screen
 * `acf_options_page`, while WordPress saves a toggle or a drag under the
 * page's own screen (`toplevel_page_settings`, …). The read key never has a
 * value, so the state is lost on every load. The tests pin the mapping that
 * repairs it, and that nothing changes while it is off.
 */
class AcfOptionsPageAdminTest extends StarterBaseTestCase {

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

	protected function tearDown(): void {
		unset( $GLOBALS['plugin_page'], $GLOBALS['submenu'] );
		parent::tearDown();
	}

	private function invokeRegisterBlockHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerBlockHooks' );
		$method->invoke( $instance );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<int, string>
	 */
	private function wiredHooks( array $overrides ): array {
		$base  = $this->createStarterBase( $overrides );
		$hooks = [];
		Functions\when( 'add_filter' )->alias( function ( $hook ) use ( &$hooks ) {
			$hooks[] = $hook;
		} );
		Functions\when( 'add_action' )->alias( function ( $hook ) use ( &$hooks ) {
			$hooks[] = $hook;
		} );

		$this->invokeRegisterBlockHooks( $base );

		return $hooks;
	}

	private function screen( string $id ): object {
		return (object) [ 'id' => $id ];
	}

	// ---- menu_title -------------------------------------------------------

	public function test_menu_title_defaults_to_page_title(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [ [ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ] ],
		] );

		$base->acf_options_page();

		$this->assertSame( 'Theme Settings', $this->top_level[0]['menu_title'] );
		$this->assertSame( 'Theme Settings', $this->top_level[0]['page_title'] );
	}

	public function test_menu_title_is_passed_through_when_declared(): void {
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'General', 'menu_title' => 'Theme Settings' ],
				[ 'menu_slug' => 'forms', 'page_title' => 'Forms', 'menu_title' => 'Forms & popups', 'parent_slug' => 'settings' ],
			],
		] );

		$base->acf_options_page();

		$this->assertSame( 'Theme Settings', $this->top_level[0]['menu_title'] );
		$this->assertSame( 'General', $this->top_level[0]['page_title'] );
		$this->assertSame( 'Forms & popups', $this->sub_pages[0]['menu_title'] );
	}

	/**
	 * WordPress copies the parent's MENU title into the first submenu entry.
	 * With a separate menu title that entry would repeat "Theme Settings"
	 * beside its siblings, so it takes the page title instead.
	 */
	public function test_first_submenu_entry_takes_the_page_title(): void {
		$GLOBALS['submenu'] = [
			'settings' => [
				[ 'Theme Settings', 'edit_posts', 'settings', 'General' ],
				[ 'Forms', 'edit_posts', 'forms', 'Forms' ],
			],
		];
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'General', 'menu_title' => 'Theme Settings' ],
				[ 'menu_slug' => 'forms', 'page_title' => 'Forms', 'parent_slug' => 'settings' ],
			],
		] );

		$base->acf_options_page_submenu_labels();

		$this->assertSame( 'General', $GLOBALS['submenu']['settings'][0][0] );
		$this->assertSame( 'Forms', $GLOBALS['submenu']['settings'][1][0] );
	}

	public function test_first_submenu_entry_is_left_alone_without_menu_title(): void {
		$GLOBALS['submenu'] = [
			'settings' => [ [ 'Theme Settings', 'edit_posts', 'settings', 'Theme Settings' ] ],
		];
		$base = $this->createStarterBase( [
			'options_pages' => [ [ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ] ],
		] );

		$base->acf_options_page_submenu_labels();

		$this->assertSame( 'Theme Settings', $GLOBALS['submenu']['settings'][0][0] );
	}

	/** Some other plugin may own the first entry; only our own slug is renamed. */
	public function test_first_submenu_entry_of_another_slug_is_left_alone(): void {
		$GLOBALS['submenu'] = [
			'settings' => [ [ 'Something else', 'edit_posts', 'other', 'Something else' ] ],
		];
		$base = $this->createStarterBase( [
			'options_pages' => [ [ 'menu_slug' => 'settings', 'page_title' => 'General', 'menu_title' => 'Theme Settings' ] ],
		] );

		$base->acf_options_page_submenu_labels();

		$this->assertSame( 'Something else', $GLOBALS['submenu']['settings'][0][0] );
	}

	public function test_admin_bar_node_uses_the_menu_title(): void {
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/wp-admin/admin.php?page=settings' );
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'General', 'menu_title' => 'Theme Settings', 'admin_bar' => true ],
			],
		] );
		$bar = new class {
			/** @var array<int, array<string, mixed>> */
			public array $nodes = [];

			/** @param array<string, mixed> $node */
			public function add_node( array $node ): void {
				$this->nodes[] = $node;
			}
		};

		$base->admin_bar_menu( $bar );

		$this->assertSame( 'Theme Settings', $bar->nodes[0]['title'] );
	}

	// ---- wiring -----------------------------------------------------------

	/** The default: nothing new is wired, so every existing consumer is unaffected. */
	public function test_nothing_is_wired_by_default(): void {
		$hooks = $this->wiredHooks( [
			'options_pages' => [ [ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ] ],
		] );

		$this->assertNotContains( 'get_user_option_closedpostboxes_acf_options_page', $hooks );
		$this->assertNotContains( 'get_user_option_meta-box-order_acf_options_page', $hooks );
		$this->assertNotContains( 'admin_menu', $hooks );
	}

	public function test_box_state_flag_wires_both_read_filters(): void {
		$hooks = $this->wiredHooks( [ 'acf_options_page_box_state' => true ] );

		$this->assertContains( 'get_user_option_closedpostboxes_acf_options_page', $hooks );
		$this->assertContains( 'get_user_option_meta-box-order_acf_options_page', $hooks );
	}

	public function test_collapsed_entry_wires_the_closed_filter_only(): void {
		$hooks = $this->wiredHooks( [
			'options_pages' => [ [ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'collapsed' => true ] ],
		] );

		$this->assertContains( 'get_user_option_closedpostboxes_acf_options_page', $hooks );
		$this->assertNotContains( 'get_user_option_meta-box-order_acf_options_page', $hooks );
	}

	public function test_menu_title_wires_the_submenu_rename(): void {
		$hooks = $this->wiredHooks( [
			'options_pages' => [ [ 'menu_slug' => 'settings', 'page_title' => 'General', 'menu_title' => 'Theme Settings' ] ],
		] );

		$this->assertContains( 'admin_menu', $hooks );
	}

	// ---- box state --------------------------------------------------------

	public function test_closed_boxes_are_read_from_the_page_screen(): void {
		$GLOBALS['plugin_page'] = 'settings';
		Functions\when( 'get_current_screen' )->justReturn( $this->screen( 'toplevel_page_settings' ) );
		Functions\expect( 'get_user_option' )
			->once()
			->with( 'closedpostboxes_toplevel_page_settings', 7 )
			->andReturn( [ 'acf-group_a' ] );
		$base = $this->createStarterBase( [ 'acf_options_page_box_state' => true ] );

		$result = $base->acf_options_page_closed_boxes( false, 'closedpostboxes_acf_options_page', $this->user( 7 ) );

		$this->assertSame( [ 'acf-group_a' ], $result );
	}

	public function test_box_order_is_read_from_the_page_screen(): void {
		$GLOBALS['plugin_page'] = 'forms';
		Functions\when( 'get_current_screen' )->justReturn( $this->screen( 'theme-settings_page_forms' ) );
		Functions\expect( 'get_user_option' )
			->once()
			->with( 'meta-box-order_theme-settings_page_forms', 7 )
			->andReturn( [ 'normal' => 'acf-group_b,acf-group_a' ] );
		$base = $this->createStarterBase( [ 'acf_options_page_box_state' => true ] );

		$result = $base->acf_options_page_box_order( false, 'meta-box-order_acf_options_page', $this->user( 7 ) );

		$this->assertSame( [ 'normal' => 'acf-group_b,acf-group_a' ], $result );
	}

	/** A value stored under the ACF key itself is never overridden. */
	public function test_a_stored_value_under_the_acf_key_wins(): void {
		Functions\when( 'get_current_screen' )->justReturn( $this->screen( 'toplevel_page_settings' ) );
		Functions\expect( 'get_user_option' )->never();
		$base = $this->createStarterBase( [ 'acf_options_page_box_state' => true ] );

		$this->assertSame( [ 'x' ], $base->acf_options_page_closed_boxes( [ 'x' ], 'closedpostboxes_acf_options_page', $this->user( 7 ) ) );
	}

	/** Outside a screen (CLI, REST, early hooks) the filter returns what it got. */
	public function test_without_a_screen_the_value_passes_through(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\expect( 'get_user_option' )->never();
		$base = $this->createStarterBase( [ 'acf_options_page_box_state' => true ] );

		$this->assertFalse( $base->acf_options_page_closed_boxes( false, 'closedpostboxes_acf_options_page', $this->user( 7 ) ) );
		$this->assertFalse( $base->acf_options_page_box_order( false, 'meta-box-order_acf_options_page', $this->user( 7 ) ) );
	}

	/** A collapsed entry alone does not switch the mapping on. */
	public function test_mapping_stays_off_without_the_flag(): void {
		$GLOBALS['plugin_page'] = 'settings';
		Functions\when( 'get_current_screen' )->justReturn( $this->screen( 'toplevel_page_settings' ) );
		Functions\expect( 'get_user_option' )->never();
		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ],
				[ 'menu_slug' => 'forms', 'page_title' => 'Forms', 'parent_slug' => 'settings', 'collapsed' => true ],
			],
		] );

		$this->assertFalse( $base->acf_options_page_closed_boxes( false, 'closedpostboxes_acf_options_page', $this->user( 7 ) ) );
	}

	// ---- collapsed --------------------------------------------------------

	public function test_collapsed_page_returns_every_box_as_closed(): void {
		$GLOBALS['plugin_page'] = 'forms';
		Functions\when( 'get_current_screen' )->justReturn( $this->screen( 'theme-settings_page_forms' ) );
		Functions\expect( 'acf_get_field_groups' )
			->once()
			->with( [ 'options_page' => 'forms' ] )
			->andReturn( [ [ 'key' => 'group_a' ], [ 'key' => 'group_b' ] ] );
		Functions\expect( 'get_user_option' )->never();
		$base = $this->createStarterBase( [
			'acf_options_page_box_state' => true,
			'options_pages'              => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ],
				[ 'menu_slug' => 'forms', 'page_title' => 'Forms', 'parent_slug' => 'settings', 'collapsed' => true ],
			],
		] );

		$result = $base->acf_options_page_closed_boxes( [ 'acf-group_a' ], 'closedpostboxes_acf_options_page', $this->user( 7 ) );

		$this->assertSame( [ 'acf-group_a', 'acf-group_b' ], $result );
	}

	public function test_collapsed_on_another_page_does_not_touch_this_one(): void {
		$GLOBALS['plugin_page'] = 'settings';
		Functions\when( 'get_current_screen' )->justReturn( $this->screen( 'toplevel_page_settings' ) );
		Functions\expect( 'acf_get_field_groups' )->never();
		Functions\when( 'get_user_option' )->justReturn( [ 'acf-group_c' ] );
		$base = $this->createStarterBase( [
			'acf_options_page_box_state' => true,
			'options_pages'              => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ],
				[ 'menu_slug' => 'forms', 'page_title' => 'Forms', 'parent_slug' => 'settings', 'collapsed' => true ],
			],
		] );

		$this->assertSame( [ 'acf-group_c' ], $base->acf_options_page_closed_boxes( false, 'closedpostboxes_acf_options_page', $this->user( 7 ) ) );
	}

	private function user( int $id ): \WP_User {
		$user     = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
		$user->ID = $id;
		return $user;
	}
}
