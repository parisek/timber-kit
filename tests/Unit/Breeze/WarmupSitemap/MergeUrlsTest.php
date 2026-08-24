<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\WarmupSitemap;

use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * Covers the merge rule — the only new code that runs inside the purge
 * request, and therefore the riskiest in the module.
 *
 * Sorting is forbidden here: the cost must not grow with the size of the
 * sitemap. The rule is positional, and correctness rests on comparing
 * canonical keys rather than raw strings.
 */
class MergeUrlsTest extends TestCase {

	private const HOME = 'https://example.test/';

	public function test_homepage_leads(): void {
		$result = WarmupSitemap::mergeUrls(
			array( 'https://example.test/', 'https://example.test/shop/' ),
			array( 'https://example.test/kontakt/' ),
			self::HOME
		);

		$this->assertSame( 'https://example.test/', $result[0] );
	}

	public function test_breeze_only_entries_come_before_our_list(): void {
		// Entries the admin typed but which are not in the sitemap cannot be
		// scored, so they sit right behind the homepage — matching the fact
		// that `manual` is the second highest weight.
		$result = WarmupSitemap::mergeUrls(
			array( 'https://example.test/', 'https://example.test/akce/' ),
			array( 'https://example.test/kontakt/' ),
			self::HOME
		);

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/akce/', 'https://example.test/kontakt/' ),
			$result
		);
	}

	public function test_our_ordering_is_preserved(): void {
		$result = WarmupSitemap::mergeUrls(
			array( 'https://example.test/' ),
			array( 'https://example.test/first/', 'https://example.test/second/' ),
			self::HOME
		);

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/first/', 'https://example.test/second/' ),
			$result
		);
	}

	public function test_homepage_is_never_duplicated(): void {
		// Breeze builds it with trailingslashit(); a sitemap may emit it
		// without the slash. Keyed on the raw string those are two URLs.
		$result = WarmupSitemap::mergeUrls(
			array( 'https://example.test/' ),
			array( 'https://example.test', 'https://example.test/a/' ),
			self::HOME
		);

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/a/' ),
			$result
		);
	}

	public function test_dedup_uses_canonical_keys(): void {
		$result = WarmupSitemap::mergeUrls(
			array( 'https://example.test/', 'https://example.test/akce' ),
			array( 'https://example.test/akce/' ),
			self::HOME
		);

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/akce' ),
			$result
		);
	}

	public function test_homepage_missing_from_breeze_list_is_not_invented(): void {
		$result = WarmupSitemap::mergeUrls(
			array( 'https://example.test/shop/' ),
			array( 'https://example.test/a/' ),
			self::HOME
		);

		$this->assertSame(
			array( 'https://example.test/shop/', 'https://example.test/a/' ),
			$result
		);
	}

	public function test_empty_ordered_list_returns_breeze_list_unchanged(): void {
		$existing = array( 'https://example.test/', 'https://example.test/shop/' );

		$this->assertSame( $existing, WarmupSitemap::mergeUrls( $existing, array(), self::HOME ) );
	}
}
