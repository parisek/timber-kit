<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Parisek\TimberKit\Seo\PaginationBase;
use PHPUnit\Framework\TestCase;

/**
 * Reads the pagination segment WordPress keeps on `$wp_rewrite`.
 *
 * `Pagination::append()` stays pure and takes the base as a parameter; this is
 * the one piece that reaches for the global, so it can be exercised without
 * bootstrapping WordPress at all — a plain global assignment is enough.
 */
final class PaginationBaseTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wp_rewrite'] );
		parent::tearDown();
	}

	public function testTheDefaultBaseIsUsedWhenTheGlobalIsAbsent(): void {
		unset( $GLOBALS['wp_rewrite'] );

		$this->assertSame( 'page', PaginationBase::current() );
	}

	public function testTheDefaultBaseIsUsedWhenTheGlobalIsNotAnObject(): void {
		$GLOBALS['wp_rewrite'] = 'not an object';

		$this->assertSame( 'page', PaginationBase::current() );
	}

	public function testTheDefaultBaseIsUsedWhenThePropertyIsEmpty(): void {
		$GLOBALS['wp_rewrite']                  = new \stdClass();
		$GLOBALS['wp_rewrite']->pagination_base = '';

		$this->assertSame( 'page', PaginationBase::current() );
	}

	public function testACustomBaseIsRead(): void {
		$GLOBALS['wp_rewrite']                  = new \stdClass();
		$GLOBALS['wp_rewrite']->pagination_base = 'strana';

		$this->assertSame( 'strana', PaginationBase::current() );
	}
}
