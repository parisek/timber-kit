<?php

declare(strict_types=1);

namespace Tests\Unit\Wpml;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Wpml\MenuSyncReadOnly;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class MenuSyncReadOnlyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_GET['page'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param list<array<string, string>> $engines Rows of TABLE_NAME + ENGINE.
	 */
	private function stubWpdb( array $engines = [] ): \wpdb {
		return new class( $engines ) extends \wpdb {
			public string $prefix = 'wp_';

			/** @var list<string> */
			public array $queries = [];

			public function __construct( private readonly array $engines ) {
			}

			public function prepare( string $query, mixed ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( string $query ): int|bool {
				$this->queries[] = $query;
				return true;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$this->queries[] = $query;
				return $this->engines;
			}
		};
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function innodbRows(): array {
		return [
			[ 'TABLE_NAME' => 'wp_posts', 'ENGINE' => 'InnoDB' ],
			[ 'TABLE_NAME' => 'wp_icl_translations', 'ENGINE' => 'InnoDB' ],
		];
	}

	/**
	 * @return array<string, array{bool, bool, bool, string, bool}>
	 */
	public static function requestProvider(): array {
		$page = MenuSyncReadOnly::PAGE;
		return [
			'menu sync screen'   => [ true, true, false, $page, true ],
			'front end'          => [ true, false, false, $page, false ],
			'admin-ajax'         => [ true, true, true, $page, false ],
			'other admin page'   => [ true, true, false, 'sitepress-multilingual-cms/menu/languages.php', false ],
			'no page parameter'  => [ true, true, false, '', false ],
			'WPML not active'    => [ false, true, false, $page, false ],
		];
	}

	#[DataProvider( 'requestProvider' )]
	public function test_applies_only_to_the_menu_sync_screen( bool $wpml, bool $admin, bool $ajax, string $page, bool $expected ): void {
		$this->assertSame( $expected, MenuSyncReadOnly::appliesTo( $wpml, $admin, $ajax, $page ) );
	}

	public function test_register_hooks_nothing_without_wpml(): void {
		$_GET['page'] = MenuSyncReadOnly::PAGE;
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'add_action' )->never();

		MenuSyncReadOnly::register();

		$this->addToAssertionCount( 1 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_begin_on_init_before_wpml(): void {
		define( 'ICL_SITEPRESS_VERSION', '4.9.7' );
		$_GET['page']    = MenuSyncReadOnly::PAGE;
		$GLOBALS['wpdb'] = $this->stubWpdb();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$hooks = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
			$hooks[] = [ $hook, $callback, $priority ];
		} );

		MenuSyncReadOnly::register();

		$this->assertCount( 1, $hooks );
		$this->assertSame( 'init', $hooks[0][0] );
		$this->assertInstanceOf( MenuSyncReadOnly::class, $hooks[0][1][0] );
		$this->assertSame( 'begin', $hooks[0][1][1] );
		// WPML's ICLMenusSync::init() runs at priority 20.
		$this->assertSame( 1, $hooks[0][2] );
	}

	public function test_begin_starts_a_transaction_and_hooks_the_rollback(): void {
		$db    = $this->stubWpdb( $this->innodbRows() );
		$guard = new MenuSyncReadOnly( $db );
		$hooks = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
			$hooks[] = [ $hook, $callback, $priority ];
		} );

		$guard->begin();

		$this->assertSame( 'START TRANSACTION', end( $db->queries ) );
		$this->assertSame( [ [ 'shutdown', [ $guard, 'rollback' ], PHP_INT_MIN ] ], $hooks );
	}

	public function test_begin_checks_every_table_wpml_writes_on_that_screen(): void {
		$db = $this->stubWpdb( $this->innodbRows() );
		Functions\when( 'add_action' )->justReturn( true );

		( new MenuSyncReadOnly( $db ) )->begin();

		$this->assertStringContainsString( 'information_schema.TABLES', $db->queries[0] );
		foreach ( [ 'term_relationships', 'term_taxonomy', 'posts', 'postmeta', 'options', 'icl_translations', 'icl_strings', 'icl_string_translations' ] as $table ) {
			$this->assertStringContainsString( "'wp_{$table}'", $db->queries[0] );
		}
	}

	public function test_begin_opens_no_transaction_when_a_table_is_not_innodb(): void {
		$db    = $this->stubWpdb( [
			[ 'TABLE_NAME' => 'wp_posts', 'ENGINE' => 'InnoDB' ],
			[ 'TABLE_NAME' => 'wp_icl_strings', 'ENGINE' => 'MyISAM' ],
		] );
		$guard = new MenuSyncReadOnly( $db );
		$hooks = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
			$hooks[] = [ $hook, $callback ];
		} );

		$guard->begin();

		$this->assertNotContains( 'START TRANSACTION', $db->queries );
		$this->assertSame( [ [ 'admin_notices', [ $guard, 'renderNotice' ] ] ], $hooks );
		$this->assertSame( [ 'wp_icl_strings (MyISAM)' ], $guard->nonTransactionalTables() );
	}

	public function test_notice_names_the_tables_and_says_the_guard_is_inactive(): void {
		$db    = $this->stubWpdb( [ [ 'TABLE_NAME' => 'wp_icl_strings', 'ENGINE' => 'MyISAM' ] ] );
		$guard = new MenuSyncReadOnly( $db );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		$guard->begin();

		ob_start();
		$guard->renderNotice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'inactive', $html );
		$this->assertStringContainsString( 'wp_icl_strings (MyISAM)', $html );
	}

	public function test_rollback_discards_the_transaction(): void {
		$db = $this->stubWpdb();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_flush' )->never();

		( new MenuSyncReadOnly( $db ) )->rollback();

		$this->assertSame( [ 'ROLLBACK' ], $db->queries );
	}

	public function test_rollback_flushes_an_external_object_cache(): void {
		$db = $this->stubWpdb();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\expect( 'wp_cache_flush' )->once();

		( new MenuSyncReadOnly( $db ) )->rollback();

		$this->assertSame( [ 'ROLLBACK' ], $db->queries );
	}
}
