<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
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
		// The link memo keys on the blog id; single-site tests get blog 1.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		// formatLink() memoizes resolved link URLs in a static array. A static
		// outlives the test that filled it, so one test's answer would be
		// returned to the next one instead of its own mocks being called.
		Helpers::flushTranslatedLinkUrls();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
