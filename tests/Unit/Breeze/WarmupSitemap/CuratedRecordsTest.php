<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\WarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * A curated URL has to become a record, not a flag on someone else's.
 *
 * The bug this guards was found in review: enrichRecords() only marked
 * `manual` on records the sitemap had already produced, so an entry the
 * sitemap ranks badly or omits entirely did nothing at all — which is the whole
 * case a curated list exists for.
 */
final class CuratedRecordsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 * @param array<string, true>              $manual
	 * @return array<int, array<string, mixed>>
	 */
	private function append( array $records, array $manual ): array {
		$method = new \ReflectionMethod( WarmupSitemap::class, 'appendMissingManual' );
		return $method->invoke( null, $records, $manual );
	}

	public function test_a_curated_key_absent_from_the_sitemap_becomes_a_record(): void {
		$records = $this->append(
			array(
				array( 'url' => 'https://example.test/a/', 'key' => 'https://example.test/a/' ),
			),
			array( 'https://example.test/b/' => true )
		);

		$this->assertCount( 2, $records );
		$this->assertSame( 'https://example.test/b/', $records[1]['key'] );
		$this->assertSame( 'curated', $records[1]['source'] );
		$this->assertNull( $records[1]['lastmod'], 'an invented date would buy a freshness score it has not earned' );
	}

	public function test_a_curated_key_the_sitemap_already_has_is_not_duplicated(): void {
		$records = $this->append(
			array(
				array( 'url' => 'https://example.test/a/', 'key' => 'https://example.test/a/' ),
			),
			array( 'https://example.test/a/' => true )
		);

		$this->assertCount( 1, $records );
	}

	public function test_an_empty_sitemap_still_yields_the_curated_records(): void {
		// The case the early return used to swallow: the sitemap is missing or
		// broken, which is when an explicit list matters most.
		$records = $this->append( array(), array( 'https://example.test/b/' => true ) );

		$this->assertCount( 1, $records );
		$this->assertSame( 'https://example.test/b/', $records[0]['url'] );
	}

	public function test_no_curated_keys_changes_nothing(): void {
		$input = array( array( 'url' => 'https://example.test/a/', 'key' => 'https://example.test/a/' ) );

		$this->assertSame( $input, $this->append( $input, array() ) );
	}
}
