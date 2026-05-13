<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class BlockAuthorEnumerationTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	private ?array $previous_get = null;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
		$this->previous_get = $_GET;
		Functions\when( 'wp_unslash' )->alias( fn( $v ) => $v );
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
	}

	protected function tearDown(): void {
		$_GET = $this->previous_get ?? [];
		unset( $GLOBALS['wp_query'] );
		parent::tearDown();
	}

	public function test_numeric_author_param_triggers_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$_GET['author'] = '1';
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->block_author_enumeration();

		$this->assertTrue( $wp_query->is_404, 'WP_Query::set_404 should have been called.' );
	}

	public function test_multi_digit_numeric_author_triggers_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$_GET['author'] = '4242';
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->block_author_enumeration();

		$this->assertTrue( $wp_query->is_404 );
	}

	public function test_non_numeric_author_slug_is_left_alone(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$_GET['author'] = 'editor';
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->block_author_enumeration();

		$this->assertFalse( $wp_query->is_404, 'Path-based /author/slug/ requests must keep working.' );
	}

	public function test_missing_author_param_is_left_alone(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		unset( $_GET['author'] );
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->block_author_enumeration();

		$this->assertFalse( $wp_query->is_404 );
	}

	public function test_admin_requests_are_skipped(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$_GET['author'] = '1';
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->block_author_enumeration();

		$this->assertFalse( $wp_query->is_404, 'Admin author filter dropdown must keep working.' );
	}

	public function test_mixed_numeric_and_alpha_author_is_left_alone(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$_GET['author'] = '1abc';
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->block_author_enumeration();

		$this->assertFalse( $wp_query->is_404 );
	}
}
