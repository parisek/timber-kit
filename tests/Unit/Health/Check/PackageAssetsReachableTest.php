<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\PackageAssetsReachable;
use Parisek\TimberKit\Health\HealthCheck;
use Tests\Unit\Health\HealthTestCase;

class PackageAssetsReachableTest extends HealthTestCase {

	private const URL = 'https://example.com/wp-content/themes/t/vendor/parisek/timber-kit/assets/css/gutenberg-resizable-sidebar.css';

	private const JS_URL = 'https://example.com/wp-content/themes/t/vendor/parisek/timber-kit/assets/js/gutenberg-resizable-sidebar.js';

	private const FS_BODY = '<p>You don\'t have permission to access this resource.Server unable to read htaccess file, denying access to be safe</p>';

	private const REWRITE_BODY = '<p>You don\'t have permission to access this resource.</p>';

	/** @var list<array{method: string, url: string, args: array<string, mixed>}> */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests = [];
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ): bool => 'wp-error' === $thing );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			fn ( $response ) => is_array( $response ) ? $response['response']['code'] : ''
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			fn ( $response ): string => is_array( $response ) ? $response['body'] : ''
		);
	}

	/**
	 * @param array{0: int, 1?: string}|string $head HEAD response [code, body] or 'wp-error'.
	 * @param array{0: int, 1?: string}|string $get  GET response [code, body] or 'wp-error'.
	 */
	private function stubResponses( array|string $head, array|string $get = 'unused' ): void {
		$shape = static fn ( array|string $r ) => is_array( $r )
			? [ 'response' => [ 'code' => $r[0] ], 'body' => $r[1] ?? '' ]
			: $r;

		Functions\when( 'wp_remote_head' )->alias( function ( string $url, array $args = [] ) use ( $head, $shape ) {
			$this->requests[] = [ 'method' => 'HEAD', 'url' => $url, 'args' => $args ];
			return $shape( $head );
		} );
		Functions\when( 'wp_remote_get' )->alias( function ( string $url, array $args = [] ) use ( $get, $shape ) {
			$this->requests[] = [ 'method' => 'GET', 'url' => $url, 'args' => $args ];
			return $shape( $get );
		} );
	}

	/**
	 * Per-URL responses for the two-file cases.
	 *
	 * @param array<string, array{head: array{0: int, 1?: string}|string, get?: array{0: int, 1?: string}|string}> $map
	 */
	private function stubResponsesByUrl( array $map ): void {
		$shape = static fn ( array|string $r ) => is_array( $r )
			? [ 'response' => [ 'code' => $r[0] ], 'body' => $r[1] ?? '' ]
			: $r;

		Functions\when( 'wp_remote_head' )->alias( function ( string $url, array $args = [] ) use ( $map, $shape ) {
			$this->requests[] = [ 'method' => 'HEAD', 'url' => $url, 'args' => $args ];
			return $shape( $map[ $url ]['head'] );
		} );
		Functions\when( 'wp_remote_get' )->alias( function ( string $url, array $args = [] ) use ( $map, $shape ) {
			$this->requests[] = [ 'method' => 'GET', 'url' => $url, 'args' => $args ];
			return $shape( $map[ $url ]['get'] ?? 'unused' );
		} );
	}

	private function run_both(): \Parisek\TimberKit\Health\Result {
		return ( new PackageAssetsReachable( self::URL, self::JS_URL ) )->run();
	}

	/**
	 * @return list<string> "METHOD url" per request, in order.
	 */
	private function requestLog(): array {
		return array_map( static fn ( array $r ): string => $r['method'] . ' ' . $r['url'], $this->requests );
	}

	private function run_check(): \Parisek\TimberKit\Health\Result {
		return ( new PackageAssetsReachable( self::URL ) )->run();
	}

	public function test_identity(): void {
		$check = new PackageAssetsReachable( self::URL );

		$this->assertSame( 'package_assets_reachable', $check->id() );
		$this->assertSame( 'timber-kit', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_good_on_200_with_a_single_head_request(): void {
		$this->stubResponses( [ 200 ] );

		$result = $this->run_check();

		$this->assertSame( 'good', $result->status() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'HEAD', $this->requests[0]['method'] );
		$this->assertSame( self::URL, $this->requests[0]['url'] );
		$this->assertSame( 5, $this->requests[0]['args']['timeout'] );
	}

	/**
	 * Regression from review: `wp_remote_get()` follows redirects by default.
	 * A redirect to a login page, a CDN challenge or a soft-404 handler ends
	 * in HTTP 200 on an HTML document, and the check would report `good` for
	 * a stylesheet the browser cannot use. Neither request may follow
	 * redirects, so a 3xx stays a 3xx and the check says it could not verify.
	 */
	public function test_head_does_not_follow_redirects_and_a_redirect_ends_without_get(): void {
		$this->stubResponses( [ 302 ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertStringContainsString( '302', $result->summary() );
		$this->assertSame( [ 'HEAD' ], array_column( $this->requests, 'method' ) );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] ?? null );
	}

	public function test_get_fallback_does_not_follow_redirects(): void {
		$this->stubResponses( [ 405 ], [ 302 ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertSame( [ 'HEAD', 'GET' ], array_column( $this->requests, 'method' ) );
		$this->assertSame( 0, $this->requests[1]['args']['redirection'] ?? null );
	}

	public function test_403_with_filesystem_body_points_at_directory_permissions(): void {
		$this->stubResponses( [ 403 ], [ 403, self::FS_BODY ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'cannot enter', $result->summary() );
		$this->assertStringNotContainsString( 'The response points to the .htaccess rule', $result->summary() );
		$this->assertStringContainsString( 'The response points to the directory permissions', $result->summary() );
		$this->assertStringContainsString( 'setfacl', $result->actions() );
		$this->assertStringContainsString( 'RewriteRule', $result->actions() );
	}

	public function test_403_with_plain_body_points_at_htaccess_rule(): void {
		$this->stubResponses( [ 403 ], [ 403, self::REWRITE_BODY ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'The response points to the .htaccess rule', $result->summary() );
		$this->assertStringNotContainsString( 'The response points to the directory permissions', $result->summary() );
	}

	public function test_403_without_body_names_both_causes_and_both_fixes(): void {
		$this->stubResponses( [ 403 ], [ 403, '' ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( '.htaccess', $result->summary() );
		$this->assertStringContainsString( 'cannot enter', $result->summary() );
		$this->assertStringNotContainsString( 'The response points to', $result->summary() );
		$this->assertStringContainsString(
			'RewriteRule ^vendor/.+\.(css|js|mjs|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif)$ - [L]',
			$result->actions()
		);
		$this->assertStringContainsString( 'setfacl -m g::--x', $result->actions() );
	}

	public function test_404_is_recommended_and_names_both_causes(): void {
		$this->stubResponses( [ 404 ], [ 404, '' ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( '404', $result->summary() );
		$this->assertStringContainsString( '.htaccess', $result->summary() );
		$this->assertStringContainsString( 'cannot enter', $result->summary() );
	}

	public function test_falls_back_to_get_when_head_is_rejected(): void {
		$this->stubResponses( [ 405 ], [ 200, 'body{}' ] );

		$result = $this->run_check();

		$this->assertSame( 'good', $result->status() );
		$this->assertSame( [ 'HEAD', 'GET' ], array_column( $this->requests, 'method' ) );
		$this->assertSame( self::URL, $this->requests[1]['url'] );
		$this->assertSame( 5, $this->requests[1]['args']['timeout'] );
	}

	public function test_falls_back_to_get_when_head_is_not_implemented(): void {
		$this->stubResponses( [ 501 ], [ 200 ] );

		$this->assertSame( 'good', $this->run_check()->status() );
		$this->assertSame( [ 'HEAD', 'GET' ], array_column( $this->requests, 'method' ) );
	}

	public function test_transport_error_is_recommended_never_critical(): void {
		$this->stubResponses( 'wp-error' );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
	}

	public function test_transport_error_on_get_fallback_is_recommended(): void {
		$this->stubResponses( [ 405 ], 'wp-error' );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
	}

	public function test_unexpected_status_is_recommended_could_not_verify(): void {
		$this->stubResponses( [ 401 ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertStringContainsString( '401', $result->summary() );
	}

	public function test_head_5xx_ends_the_check_without_a_get(): void {
		$this->stubResponses( [ 503 ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertStringContainsString( '503', $result->summary() );
		$this->assertSame( [ 'HEAD' ], array_column( $this->requests, 'method' ) );
	}

	public function test_transport_error_on_head_ends_the_check_without_a_get(): void {
		$this->stubResponses( 'wp-error' );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'loopback request failed', $result->summary() );
		$this->assertSame( [ 'HEAD' ], array_column( $this->requests, 'method' ) );
	}

	public function test_head_404_falls_back_to_get(): void {
		$this->stubResponses( [ 404 ], [ 200 ] );

		$this->assertSame( 'good', $this->run_check()->status() );
		$this->assertSame( [ 'HEAD', 'GET' ], array_column( $this->requests, 'method' ) );
	}

	public function test_both_files_good_with_one_head_each(): void {
		$this->stubResponsesByUrl( [
			self::URL    => [ 'head' => [ 200 ] ],
			self::JS_URL => [ 'head' => [ 200 ] ],
		] );

		$result = $this->run_both();

		$this->assertSame( 'good', $result->status() );
		$this->assertSame( [ 'HEAD ' . self::URL, 'HEAD ' . self::JS_URL ], $this->requestLog() );
	}

	public function test_script_403_while_stylesheet_200_names_only_the_script(): void {
		$this->stubResponsesByUrl( [
			self::URL    => [ 'head' => [ 200 ] ],
			self::JS_URL => [ 'head' => [ 403 ], 'get' => [ 403, self::REWRITE_BODY ] ],
		] );

		$result = $this->run_both();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( self::JS_URL . ' answered HTTP 403', $result->summary() );
		$this->assertStringNotContainsString( self::URL, $result->summary() );
		$this->assertStringContainsString( 'The response points to the .htaccess rule', $result->summary() );
		$this->assertStringContainsString( 'RewriteRule', $result->actions() );
		$this->assertSame(
			[ 'HEAD ' . self::URL, 'HEAD ' . self::JS_URL, 'GET ' . self::JS_URL ],
			$this->requestLog()
		);
	}

	public function test_both_files_403_names_both_files_once_each(): void {
		$this->stubResponsesByUrl( [
			self::URL    => [ 'head' => [ 403 ], 'get' => [ 403, self::FS_BODY ] ],
			self::JS_URL => [ 'head' => [ 403 ], 'get' => [ 403, self::FS_BODY ] ],
		] );

		$result = $this->run_both();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( self::URL . ' answered HTTP 403', $result->summary() );
		$this->assertStringContainsString( self::JS_URL . ' answered HTTP 403', $result->summary() );
		$this->assertSame( 1, substr_count( $result->summary(), 'Two causes produce this' ) );
		$this->assertStringContainsString( 'The response points to the directory permissions', $result->summary() );
	}

	public function test_transport_error_on_one_file_and_200_on_the_other_is_could_not_verify(): void {
		$this->stubResponsesByUrl( [
			self::URL    => [ 'head' => [ 200 ] ],
			self::JS_URL => [ 'head' => 'wp-error' ],
		] );

		$result = $this->run_both();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify that ' . self::JS_URL, $result->summary() );
		$this->assertStringNotContainsString( self::URL, $result->summary() );
		$this->assertSame( '', $result->actions() );
		$this->assertSame( [ 'HEAD ' . self::URL, 'HEAD ' . self::JS_URL ], $this->requestLog() );
	}

	public function test_a_blocked_file_wins_over_an_unverified_one_and_both_are_named(): void {
		$this->stubResponsesByUrl( [
			self::URL    => [ 'head' => [ 404 ], 'get' => [ 404 ] ],
			self::JS_URL => [ 'head' => [ 500 ] ],
		] );

		$result = $this->run_both();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( self::URL . ' answered HTTP 404', $result->summary() );
		$this->assertStringContainsString( 'Could not verify that ' . self::JS_URL, $result->summary() );
		$this->assertStringContainsString( 'RewriteRule', $result->actions() );
	}
}
