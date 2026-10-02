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
	 * Build the object `wp_remote_retrieve_headers()` really returns on WordPress:
	 * a `WpOrg\Requests\Utility\CaseInsensitiveDictionary`. It is an ArrayAccess
	 * with a case-insensitive key and a `getAll()`. It has NO `getValues()` (that
	 * belongs to `Requests\Response\Headers`, which WordPress does not hand back).
	 * A header sent on one line reads as a string. A header sent on several lines
	 * reads as an array of strings. Apache folds a second `Header append` into one
	 * line, which reads as one comma-joined string.
	 *
	 * @param array<string, string|array<int, string>> $map
	 */
	private function headersObject( array $map ): object {
		return new class( $map ) implements \ArrayAccess {
			/** @var array<string, string|array<int, string>> */
			private array $map = [];

			/** @param array<string, string|array<int, string>> $map */
			public function __construct( array $map ) {
				foreach ( $map as $name => $value ) {
					$this->map[ strtolower( $name ) ] = $value;
				}
			}

			public function offsetExists( mixed $offset ): bool {
				return isset( $this->map[ strtolower( (string) $offset ) ] );
			}

			public function offsetGet( mixed $offset ): mixed {
				return $this->map[ strtolower( (string) $offset ) ] ?? null;
			}

			public function offsetSet( mixed $offset, mixed $value ): void {
				$this->map[ strtolower( (string) $offset ) ] = $value;
			}

			public function offsetUnset( mixed $offset ): void {
				unset( $this->map[ strtolower( (string) $offset ) ] );
			}

			/** @return array<string, string|array<int, string>> */
			public function getAll(): array {
				return $this->map;
			}
		};
	}

	/**
	 * Run the detector against a fake loopback answer and return the Site Health
	 * result. The transient cache is empty and writable.
	 *
	 * @param array<string, string|array<int, string>> $map
	 * @return array<string, mixed>
	 */
	private function runWith( array $map ): array {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( $this->headersObject( $map ) );
		Functions\when( 'set_transient' )->justReturn( true );

		return $this->createStarterBase()->site_health_test_duplicate_security_headers();
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

	public function test_value_repeated_inside_one_comma_joined_line_is_reported(): void {
		// Apache folds a second `Header append` into one line.
		$result = $this->runWith( [ 'x-frame-options' => 'SAMEORIGIN, SAMEORIGIN' ] );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'x-frame-options', $result['description'] );
	}

	public function test_comma_joined_repeat_is_compared_without_regard_to_case_and_spacing(): void {
		$result = $this->runWith( [ 'referrer-policy' => 'no-referrer,  No-Referrer' ] );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'referrer-policy', $result['description'] );
	}

	public function test_a_comma_list_that_does_not_repeat_is_not_a_duplicate(): void {
		// Referrer-Policy takes a fallback list: two different tokens, one source.
		$result = $this->runWith( [ 'referrer-policy' => 'no-referrer, strict-origin-when-cross-origin' ] );

		$this->assertSame( 'good', $result['status'] );
		// "Could not verify" also reports status good: the label tells them apart.
		$this->assertStringContainsString( 'exactly once', $result['label'] );
	}

	public function test_a_header_sent_on_several_lines_is_reported(): void {
		$result = $this->runWith( [ 'x-content-type-options' => [ 'nosniff', 'nosniff' ] ] );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'x-content-type-options', $result['description'] );
	}

	public function test_a_headers_object_that_is_not_array_access_is_inconclusive(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( new \stdClass() );
		Functions\expect( 'set_transient' )->never();

		$result = $this->createStarterBase()->site_health_test_duplicate_security_headers();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'could not be verified', $result['label'] );
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
