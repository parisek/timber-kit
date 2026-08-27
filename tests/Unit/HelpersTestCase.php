<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

abstract class HelpersTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// get_post_type is called by Helpers::isNavMenuItemPostId() on the
		// function_exists() branch.  Default to 'post' so tests that don't
		// need nav_menu_item dispatch don't have to mock it individually.
		// Tests that need a specific return value override this in their body.
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		// No persistent object cache in a unit test, so the cross-request menu
		// payload is a no-op unless a test opts into it. Same reason as
		// get_post_type above: the alternative is stubbing it in every test
		// that formats a menu.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
