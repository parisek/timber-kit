<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Health\Check\PreloadChainHealthy;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Covers the stalled-preload-chain check.
 *
 * Breeze drives its queue through an Action Scheduler loopback. When that
 * loopback cannot reach the site the queue simply stops, and nothing anywhere
 * says so — the site just stays cold.
 */
class PreloadChainHealthyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_identity(): void {
		$check = new PreloadChainHealthy();

		$this->assertSame( 'preload_chain_healthy', $check->id() );
		$this->assertSame( 'caching', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_empty_queue_passes(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key ) => 'breeze_preload_queue' === $key ? array() : 0
		);

		$this->assertSame( Result::GOOD, ( new PreloadChainHealthy() )->run()->status() );
	}

	public function test_moving_queue_passes(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key ) => 'breeze_preload_queue' === $key
				? array( 'https://example.test/a/' )
				: time() - 5
		);

		$this->assertSame( Result::GOOD, ( new PreloadChainHealthy() )->run()->status() );
	}

	public function test_stalled_queue_fails(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key ) => 'breeze_preload_queue' === $key
				? array( 'https://example.test/a/', 'https://example.test/b/' )
				: time() - 600
		);

		$this->assertSame( Result::CRITICAL, ( new PreloadChainHealthy() )->run()->status() );
	}

	public function test_never_warmed_queue_fails_without_a_nonsensical_elapsed_time(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $default = false ) {
				if ( 'breeze_preload_queue' === $key ) {
					return array( 'https://example.test/a/' );
				}

				// breeze_preload_last_warm is absent; get_option falls back to $default.
				return $default;
			}
		);

		$result = ( new PreloadChainHealthy() )->run();

		$this->assertSame( Result::CRITICAL, $result->status() );
		$this->assertDoesNotMatchRegularExpression( '/\d{5,}/', $result->summary() );
	}

	public function test_idle_exactly_at_the_stall_boundary_passes(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key ) => 'breeze_preload_queue' === $key
				? array( 'https://example.test/a/' )
				: time() - 60
		);

		$this->assertSame( Result::GOOD, ( new PreloadChainHealthy() )->run()->status() );
	}

	public function test_idle_one_second_past_the_stall_boundary_fails(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key ) => 'breeze_preload_queue' === $key
				? array( 'https://example.test/a/' )
				: time() - 61
		);

		$this->assertSame( Result::CRITICAL, ( new PreloadChainHealthy() )->run()->status() );
	}
}
