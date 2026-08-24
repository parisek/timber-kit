<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmup\Scorer;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers the refresh pipeline: signals in, ordered list out.
 *
 * The lock release is asserted explicitly because the refresh body grew from
 * "fetch and store" to "fetch, collect, score, quota, store" — any throw in
 * that chain used to leave the lock held until its TTL expired.
 */
class RunRefreshPriorityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_menu_pages_outrank_plain_pages(): void {
		$records = array(
			$this->record( 'https://example.test/plain/' ),
			$this->record( 'https://example.test/kontakt/', array( 'menu' => true ) ),
		);

		$built = BreezeWarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, 1000000000, 50 );

		$this->assertSame( 'https://example.test/kontakt/', $built['urls'][0] );
	}

	public function test_front_page_leads(): void {
		$records = array(
			$this->record( 'https://example.test/kontakt/', array( 'menu' => true ) ),
			$this->record( 'https://example.test/', array( 'front_page' => true ) ),
		);

		$built = BreezeWarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, 1000000000, 50 );

		$this->assertSame( 'https://example.test/', $built['urls'][0] );
	}

	public function test_signals_are_stored_keyed_by_canonical_url(): void {
		$records = array( $this->record( 'https://example.test/a/', array( 'menu' => true ) ) );

		$built = BreezeWarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, 1000000000, 50 );

		$this->assertArrayHasKey( 'https://example.test/a/', $built['signals'] );
		$this->assertTrue( $built['signals']['https://example.test/a/']['menu'] );
	}

	public function test_stored_signals_include_manual(): void {
		// Without this the menu rescore would lose the manual weight and push
		// hand-picked URLs down the list.
		$records = array( $this->record( 'https://example.test/akce/', array( 'manual' => true ) ) );

		$built = BreezeWarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, 1000000000, 50 );

		$this->assertTrue( $built['signals']['https://example.test/akce/']['manual'] );
	}

	public function test_cap_is_applied(): void {
		$records = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$records[] = $this->record( 'https://example.test/' . $i . '/' );
		}

		$built = BreezeWarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, 1000000000, 3 );

		$this->assertCount( 3, $built['urls'] );
	}

	public function test_refresh_releases_the_lock_on_failure(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'home_url' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'delete_transient' )->once();

		BreezeWarmupSitemap::runRefresh();

		// Functions\expect()->once() above is the real assertion (verified on
		// Mockery::close() in Monkey\tearDown()); this just keeps PHPUnit from
		// flagging the test as risky for having no assertions of its own.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function record( string $url, array $overrides = array() ): array {
		return array_merge(
			array(
				'url'        => $url,
				'key'        => $url,
				'lastmod'    => null,
				'type'       => '',
				'lang'       => 'cs',
				'source'     => 'https://example.test/wp-sitemap.xml',
				'menu'       => false,
				'front_page' => false,
				'manual'     => false,
			),
			$overrides
		);
	}
}
