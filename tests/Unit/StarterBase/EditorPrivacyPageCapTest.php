<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class EditorPrivacyPageCapTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_editor_gets_privacy_cap_removed(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );

		$user_data = (object) [ 'roles' => [ 'editor' ] ];
		Functions\when( 'get_userdata' )->justReturn( $user_data );

		$caps = [ 'manage_options' ];
		$result = $this->base->editor_privacy_page_cap( $caps, 'manage_privacy_options', 1, [] );

		$this->assertNotContains( 'manage_options', $result );
	}

	public function test_administrator_gets_privacy_cap_removed(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );

		$user_data = (object) [ 'roles' => [ 'administrator' ] ];
		Functions\when( 'get_userdata' )->justReturn( $user_data );

		$caps = [ 'manage_options' ];
		$result = $this->base->editor_privacy_page_cap( $caps, 'manage_privacy_options', 1, [] );

		$this->assertNotContains( 'manage_options', $result );
	}

	public function test_subscriber_caps_unchanged(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$user_data = (object) [ 'roles' => [ 'subscriber' ] ];
		Functions\when( 'get_userdata' )->justReturn( $user_data );

		$caps = [ 'manage_options' ];
		$result = $this->base->editor_privacy_page_cap( $caps, 'manage_privacy_options', 1, [] );

		$this->assertContains( 'manage_options', $result );
	}

	public function test_not_logged_in_returns_unchanged(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$caps = [ 'manage_options' ];
		$result = $this->base->editor_privacy_page_cap( $caps, 'manage_privacy_options', 1, [] );

		$this->assertSame( $caps, $result );
	}

	public function test_multisite_removes_manage_network(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );

		$user_data = (object) [ 'roles' => [ 'editor' ] ];
		Functions\when( 'get_userdata' )->justReturn( $user_data );

		$caps = [ 'manage_network' ];
		$result = $this->base->editor_privacy_page_cap( $caps, 'manage_privacy_options', 1, [] );

		$this->assertNotContains( 'manage_network', $result );
	}
}
