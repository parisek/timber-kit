<?php
/**
 * WordPress test configuration for the Integration suite.
 *
 * Loaded twice: by the core test bootstrap in this process, and by the core
 * installer, which the bootstrap runs as a child process. Both read the same
 * environment, so the values come from there and nowhere else.
 *
 * The installer drops every table with the `wptests_` prefix and installs a
 * fresh site on each run. Point it at a database you can lose.
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/vendor/roots/wordpress-no-content/' );

// The core checkout ships no wp-content/. Keep uploads and caches out of vendor/.
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/timber-kit-integration/wp-content' );

define( 'DB_HOST', (string) getenv( 'TIMBERKIT_TEST_DB_HOST' ) );
define( 'DB_NAME', (string) getenv( 'TIMBERKIT_TEST_DB_NAME' ) );
define( 'DB_USER', (string) getenv( 'TIMBERKIT_TEST_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'TIMBERKIT_TEST_DB_PASSWORD' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_DEBUG', true );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Timber Kit Integration' );
define( 'WP_PHP_BINARY', PHP_BINARY );

$table_prefix = 'wptests_';
