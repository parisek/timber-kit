<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\Health;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\Health\WarmupSitemapResolved;
use Parisek\TimberKit\Breeze\PriorityStore;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Covers the empty-warmup-list check.
 *
 * `WarmupSitemap` degrades silently by design: an empty sitemap result is a
 * normal return value and the refresh job swallows throwables by contract. The
 * administrator is the one left without a signal, and this check is that
 * signal.
 */
class WarmupSitemapResolvedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, string>|null $urls Stored URLs, or null for "never refreshed".
	 */
	private function storeHolds( ?array $urls ): void {
		$record = null === $urls
			? null
			: array(
				'urls'         => $urls,
				'signals'      => array(),
				'fetched_at'   => 1000,
				'weights_hash' => 'h',
				'revision'     => 1,
			);

		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => PriorityStore::OPTION_KEY === $key ? $record : $default
		);
	}

	public function test_identity(): void {
		$check = new WarmupSitemapResolved();

		$this->assertSame( 'warmup_sitemap_resolved', $check->id() );
		$this->assertSame( 'caching', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_a_never_refreshed_store_is_not_a_failure(): void {
		// The first purge after activation schedules the refresh, so a fresh
		// install sits here through no fault of its own. Calling that a
		// failure trains an administrator to ignore the check.
		$this->storeHolds( null );

		$this->assertSame( Result::GOOD, ( new WarmupSitemapResolved() )->run()->status() );
	}

	public function test_a_refreshed_but_empty_list_is_critical(): void {
		$this->storeHolds( array() );

		$result = ( new WarmupSitemapResolved() )->run();

		$this->assertSame( Result::CRITICAL, $result->status() );
	}

	public function test_a_populated_list_passes_and_reports_the_count(): void {
		$this->storeHolds( array( 'https://example.test/a/', 'https://example.test/b/' ) );

		$result = ( new WarmupSitemapResolved() )->run();

		$this->assertSame( Result::GOOD, $result->status() );
		$this->assertStringContainsString( '2', $result->summary() );
	}
}
