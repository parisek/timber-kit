<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Wpml;

/**
 * Makes a plain page load of WPML → WP Menus Sync read-only.
 *
 * WPML writes to the database when that screen only loads.
 * `ICLMenusSync::init()` runs on `init` priority 20 and repairs menu items while
 * it builds the preview: `cleanup_broken_page_items()`,
 * `fix_assignment_to_menu()`, `fix_language_conflicts()` and
 * `sync_page_menu_item_trids()`. One visit, closed without confirming, removed
 * 56 items from four main menus and rewrote 230 `icl_translations` rows.
 *
 * The guard opens a transaction on `init` priority 1 and rolls it back on
 * `shutdown`. The preview still renders, because it reads inside the same
 * transaction. Nothing it wrote survives the request.
 *
 * The deliberate Sync is a separate request (`admin-ajax.php`, action
 * `icl_msync_confirm`). The guard does not touch it, so a confirmed Sync still
 * writes.
 *
 * An external object cache is not part of the transaction. The request caches
 * what it wrote, so the rollback flushes that cache.
 *
 * Limits: every table WPML writes here must be InnoDB, or the guard stays off
 * and says so in an admin notice. A DDL statement inside the request commits
 * implicitly. Shutdown callbacks that run after the rollback commit normally.
 */
final class MenuSyncReadOnly {

	/**
	 * The `page` query value of the WPML menu sync screen.
	 */
	public const PAGE = 'sitepress-multilingual-cms/menu/menu-sync/menus-sync.php';

	/**
	 * Tables WPML writes on that screen, without the prefix.
	 */
	private const TABLES = array(
		'term_relationships',
		'term_taxonomy',
		'posts',
		'postmeta',
		'options',
		'icl_translations',
		'icl_strings',
		'icl_string_translations',
	);

	/**
	 * Tables that cannot roll back, as `name (ENGINE)`. Null until checked.
	 *
	 * @var list<string>|null
	 */
	private ?array $non_transactional = null;

	public function __construct( private readonly \wpdb $db ) {
	}

	/**
	 * Hooks the guard when the current request is the menu sync screen.
	 */
	public static function register(): void {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page = isset( $_GET['page'] ) && \is_string( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! self::appliesTo( \defined( 'ICL_SITEPRESS_VERSION' ), is_admin(), wp_doing_ajax(), $page ) ) {
			return;
		}

		// Before WPML's own callback at priority 20.
		add_action( 'init', array( new self( $wpdb ), 'begin' ), 1 );
	}

	/**
	 * Whether a request with these properties is the menu sync screen.
	 */
	public static function appliesTo( bool $wpml_active, bool $is_admin, bool $doing_ajax, string $page ): bool {
		return $wpml_active && $is_admin && ! $doing_ajax && self::PAGE === $page;
	}

	/**
	 * Opens the transaction, or shows a notice when a table cannot roll back.
	 */
	public function begin(): void {
		if ( array() !== $this->nonTransactionalTables() ) {
			add_action( 'admin_notices', array( $this, 'renderNotice' ) );
			return;
		}

		$this->db->query( 'START TRANSACTION' );
		// First, so callbacks that run later commit as usual.
		add_action( 'shutdown', array( $this, 'rollback' ), PHP_INT_MIN );
	}

	/**
	 * Discards everything the request wrote.
	 */
	public function rollback(): void {
		$this->db->query( 'ROLLBACK' );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	/**
	 * Lists the existing tables from {@see self::TABLES} that are not InnoDB.
	 *
	 * A missing table (String Translation not installed) is not an error.
	 *
	 * @return list<string>
	 */
	public function nonTransactionalTables(): array {
		if ( null !== $this->non_transactional ) {
			return $this->non_transactional;
		}

		$names = array_map( fn ( string $table ): string => $this->db->prefix . $table, self::TABLES );
		$sql   = 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES'
			. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
			. implode( ', ', array_fill( 0, \count( $names ), '%s' ) ) . ')';

		$rows = $this->db->get_results( (string) $this->db->prepare( $sql, ...$names ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.

		$this->non_transactional = array();
		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			$engine = (string) ( $row['ENGINE'] ?? '' );
			if ( 'innodb' !== strtolower( $engine ) ) {
				$this->non_transactional[] = \sprintf( '%s (%s)', (string) ( $row['TABLE_NAME'] ?? '' ), $engine );
			}
		}

		return $this->non_transactional;
	}

	/**
	 * Tells the editor that this page load writes to the database.
	 */
	public function renderNotice(): void {
		$message = \sprintf(
			'Menus Sync read-only guard is inactive: these tables cannot roll back, so opening this screen can change menus. %s',
			implode( ', ', $this->nonTransactionalTables() )
		);

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
