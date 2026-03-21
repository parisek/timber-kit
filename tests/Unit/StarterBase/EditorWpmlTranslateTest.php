<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class EditorWpmlTranslateTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_editor_with_translate_cap_returns_true(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$user = new \stdClass();
		$user->roles = [ 'editor' ];

		$this->assertTrue( $this->base->editor_wpml_translate( false, $user ) );
	}

	public function test_editor_without_translate_cap_returns_original(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = new \stdClass();
		$user->roles = [ 'editor' ];

		$this->assertFalse( $this->base->editor_wpml_translate( false, $user ) );
	}

	public function test_administrator_returns_original(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$user = new \stdClass();
		$user->roles = [ 'administrator' ];

		$this->assertTrue( $this->base->editor_wpml_translate( true, $user ) );
	}

	public function test_subscriber_returns_original(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = new \stdClass();
		$user->roles = [ 'subscriber' ];

		$this->assertFalse( $this->base->editor_wpml_translate( false, $user ) );
	}
}
