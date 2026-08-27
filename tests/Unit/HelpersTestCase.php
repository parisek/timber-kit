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
		// Field-group answers are memoized on a static, which outlives the
		// test that filled it.
		Helpers::flushFieldGroups();
		// get_post_type is called by Helpers::isNavMenuItemPostId() on the
		// function_exists() branch.  Default to 'post' so tests that don't
		// need nav_menu_item dispatch don't have to mock it individually.
		// Tests that need a specific return value override this in their body.
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		// The field-group memo keys on blog + language, so any test that reaches
		// formatFields() calls these. Defaults here rather than in each test,
		// matching get_post_type above; tests that care override them.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $value ) => json_encode( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
