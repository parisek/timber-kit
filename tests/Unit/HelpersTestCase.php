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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
