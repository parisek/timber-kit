<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

	public function test_package_assets_check_probes_the_sidebar_stylesheet_and_script_urls(): void {
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
		$this->assertCount( 2, $probed );
		$this->assertStringEndsWith( '/assets/css/gutenberg-resizable-sidebar.css', $probed[0] );
		$this->assertStringEndsWith( '/assets/js/gutenberg-resizable-sidebar.js', $probed[1] );
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function securityHeadersStates(): array {
		return [
			'security_headers off' => [ false ],
			'security_headers on'  => [ true ],
		];
	}

	// BREEZE_VERSION is a constant, so the test runs in its own process and
	// the constant cannot leak into the "Breeze absent" case below.
	#[DataProvider( 'securityHeadersStates' )]
	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_cache_hit_headers_check_is_registered_when_breeze_is_active( bool $security_headers ): void {
		define( 'BREEZE_VERSION', '2.5.0' );

		$base = $this->createStarterBase( [ 'security_headers' => $security_headers ] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayHasKey( 'timber_kit_health_security_headers_single_on_cache_hit', $tests['direct'] );
	}

	// Another test in a shared run may mock breeze_get_option(), and
	// Brain\Monkey keeps that function defined for the rest of the process.
	// The "Breeze absent" state is only observable in a fresh process.
	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_cache_hit_headers_check_is_not_registered_without_breeze(): void {
		$base = $this->createStarterBase( [ 'security_headers' => true ] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayNotHasKey( 'timber_kit_health_security_headers_single_on_cache_hit', $tests['direct'] );
	}

	public function test_breadcrumb_check_is_registered_while_the_suppression_is_active(): void {
		$base = $this->createStarterBase( [
			'seo_suppress_plugin_breadcrumb' => true,
			'breadcrumbSchemaState'          => [ 'plugin' => 'yoast', 'enabled' => false ],
		] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayHasKey( 'timber_kit_health_breadcrumb_list_rendered', $tests['direct'] );
	}

	/** A plugin without its own switch on this install still counts as suppressed. */
	public function test_breadcrumb_check_is_registered_when_the_plugin_has_no_switch(): void {
		$base = $this->createStarterBase( [
			'breadcrumbSchemaState' => [ 'plugin' => 'yoast', 'enabled' => null ],
		] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayHasKey( 'timber_kit_health_breadcrumb_list_rendered', $tests['direct'] );
	}

	public function test_breadcrumb_check_is_not_registered_when_the_flag_is_false(): void {
		$base = $this->createStarterBase( [
			'seo_suppress_plugin_breadcrumb' => false,
			'breadcrumbSchemaState'          => [ 'plugin' => 'yoast', 'enabled' => false ],
		] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayNotHasKey( 'timber_kit_health_breadcrumb_list_rendered', $tests['direct'] );
	}

	public function test_breadcrumb_check_is_not_registered_without_an_seo_plugin(): void {
		$base = $this->createStarterBase( [
			'seo_suppress_plugin_breadcrumb' => true,
			'breadcrumbSchemaState'          => [ 'plugin' => null, 'enabled' => null ],
		] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayNotHasKey( 'timber_kit_health_breadcrumb_list_rendered', $tests['direct'] );
	}

	/** The plugin's own breadcrumb switch is on, so its list stays and nothing is lost. */
	public function test_breadcrumb_check_is_not_registered_when_the_plugin_keeps_its_list(): void {
		$base = $this->createStarterBase( [
			'seo_suppress_plugin_breadcrumb' => true,
			'breadcrumbSchemaState'          => [ 'plugin' => 'yoast', 'enabled' => true ],
		] );

		$tests = $base->site_health_register_checks( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayNotHasKey( 'timber_kit_health_breadcrumb_list_rendered', $tests['direct'] );
	}

	public function test_health_checks_override_can_drop_a_default(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$base = new class extends StarterBase {
			public function __construct() {
				// Skip parent constructor to avoid hook registration.
			}
			// No SEO plugin: see StarterBaseTestCase for why the real lookup is avoided.
			protected function breadcrumb_schema_state(): array {
				return [ 'plugin' => null, 'enabled' => null ];
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
