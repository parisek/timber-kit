<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\RestUsersRestricted;
use Parisek\TimberKit\Health\HealthCheck;
use Tests\Unit\Health\HealthTestCase;

class RestUsersRestrictedTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_url' )->alias( fn ( string $path ): string => 'https://example.com/wp-json/' . $path );
	}

	private function stubResponse( int $code ): void {
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => $code ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
	}

	public function test_identity(): void {
		$check = new RestUsersRestricted();

		$this->assertSame( 'rest_users_restricted', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_critical_when_anonymous_request_succeeds(): void {
		$this->stubResponse( 200 );

		$this->assertSame( 'critical', ( new RestUsersRestricted() )->run()->status() );
	}

	public function test_good_when_anonymous_request_is_rejected(): void {
		$this->stubResponse( 401 );

		$this->assertSame( 'good', ( new RestUsersRestricted() )->run()->status() );
	}

	public function test_recommended_when_loopback_fails(): void {
		Functions\when( 'wp_remote_get' )->justReturn( 'error-object' );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertSame( 'recommended', ( new RestUsersRestricted() )->run()->status() );
	}
}
