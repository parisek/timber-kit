<?php
/**
 * Bootstrap for the Integration suite: real WordPress, real database.
 *
 * Deliberately does not load tests/bootstrap.php. Its WP_Query, WP_Error and
 * wpdb stubs would collide with the real classes, and Brain\Monkey must not
 * run here.
 *
 * When no database answers, WordPress is not loaded. The bootstrap defines
 * TIMBERKIT_INTEGRATION_SKIP instead, and IntegrationTestCase skips every test
 * with that reason. With TIMBERKIT_REQUIRE_TEST_DB=1 the same condition exits
 * non-zero, so a broken CI service cannot pass as a skip.
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$timberkit_skip_reason = ( static function (): ?string {
	$host = (string) getenv( 'TIMBERKIT_TEST_DB_HOST' );
	$name = (string) getenv( 'TIMBERKIT_TEST_DB_NAME' );
	$user = (string) getenv( 'TIMBERKIT_TEST_DB_USER' );

	if ( '' === $host || '' === $name || '' === $user ) {
		return 'No test database configured. Set TIMBERKIT_TEST_DB_HOST, TIMBERKIT_TEST_DB_NAME, TIMBERKIT_TEST_DB_USER and TIMBERKIT_TEST_DB_PASSWORD.';
	}

	if ( ! extension_loaded( 'mysqli' ) ) {
		return 'The mysqli extension is not loaded.';
	}

	// Same host forms wpdb accepts: host, host:port, host:/path/to/socket.
	$port   = null;
	$socket = null;
	if ( preg_match( '/^(.*):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	} elseif ( preg_match( '/^(.*):(\/.+)$/', $host, $m ) ) {
		$host   = $m[1];
		$socket = $m[2];
	}

	// PHP 8.1+ throws on a failed connect by default. A skip needs the error, not the exception.
	mysqli_report( MYSQLI_REPORT_OFF );
	$link = mysqli_init();
	if ( false === $link ) {
		return 'mysqli_init() failed.';
	}
	$link->options( MYSQLI_OPT_CONNECT_TIMEOUT, 5 );
	$connected = @$link->real_connect( $host, $user, (string) getenv( 'TIMBERKIT_TEST_DB_PASSWORD' ), $name, $port, $socket );
	if ( ! $connected ) {
		return sprintf( 'Test database at %s does not answer: %s', (string) getenv( 'TIMBERKIT_TEST_DB_HOST' ), $link->connect_error );
	}
	$link->close();

	return null;
} )();

if ( null !== $timberkit_skip_reason ) {
	if ( '1' === getenv( 'TIMBERKIT_REQUIRE_TEST_DB' ) ) {
		fwrite( STDERR, 'TIMBERKIT_REQUIRE_TEST_DB is set. ' . $timberkit_skip_reason . PHP_EOL );
		exit( 1 );
	}
	define( 'TIMBERKIT_INTEGRATION_SKIP', $timberkit_skip_reason );
	return;
}
unset( $timberkit_skip_reason );

foreach ( array( 'plugins', 'themes', 'uploads' ) as $timberkit_dir ) {
	$timberkit_path = sys_get_temp_dir() . '/timber-kit-integration/wp-content/' . $timberkit_dir;
	if ( ! is_dir( $timberkit_path ) ) {
		mkdir( $timberkit_path, 0777, true );
	}
}
unset( $timberkit_dir, $timberkit_path );

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once (string) getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
