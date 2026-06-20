<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class CleanupMethodsTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	// cleanup_wp_head — 9 remove_action calls

	public function test_cleanup_wp_head_removes_actions(): void {
		$removed = [];
		Functions\when( 'remove_action' )->alias( function ( ...$args ) use ( &$removed ) {
			$removed[] = $args;
		} );

		$this->base->cleanup_wp_head();

		$this->assertCount( 9, $removed );
	}

	// disable_emojis

	public function test_disable_emojis_removes_hooks(): void {
		$removed_actions = [];
		$removed_filters = [];
		Functions\when( 'remove_action' )->alias( function ( ...$args ) use ( &$removed_actions ) {
			$removed_actions[] = $args;
		} );
		Functions\when( 'remove_filter' )->alias( function ( ...$args ) use ( &$removed_filters ) {
			$removed_filters[] = $args;
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->base->disable_emojis();

		$this->assertCount( 4, $removed_actions );
		$this->assertCount( 3, $removed_filters );
	}

	// disable_feeds

	public function test_disable_feeds_registers_handlers(): void {
		$actions_added = [];
		Functions\when( 'add_action' )->alias( function ( ...$args ) use ( &$actions_added ) {
			$actions_added[] = $args[0];
		} );
		Functions\when( 'remove_action' )->justReturn( true );

		$this->base->disable_feeds();

		$this->assertContains( 'do_feed', $actions_added );
		$this->assertContains( 'do_feed_rss2', $actions_added );
		$this->assertContains( 'do_feed_atom', $actions_added );
	}

	// disable_comments

	public function test_disable_comments_removes_support_from_all_post_types(): void {
		Functions\when( 'get_post_types' )->justReturn( [ 'post', 'page', 'custom_type' ] );
		Functions\when( 'post_type_supports' )->alias( function ( $type, $feature ) {
			// Simulate that post and custom_type support comments, page supports trackbacks
			if ( $feature === 'comments' ) {
				return in_array( $type, [ 'post', 'custom_type' ], true );
			}
			if ( $feature === 'trackbacks' ) {
				return in_array( $type, [ 'post', 'page' ], true );
			}
			return false;
		} );

		$removed = [];
		Functions\when( 'remove_post_type_support' )->alias( function ( $type, $feature ) use ( &$removed ) {
			$removed[] = [ $type, $feature ];
		} );
		Functions\when( 'unregister_widget' )->justReturn( true );

		$this->base->disable_comments();

		$this->assertContains( [ 'post', 'comments' ], $removed );
		$this->assertContains( [ 'custom_type', 'comments' ], $removed );
		$this->assertContains( [ 'post', 'trackbacks' ], $removed );
		$this->assertContains( [ 'page', 'trackbacks' ], $removed );
	}

	public function test_disable_comments_unregisters_recent_comments_widget(): void {
		Functions\when( 'get_post_types' )->justReturn( [] );
		Functions\when( 'post_type_supports' )->justReturn( false );

		$unregistered = [];
		Functions\when( 'unregister_widget' )->alias( function ( $widget ) use ( &$unregistered ) {
			$unregistered[] = $widget;
		} );

		$this->base->disable_comments();

		$this->assertContains( 'WP_Widget_Recent_Comments', $unregistered );
	}

	// disable_comments_admin_menu

	public function test_disable_comments_admin_menu_removes_pages(): void {
		$removed_menus = [];
		$removed_submenus = [];
		Functions\when( 'remove_menu_page' )->alias( function ( $page ) use ( &$removed_menus ) {
			$removed_menus[] = $page;
		} );
		Functions\when( 'remove_submenu_page' )->alias( function ( $parent, $page ) use ( &$removed_submenus ) {
			$removed_submenus[] = [ $parent, $page ];
		} );

		$this->base->disable_comments_admin_menu();

		$this->assertContains( 'edit-comments.php', $removed_menus );
		$this->assertContains( [ 'options-general.php', 'options-discussion.php' ], $removed_submenus );
	}

	// disable_comments_admin_redirect

	public function test_disable_comments_admin_redirect(): void {
		// `exit;` is a language construct and cannot be caught. Make the mocked
		// `wp_safe_redirect` throw so the production code short-circuits before
		// reaching `exit;`, letting the test continue.
		$redirected_to = null;
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'wp_safe_redirect' )->alias( function ( $url ) use ( &$redirected_to ) {
			$redirected_to = $url;
			throw new \RuntimeException( 'short-circuit before exit;' );
		} );

		try {
			$this->base->disable_comments_admin_redirect();
			$this->fail( 'wp_safe_redirect mock should have thrown to prevent exit;' );
		} catch ( \RuntimeException $e ) {
			// Expected — prevents `exit;` from terminating the test runner.
		}

		$this->assertSame( 'https://example.com/wp-admin/', $redirected_to );
	}

	// disable_comments_dequeue_scripts

	public function test_disable_comments_dequeue_scripts(): void {
		$dequeued = [];
		Functions\when( 'wp_dequeue_script' )->alias( function ( $handle ) use ( &$dequeued ) {
			$dequeued[] = $handle;
		} );

		$this->base->disable_comments_dequeue_scripts();

		$this->assertContains( 'comment-reply', $dequeued );
	}

	// disable_comments_rest_pre_dispatch

	private function makeRestRequest( string $route, $type = null ): object {
		return new class( $route, $type ) {
			public function __construct( private string $route, private $type ) {}
			public function get_route(): string { return $this->route; }
			public function get_param( string $key ) {
				return $key === 'type' ? $this->type : null;
			}
		};
	}

	public function test_disable_comments_rest_pre_dispatch_blocks_standard_comments_request(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		$req = $this->makeRestRequest( '/wp/v2/comments', null );

		$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
		$this->assertSame( [ 'status' => 404 ], $result->error_data['rest_no_route'] );
	}

	public function test_disable_comments_rest_pre_dispatch_blocks_explicit_comment_type(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		$req = $this->makeRestRequest( '/wp/v2/comments', 'comment' );

		$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_disable_comments_rest_pre_dispatch_blocks_pingback_and_trackback(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		foreach ( [ 'pingback', 'trackback' ] as $blocked ) {
			$req = $this->makeRestRequest( '/wp/v2/comments', $blocked );
			$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );
			$this->assertInstanceOf( \WP_Error::class, $result, "type=$blocked must be blocked" );
		}
	}

	public function test_disable_comments_rest_pre_dispatch_passes_through_note_type_for_wp_69_editor(): void {
		// WordPress 6.9+ editor sidebar fetches `?type=note` to populate
		// the post-notes panel. Stripping the route silently broke that
		// UI; the dispatch filter must let those requests through.
		$req = $this->makeRestRequest( '/wp/v2/comments', 'note' );

		$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );

		$this->assertNull( $result, 'type=note must pass through unchanged' );
	}

	public function test_disable_comments_rest_pre_dispatch_passes_through_plugin_specific_types(): void {
		// WooCommerce, editorial workflow plugins, etc. use other
		// non-standard `comment_type` values. None of them are the
		// public-spam vector this flag exists to block.
		foreach ( [ 'review', 'order_note', 'editorial-comment' ] as $custom ) {
			$req = $this->makeRestRequest( '/wp/v2/comments', $custom );
			$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );
			$this->assertNull( $result, "type=$custom must pass through unchanged" );
		}
	}

	public function test_disable_comments_rest_pre_dispatch_ignores_non_comment_routes(): void {
		$req = $this->makeRestRequest( '/wp/v2/posts', null );

		$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );

		$this->assertNull( $result, '/wp/v2/posts must not be touched by the comments filter' );
	}

	public function test_disable_comments_rest_pre_dispatch_passes_through_single_item_routes(): void {
		// Regression guard for the Copilot review pass — `/wp/v2/comments/<id>`
		// (read/update/delete by id) usually carries no `type` query param,
		// so a `starts_with` filter would 404 those id-scoped operations
		// unconditionally and break WP 6.9 editor's note delete/update flow.
		// Only the bare collection route is the public spam surface.
		foreach ( [
			'/wp/v2/comments/42',
			'/wp/v2/comments/42/edit-link',
			'/wp/v2/comments/some-slug',
		] as $route ) {
			$req = $this->makeRestRequest( $route, null );
			$result = $this->base->disable_comments_rest_pre_dispatch( null, null, $req );
			$this->assertNull( $result, "$route must pass through (single-item route, not the collection)" );
		}
	}

	public function test_disable_comments_rest_pre_dispatch_respects_prior_short_circuit(): void {
		// If another `rest_pre_dispatch` filter already returned a result
		// (response or WP_Error), we must not overwrite it.
		$priorResponse = new \WP_Error( 'other_filter', 'taken' );
		$req = $this->makeRestRequest( '/wp/v2/comments', null );

		$result = $this->base->disable_comments_rest_pre_dispatch( $priorResponse, null, $req );

		$this->assertSame( $priorResponse, $result );
	}

	// disable_comments_xmlrpc_methods

	public function test_disable_comments_xmlrpc_methods_removes_comment_and_pingback_methods(): void {
		$methods = [
			'wp.getCommentCount'              => 'handler',
			'wp.getComment'                   => 'handler',
			'wp.getComments'                  => 'handler',
			'wp.newComment'                   => 'handler',
			'wp.editComment'                  => 'handler',
			'wp.deleteComment'                => 'handler',
			'wp.getCommentStatusList'         => 'handler',
			'pingback.ping'                   => 'handler',
			'pingback.extensions.getPingbacks' => 'handler',
			'wp.getUsersBlogs'                => 'handler',
			'wp.getPost'                      => 'handler',
		];

		$filtered = $this->base->disable_comments_xmlrpc_methods( $methods );

		$this->assertArrayNotHasKey( 'wp.getCommentCount', $filtered );
		$this->assertArrayNotHasKey( 'wp.getComment', $filtered );
		$this->assertArrayNotHasKey( 'wp.getComments', $filtered );
		$this->assertArrayNotHasKey( 'wp.newComment', $filtered );
		$this->assertArrayNotHasKey( 'wp.editComment', $filtered );
		$this->assertArrayNotHasKey( 'wp.deleteComment', $filtered );
		$this->assertArrayNotHasKey( 'wp.getCommentStatusList', $filtered );
		$this->assertArrayNotHasKey( 'pingback.ping', $filtered );
		$this->assertArrayNotHasKey( 'pingback.extensions.getPingbacks', $filtered );

		// Non-comment methods must be preserved.
		$this->assertArrayHasKey( 'wp.getUsersBlogs', $filtered );
		$this->assertArrayHasKey( 'wp.getPost', $filtered );
	}

	public function test_disable_comments_xmlrpc_methods_is_safe_on_empty_input(): void {
		$this->assertSame( [], $this->base->disable_comments_xmlrpc_methods( [] ) );
	}

	// disable_comments_for_post_type

	public function test_disable_comments_for_post_type_removes_support_when_present(): void {
		$removed = [];
		Functions\when( 'post_type_supports' )->alias( function ( $type, $feature ) {
			return in_array( $feature, [ 'comments', 'trackbacks' ], true );
		} );
		Functions\when( 'remove_post_type_support' )->alias( function ( $type, $feature ) use ( &$removed ) {
			$removed[] = [ $type, $feature ];
		} );

		$this->base->disable_comments_for_post_type( 'custom_late_cpt' );

		$this->assertContains( [ 'custom_late_cpt', 'comments' ], $removed );
		$this->assertContains( [ 'custom_late_cpt', 'trackbacks' ], $removed );
	}

	public function test_disable_comments_for_post_type_skips_when_support_absent(): void {
		$removed = [];
		Functions\when( 'post_type_supports' )->justReturn( false );
		Functions\when( 'remove_post_type_support' )->alias( function ( $type, $feature ) use ( &$removed ) {
			$removed[] = [ $type, $feature ];
		} );

		$this->base->disable_comments_for_post_type( 'no_comments_cpt' );

		$this->assertEmpty( $removed );
	}

	// disable_comments_rest_insertion

	public function test_disable_comments_rest_insertion_blocks_when_no_comment_type_set(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = $this->base->disable_comments_rest_insertion( [ 'comment_content' => 'spam' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_comment_closed', $result->get_error_code() );
		$this->assertSame( [ 'status' => 403 ], $result->error_data['rest_comment_closed'] );
	}

	public function test_disable_comments_rest_insertion_blocks_explicit_standard_types(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );

		foreach ( [ 'comment', 'pingback', 'trackback' ] as $blocked ) {
			$result = $this->base->disable_comments_rest_insertion( [
				'comment_type'    => $blocked,
				'comment_content' => 'spam',
			] );
			$this->assertInstanceOf( \WP_Error::class, $result, "comment_type=$blocked must be blocked" );
		}
	}

	public function test_disable_comments_rest_insertion_passes_through_note_type(): void {
		// WP 6.9+ editor notes are POSTed with comment_type=note. They
		// must reach the controller so the editor's notes feature works
		// even when public comments are disabled.
		$prepared = [
			'comment_type'    => 'note',
			'comment_content' => 'Internal editor note',
			'comment_post_ID' => 42,
		];

		$result = $this->base->disable_comments_rest_insertion( $prepared );

		$this->assertSame( $prepared, $result );
	}

	public function test_disable_comments_rest_insertion_passes_through_plugin_specific_types(): void {
		foreach ( [ 'review', 'order_note', 'editorial-comment' ] as $custom ) {
			$prepared = [ 'comment_type' => $custom, 'comment_content' => 'x' ];
			$result   = $this->base->disable_comments_rest_insertion( $prepared );
			$this->assertSame( $prepared, $result, "comment_type=$custom must pass through" );
		}
	}

	public function test_disable_comments_rest_insertion_handles_object_payload(): void {
		// Defensive: real WordPress passes an array, but plugins
		// occasionally hydrate the prepared comment as an object before
		// the filter chain runs.
		$prepared = (object) [ 'comment_type' => 'note', 'comment_content' => 'x' ];

		$result = $this->base->disable_comments_rest_insertion( $prepared );

		$this->assertSame( $prepared, $result );
	}

	public function test_disable_comments_rest_insertion_preserves_prior_wp_error(): void {
		// Regression guard for the Copilot review pass — if an earlier
		// `rest_pre_insert_comment` filter (anti-spam, custom validation,
		// etc.) already returned a WP_Error, we must not overwrite it
		// with our generic `rest_comment_closed` and mask the real
		// failure reason.
		$priorError = new \WP_Error( 'akismet_spam', 'Looks like spam.' );

		$result = $this->base->disable_comments_rest_insertion( $priorError );

		$this->assertSame( $priorError, $result );
	}

	public function test_disable_comments_rest_insertion_preserves_prior_null(): void {
		// Same defensive pattern: `null` is also a valid short-circuit
		// signal another filter can use to abort insertion silently.
		$result = $this->base->disable_comments_rest_insertion( null );

		$this->assertNull( $result );
	}

	// remove_global_styles_and_svg_filters

	public function test_remove_global_styles(): void {
		$removed = [];
		Functions\when( 'remove_action' )->alias( function ( $hook, $callback ) use ( &$removed ) {
			$removed[] = [ $hook, $callback ];
		} );

		$this->base->remove_global_styles_and_svg_filters();

		$this->assertCount( 1, $removed );
		$this->assertSame( 'wp_footer', $removed[0][0] );
		$this->assertSame( 'wp_enqueue_global_styles', $removed[0][1] );
	}

	// cleanup_dashboard_widgets

	public function test_cleanup_dashboard_widgets(): void {
		$GLOBALS['wp_meta_boxes'] = [
			'dashboard' => [
				'normal' => [
					'core' => [
						'dashboard_activity' => true,
						'dashboard_incoming_links' => true,
						'dashboard_right_now' => true,
						'dashboard_plugins' => true,
						'dashboard_recent_drafts' => true,
						'dashboard_recent_comments' => true,
					],
				],
				'side' => [
					'core' => [
						'dashboard_primary' => true,
						'dashboard_secondary' => true,
						'dashboard_quick_press' => true,
					],
					'high' => [
						'loginlockdown_dashboard_widget' => true,
					],
				],
			],
		];

		$this->base->cleanup_dashboard_widgets();

		$this->assertEmpty( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] );
		$this->assertEmpty( $GLOBALS['wp_meta_boxes']['dashboard']['side']['core'] );
		$this->assertEmpty( $GLOBALS['wp_meta_boxes']['dashboard']['side']['high'] );
	}

	// cleanup_dashboard_menu

	public function test_cleanup_dashboard_menu_removes_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$removed = [];
		Functions\when( 'remove_menu_page' )->alias( function ( $page ) use ( &$removed ) {
			$removed[] = $page;
		} );

		$this->base->cleanup_dashboard_menu();

		$this->assertContains( 'index.php', $removed );
	}

	public function test_cleanup_dashboard_menu_keeps_for_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$removed = [];
		Functions\when( 'remove_menu_page' )->alias( function ( $page ) use ( &$removed ) {
			$removed[] = $page;
		} );

		$this->base->cleanup_dashboard_menu();

		$this->assertEmpty( $removed );
	}

	// cleanup_admin_bar_items

	public function test_cleanup_admin_bar_items(): void {
		$removed = [];
		$bar = new \stdClass();
		$bar->removed = [];
		// Use a closure-based approach
		$tracker = new \ArrayObject();
		$bar = new class {
			public array $removed = [];

			public function remove_node( string $id ): void {
				$this->removed[] = $id;
			}
		};

		$this->base->cleanup_admin_bar_items( $bar );

		$this->assertContains( 'updates', $bar->removed );
		$this->assertContains( 'comments', $bar->removed );
	}

	// admin_bar_menu

	public function test_admin_bar_menu_adds_settings_node(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'admin_url' )->alias( fn( $s ) => 'https://example.com/wp-admin/' . $s );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$bar = new class {
			public array $added = [];

			public function add_node( array $args ): void {
				$this->added[] = $args;
			}
		};

		$this->base->admin_bar_menu( $bar );

		$this->assertCount( 1, $bar->added );
		$this->assertSame( 'site-name', $bar->added[0]['parent'] );
		$this->assertSame( 'theme-settings-settings', $bar->added[0]['id'] );
		$this->assertStringContainsString( 'settings', $bar->added[0]['href'] );
	}

	public function test_admin_bar_menu_skips_pages_without_flag(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'admin_url' )->alias( fn( $s ) => 'https://example.com/wp-admin/' . $s );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings' ],
			],
		] );

		$bar = new class {
			public array $added = [];

			public function add_node( array $args ): void {
				$this->added[] = $args;
			}
		};

		$base->admin_bar_menu( $bar );

		$this->assertCount( 0, $bar->added );
	}

	public function test_admin_bar_menu_adds_multiple_nodes(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'admin_url' )->alias( fn( $s ) => 'https://example.com/wp-admin/' . $s );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$base = $this->createStarterBase( [
			'options_pages' => [
				[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'admin_bar' => true ],
				[ 'menu_slug' => 'dev', 'page_title' => 'Dev Settings', 'admin_bar' => true ],
			],
		] );

		$bar = new class {
			public array $added = [];

			public function add_node( array $args ): void {
				$this->added[] = $args;
			}
		};

		$base->admin_bar_menu( $bar );

		$this->assertCount( 2, $bar->added );
		$this->assertSame( 'theme-settings-settings', $bar->added[0]['id'] );
		$this->assertSame( 'theme-settings-dev', $bar->added[1]['id'] );
	}

	// hide_core_update_notifications

	public function test_hide_update_nag_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$removed = [];
		Functions\when( 'remove_action' )->alias( function ( ...$args ) use ( &$removed ) {
			$removed[] = $args;
		} );

		$this->base->hide_core_update_notifications();

		$this->assertCount( 1, $removed );
		$this->assertSame( 'admin_notices', $removed[0][0] );
	}

	public function test_keeps_update_nag_for_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$removed = [];
		Functions\when( 'remove_action' )->alias( function ( ...$args ) use ( &$removed ) {
			$removed[] = $args;
		} );

		$this->base->hide_core_update_notifications();

		$this->assertEmpty( $removed );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_meta_boxes'] );
		parent::tearDown();
	}
}
