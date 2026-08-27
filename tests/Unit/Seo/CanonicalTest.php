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
	}

	protected function tearDown(): void {
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

	public function testASingularPageGetsASelfReferencingCanonical(): void {
		$this->onPage( 0, 10 );

		$this->assertSame(
			'https://x.test/blog/page/10/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	public function testAnArchiveIsCoveredByTheSameCallback(): void {
		$this->onPage( 3, 0 );

		$this->assertSame(
			'https://x.test/blog/page/3/',
			Canonical::filter( 'https://x.test/blog/' )
		);
	}

	public function testTheFirstPageIsLeftAlone(): void {
		$this->onPage( 0, 0 );

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
}
