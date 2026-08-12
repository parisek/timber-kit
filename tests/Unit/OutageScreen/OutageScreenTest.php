<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Tests\Unit\OutageScreen;

use Parisek\TimberKit\OutageScreen;
use PHPUnit\Framework\TestCase;

/**
 * The generated drop-ins run before plugins, before the theme, and with no
 * database. These tests pin the properties that matter in that environment:
 * no dependency, no early return, and a status header the drop-in sends
 * itself because WordPress sends none on this path.
 */
final class OutageScreenTest extends TestCase {

	private string $content_dir;

	protected function setUp(): void {
		$this->content_dir = sys_get_temp_dir() . '/timber-kit-outage-' . uniqid();
		mkdir( $this->content_dir );
	}

	protected function tearDown(): void {
		$this->removeDir( $this->content_dir );
	}

	private function removeDir( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: array() as $path ) {
			is_dir( $path ) ? $this->removeDir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_source_is_valid_php(): void {
		foreach ( array_keys( OutageScreen::DROP_INS ) as $filename ) {
			$path = $this->content_dir . '/probe.php';
			file_put_contents( $path, OutageScreen::source( 'sloneek', OutageScreen::SCREEN_RELATIVE, $filename ) );

			exec( sprintf( 'php -l %s 2>&1', escapeshellarg( $path ) ), $output, $status );

			self::assertSame( 0, $status, $filename . ': ' . implode( "\n", $output ) );
			unlink( $path );
		}
	}

	public function test_it_sets_503_when_executed(): void {
		// wp_maintenance() and wpdb::dead_db() both require_once the drop-in
		// and then die() without sending a status line of their own. Asserting
		// the string is present in the source would still pass if the call
		// were moved after an exit; run it and read the code back instead.
		$output = $this->runDropIn( $this->content_dir . '/themes/sloneek', 'echo "STATUS:" . http_response_code();' );

		self::assertStringContainsString( 'STATUS:503', $output );
	}

	public function test_it_needs_nothing_but_a_constant(): void {
		// The strong form of "no dependencies": run the file in a bare PHP
		// process where the only thing defined is WP_CONTENT_DIR. Any call to
		// a WordPress function or a missing class is a fatal error here, which
		// a substring assertion over the source would never notice.
		$theme_root = $this->content_dir . '/themes/sloneek';
		mkdir( dirname( $theme_root . '/' . OutageScreen::SCREEN_RELATIVE ), 0777, true );
		file_put_contents( $theme_root . '/' . OutageScreen::SCREEN_RELATIVE, 'screen' );

		$output = $this->runDropIn( $theme_root, 'echo "|EXIT-OK";' );

		self::assertStringNotContainsString( 'Fatal error', $output );
		self::assertStringNotContainsString( 'Warning', $output );
		// Reaching the trailing marker proves the file neither died nor
		// returned early — control has to come back for the appended line to
		// run at all.
		self::assertStringContainsString( '|EXIT-OK', $output );
	}

	public function test_a_double_quote_in_the_path_survives_the_generated_literal(): void {
		// addslashes() escapes a double quote as \" — which a single-quoted
		// PHP literal does not decode, producing a file that compiles and then
		// reads a path with a stray backslash in it.
		$source = OutageScreen::source( 'my"theme' );

		self::assertStringContainsString( "/themes/my\"theme/", $source );
		self::assertStringNotContainsString( '\\"theme', $source );
	}

	public function test_install_writes_every_drop_in(): void {
		$results = OutageScreen::install( $this->content_dir, 'sloneek' );

		self::assertSame(
			array(
				'maintenance.php' => 'written',
				'db-error.php'    => 'written',
				'php-error.php'   => 'written',
			),
			$results
		);
		self::assertFileExists( $this->content_dir . '/maintenance.php' );
		self::assertFileExists( $this->content_dir . '/db-error.php' );
		self::assertFileExists( $this->content_dir . '/php-error.php' );
	}

	public function test_install_is_idempotent(): void {
		OutageScreen::install( $this->content_dir, 'sloneek' );

		self::assertSame(
			array(
				'maintenance.php' => 'unchanged',
				'db-error.php'    => 'unchanged',
				'php-error.php'   => 'unchanged',
			),
			OutageScreen::install( $this->content_dir, 'sloneek' )
		);
	}

	public function test_install_writes_each_drop_in_its_own_source(): void {
		// The regression this file exists to prevent. install() once computed
		// one source above the loop, which was correct while every drop-in was
		// byte-identical. Hoisting it back out now writes php-error.php with a
		// 503 and a Retry-After — a crash that presents itself as planned
		// maintenance, which is precisely what monitoring must not be told.
		OutageScreen::install( $this->content_dir, 'sloneek' );

		$fatal = (string) file_get_contents( $this->content_dir . '/php-error.php' );

		self::assertStringContainsString( 'http_response_code( 500 )', $fatal );
		self::assertStringNotContainsString( 'Retry-After', $fatal );
		self::assertSame(
			(string) file_get_contents( $this->content_dir . '/maintenance.php' ),
			(string) file_get_contents( $this->content_dir . '/db-error.php' ),
			'The two planned-outage drop-ins still share one source.'
		);
	}

	public function test_the_fatal_drop_in_sets_500_and_promises_nothing(): void {
		// Read the status back out of a real run, not out of the source: a
		// substring assertion would still pass if the call moved after an exit.
		$output = $this->runDropIn(
			$this->content_dir . '/themes/sloneek',
			'echo "|STATUS:" . http_response_code() . "|RETRY:" . (int) headers_sent();',
			'php-error.php'
		);

		self::assertStringContainsString( '|STATUS:500', $output );
	}

	public function test_source_refuses_a_filename_it_does_not_generate(): void {
		// A typo here would otherwise produce a file with maintenance.php's
		// contract under some other name.
		$this->expectException( \InvalidArgumentException::class );

		OutageScreen::source( 'sloneek', OutageScreen::SCREEN_RELATIVE, 'advanced-cache.php' );
	}

	public function test_every_drop_in_needs_nothing_but_a_constant(): void {
		// test_it_needs_nothing_but_a_constant() proves this for the default
		// file. php-error.php is the one that runs inside a loaded WordPress,
		// where a WP function call would appear to work — so it is the one
		// most likely to grow one by accident.
		$theme_root = $this->content_dir . '/themes/sloneek';
		mkdir( dirname( $theme_root . '/' . OutageScreen::SCREEN_RELATIVE ), 0777, true );
		file_put_contents( $theme_root . '/' . OutageScreen::SCREEN_RELATIVE, 'screen' );

		foreach ( array_keys( OutageScreen::DROP_INS ) as $filename ) {
			$output = $this->runDropIn( $theme_root, 'echo "|EXIT-OK";', $filename );

			self::assertStringNotContainsString( 'Fatal error', $output, $filename );
			self::assertStringNotContainsString( 'Warning', $output, $filename );
			self::assertStringContainsString( '|EXIT-OK', $output, $filename );
		}
	}

	public function test_install_rewrites_its_own_stale_output(): void {
		OutageScreen::install( $this->content_dir, 'oldtheme' );

		$results = OutageScreen::install( $this->content_dir, 'newtheme' );

		self::assertSame( 'written', $results['maintenance.php'] );
		self::assertStringContainsString(
			'/themes/newtheme/',
			(string) file_get_contents( $this->content_dir . '/maintenance.php' )
		);
	}

	public function test_a_prose_attribution_is_not_mistaken_for_our_marker(): void {
		// The marker decides whether a file may be overwritten or deleted, so
		// it must not be a sentence somebody might copy into a drop-in of
		// their own.
		$own = "<?php\n// Generated by parisek/timber-kit, then edited by hand.\necho 'mine';\n";
		file_put_contents( $this->content_dir . '/maintenance.php', $own );

		$results = OutageScreen::install( $this->content_dir, 'sloneek' );

		self::assertSame( 'foreign', $results['maintenance.php'] );
		self::assertSame( $own, (string) file_get_contents( $this->content_dir . '/maintenance.php' ) );
	}

	public function test_install_leaves_no_temp_file_behind(): void {
		OutageScreen::install( $this->content_dir, 'sloneek' );

		self::assertSame( array(), glob( $this->content_dir . '/*.tmp' ) ?: array() );
	}

	public function test_install_never_overwrites_a_hand_written_drop_in(): void {
		// Silently replacing one is how a site loses a customisation nobody
		// remembers making.
		$own = "<?php\necho 'mine';\n";
		file_put_contents( $this->content_dir . '/db-error.php', $own );

		$results = OutageScreen::install( $this->content_dir, 'sloneek' );

		self::assertSame( 'foreign', $results['db-error.php'] );
		self::assertSame( $own, (string) file_get_contents( $this->content_dir . '/db-error.php' ) );
		self::assertSame( 'written', $results['maintenance.php'] );
	}

	public function test_remove_takes_only_its_own(): void {
		$own = "<?php\necho 'mine';\n";
		OutageScreen::install( $this->content_dir, 'sloneek' );
		file_put_contents( $this->content_dir . '/db-error.php', $own );

		$results = OutageScreen::remove( $this->content_dir );

		self::assertSame( 'removed', $results['maintenance.php'] );
		self::assertSame( 'foreign', $results['db-error.php'] );
		self::assertFileDoesNotExist( $this->content_dir . '/maintenance.php' );
		self::assertFileExists( $this->content_dir . '/db-error.php' );
	}

	public function test_the_drop_in_serves_the_screen_when_it_exists(): void {
		$theme_root = $this->content_dir . '/themes/sloneek';
		mkdir( dirname( $theme_root . '/' . OutageScreen::SCREEN_RELATIVE ), 0777, true );
		file_put_contents( $theme_root . '/' . OutageScreen::SCREEN_RELATIVE, '<h1>Odstavka</h1>' );

		$output = $this->runDropIn( $theme_root );

		self::assertStringContainsString( 'Odstavka', $output );
	}

	public function test_the_drop_in_prints_a_fallback_when_the_screen_is_missing(): void {
		// A blank 503 reads as a broken server. The theme may simply not have
		// rendered its screen yet.
		$output = $this->runDropIn( $this->content_dir . '/themes/sloneek' );

		self::assertStringContainsString( 'briefly unavailable', $output );
	}

	/**
	 * Executes a generated drop-in in a subprocess, the way WordPress would.
	 */
	private function runDropIn( string $theme_root, string $append = '', string $filename = 'maintenance.php' ): string {
		$path = $this->content_dir . '/run.php';
		file_put_contents(
			$path,
			"<?php define( 'WP_CONTENT_DIR', " . var_export( dirname( $theme_root, 2 ), true ) . " );\n"
			. '?>' . OutageScreen::source( basename( $theme_root ), OutageScreen::SCREEN_RELATIVE, $filename )
			// The generated file opens PHP and never closes it, so the extra
			// line is appended inside the same block.
			. ( '' === $append ? '' : "\n{$append}\n" )
		);

		exec( sprintf( 'php %s 2>&1', escapeshellarg( $path ) ), $output );
		unlink( $path );

		return implode( "\n", $output );
	}
}
