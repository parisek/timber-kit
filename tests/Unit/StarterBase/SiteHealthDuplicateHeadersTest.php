<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/**
 * Covers the duplicate-security-headers Site Health detector: the loopback
 * result mapping (duplicate / clean / could-not-verify), the comma-safe header
 * counting, and the transient cache short-circuit.
 */
class SiteHealthDuplicateHeadersTest extends StarterBaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/?timber-kit-health=1' );
	}

	/**
	 * Build a fake WpOrg\Requests\Response\Headers-like object whose getValues()
	 * returns the supplied array per (lower-cased) header name — one element per
	 * received field line, exactly as the real object does.
	 *
	 * @param array<string, array<int, string>> $map
	 */
	private function headersObject( array $map ): object {
		return new class( $map ) {
			/** @param array<string, array<int, string>> $map */
			public function __construct( private array $map ) {}

			/** @return array<int, string>|null */
			public function getValues( string $name ): ?array {
				return $this->map[ strtolower( $name ) ] ?? null;
			}
		};
	}

	public function test_duplicate_header_is_reported_as_recommended(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( $this->headersObject( [
			'strict-transport-security' => [ 'max-age=31536000', 'max-age=31536000; preload' ],
			'x-frame-options'           => [ 'SAMEORIGIN' ],
		] ) );
		// A clean result is cached; a duplicate is too.
		Functions\expect( 'set_transient' )->once()->with(
			'timber_kit_duplicate_security_headers',
			[ 'strict-transport-security' ],
			\Mockery::any()
		)->andReturn( true );

		$result = $this->createStarterBase()->site_health_test_duplicate_security_headers();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'strict-transport-security', $result['description'] );
		$this->assertArrayHasKey( 'actions', $result );
	}

	public function test_single_value_no_duplicate_is_reported_as_good(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// Referrer-Policy legitimately carries an internal comma but as ONE line —
		// must not be mis-counted as a duplicate.
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( $this->headersObject( [
			'strict-transport-security' => [ 'max-age=31536000; includeSubDomains; preload' ],
			'referrer-policy'           => [ 'strict-origin-when-cross-origin, no-referrer' ],
		] ) );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = $this->createStarterBase()->site_health_test_duplicate_security_headers();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'exactly once', $result['label'] );
	}

	public function test_wp_error_reports_could_not_verify_and_does_not_cache(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( new \stdClass() );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\expect( 'set_transient' )->never();

		$result = $this->createStarterBase()->site_health_test_duplicate_security_headers();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'could not be verified', $result['label'] );
	}

	public function test_non_2xx_response_reports_could_not_verify_and_does_not_cache(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 301 );
		Functions\expect( 'set_transient' )->never();

		$result = $this->createStarterBase()->site_health_test_duplicate_security_headers();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'could not be verified', $result['label'] );
	}

	public function test_cached_result_short_circuits_loopback(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'x-frame-options' ] );
		// A cached array must be used verbatim — no HTTP request, no re-cache.
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$result = $this->createStarterBase()->site_health_test_duplicate_security_headers();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'x-frame-options', $result['description'] );
	}
}
