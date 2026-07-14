<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Updates\UpdateDiscovery;
use PHPUnit\Framework\TestCase;

class UpdateDiscoveryTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->root = sys_get_temp_dir() . '/timber-kit-updates-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root, 0777, true );
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->root );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_discovers_ids_across_default_roots_in_component_then_number_order(): void {
		$this->writeUpdate( 'updates/0002-theme-step.php', 'Theme step' );
		$this->writeUpdate( 'templates/component/card/updates/0001-card-step.php', 'Card step' );
		$this->writeUpdate( 'static/templates/component/alert/updates/0003-alert-step.php', 'Alert step' );

		$result = ( new UpdateDiscovery() )->discoverInTheme( $this->root );

		$this->assertSame( [ 'alert:0003', 'card:0001', 'theme:0002' ], array_column( $result->updates, 'id' ) );
		$this->assertSame( [], $result->errors );
		$this->assertStringEndsWith( '0001-card-step.php', $result->updates[1]['path'] );
	}

	public function test_flags_duplicate_numbers_within_the_same_component(): void {
		$this->writeUpdate( 'templates/component/card/updates/0001-first.php', 'First' );
		$this->writeUpdate( 'templates/component/card/updates/0001-second.php', 'Second' );

		$result = ( new UpdateDiscovery() )->discoverInTheme( $this->root );

		$this->assertCount( 1, $result->errors );
		$this->assertStringContainsString( 'Duplicate update id card:0001', $result->errors[0] );
	}

	public function test_collects_malformed_files_as_errors(): void {
		$this->writeFile( 'updates/0001-bad.php', '<?php return ["description" => "Missing run"];' );

		$result = ( new UpdateDiscovery() )->discoverInTheme( $this->root );

		$this->assertSame( [], $result->updates );
		$this->assertCount( 1, $result->errors );
		$this->assertStringContainsString( 'Malformed update file', $result->errors[0] );
	}

	public function test_filter_override_can_add_scan_patterns(): void {
		$this->writeUpdate( 'custom/promo/updates/0004-promo.php', 'Promo' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->root );
		Functions\when( 'apply_filters' )->alias(
			fn ( string $filter, array $patterns ): array => 'timberkit_update_paths' === $filter
				? [ $this->root . '/custom/*/updates/*.php' ]
				: $patterns
		);

		$result = ( new UpdateDiscovery() )->discover();

		$this->assertSame( [ 'promo:0004' ], array_column( $result->updates, 'id' ) );
	}

	private function writeUpdate( string $relative, string $description ): void {
		$this->writeFile(
			$relative,
			'<?php return ["description" => ' . var_export( $description, true ) . ', "run" => static function (): void {}];'
		);
	}

	private function writeFile( string $relative, string $contents ): void {
		$path = $this->root . '/' . $relative;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $path, $contents );
	}

	private function removeDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ) ?: [], [ '.', '..' ] ) as $entry ) {
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->removeDirectory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
