<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Wpml;

/**
 * Discards the database writes of a plain page load of WPML → WP Menus Sync.
 *
 * WPML writes to the database when that screen only loads.
 * `ICLMenusSync::init()` runs on `init` priority 20 and repairs menu items while
 * it builds the preview: `cleanup_broken_page_items()`,
 * `fix_assignment_to_menu()`, `fix_language_conflicts()` and
 * `sync_page_menu_item_trids()`. One visit, closed without confirming, removed
 * 56 items from four main menus and rewrote 230 `icl_translations` rows.
 *
 * On `init` priority 1 the guard runs `SET autocommit = 0` and checks that
 * `SELECT @@autocommit` returns 0. It does not use `START TRANSACTION`: WPML TM
 * upgrades run on `init` priority 10 and can send DDL, which commits
 * implicitly. With autocommit off, InnoDB opens a new implicit transaction for
 * the next write, so writes after the DDL still roll back. On `shutdown` the
 * guard runs `ROLLBACK` and then `SET autocommit = 1`.
 *
 * The guard fails closed. It blocks the screen with `wp_die()` (HTTP 403) when
 * it cannot prove the protection: the `information_schema` query fails, a
 * mandatory table is missing, a table is not InnoDB, autocommit stays on, or
 * the guard registers after `init` priority 1 has started. A notice would come
 * too late, because WPML writes on priority 20 of the same request. 403 says
 * that the kit refuses the request on purpose; it is not a server fault.
 *
 * Scope of the protection: writes through the global `$wpdb` connection to
 * InnoDB tables during the WordPress request phase. Not covered: other
 * database connections (HyperDB, LudicrousDB, a read/write split), writes by
 * shutdown callbacks that run after the rollback, PHP sessions and files.
 *
 * The preview tree that WPML keeps in `$_SESSION['wpml_menu_sync_menu']` comes
 * from the state before the rollback. The Sync request (`admin-ajax.php`,
 * action `icl_msync_confirm`) reuses that tree and is not guarded. So Sync
 * still writes, but it does not apply the repairs of the page load.
 *
 * An external object cache is not part of the transaction, so the rollback
 * flushes it. On multisite or a shared Redis, that flush empties the cache of
 * every site that uses it.
 */
final class MenuSyncReadOnly {

	/**
	 * WPML's plugin folder when `ICL_PLUGIN_FOLDER` is not defined.
	 */
	public const DEFAULT_PLUGIN_FOLDER = 'sitepress-multilingual-cms';

	/**
	 * The screen, relative to WPML's `menu/` directory.
	 */
	public const PAGE = 'menu-sync/menus-sync.php';

	/**
	 * Tables WPML writes on that screen and that always exist, without the prefix.
	 */
	private const MANDATORY_TABLES = array(
		'posts',
		'postmeta',
		'options',
		'term_relationships',
		'term_taxonomy',
		'terms',
		'icl_translations',
	);

	/**
	 * Tables of String Translation, which can be absent.
	 */
	private const OPTIONAL_TABLES = array(
		'icl_strings',
		'icl_string_translations',
	);

	public function __construct( private readonly \wpdb $db ) {
	}

	/**
	 * Hooks the guard when the current request is the menu sync screen.
	 */
	public static function register(): void {
		global $wpdb, $wp_filter;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page   = isset( $_GET['page'] ) && \is_string( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$folder = \defined( 'ICL_PLUGIN_FOLDER' ) ? (string) \constant( 'ICL_PLUGIN_FOLDER' ) : self::DEFAULT_PLUGIN_FOLDER;

		if ( ! self::appliesTo( \defined( 'ICL_SITEPRESS_VERSION' ), is_admin(), $page, $folder ) ) {
			return;
		}

		$guard    = new self( $wpdb );
		$doing    = doing_action( 'init' );
		$priority = $doing && isset( $wp_filter['init'] ) && \is_object( $wp_filter['init'] ) && method_exists( $wp_filter['init'], 'current_priority' )
			? $wp_filter['init']->current_priority()
			: false;

		if ( self::isTooLate( (int) did_action( 'init' ), $doing, \is_int( $priority ) ? $priority : false ) ) {
			_doing_it_wrong(
				__METHOD__,
				'Register the Menus Sync guard before init priority 1. WPML writes on init priority 20.',
				'1.55.0'
			);
			$guard->block( 'the guard registered after init priority 1 had started' );
			return;
		}

		// Before WPML's own callback at priority 20.
		add_action( 'init', array( $guard, 'begin' ), 1 );
	}

	/**
	 * Whether WPML sets up menu sync for this request.
	 *
	 * Mirrors `WPML_WP_API::is_core_page( 'menu-sync/menus-sync.php' )`: a
	 * case-insensitive substring match on `page`. WPML checks no AJAX flag, so
	 * neither does the guard. The Sync request posts to plain `admin-ajax.php`
	 * with no `page`, so it does not match.
	 */
	public static function appliesTo( bool $wpml_active, bool $is_admin, string $page, string $plugin_folder ): bool {
		return $wpml_active
			&& $is_admin
			&& '' !== $page
			&& false !== stripos( $page, $plugin_folder . '/menu/' . self::PAGE );
	}

	/**
	 * Whether `init` priority 1 can no longer run before WPML.
	 *
	 * @param int       $init_count `did_action( 'init' )`.
	 * @param bool      $doing_init `doing_action( 'init' )`.
	 * @param int|false $priority   The running `init` priority, or false when unknown.
	 */
	public static function isTooLate( int $init_count, bool $doing_init, int|false $priority ): bool {
		if ( 0 === $init_count ) {
			return false;
		}

		return ! $doing_init || false === $priority || $priority >= 1;
	}

	/**
	 * Switches autocommit off, or blocks the screen when that is not proven.
	 */
	public function begin(): void {
		$reason = $this->unprotectedReason();

		if ( null === $reason ) {
			$switched = $this->db->query( 'SET autocommit = 0' );
			if ( false === $switched || '0' !== (string) $this->db->get_var( 'SELECT @@autocommit' ) ) {
				$this->db->query( 'SET autocommit = 1' );
				$reason = 'autocommit could not be switched off';
			}
		}

		if ( null !== $reason ) {
			$this->block( $reason );
			return;
		}

		// First, so callbacks that run later commit as usual.
		add_action( 'shutdown', array( $this, 'rollback' ), PHP_INT_MIN );
	}

	/**
	 * Discards everything the request wrote through `$wpdb`.
	 */
	public function rollback(): void {
		$this->db->query( 'ROLLBACK' );
		$this->db->query( 'SET autocommit = 1' );

		// A failed flush leaves rolled-back data in the cache. Log it; an exception at shutdown helps nobody.
		if ( wp_using_ext_object_cache() && ! wp_cache_flush() ) {
			error_log( 'timber-kit MenuSyncReadOnly: wp_cache_flush() failed after the rollback. The object cache can hold rolled-back menu data.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Says why the guard cannot prove the rollback, or null when it can.
	 */
	public function unprotectedReason(): ?string {
		$names = array_map(
			fn ( string $table ): string => $this->db->prefix . $table,
			array_merge( self::MANDATORY_TABLES, self::OPTIONAL_TABLES )
		);
		$sql   = 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES'
			. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
			. implode( ', ', array_fill( 0, \count( $names ), '%s' ) ) . ')';

		$rows = $this->db->get_results( (string) $this->db->prepare( $sql, ...$names ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.

		if ( ! \is_array( $rows ) || '' !== (string) $this->db->last_error ) {
			return 'the information_schema query failed';
		}

		$found     = array();
		$non_inno  = array();
		foreach ( $rows as $row ) {
			$name    = (string) ( $row['TABLE_NAME'] ?? '' );
			$engine  = (string) ( $row['ENGINE'] ?? '' );
			$found[] = $name;
			if ( 'innodb' !== strtolower( $engine ) ) {
				$non_inno[] = \sprintf( '%s (%s)', $name, $engine );
			}
		}

		$missing = array_diff(
			array_map( fn ( string $table ): string => $this->db->prefix . $table, self::MANDATORY_TABLES ),
			$found
		);
		if ( array() !== $missing ) {
			return 'mandatory tables are missing: ' . implode( ', ', $missing );
		}

		if ( array() !== $non_inno ) {
			return 'these tables cannot roll back: ' . implode( ', ', $non_inno );
		}

		return null;
	}

	/**
	 * Stops the request before WPML can write.
	 */
	private function block( string $reason ): void {
		wp_die(
			esc_html( 'Menus Sync is blocked: the read-only guard cannot discard what this screen writes, because ' . $reason . '.' ),
			'Menus Sync blocked',
			array( 'response' => 403 )
		);
	}
}
