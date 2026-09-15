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
`icl_msync_confirm`. It reuses the tree from `$_SESSION` and does not run those
repairs.

WPML publishes errata for Menus Sync. A plugin, "Remove WPML Menu Sync", exists
only to disable the screen.

## Decision

The kit ships `Wpml\MenuSyncReadOnly` behind `StarterBase::$wpml_menu_sync_read_only`,
default off.

On that screen only (`is_admin()`, not `wp_doing_ajax()`, exact `page` value,
WPML active), the guard:

1. reads `information_schema` for the tables WPML writes there;
2. if all are InnoDB, runs `START TRANSACTION` on `init` priority 1;
3. runs `ROLLBACK` on `shutdown` priority `PHP_INT_MIN`;
4. calls `wp_cache_flush()` when an external object cache is in use.

If a table is not InnoDB, the guard opens no transaction. It shows an admin
notice that names the table.

Rejected alternatives:

- **Hide or disable the screen.** Editors lose the preview of what Sync would
  do. That preview is the only safe way to use Sync.
- **Repair the data until WPML finds nothing to fix.** It holds only until the
  next menu edit or WPML release gives the repairs something to do again.
- **Flush only the cache groups we measured.** The list is one WPML version
  deep. A new group in a later release would serve rolled-back data.
- **Default on.** The kit rule is default off for admin behaviour. The
  `wordpress-base` template enables the flag.

Prior art: WordPress core PHPUnit wraps each test in `START TRANSACTION` /
`ROLLBACK`. WooCommerce has `wc_transaction_query()`. We know of no production
use as a guard against a plugin's page-load side effects.

## Consequences

Evidence (sloneek, 2026-09-15):

- A guarded page load and a preview POST changed 0 rows.
- The SQL general log shows one `START TRANSACTION` and one `ROLLBACK`, with no
  `COMMIT`, DDL or `LOCK TABLES` between them.
- A confirmed Sync still writes.
- Other admin pages, the front end and `admin-ajax.php` open no transaction.
- A fatal error inside the guarded request leaves nothing behind.
- 38 object-cache groups change during the request (`nav_menu_relationships`,
  `element_translations`, `wpml_term_translation`, `terms`, `post_meta`,
  `options` and more). This is why the guard flushes the whole cache.
- A full Sync of all 337 items with and without the guard gave 0 different rows
  in the guarded main menus. The only difference: WPML's repairs did not
  persist.

Risks that stay true:

- A DDL statement inside the request commits implicitly and ends the guard.
- Shutdown callbacks that run after the rollback commit normally.
- Each visit empties an external object cache. The screen is rare, and a cold
  cache is cheaper than a wrong one.
- WPML repairs that editors relied on no longer persist from a page load. Sync
  still applies what it previews.
- A WPML release that moves the repairs into the AJAX request, or changes the
  page slug, bypasses the guard. Re-measure after a WPML upgrade.

Guards: `MenuSyncReadOnlyTest` and `RegisterAdminAndEditorHooksTest`.
