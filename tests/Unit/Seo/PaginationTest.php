<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Seo\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `append()` EDITS the canonical the SEO plugin already produced; it never
 * rebuilds one from `get_permalink()`.
 *
 * That is what keeps WPML intact for free. The language domain, the
 * per-language slug and the site's trailing-slash policy are all baked into
 * the string the plugin hands over. Rebuilding would re-derive all three and
 * get at least one wrong.
 */
final class PaginationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The real function appends or strips a trailing slash to match the
		// site's permalink structure. The suite pins the trailing-slash case,
		// which is what every route in the motivating project serves.
		Functions\when( 'user_trailingslashit' )->alias(
			static fn( string $string ): string => rtrim( $string, '/' ) . '/'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function canonicals(): array {
		return array(
			'page one is untouched'          => array( 'https://x.test/blog/', 1, 'https://x.test/blog/' ),
			'a later page gains the segment' => array( 'https://x.test/blog/', 10, 'https://x.test/blog/page/10/' ),
			'already paginated is idempotent' => array( 'https://x.test/blog/page/10/', 10, 'https://x.test/blog/page/10/' ),
			'page one never keeps page/1'    => array( 'https://x.test/blog/page/1/', 1, 'https://x.test/blog/' ),
			'a query string survives'        => array( 'https://x.test/blog/?s=a', 2, 'https://x.test/blog/page/2/?s=a' ),
			'a fragment survives'            => array( 'https://x.test/blog/#top', 2, 'https://x.test/blog/page/2/#top' ),
			'a language directory survives'  => array( 'https://x.test/en/blog/', 3, 'https://x.test/en/blog/page/3/' ),
		);
	}

	#[DataProvider( 'canonicals' )]
	public function testTheSegmentIsAppendedWithoutDisturbingTheRest(
		string $canonical,
		int $page,
		string $expected
	): void {
		$this->assertSame( $expected, Pagination::append( $canonical, $page ) );
	}

	/**
	 * Yoast's `wpseo_canonical` may hand back `false` to drop the tag entirely.
	 * Appending to that would resurrect a canonical someone deliberately
	 * removed, so the adapters must never reach this method with a non-string —
	 * and an empty string is the same shape of nothing.
	 */
	public function testAnEmptyCanonicalStaysEmpty(): void {
		$this->assertSame( '', Pagination::append( '', 5 ) );
	}
}
