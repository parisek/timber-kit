<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class EditorLoginRedirectTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase( [
			'editor_login_redirect_url' => 'edit.php?post_type=page',
		] );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
	}

	public function test_admin_gets_original_redirect(): void {
		$user = \Mockery::mock( 'WP_User' );
		$user->roles = [ 'administrator' ];

		$result = $this->base->editor_login_redirect( '/wp-admin/', '', $user );
		$this->assertSame( '/wp-admin/', $result );
	}

	public function test_editor_gets_custom_redirect(): void {
		$user = \Mockery::mock( 'WP_User' );
		$user->roles = [ 'editor' ];

		$result = $this->base->editor_login_redirect( '/wp-admin/', '', $user );
		$this->assertSame( 'https://example.com/wp-admin/edit.php?post_type=page', $result );
	}

	public function test_subscriber_gets_custom_redirect(): void {
		$user = \Mockery::mock( 'WP_User' );
		$user->roles = [ 'subscriber' ];

		$result = $this->base->editor_login_redirect( '/wp-admin/', '', $user );
		$this->assertSame( 'https://example.com/wp-admin/edit.php?post_type=page', $result );
	}

	public function test_non_wp_user_gets_original_redirect(): void {
		$result = $this->base->editor_login_redirect( '/wp-admin/', '', null );
		$this->assertSame( '/wp-admin/', $result );
	}

	public function test_custom_redirect_url_is_configurable(): void {
		$base = $this->createStarterBase( [
			'editor_login_redirect_url' => 'edit.php?post_type=post',
		] );

		$user = \Mockery::mock( 'WP_User' );
		$user->roles = [ 'editor' ];

		$result = $base->editor_login_redirect( '/wp-admin/', '', $user );
		$this->assertSame( 'https://example.com/wp-admin/edit.php?post_type=post', $result );
	}
}
