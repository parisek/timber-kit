<?php

declare(strict_types=1);

namespace Tests\Unit\CacheSignature;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\CacheSignature;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared part of every cross-request cache key.
 *
 * One test per dimension, because each one is in the key for its own reason.
 * A dimension that stops separating two worlds is a cache that serves one
 * visitor's content to another, and nothing downstream can notice.
 */
class SharedSignatureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CacheSignature::flush();

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default = null, ...$args ) {
				unset( $filter, $args );
				return $default;
			}
		);
		Functions\when( 'get_locale' )->justReturn( 'cs_CZ' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_cache_get_last_changed' )->alias(
			static fn ( string $group ) => $group . ':1'
		);
	}

	protected function tearDown(): void {
		CacheSignature::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function signature(): string {
		CacheSignature::flush();
		return CacheSignature::shared();
	}

	private function loggedInWith( array $roles ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) [ 'roles' => $roles ]
		);
	}

	public function test_another_site_is_a_different_world(): void {
		$one = $this->signature();
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );

		$this->assertNotSame( $one, $this->signature() );
	}

	public function test_another_language_is_a_different_world(): void {
		$one = $this->signature();
		Functions\when( 'get_locale' )->justReturn( 'it_IT' );

		$this->assertNotSame( $one, $this->signature() );
	}

	public function test_another_role_set_is_a_different_world(): void {
		$anon = $this->signature();
		$this->loggedInWith( [ 'editor' ] );
		$editor = $this->signature();
		$this->loggedInWith( [ 'administrator' ] );

		$this->assertNotSame( $anon, $editor );
		$this->assertNotSame( $editor, $this->signature() );
	}

	public function test_role_order_is_not_a_different_world(): void {
		// Two accounts holding the same roles must share an entry, or the
		// cache fragments on an accident of storage order.
		$this->loggedInWith( [ 'editor', 'shop_manager' ] );
		$one = $this->signature();
		$this->loggedInWith( [ 'shop_manager', 'editor' ] );

		$this->assertSame( $one, $this->signature() );
	}

	public function test_a_logged_in_user_without_roles_is_not_anonymous(): void {
		// A plugin can show a logged-in visitor something an anonymous one
		// must not see, roles or no roles.
		$anon = $this->signature();
		$this->loggedInWith( [] );

		$this->assertNotSame( $anon, $this->signature() );
	}

	public function test_a_changed_post_is_a_different_world(): void {
		$one = $this->signature();
		Functions\when( 'wp_cache_get_last_changed' )->alias(
			static fn ( string $group ) => 'posts' === $group ? 'posts:2' : $group . ':1'
		);

		$this->assertNotSame( $one, $this->signature(), 'A renamed page moves the links inside a cached menu.' );
	}

	public function test_a_changed_term_is_a_different_world(): void {
		$one = $this->signature();
		Functions\when( 'wp_cache_get_last_changed' )->alias(
			static fn ( string $group ) => 'terms' === $group ? 'terms:2' : $group . ':1'
		);

		$this->assertNotSame( $one, $this->signature(), 'A menu is a term.' );
	}

	public function test_the_signature_holds_still_within_one_request(): void {
		$one = CacheSignature::shared();
		Functions\when( 'get_current_blog_id' )->justReturn( 99 );

		$this->assertSame( $one, CacheSignature::shared(), 'Memoized until flushed.' );
	}

	public function test_flush_lets_a_long_running_process_move_on(): void {
		$one = CacheSignature::shared();
		Functions\when( 'get_current_blog_id' )->justReturn( 99 );
		CacheSignature::flush();

		$this->assertNotSame( $one, CacheSignature::shared() );
	}
}
