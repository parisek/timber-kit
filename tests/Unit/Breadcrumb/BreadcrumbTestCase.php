<?php

declare(strict_types=1);

namespace Tests\Unit\Breadcrumb;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breadcrumb;
use ReflectionMethod;

abstract class BreadcrumbTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Breadcrumb::build() resolves menu item URLs through
		// Helpers::urlToPostId(), which memoizes on a static keyed by blog,
		// language and URL. A static outlives the test that filled it, so
		// without this a later test is answered by an earlier test's mocks.
		Helpers::flushResolvedPostIds();
		// The memo key carries the blog id; single-site tests are blog 1.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke a protected method on a Breadcrumb instance via reflection.
	 * Used by subclass tests to exercise strategy methods (`build_for_*`)
	 * and helpers (`get_menu_item`, `by_menu_trail`, `get_global_links`)
	 * in isolation.
	 *
	 * @param array<int, mixed> $args
	 */
	protected function invoke_protected( Breadcrumb $bc, string $method, array $args = [] ): mixed {
		$reflection = new ReflectionMethod( $bc, $method );
		return $reflection->invoke( $bc, ...$args );
	}
}
