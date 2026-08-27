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

	/**
	 * Whether `$_SERVER['REQUEST_URI']` existed before this test touched it,
	 * and its original value -- restored in tearDown() so no test leaks
	 * superglobal state into the rest of the suite.
	 */
	private bool $had_request_uri = false;

	private mixed $original_request_uri = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_request_uri      = array_key_exists( 'REQUEST_URI', $_SERVER );
		$this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;

		Functions\when( 'user_trailingslashit' )->alias(
			static fn( string $string ): string => rtrim( $string, '/' ) . '/'
		);

		// Every fixture URL below lives on this host and carries no language
		// prefix by default -- individual tests override this to model a
		// WPML directory-per-language install instead.
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://x.test' . $path
		);
	}

	protected function tearDown(): void {
		if ( $this->had_request_uri ) {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}

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
	 * Sets `$_SERVER['REQUEST_URI']` -- the raw request line, exactly as a
	 * browser sent it, unfiltered by anything WordPress or a language plugin
	 * does to it afterwards.
	 */
	private function onRequest( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;
	}

	public function testASingularPageGetsASelfReferencingCanonical(): void {
		$this->onPage( 0, 10 );
		$this->onRequest( '/blog/' );

		$this->assertSame(
			'https://x.test/blog/page/10/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	public function testAnArchiveIsCoveredByTheSameCallback(): void {
		$this->onPage( 3, 0 );
		$this->onRequest( '/blog/' );

		$this->assertSame(
			'https://x.test/blog/page/3/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	public function testTheFirstPageIsLeftAlone(): void {
		$this->onPage( 0, 0 );
		$this->onRequest( '/blog/' );

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
		$this->onRequest( '/blog/page/2/' );

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
		$this->onRequest( '/blog/page/2/' );

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
		$this->onRequest( '/blog/page/2/' );

		$this->assertSame(
			'https://other.example/x/',
			Canonical::filter( 'https://other.example/x/' )
		);
	}

	/**
	 * Degradation path: with no usable `$_SERVER['REQUEST_URI']` this package
	 * cannot tell whether the canonical describes the current request, so it
	 * must fail safe by leaving the canonical alone rather than guessing.
	 */
	public function testAnUnreadableCurrentRequestLeavesTheCanonicalAlone(): void {
		$this->onPage( 2, 0 );
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertSame(
			'https://x.test/blog/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	/**
	 * WPML's directory-per-language mode filters `home_url()` to prepend the
	 * current language's directory to whatever path it is handed. A stub
	 * modelling that -- rather than the flat "no prefixing" stub the old
	 * suite used -- is what the previous version of `currentUrl()` fed a
	 * double-prefixed path into: `home_url( '/cs/blog/page/2' )` came back as
	 * `/cs/cs/blog/page/2`. Deriving the path from `REQUEST_URI` instead of
	 * from a call through `home_url()` is immune to that filter entirely, so
	 * this must still match.
	 */
	public function testALanguagePrefixingHomeUrlDoesNotDoublePrefixTheCurrentUrl(): void {
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://x.test/cs' . $path
		);

		$this->onPage( 2, 0 );
		$this->onRequest( '/cs/blog/page/2/' );

		$this->assertSame(
			'https://x.test/cs/blog/page/2/',
			Canonical::filter( 'https://x.test/cs/blog/' )
		);
	}

	/**
	 * Full round trip for a WPML directory-mode site: the language segment
	 * lives in both the canonical and the request path, and the two must
	 * still compare equal once pagination is stripped.
	 */
	public function testADirectoryModeRoundTripPaginatesTheLanguagePrefixedCanonical(): void {
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://site.test/cs' . $path
		);

		$this->onPage( 2, 0 );
		$this->onRequest( '/cs/blog/page/2/' );

		$this->assertSame(
			'https://site.test/cs/blog/page/2/',
			Canonical::filter( 'https://site.test/cs/blog/' )
		);
	}

	/**
	 * Full round trip for a WPML domain-mode site: no directory segment
	 * anywhere, `home_url()` already resolves to the language's own domain.
	 */
	public function testADomainModeRoundTripPaginatesTheCanonical(): void {
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://site.cz' . $path
		);

		$this->onPage( 2, 0 );
		$this->onRequest( '/blog/page/2/' );

		$this->assertSame(
			'https://site.cz/blog/page/2/',
			Canonical::filter( 'https://site.cz/blog/' )
		);
	}
}
