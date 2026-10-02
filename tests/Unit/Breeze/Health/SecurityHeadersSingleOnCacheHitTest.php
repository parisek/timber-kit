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
