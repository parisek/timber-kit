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
}
