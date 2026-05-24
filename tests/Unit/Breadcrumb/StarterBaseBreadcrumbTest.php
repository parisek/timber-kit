<?php
declare(strict_types=1);

namespace Tests\Unit\Breadcrumb;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Parisek\TimberKit\StarterBase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests the legacy-class guard in StarterBase::timber_context().
 *
 * Each test runs in a separate PHP process (@runInSeparateProcess) so the
 * global \Breadcrumb class declaration doesn't leak between tests.
 *
 * StarterBase is instantiated without invoking its constructor (which would
 * fire 30+ WordPress hooks during testing). We use reflection to call
 * timber_context() directly on a constructor-less instance.
 */
final class StarterBaseBreadcrumbTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auto_populate_skipped_when_breadcrumb_class_exists(): void {
		require __DIR__ . '/../../Fixtures/LegacyBreadcrumbStub.php';

		$this->stub_timber_context_wp_functions();

		$context = $this->invoke_timber_context( [ 'existing_key' => 'preserved' ] );

		$this->assertArrayNotHasKey( 'breadcrumb', $context );
		$this->assertSame( 'preserved', $context['existing_key'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auto_populate_runs_when_breadcrumb_class_absent(): void {
		// Fixture NOT required — \Breadcrumb is absent in this fresh process.
		$this->stub_timber_context_wp_functions();

		// Stub the WP functions Breadcrumb::get() needs to traverse the dispatcher;
		// shortest path: front_page returns [] before any strategy dispatch fires.
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_paged' )->justReturn( false );
		Filters\expectApplied( 'timber_kit_breadcrumb_skip' )->andReturn( false );

		$context = $this->invoke_timber_context( [] );

		$this->assertArrayHasKey( 'breadcrumb', $context );
		$this->assertSame( [], $context['breadcrumb'] );
	}

	/**
	 * Stub the WordPress functions that timber_context() calls unconditionally.
	 * These must be wired before invoke_timber_context() is called.
	 */
	private function stub_timber_context_wp_functions(): void {
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.com/wp-content/themes/test' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'function_exists' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'en-US' );
		Functions\when( 'get_search_query' )->justReturn( '' );
	}

	/**
	 * @param array<string, mixed> $initial
	 * @return array<string, mixed>
	 */
	private function invoke_timber_context( array $initial ): array {
		$reflection = new ReflectionClass( StarterBase::class );
		$instance = $reflection->newInstanceWithoutConstructor();

		$method = new ReflectionMethod( $instance, 'timber_context' );
		return $method->invoke( $instance, $initial );
	}
}
