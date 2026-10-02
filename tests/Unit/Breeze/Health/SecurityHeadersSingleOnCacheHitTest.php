<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\Health;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\Health\SecurityHeadersSingleOnCacheHit;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Covers the cache-hit duplicate security header check.
 *
 * Breeze saves the front page's security headers and replays them with
 * header() on every cache hit. When the web server sends the same headers,
 * a hit carries each one twice while a miss carries it once. The check reads
 * a miss and a hit and compares them.
 */
class SecurityHeadersSingleOnCacheHitTest extends TestCase {

	private const HOME = 'https://example.test/';

	private const CLEAN = array(
		'x-frame-options'           => 'SAMEORIGIN',
		'x-content-type-options'    => 'nosniff',
		'referrer-policy'           => 'strict-origin-when-cross-origin',
		'content-security-policy'   => 'upgrade-insecure-requests',
		'permissions-policy'        => 'geolocation=(), microphone=(), camera=()',
		'x-xss-protection'          => '0',
		'strict-transport-security' => 'max-age=31536000; includeSubDomains; preload',
	);

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->requests = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'home_url' )->justReturn( self::HOME );
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ): bool => 'wp-error' === $thing );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			fn ( $response ) => is_array( $response ) ? $response['response']['code'] : ''
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn ( $response, string $name ) => is_array( $response ) ? ( $response['headers'][ strtolower( $name ) ] ?? '' ) : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Queue the three GET responses in request order: miss, prime, hit.
	 *
	 * @param array<string, string|list<string>>|string $miss  Headers, or 'wp-error'.
	 * @param array<string, string|list<string>>|string $prime Headers, or 'wp-error'.
	 * @param array<string, string|list<string>>|string $hit   Headers, or 'wp-error'.
	 */
	private function stubResponses( array|string $miss, array|string $prime, array|string $hit ): void {
		$queue = array_map(
			static fn ( array|string $headers ) => is_array( $headers )
				? array(
					'response' => array( 'code' => 200 ),
					'headers'  => $headers,
				)
				: $headers,
			array( $miss, $prime, $hit )
		);

		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args = array() ) use ( &$queue ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array_shift( $queue );
			}
		);
	}

	/**
	 * @param array<string, string|list<string>> $extra
	 * @return array<string, string|list<string>>
	 */
	private static function hit( array $extra = array() ): array {
		return array_merge( self::CLEAN, array( 'x-cache' => 'HIT' ), $extra );
	}

	/**
	 * @param array<string, string|list<string>> $extra
	 * @return array<string, string|list<string>>
	 */
	private static function miss( array $extra = array() ): array {
		return array_merge( self::CLEAN, array( 'x-cache' => 'MISS' ), $extra );
	}

	private function runCheck(): Result {
		return ( new SecurityHeadersSingleOnCacheHit() )->run();
	}

	public function test_identity(): void {
		$check = new SecurityHeadersSingleOnCacheHit();

		$this->assertSame( 'security_headers_single_on_cache_hit', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_good_when_each_header_is_single_on_miss_and_hit(): void {
		$this->stubResponses( self::miss(), self::miss(), self::hit() );

		$result = $this->runCheck();

		$this->assertSame( Result::GOOD, $result->status() );
	}

	public function test_requests_a_busted_miss_then_the_plain_url_twice(): void {
		$this->stubResponses( self::miss(), self::miss(), self::hit() );

		$this->runCheck();

		$this->assertCount( 3, $this->requests );
		$this->assertMatchesRegularExpression( '#^https://example\.test/\?timber-kit-cache-probe=[0-9a-f]{16}$#', $this->requests[0]['url'] );
		$this->assertSame( self::HOME, $this->requests[1]['url'] );
		$this->assertSame( self::HOME, $this->requests[2]['url'] );

		foreach ( $this->requests as $request ) {
			$this->assertSame( 5, $request['args']['timeout'] );
			$this->assertSame( 0, $request['args']['redirection'] );
		}
	}

	public function test_cache_buster_differs_between_runs(): void {
		$this->stubResponses( self::miss(), self::miss(), self::hit() );
		$this->runCheck();
		$first = $this->requests[0]['url'];

		$this->requests = array();
		$this->stubResponses( self::miss(), self::miss(), self::hit() );
		$this->runCheck();

		$this->assertNotSame( $first, $this->requests[0]['url'] );
	}

	public function test_flags_header_single_on_miss_and_repeated_as_lines_on_hit(): void {
		$this->stubResponses(
			self::miss(),
			self::miss(),
			self::hit( array( 'x-frame-options' => array( 'SAMEORIGIN', 'SAMEORIGIN' ) ) )
		);

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'X-Frame-Options', $result->summary() );
		$this->assertStringNotContainsString( 'Referrer-Policy', $result->summary() );
		$this->assertStringContainsString( 'Breeze', $result->summary() );
		$this->assertStringContainsString( 'breeze_custom_headers_allow', $result->actions() );
		$this->assertStringContainsString( "'x-frame-options'", $result->actions() );
	}

	public function test_flags_header_repeated_as_one_comma_joined_line_on_hit(): void {
		$this->stubResponses(
			self::miss(),
			self::miss(),
			self::hit(
				array(
					'x-content-type-options' => 'nosniff, nosniff',
					'permissions-policy'     => 'geolocation=(), microphone=(), camera=(), geolocation=(), microphone=(), camera=()',
				)
			)
		);

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'X-Content-Type-Options', $result->summary() );
		$this->assertStringContainsString( 'Permissions-Policy', $result->summary() );
	}

	public function test_comma_list_inside_one_value_is_not_a_repeat(): void {
		$this->stubResponses(
			self::miss( array( 'referrer-policy' => 'no-referrer, strict-origin-when-cross-origin' ) ),
			self::miss(),
			self::hit( array( 'referrer-policy' => 'no-referrer, strict-origin-when-cross-origin' ) )
		);

		$result = $this->runCheck();

		$this->assertSame( Result::GOOD, $result->status() );
	}

	public function test_differing_permissions_policy_repeated_on_miss_too_is_not_flagged(): void {
		$two = array( 'geolocation=(), microphone=(), camera=()', 'interest-cohort=()' );
		$this->stubResponses(
			self::miss( array( 'permissions-policy' => $two ) ),
			self::miss( array( 'permissions-policy' => $two ) ),
			self::hit( array( 'permissions-policy' => $two ) )
		);

		$result = $this->runCheck();

		$this->assertSame( Result::GOOD, $result->status() );
	}

	public function test_flagged_remedy_names_only_the_headers_to_drop(): void {
		$this->stubResponses(
			self::miss(),
			self::miss(),
			self::hit(
				array(
					'x-frame-options'         => 'SAMEORIGIN, SAMEORIGIN',
					'content-security-policy' => array( 'upgrade-insecure-requests', 'upgrade-insecure-requests' ),
				)
			)
		);

		$result = $this->runCheck();

		$this->assertStringContainsString( "'x-frame-options'", $result->actions() );
		$this->assertStringContainsString( "'content-security-policy'", $result->actions() );
		$this->assertStringNotContainsString( "'referrer-policy'", $result->actions() );
		$this->assertStringContainsString( 'rebuild', $result->actions() );
		$this->assertStringContainsString( 'Permissions-Policy', $result->actions() );
	}

	/**
	 * Only a header with exactly one copy on the miss gets the removal advice.
	 *
	 * @return array<string, array{0: int, 1: int, 2: string}>
	 */
	public static function copyCounts(): array {
		return array(
			'1 on miss, 2 on hit'  => array( 1, 2, Result::RECOMMENDED ),
			'1 on miss, 3 on hit'  => array( 1, 3, Result::RECOMMENDED ),
			'1 on miss, 1 on hit'  => array( 1, 1, Result::GOOD ),
			'2 on miss, 2 on hit'  => array( 2, 2, Result::GOOD ),
			'2 on miss, 3 on hit'  => array( 2, 3, Result::GOOD ),
		);
	}

	#[DataProvider( 'copyCounts' )]
	public function test_removal_advice_needs_exactly_one_copy_on_the_miss( int $on_miss, int $on_hit, string $status ): void {
		$this->stubResponses(
			self::miss( array( 'x-frame-options' => array_fill( 0, $on_miss, 'SAMEORIGIN' ) ) ),
			self::miss(),
			self::hit( array( 'x-frame-options' => array_fill( 0, $on_hit, 'SAMEORIGIN' ) ) )
		);

		$result = $this->runCheck();

		$this->assertSame( $status, $result->status() );
		if ( Result::RECOMMENDED === $status ) {
			$this->assertStringContainsString( "'x-frame-options'", $result->actions() );
		} else {
			$this->assertStringNotContainsString( 'breeze_custom_headers_allow', $result->actions() );
		}
	}

	public function test_comma_joined_repeat_on_hit_with_one_copy_on_miss_is_flagged(): void {
		$this->stubResponses(
			self::miss(),
			self::miss(),
			self::hit( array( 'strict-transport-security' => 'max-age=31536000, max-age=31536000' ) )
		);

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( "'strict-transport-security'", $result->actions() );
	}

	public function test_header_absent_on_miss_and_repeated_on_hit_gets_no_removal_advice(): void {
		$miss = self::miss();
		unset( $miss['strict-transport-security'] );
		$this->stubResponses(
			$miss,
			$miss,
			self::hit( array( 'strict-transport-security' => array( 'max-age=1', 'max-age=1' ) ) )
		);

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'Strict-Transport-Security', $result->summary() );
		$this->assertStringContainsString( 'only on a cache hit', $result->summary() );
		$this->assertStringNotContainsString( 'breeze_custom_headers_allow', $result->actions() );
		$this->assertStringNotContainsString( 'breeze_custom_headers_allow', $result->summary() );
	}

	public function test_hit_only_header_stays_out_of_the_removal_snippet(): void {
		$miss = self::miss();
		unset( $miss['strict-transport-security'] );
		$this->stubResponses(
			$miss,
			$miss,
			self::hit(
				array(
					'x-frame-options'           => array( 'SAMEORIGIN', 'SAMEORIGIN' ),
					'strict-transport-security' => array( 'max-age=1', 'max-age=1' ),
				)
			)
		);

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( "'x-frame-options'", $result->actions() );
		$this->assertStringNotContainsString( "'strict-transport-security'", $result->actions() );
		$this->assertStringContainsString( 'only on a cache hit', $result->summary() );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function hitEvidence(): array {
		return array(
			'x-cache HIT'             => array( array( 'x-cache' => 'HIT' ) ),
			'x-cache with suffix'     => array( array( 'x-cache' => 'HIT from proxy' ) ),
			'age above zero'          => array( array( 'age' => '42' ) ),
			'breeze served from file' => array( array( 'cache-provider' => 'CLOUDWAYS-CACHE-DE' ) ),
		);
	}

	/**
	 * @param array<string, string> $evidence
	 */
	#[DataProvider( 'hitEvidence' )]
	public function test_each_hit_signal_confirms_the_hit( array $evidence ): void {
		$hit = array_merge( self::CLEAN, $evidence, array( 'x-frame-options' => array( 'SAMEORIGIN', 'SAMEORIGIN' ) ) );
		$this->stubResponses( self::CLEAN, self::CLEAN, $hit );

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'X-Frame-Options', $result->summary() );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function noHitEvidence(): array {
		return array(
			'no cache headers'      => array( array() ),
			'x-cache MISS'          => array( array( 'x-cache' => 'MISS' ) ),
			'age zero'              => array( array( 'age' => '0' ) ),
			'breeze wrote the file' => array( array( 'cache-provider' => 'CLOUDWAYS-CACHE-DC' ) ),
		);
	}

	/**
	 * @param array<string, string> $evidence
	 */
	#[DataProvider( 'noHitEvidence' )]
	public function test_could_not_verify_when_hit_is_not_confirmed( array $evidence ): void {
		$this->stubResponses( self::CLEAN, self::CLEAN, array_merge( self::CLEAN, $evidence ) );

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertStringContainsString( 'cache hit', $result->summary() );
	}

	public function test_could_not_verify_when_miss_request_fails(): void {
		$this->stubResponses( 'wp-error', self::miss(), self::hit() );

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_could_not_verify_when_priming_request_fails(): void {
		$this->stubResponses( self::miss(), 'wp-error', self::hit() );

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertCount( 2, $this->requests );
	}

	public function test_could_not_verify_when_hit_request_fails(): void {
		$this->stubResponses( self::miss(), self::miss(), 'wp-error' );

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
	}

	public function test_could_not_verify_on_a_non_2xx_answer(): void {
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args = array() ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 301 ),
					'headers'  => array(),
				);
			}
		);

		$result = $this->runCheck();

		$this->assertSame( Result::RECOMMENDED, $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertStringContainsString( '301', $result->summary() );
	}
}
