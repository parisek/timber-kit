<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\ServerHeaders;

/**
 * Breeze pings the site's own home URL, saves every header it sees under
 * `breeze_custom_headers`, and replays that list on each cache hit. A header
 * the web server already sends then goes out twice. The class removes the
 * server's headers from the list and rebuilds a config file that still holds
 * them.
 */
class ServerHeadersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		ServerHeaders::reset_for_tests();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
	}

	protected function tearDown(): void {
		ServerHeaders::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_filter_removes_the_server_headers_and_keeps_the_rest(): void {
		$allowed = array( 'content-security-policy', 'x-frame-options', 'Permissions-Policy', 'Access-Control-Allow-Origin' );

		$this->assertSame(
			array( 'Permissions-Policy', 'Access-Control-Allow-Origin' ),
			ServerHeaders::filter_allowed( $allowed, array( 'Content-Security-Policy', 'X-Frame-Options' ) )
		);
	}

	public function test_filter_compares_names_without_regard_to_case(): void {
		$this->assertSame(
			array(),
			ServerHeaders::filter_allowed( array( 'X-FRAME-OPTIONS' ), array( 'x-frame-options' ) )
		);
	}

	public function test_filter_returns_a_list_even_for_a_non_array_input(): void {
		$this->assertSame( array(), ServerHeaders::filter_allowed( null, array( 'x-frame-options' ) ) );
	}

	public function test_fingerprint_ignores_order_and_case(): void {
		$this->assertSame(
			ServerHeaders::fingerprint( array( 'X-Frame-Options', 'referrer-policy' ) ),
			ServerHeaders::fingerprint( array( 'Referrer-Policy', 'x-frame-options' ) )
		);
		$this->assertNotSame(
			ServerHeaders::fingerprint( array( 'x-frame-options' ) ),
			ServerHeaders::fingerprint( array( 'x-frame-options', 'referrer-policy' ) )
		);
	}

	public function test_config_lists_a_header_that_is_stored(): void {
		$config = "<?php\nreturn array (\n  'breeze_custom_headers' => \n  array (\n    'x-frame-options' => 'SAMEORIGIN',\n    'permissions-policy' => 'x',\n  ),\n);\n";

		$this->assertTrue( ServerHeaders::config_lists_headers( $config, array( 'X-Frame-Options' ) ) );
		$this->assertFalse( ServerHeaders::config_lists_headers( $config, array( 'referrer-policy' ) ) );
	}

	public function test_config_lists_ignores_the_same_name_outside_the_headers_block(): void {
		$config = "<?php\nreturn array (\n  'x-frame-options' => 'elsewhere',\n  'breeze_custom_headers' => \n  array (\n    'permissions-policy' => 'x',\n  ),\n);\n";

		$this->assertFalse( ServerHeaders::config_lists_headers( $config, array( 'x-frame-options' ) ) );
	}

	public function test_register_wires_the_filter_and_the_init_check(): void {
		$calls = array();
		Functions\when( 'add_filter' )->alias(
			function ( $tag ) use ( &$calls ) {
				$calls[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $tag ) use ( &$calls ) {
				$calls[] = $tag;
				return true;
			}
		);

		ServerHeaders::register( array( 'x-frame-options' ) );

		$this->assertContains( 'breeze_custom_headers_allow', $calls );
		$this->assertContains( 'init', $calls );
	}

	public function test_check_does_nothing_when_the_stored_fingerprint_matches(): void {
		$names = array( 'x-frame-options' );
		Functions\when( 'get_option' )->justReturn( ServerHeaders::fingerprint( $names ) );
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'add_action' )->never();

		ServerHeaders::maybe_schedule_rebuild( $names );
		$this->addToAssertionCount( 1 );
	}

	public function test_check_schedules_one_rebuild_when_the_fingerprint_differs(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();
		$tags = array();
		Functions\when( 'add_action' )->alias(
			function ( $tag ) use ( &$tags ) {
				$tags[] = $tag;
				return true;
			}
		);

		ServerHeaders::maybe_schedule_rebuild( array( 'x-frame-options' ) );

		$this->assertSame( array( 'shutdown' ), $tags );
	}

	public function test_check_waits_while_a_rebuild_lock_is_held(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\expect( 'add_action' )->never();

		ServerHeaders::maybe_schedule_rebuild( array( 'x-frame-options' ) );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Builds a content dir whose "rebuilt" config holds the given header names.
	 *
	 * @param string[] $stored Names Breeze_ConfigCache will write.
	 */
	private function fakeBreeze( array $stored, bool $writes = true ): void {
		$dir = WP_CONTENT_DIR . '/tk-test-' . uniqid();
		mkdir( $dir, 0777, true );
		if ( ! is_dir( WP_CONTENT_DIR . '/breeze-config' ) ) {
			mkdir( WP_CONTENT_DIR . '/breeze-config', 0777, true );
		}
		@unlink( WP_CONTENT_DIR . '/breeze-config/breeze-config.php' );
		$GLOBALS['tk_breeze_stored'] = $stored;
		$GLOBALS['tk_breeze_writes'] = $writes;

		$class = <<<'PHP'
<?php
class Breeze_ConfigCache {
	public static function write_config_cache() {
		if ( ! $GLOBALS['tk_breeze_writes'] ) {
			return;
		}
		$rows = '';
		foreach ( $GLOBALS['tk_breeze_stored'] as $name ) {
			$rows .= "    '" . $name . "' => 'v',\n";
		}
		$php = "<?php\nreturn array (\n  'breeze_custom_headers' => \n  array (\n" . $rows . "  ),\n);\n";
		file_put_contents( WP_CONTENT_DIR . '/breeze-config/breeze-config.php', $php );
	}
}
PHP;
		file_put_contents( $dir . '/fake-breeze.php', $class );
		require $dir . '/fake-breeze.php';
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_rebuild_records_the_list_once_the_config_is_clean(): void {
		$this->fakeBreeze( array( 'permissions-policy' ) );
		$saved = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		ServerHeaders::rebuild( array( 'x-frame-options' ) );

		$this->assertSame( array( ServerHeaders::OPTION, ServerHeaders::fingerprint( array( 'x-frame-options' ) ) ), $saved );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_rebuild_leaves_the_option_unset_while_the_config_still_holds_a_header(): void {
		$this->fakeBreeze( array( 'x-frame-options' ) );
		Functions\expect( 'update_option' )->never();

		ServerHeaders::rebuild( array( 'x-frame-options' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_check_skips_admin_requests(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'add_action' )->never();

		ServerHeaders::maybe_schedule_rebuild( array( 'x-frame-options' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_check_skips_ajax_requests(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'add_action' )->never();

		ServerHeaders::maybe_schedule_rebuild( array( 'x-frame-options' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_an_empty_list_restores_the_config_when_a_fingerprint_was_stored(): void {
		Functions\when( 'get_option' )->justReturn( 'abc' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();
		$tags = array();
		Functions\when( 'add_action' )->alias(
			function ( $tag ) use ( &$tags ) {
				$tags[] = $tag;
				return true;
			}
		);

		ServerHeaders::maybe_schedule_rebuild( array() );

		$this->assertSame( array( 'shutdown' ), $tags );
	}

	public function test_an_empty_list_with_nothing_stored_does_nothing(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'add_action' )->never();

		ServerHeaders::maybe_schedule_rebuild( array() );
		$this->addToAssertionCount( 1 );
	}

	public function test_register_with_no_names_still_wires_the_restore_check(): void {
		$calls = array();
		Functions\when( 'add_filter' )->alias(
			function ( $tag ) use ( &$calls ) {
				$calls[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $tag ) use ( &$calls ) {
				$calls[] = $tag;
				return true;
			}
		);

		ServerHeaders::register( array() );

		$this->assertSame( array( 'init' ), $calls );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_rebuild_with_an_empty_list_deletes_the_stored_fingerprint(): void {
		$this->fakeBreeze( array( 'x-frame-options' ) );
		$deleted = null;
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$deleted ) {
				$deleted = $key;
				return true;
			}
		);

		ServerHeaders::rebuild( array() );

		$this->assertSame( ServerHeaders::OPTION, $deleted );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_rebuild_records_nothing_when_the_config_file_is_missing(): void {
		$this->fakeBreeze( array(), false );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		ServerHeaders::rebuild( array( 'x-frame-options' ) );
		$this->addToAssertionCount( 1 );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_restore_keeps_the_fingerprint_when_the_config_was_not_rewritten(): void {
		$this->fakeBreeze( array( 'x-frame-options' ), false );
		file_put_contents( WP_CONTENT_DIR . '/breeze-config/breeze-config.php', '<?php return array();' );
		touch( WP_CONTENT_DIR . '/breeze-config/breeze-config.php', time() - 3600 );
		Functions\expect( 'delete_option' )->never();

		ServerHeaders::rebuild( array() );
		$this->addToAssertionCount( 1 );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_rebuild_does_nothing_when_the_request_turned_out_to_be_rest(): void {
		// WordPress defines REST_REQUEST after init, so only the shutdown-time
		// check can see it.
		define( 'REST_REQUEST', true );
		$this->fakeBreeze( array() );
		Functions\expect( 'update_option' )->never();

		ServerHeaders::rebuild( array( 'x-frame-options' ) );

		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/breeze-config/breeze-config.php' );
	}

	public function test_check_skips_the_login_page(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'add_action' )->never();

		try {
			ServerHeaders::maybe_schedule_rebuild( array( 'x-frame-options' ) );
		} finally {
			unset( $GLOBALS['pagenow'] );
		}

		$this->addToAssertionCount( 1 );
	}
}
