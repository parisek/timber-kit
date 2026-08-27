<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Seo\Canonical;
use PHPUnit\Framework\TestCase;

/**
 * The filter both plugins call.
 *
 * The load-bearing case is the non-string one. Yoast's `wpseo_canonical` may
 * hand back `false` to drop the tag entirely; appending `/page/2/` to that
 * would resurrect a canonical somebody deliberately removed.
 */
final class CanonicalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'user_trailingslashit' )->alias(
			static fn( string $string ): string => rtrim( $string, '/' ) . '/'
		);

		// Every fixture URL below lives on this host; `home_url()` mirrors
		// whatever path the test hands `$wp->request`.
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://x.test' . $path
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param mixed $paged Value `get_query_var( 'paged' )` returns.
	 * @param mixed $page  Value `get_query_var( 'page' )` returns.
	 */
	private function onPage( mixed $paged, mixed $page ): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( string $key ): mixed => array(
				'paged' => $paged,
				'page'  => $page,
			)[ $key ] ?? ''
		);
	}

	/**
	 * Sets `$wp->request` to the path WordPress matched for this request --
	 * unprefixed, the way `WP::$request` really carries it, e.g. `blog`.
	 */
	private function onRequest( string $path ): void {
		$GLOBALS['wp']          = new \stdClass();
		$GLOBALS['wp']->request = $path;
	}

	public function testASingularPageGetsASelfReferencingCanonical(): void {
		$this->onPage( 0, 10 );
		$this->onRequest( 'blog' );

		$this->assertSame(
			'https://x.test/blog/page/10/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	public function testAnArchiveIsCoveredByTheSameCallback(): void {
		$this->onPage( 3, 0 );
		$this->onRequest( 'blog' );

		$this->assertSame(
			'https://x.test/blog/page/3/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	public function testTheFirstPageIsLeftAlone(): void {
		$this->onPage( 0, 0 );
		$this->onRequest( 'blog' );

		$this->assertSame(
			'https://x.test/blog/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	/**
	 * Yoast returning false means "emit no canonical". Honour it.
	 */
	public function testFalsePassesThroughSoADroppedTagStaysDropped(): void {
		$this->onPage( 0, 10 );

		$this->assertFalse( Canonical::filter( false ) );
	}

	public function testANonStringIsNeverCoercedIntoAUrl(): void {
		$this->onPage( 0, 10 );

		$this->assertNull( Canonical::filter( null ) );
	}

	/**
	 * The whole point of the PR: a request on `/blog/page/2/` whose plugin
	 * resolved the canonical to the un-paginated `/blog/` still gets the
	 * segment back, because the canonical -- pagination stripped -- describes
	 * this request.
	 */
	public function testACanonicalDescribingTheCurrentListingIsPaginated(): void {
		$this->onPage( 2, 0 );
		$this->onRequest( 'blog/page/2' );

		$this->assertSame(
			'https://x.test/blog/page/2/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	/**
	 * A manual canonical pointed at a different page on the same site --
	 * an editor's deliberate override -- is left exactly as they set it.
	 */
	public function testAManualCanonicalToADifferentPageIsUntouched(): void {
		$this->onPage( 2, 0 );
		$this->onRequest( 'blog/page/2' );

		$this->assertSame(
			'https://x.test/campaign/',
			Canonical::filter( 'https://x.test/campaign/' )
		);
	}

	/**
	 * A manual canonical pointed at another domain entirely is left alone too
	 * -- host is part of the comparison, not just path.
	 */
	public function testAManualCanonicalToAnotherDomainIsUntouched(): void {
		$this->onPage( 2, 0 );
		$this->onRequest( 'blog/page/2' );

		$this->assertSame(
			'https://other.example/x/',
			Canonical::filter( 'https://other.example/x/' )
		);
	}

	/**
	 * Degradation path: with no usable `$wp->request` this package cannot
	 * tell whether the canonical describes the current request, so it must
	 * fail safe by leaving the canonical alone rather than guessing.
	 */
	public function testAnUnreadableCurrentRequestLeavesTheCanonicalAlone(): void {
		$this->onPage( 2, 0 );
		unset( $GLOBALS['wp'] );

		$this->assertSame(
			'https://x.test/blog/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}
}
