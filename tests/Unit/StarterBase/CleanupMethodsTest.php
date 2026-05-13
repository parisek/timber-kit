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

	// disable_comments_rest_endpoints

	public function test_disable_comments_rest_endpoints_removes_comment_routes(): void {
		$endpoints = [
			'/wp/v2/comments'                  => 'handler-a',
			'/wp/v2/comments/(?P<id>[\d]+)'    => 'handler-b',
			'/wp/v2/posts'                     => 'handler-c',
			'/wp/v2/users'                     => 'handler-d',
		];

		$filtered = $this->base->disable_comments_rest_endpoints( $endpoints );

		$this->assertArrayNotHasKey( '/wp/v2/comments', $filtered );
		$this->assertArrayNotHasKey( '/wp/v2/comments/(?P<id>[\d]+)', $filtered );
		$this->assertArrayHasKey( '/wp/v2/posts', $filtered );
		$this->assertArrayHasKey( '/wp/v2/users', $filtered );
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

		$bar = new class {
			public array $added = [];

			public function add_node( array $args ): void {
				$this->added[] = $args;
			}
		};

		$this->base->admin_bar_menu( $bar );

		$this->assertCount( 1, $bar->added );
		$this->assertSame( 'site-name', $bar->added[0]['parent'] );
		$this->assertSame( 'theme-settings', $bar->added[0]['id'] );
		$this->assertStringContainsString( 'settings', $bar->added[0]['href'] );
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
