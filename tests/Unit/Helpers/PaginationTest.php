<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class PaginationTest extends HelpersTestCase {

	private ?string $previous_request_uri = null;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_request_uri = $_SERVER['REQUEST_URI'] ?? null;
		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'https://example.com' . $path;
		} );
		$_SERVER['REQUEST_URI'] = '/blog/page/2';
	}

	protected function tearDown(): void {
		if ( $this->previous_request_uri === null ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->previous_request_uri;
		}
		parent::tearDown();
	}

	public function test_basic_pagination(): void {
		$pagination = (object) [
			'current' => 2,
			'total'   => 5,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => false ],
				[ 'link' => '/blog/page/2/', 'title' => '2', 'current' => true ],
				[ 'link' => '/blog/page/3/', 'title' => '3', 'current' => false ],
			],
			'next'    => [ 'link' => '/blog/page/3/' ],
			'prev'    => [ 'link' => '/blog/' ],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( 2, $result['current'] );
		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 3, $result['pages'] );
		$this->assertSame( '/blog/', $result['pages'][0]['url'] );
		$this->assertTrue( $result['pages'][1]['current'] );
	}

	public function test_first_and_last(): void {
		$pagination = (object) [
			'current' => 2,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => false ],
				[ 'link' => '/blog/page/2/', 'title' => '2', 'current' => true ],
				[ 'link' => '/blog/page/3/', 'title' => '3', 'current' => false ],
			],
			'next'    => [ 'link' => '/blog/page/3/' ],
			'prev'    => [ 'link' => '/blog/' ],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( '/blog/', $result['first']['url'] );
		$this->assertFalse( $result['first']['disabled'] );
		$this->assertSame( '/blog/page/3/', $result['last']['url'] );
		$this->assertFalse( $result['last']['disabled'] );
	}

	public function test_first_disabled_on_first_page(): void {
		$pagination = (object) [
			'current' => 1,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => true ],
				[ 'link' => '/blog/page/2/', 'title' => '2', 'current' => false ],
				[ 'link' => '/blog/page/3/', 'title' => '3', 'current' => false ],
			],
			'prev'    => null,
		];

		$result = Helpers::pagination( $pagination );

		$this->assertTrue( $result['first']['disabled'] );
	}

	public function test_last_disabled_on_last_page(): void {
		$pagination = (object) [
			'current' => 3,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => false ],
				[ 'link' => '/blog/page/2/', 'title' => '2', 'current' => false ],
				[ 'link' => '/blog/page/3/', 'title' => '3', 'current' => true ],
			],
			'next'    => null,
		];

		$result = Helpers::pagination( $pagination );

		$this->assertTrue( $result['last']['disabled'] );
	}

	public function test_next_link(): void {
		$pagination = (object) [
			'current' => 1,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => true ],
				[ 'link' => '/blog/page/2/', 'title' => '2', 'current' => false ],
			],
			'next'    => [ 'link' => '/blog/page/2/' ],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( '/blog/page/2/', $result['next']['url'] );
		$this->assertFalse( $result['next']['disabled'] );
	}

	public function test_no_next(): void {
		$pagination = (object) [
			'current' => 3,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/page/3/', 'title' => '3', 'current' => true ],
			],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( '', $result['next']['url'] );
		$this->assertTrue( $result['next']['disabled'] );
	}

	public function test_prev_link(): void {
		$pagination = (object) [
			'current' => 2,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => false ],
				[ 'link' => '/blog/page/2/', 'title' => '2', 'current' => true ],
			],
			'prev'    => [ 'link' => '/blog/' ],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( '/blog/', $result['previous']['url'] );
		$this->assertFalse( $result['previous']['disabled'] );
	}

	public function test_no_prev(): void {
		$pagination = (object) [
			'current' => 1,
			'total'   => 3,
			'pages'   => [
				[ 'link' => '/blog/', 'title' => '1', 'current' => true ],
			],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( '', $result['previous']['url'] );
		$this->assertTrue( $result['previous']['disabled'] );
	}

	public function test_page_without_link_uses_home_url(): void {
		$pagination = (object) [
			'current' => 1,
			'total'   => 2,
			'pages'   => [
				[ 'title' => '1', 'current' => true ],
			],
		];

		$result = Helpers::pagination( $pagination );

		$this->assertSame( 'https://example.com/blog/page/2', $result['pages'][0]['url'] );
	}
}
