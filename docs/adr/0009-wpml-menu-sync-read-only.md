# 0009. Roll back the page load of WPML Menus Sync in a transaction

## Context

WPML → WP Menus Sync writes to the database when the screen only loads. WPML
hooks `ICLMenusSync::init()` on `init` priority 20 for that page. It calls
`cleanup_broken_page_items()` and `get_menus_tree()`, which runs
`fix_assignment_to_menu()`, `fix_language_conflicts()` and
`sync_page_menu_item_trids()` for each item.

On 2026-09-14, on project sloneek (WPML 4.9.7), one visit closed without
confirming removed 56 items from the cs, sk, pl and it main menus. It also
rewrote 230 `icl_translations` rows. Nobody pressed Sync.

The real sync is a different request: `admin-ajax.php`, action
`icl_msync_confirm`. It reuses the tree from `$_SESSION['wpml_menu_sync_menu']`
and does not run those repairs.

WPML TM runs its upgrades on `init` priority 10. They can send `ALTER TABLE` and
`CREATE TABLE`. DDL commits implicitly, so a `START TRANSACTION` opened before
it ends there.

WPML publishes errata for Menus Sync. A plugin, "Remove WPML Menu Sync", exists
only to disable the screen.

## Decision

The kit ships `Wpml\MenuSyncReadOnly` behind `StarterBase::$wpml_menu_sync_read_only`,
default off.

The guard applies where WPML sets up menu sync. It mirrors
`WPML_WP_API::is_core_page( 'menu-sync/menus-sync.php' )`: `is_admin()` and a
case-insensitive substring match of `<ICL_PLUGIN_FOLDER>/menu/menu-sync/menus-sync.php`
in `page`, with WPML active. There, the guard:

1. reads `information_schema` for the tables WPML writes there;
2. runs `SET autocommit = 0` on `init` priority 1 and checks that
   `SELECT @@autocommit` returns 0;
3. runs `ROLLBACK` and `SET autocommit = 1` on `shutdown` priority `PHP_INT_MIN`;
4. calls `wp_cache_flush()` when an external object cache is in use, and logs a
   failed flush.

With autocommit off, InnoDB starts a new implicit transaction at the next write
after a DDL statement. So writes after a WPML upgrade still roll back.

The guard fails closed. It stops the request with `wp_die()` (HTTP 403) when the
`information_schema` query fails, a mandatory table is missing, a table is not
InnoDB, autocommit stays on, or the guard registers after `init` priority 1 has
started. A notice would come too late: WPML writes on priority 20 of the same
request. `icl_strings` and `icl_string_translations` are optional, because
String Translation can be absent.

The guarantee is narrow. It covers writes through the global `$wpdb` connection
to InnoDB tables during the WordPress request phase. It does not cover other
database connections (HyperDB, LudicrousDB, a read/write split), shutdown
callbacks that run after the rollback, PHP sessions or files.

Rejected alternatives:

- **Unhook `ICLMenusSync::init()`.** WPML builds the tree and the preview there.
  Editors lose both.
- **Short-circuit `setup_menu_synchronization()`.** The method is private and has
  no filter.
- **Filter the four repair methods.** They have no hooks.
- **Compute the preview on a copy of the data.** It reproduces WPML internals and
  breaks most easily on a WPML release.
- **Disable the screen.** Safest, but editors lose the preview and Sync. The
  preview is the only safe way to use Sync.
- **Repair the data until WPML finds nothing to fix.** It holds only until the
  next menu edit or WPML release gives the repairs something to do again.
- **Flush only the cache groups we measured.** The list is one WPML version
  deep. A new group in a later release would serve rolled-back data.
- **`START TRANSACTION`.** A WPML TM upgrade on `init` priority 10 can commit it
  with DDL before WPML menu sync writes.
- **An admin notice when protection is not proven.** WPML has already written
  when the notice renders.
- **Invalidate `$_SESSION['wpml_menu_sync_menu']` after the rollback, so Sync
  rebuilds the tree.** A reviewer suggested it. The `icl_msync_confirm` request
  is not guarded. A rebuild there runs `get_menus_tree()` and its repairs
  unguarded, and persists exactly the writes this guard prevents.
- **Default on.** The kit rule is default off for admin behaviour. The
  `wordpress-base` template enables the flag.

Prior art: WordPress core PHPUnit wraps each test in `START TRANSACTION` /
`ROLLBACK`. WooCommerce has `wc_transaction_query()`. We know of no production
use as a guard against a plugin's page-load side effects.

## Consequences

Evidence (sloneek, 2026-09-15, measured with `START TRANSACTION` before the
switch to autocommit):

- A guarded page load and a preview POST changed 0 rows.
- The SQL general log shows one transaction start and one `ROLLBACK`, with no
  `COMMIT`, DDL or `LOCK TABLES` between them.
- A confirmed Sync still writes.
- Other admin pages, the front end and `admin-ajax.php` open no transaction.
- A fatal error inside the guarded request leaves nothing behind.
- 38 object-cache groups change during the request (`nav_menu_relationships`,
  `element_translations`, `wpml_term_translation`, `terms`, `post_meta`,
  `options` and more). This is why the guard flushes the whole cache.
- A full Sync of all 337 items with and without the guard gave 0 different rows
  in the guarded main menus. Rows differed only where WPML's repairs were
  discarded.

Risks that stay true:

- The session tree comes from the state before the rollback, and Sync reuses it
  without running the repairs again. Sync no longer applies WPML's silent
  repairs. The sloneek measurement shows no harm, but it does not prove this for
  every data shape.
- Shutdown callbacks that run after the rollback commit normally.
- Writes through another connection, to a non-InnoDB table created during the
  request, or to sessions and files persist.
- Each visit empties an external object cache. On multisite or a shared Redis,
  that is every site on the cache. The screen is rare, and a cold cache is
  cheaper than a wrong one.
- A misconfigured database blocks the screen. That is the intent: an editor
  sees an error instead of silent menu damage.
- A WPML release that moves the repairs into the AJAX request, or changes the
  page slug, bypasses the guard. Re-measure after a WPML upgrade.

Guards: `MenuSyncReadOnlyTest` and `RegisterAdminAndEditorHooksTest`.
