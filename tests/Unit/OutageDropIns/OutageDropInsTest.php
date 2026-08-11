<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Tests\Unit\OutageDropIns;

use Parisek\TimberKit\OutageDropIns;
use PHPUnit\Framework\TestCase;

/**
 * The generated drop-ins run before plugins, before the theme, and with no
 * database. These tests pin the properties that matter in that environment:
 * no dependency, no early return, and a status header the drop-in sends
 * itself because WordPress sends none on this path.
 */
final class OutageDropInsTest extends TestCase {

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
		$path = $this->content_dir . '/probe.php';
		file_put_contents( $path, OutageDropIns::source( 'sloneek' ) );

		exec( sprintf( 'php -l %s 2>&1', escapeshellarg( $path ) ), $output, $status );

		self::assertSame( 0, $status, implode( "\n", $output ) );
	}

	public function test_source_sends_its_own_503_and_retry_after(): void {
		// wp_maintenance() and wpdb::dead_db() both require_once the drop-in
		// and then die() without sending a status line of their own.
		$source = OutageDropIns::source( 'sloneek' );

		self::assertStringContainsString( 'http_response_code( 503 )', $source );
		self::assertStringContainsString( "header( 'Retry-After: 600' )", $source );
	}

	public function test_source_never_returns_early(): void {
		// Control does not go back to WordPress after this file — it goes to
		// die(). A return would serve a blank page.
		self::assertStringNotContainsString( 'return;', OutageDropIns::source( 'sloneek' ) );
	}

	public function test_source_needs_nothing_but_a_constant(): void {
		$source = OutageDropIns::source( 'sloneek' );

		self::assertStringNotContainsString( 'autoload', $source );
		self::assertStringNotContainsString( 'get_template_directory', $source );
		// The only WordPress name it may use is the constant, which is defined
		// before wp_maintenance() runs.
		self::assertStringContainsString( 'WP_CONTENT_DIR', $source );
	}

	public function test_install_writes_both_drop_ins(): void {
		$results = OutageDropIns::install( $this->content_dir, 'sloneek' );

		self::assertSame(
			array(
				'maintenance.php' => 'written',
				'db-error.php'    => 'written',
			),
			$results
		);
		self::assertFileExists( $this->content_dir . '/maintenance.php' );
		self::assertFileExists( $this->content_dir . '/db-error.php' );
	}

	public function test_install_is_idempotent(): void {
		OutageDropIns::install( $this->content_dir, 'sloneek' );

		self::assertSame(
			array(
				'maintenance.php' => 'unchanged',
				'db-error.php'    => 'unchanged',
			),
			OutageDropIns::install( $this->content_dir, 'sloneek' )
		);
	}

	public function test_install_rewrites_its_own_stale_output(): void {
		OutageDropIns::install( $this->content_dir, 'oldtheme' );

		$results = OutageDropIns::install( $this->content_dir, 'newtheme' );

		self::assertSame( 'written', $results['maintenance.php'] );
		self::assertStringContainsString(
			'/themes/newtheme/',
			(string) file_get_contents( $this->content_dir . '/maintenance.php' )
		);
	}

	public function test_install_never_overwrites_a_hand_written_drop_in(): void {
		// Silently replacing one is how a site loses a customisation nobody
		// remembers making.
		$own = "<?php\necho 'mine';\n";
		file_put_contents( $this->content_dir . '/db-error.php', $own );

		$results = OutageDropIns::install( $this->content_dir, 'sloneek' );

		self::assertSame( 'foreign', $results['db-error.php'] );
		self::assertSame( $own, (string) file_get_contents( $this->content_dir . '/db-error.php' ) );
		self::assertSame( 'written', $results['maintenance.php'] );
	}

	public function test_remove_takes_only_its_own(): void {
		$own = "<?php\necho 'mine';\n";
		OutageDropIns::install( $this->content_dir, 'sloneek' );
		file_put_contents( $this->content_dir . '/db-error.php', $own );

		$results = OutageDropIns::remove( $this->content_dir );

		self::assertSame( 'removed', $results['maintenance.php'] );
		self::assertSame( 'foreign', $results['db-error.php'] );
		self::assertFileDoesNotExist( $this->content_dir . '/maintenance.php' );
		self::assertFileExists( $this->content_dir . '/db-error.php' );
	}

	public function test_the_drop_in_serves_the_screen_when_it_exists(): void {
		$theme_root = $this->content_dir . '/themes/sloneek';
		mkdir( dirname( $theme_root . '/' . OutageDropIns::SCREEN_RELATIVE ), 0777, true );
		file_put_contents( $theme_root . '/' . OutageDropIns::SCREEN_RELATIVE, '<h1>Odstavka</h1>' );

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
	private function runDropIn( string $theme_root ): string {
		$path = $this->content_dir . '/run.php';
		file_put_contents(
			$path,
			"<?php define( 'WP_CONTENT_DIR', " . var_export( dirname( $theme_root, 2 ), true ) . " );\n"
			. '?>' . OutageDropIns::source( basename( $theme_root ) )
		);

		exec( sprintf( 'php %s 2>&1', escapeshellarg( $path ) ), $output );
		unlink( $path );

		return implode( "\n", $output );
	}
}
