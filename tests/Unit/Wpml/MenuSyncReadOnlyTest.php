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

	private const PAGE = 'sitepress-multilingual-cms/menu/menu-sync/menus-sync.php';

	private const ALL_TABLES = [ 'posts', 'postmeta', 'options', 'term_relationships', 'term_taxonomy', 'terms', 'icl_translations', 'icl_strings', 'icl_string_translations' ];

	private const MANDATORY_TABLES = [ 'posts', 'postmeta', 'options', 'term_relationships', 'term_taxonomy', 'terms', 'icl_translations' ];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	private string $previous_log = '';

	/**
	 * Redirects error_log into a temp file. Call it from the test body: PHPUnit 12
	 * installs its own redirect after setUp().
	 */
	private function captureErrorLog(): string {
		$file               = (string) tempnam( sys_get_temp_dir(), 'tk-menusync-' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $file );
		return $file;
	}

	private function logged( string $file ): string {
		clearstatcache();
		$content = is_file( $file ) ? (string) file_get_contents( $file ) : '';
		ini_set( 'error_log', $this->previous_log );
		@unlink( $file );
		return $content;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_filter'], $_GET['page'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param list<array<string, string>>|null $engines    Rows of TABLE_NAME + ENGINE; null is a failed query.
	 * @param string                           $autocommit What `SELECT @@autocommit` returns.
	 */
	private function stubWpdb( ?array $engines = [], string $autocommit = '0', string $last_error = '' ): \wpdb {
		return new class( $engines, $autocommit, $last_error ) extends \wpdb {
			public string $prefix = 'wp_';

			/** @var list<string> */
			public array $queries = [];

			public function __construct( private readonly ?array $engines, private readonly string $autocommit, public string $last_error ) {
			}

			public function prepare( string $query, mixed ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( string $query ): int|bool {
				$this->queries[] = $query;
				return true;
			}

			public function get_var( string $query ): ?string {
				$this->queries[] = $query;
				return $this->autocommit;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): ?array {
				$this->queries[] = $query;
				return $this->engines;
			}
		};
	}

	/**
	 * @param list<string> $tables
	 * @return list<array<string, string>>
	 */
	private function rows( array $tables, string $engine = 'InnoDB' ): array {
		return array_map( fn ( string $t ): array => [ 'TABLE_NAME' => 'wp_' . $t, 'ENGINE' => $engine ], $tables );
	}

	/**
	 * @return array<string, array{bool, bool, string, string, bool}>
	 */
	public static function requestProvider(): array {
		$folder = 'sitepress-multilingual-cms';
		return [
			'menu sync screen'           => [ true, true, self::PAGE, $folder, true ],
			'case differs'               => [ true, true, 'SitePress-Multilingual-CMS/menu/Menu-Sync/menus-sync.php', $folder, true ],
			'text around the slug'       => [ true, true, '/' . self::PAGE . '&x=1', $folder, true ],
			'renamed plugin folder'      => [ true, true, 'wpml/menu/menu-sync/menus-sync.php', 'wpml', true ],
			'default slug, renamed'      => [ true, true, self::PAGE, 'wpml', false ],
			'front end'                  => [ true, false, self::PAGE, $folder, false ],
			'other WPML page'            => [ true, true, $folder . '/menu/languages.php', $folder, false ],
			'WPML menu root'             => [ true, true, $folder . '/menu/', $folder, false ],
			'no page parameter'          => [ true, true, '', $folder, false ],
			'WPML not active'            => [ false, true, self::PAGE, $folder, false ],
		];
	}

	#[DataProvider( 'requestProvider' )]
	public function test_applies_where_wpml_sets_up_menu_sync( bool $wpml, bool $admin, string $page, string $folder, bool $expected ): void {
		$this->assertSame( $expected, MenuSyncReadOnly::appliesTo( $wpml, $admin, $page, $folder ) );
	}

	/**
	 * @return array<string, array{int, bool, int|false, bool}>
	 */
	public static function timingProvider(): array {
		return [
			'before init'               => [ 0, false, false, false ],
			'init priority 0'           => [ 1, true, 0, false ],
			'init priority 1'           => [ 1, true, 1, true ],
			'init priority 10'          => [ 1, true, 10, true ],
			'init finished'             => [ 1, false, false, true ],
			'init running, no priority' => [ 1, true, false, true ],
		];
	}

	#[DataProvider( 'timingProvider' )]
	public function test_is_too_late_once_init_priority_1_has_started( int $init_count, bool $doing_init, int|false $priority, bool $expected ): void {
		$this->assertSame( $expected, MenuSyncReadOnly::isTooLate( $init_count, $doing_init, $priority ) );
	}

	private function stubRequest(): void {
		$_GET['page'] = self::PAGE;
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	public function test_register_hooks_nothing_without_wpml(): void {
		$this->stubRequest();
		Functions\expect( 'add_action' )->never();
		Functions\expect( 'wp_die' )->never();

		MenuSyncReadOnly::register();

		$this->addToAssertionCount( 1 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_hooks_begin_on_init_before_wpml(): void {
		define( 'ICL_SITEPRESS_VERSION', '4.9.7' );
		$this->stubRequest();
		$GLOBALS['wpdb']              = $this->stubWpdb();
		$GLOBALS['wp_filter']['init'] = new class() {
			public function current_priority(): int {
				return 0;
			}
		};
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'doing_action' )->justReturn( true );
		Functions\expect( 'wp_die' )->never();
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_matches_the_folder_in_icl_plugin_folder(): void {
		define( 'ICL_SITEPRESS_VERSION', '4.9.7' );
		define( 'ICL_PLUGIN_FOLDER', 'wpml' );
		$this->stubRequest();
		$GLOBALS['wpdb'] = $this->stubWpdb();
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\expect( 'add_action' )->never();

		// The request carries the default slug, which is not WPML's page here.
		MenuSyncReadOnly::register();

		$this->addToAssertionCount( 1 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_late_register_reports_doing_it_wrong_and_blocks_the_screen(): void {
		define( 'ICL_SITEPRESS_VERSION', '4.9.7' );
		$this->stubRequest();
		$GLOBALS['wpdb'] = $this->stubWpdb();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\expect( 'add_action' )->never();
		Functions\expect( '_doing_it_wrong' )->once();
		Functions\expect( 'wp_die' )->once()->with( \Mockery::type( 'string' ), \Mockery::type( 'string' ), [ 'response' => 403 ] );

		MenuSyncReadOnly::register();

		$this->addToAssertionCount( 1 );
	}

	public function test_begin_switches_autocommit_off_verifies_it_and_hooks_the_rollback(): void {
		$db    = $this->stubWpdb( $this->rows( self::ALL_TABLES ) );
		$guard = new MenuSyncReadOnly( $db );
		$hooks = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
			$hooks[] = [ $hook, $callback, $priority ];
		} );
		Functions\expect( 'wp_die' )->never();

		$guard->begin();

		$this->assertSame( [ 'SET autocommit = 0', 'SELECT @@autocommit' ], array_slice( $db->queries, 1 ) );
		$this->assertSame( [ [ 'shutdown', [ $guard, 'rollback' ], PHP_INT_MIN ] ], $hooks );
	}

	public function test_begin_checks_every_table_wpml_writes_on_that_screen(): void {
		$db = $this->stubWpdb( $this->rows( self::ALL_TABLES ) );
		Functions\when( 'add_action' )->justReturn( true );

		( new MenuSyncReadOnly( $db ) )->begin();

		$this->assertStringContainsString( 'information_schema.TABLES', $db->queries[0] );
		foreach ( self::ALL_TABLES as $table ) {
			$this->assertStringContainsString( "'wp_{$table}'", $db->queries[0] );
		}
	}

	public function test_begin_allows_a_missing_optional_table(): void {
		$db = $this->stubWpdb( $this->rows( self::MANDATORY_TABLES ) );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\expect( 'wp_die' )->never();

		( new MenuSyncReadOnly( $db ) )->begin();

		$this->assertContains( 'SET autocommit = 0', $db->queries );
	}

	/**
	 * @return array<string, array{\Closure(self): \wpdb, string}>
	 */
	public static function unprotectedProvider(): array {
		return [
			'query returns null'      => [ fn ( self $t ): \wpdb => $t->stubWpdb( null ), 'information_schema' ],
			'query sets last_error'   => [ fn ( self $t ): \wpdb => $t->stubWpdb( $t->rows( self::ALL_TABLES ), '0', 'Access denied' ), 'information_schema' ],
			'mandatory table missing' => [ fn ( self $t ): \wpdb => $t->stubWpdb( $t->rows( array_values( array_diff( self::ALL_TABLES, [ 'icl_translations' ] ) ) ) ), 'wp_icl_translations' ],
			'non-InnoDB table'        => [ fn ( self $t ): \wpdb => $t->stubWpdb( array_merge( $t->rows( self::MANDATORY_TABLES ), $t->rows( [ 'icl_strings' ], 'MyISAM' ) ) ), 'wp_icl_strings (MyISAM)' ],
			'autocommit still on'     => [ fn ( self $t ): \wpdb => $t->stubWpdb( $t->rows( self::ALL_TABLES ), '1' ), 'autocommit' ],
		];
	}

	/**
	 * @param \Closure(self): \wpdb $make
	 */
	#[DataProvider( 'unprotectedProvider' )]
	public function test_begin_blocks_the_screen_when_it_cannot_prove_protection( \Closure $make, string $reason ): void {
		$guard = new MenuSyncReadOnly( $make( $this ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\expect( 'add_action' )->never();
		$die = [];
		Functions\expect( 'wp_die' )->once()->andReturnUsing( function ( ...$args ) use ( &$die ) {
			$die = $args;
		} );

		$guard->begin();

		$this->assertStringContainsString( $reason, $die[0] );
		$this->assertSame( [ 'response' => 403 ], $die[2] );
	}

	public function test_rollback_issues_rollback_then_restores_autocommit(): void {
		$db = $this->stubWpdb();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_flush' )->never();

		( new MenuSyncReadOnly( $db ) )->rollback();

		$this->assertSame( [ 'ROLLBACK', 'SET autocommit = 1' ], $db->queries );
	}

	public function test_rollback_flushes_an_external_object_cache(): void {
		$db = $this->stubWpdb();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\expect( 'wp_cache_flush' )->once()->andReturn( true );
		$log = $this->captureErrorLog();

		( new MenuSyncReadOnly( $db ) )->rollback();

		$this->assertSame( [ 'ROLLBACK', 'SET autocommit = 1' ], $db->queries );
		$this->assertSame( '', $this->logged( $log ) );
	}

	public function test_rollback_logs_a_failed_cache_flush_and_does_not_throw(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\expect( 'wp_cache_flush' )->once()->andReturn( false );
		$log = $this->captureErrorLog();

		( new MenuSyncReadOnly( $this->stubWpdb() ) )->rollback();

		$this->assertStringContainsString( 'wp_cache_flush() failed', $this->logged( $log ) );
	}
}
