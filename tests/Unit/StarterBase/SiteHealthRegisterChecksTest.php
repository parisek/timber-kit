<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

class SiteHealthRegisterChecksTest extends StarterBaseTestCase {

	private const DEFAULT_IDS = [
		'timber_kit_health_xmlrpc_disabled',
		'timber_kit_health_wp_version_hidden',
		'timber_kit_health_author_sitemap_disabled',
		'timber_kit_health_file_editing_disabled',
		'timber_kit_health_rest_users_restricted',
		'timber_kit_health_utf8mb4_tables',
	];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_default_checks_land_in_direct_tests(): void {
		$base = $this->createStarterBase();

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		foreach ( self::DEFAULT_IDS as $id ) {
			$this->assertArrayHasKey( $id, $tests['direct'] );
		}
	}

	public function test_package_assets_check_is_not_registered_without_resizable_sidebar(): void {
		$base = $this->createStarterBase( [ 'admin_resizable_sidebar' => false ] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayNotHasKey( 'timber_kit_health_package_assets_reachable', $tests['direct'] );
	}

	public function test_package_assets_check_probes_the_sidebar_stylesheet_url(): void {
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->alias( fn ( string $path = '' ): string => 'https://example.test/wp-content' . $path );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/wp-content/themes/test' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$probed = [];
		Functions\when( 'wp_remote_head' )->alias( function ( string $url ) use ( &$probed ): array {
			$probed[] = $url;
			return [ 'response' => [ 'code' => 200 ] ];
		} );

		$base = $this->createStarterBase( [ 'admin_resizable_sidebar' => true ] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayHasKey( 'timber_kit_health_package_assets_reachable', $tests['direct'] );

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		$result = ( $tests['direct']['timber_kit_health_package_assets_reachable']['test'] )();

		$this->assertSame( 'good', $result['status'] );
		$this->assertCount( 1, $probed );
		$this->assertStringEndsWith( '/assets/css/gutenberg-resizable-sidebar.css', $probed[0] );
	}

	public function test_health_checks_override_can_drop_a_default(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$base = new class extends StarterBase {
			public function __construct() {
				// Skip parent constructor to avoid hook registration.
			}
			protected function health_checks( array $checks ): array {
				unset( $checks['xmlrpc_disabled'] );
				return $checks;
			}
		};

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayNotHasKey( 'timber_kit_health_xmlrpc_disabled', $tests['direct'] );
		$this->assertArrayHasKey( 'timber_kit_health_wp_version_hidden', $tests['direct'] );
	}

	public function test_filter_runs_after_override_and_can_replace_the_set(): void {
		Filters\expectApplied( 'timber_kit_health_checks' )->once()->andReturn( [] );

		$base = $this->createStarterBase();

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		foreach ( self::DEFAULT_IDS as $id ) {
			$this->assertArrayNotHasKey( $id, $tests['direct'] );
		}
	}

	public function test_non_array_filter_return_is_tolerated(): void {
		Filters\expectApplied( 'timber_kit_health_checks' )->once()->andReturn( 'garbage' );

		$base = $this->createStarterBase();

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertSame( [], $tests['direct'] );
	}
}
