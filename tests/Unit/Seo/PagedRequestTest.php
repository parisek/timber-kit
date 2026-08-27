<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Seo\PagedRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WordPress fills `paged` on an archive and `page` on a singular page. A block
 * that paginates while sitting on an ordinary page reads the second one, and a
 * reader of only the first gets 0 there — serving page 1 forever behind a 200,
 * which no status check can see.
 */
final class PagedRequestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed, 2: int}>
	 */
	public static function queryVars(): array {
		return array(
			'archive reads paged'          => array( 4, 0, 4 ),
			'singular page reads page'     => array( 0, 7, 7 ),
			'unpaginated is page one'      => array( 0, 0, 1 ),
			'absent variable is page one'  => array( '', '', 1 ),
			'negative floors at one'       => array( -3, 0, 1 ),
			'both set takes the larger'    => array( 2, 5, 5 ),
		);
	}

	#[DataProvider( 'queryVars' )]
	public function testTheRequestedPageIsReadFromWhicheverVariableCarriesIt(
		mixed $paged,
		mixed $page,
		int $expected
	): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( string $key ): mixed => array(
				'paged' => $paged,
				'page'  => $page,
			)[ $key ] ?? ''
		);

		$this->assertSame( $expected, PagedRequest::current() );
	}
}
