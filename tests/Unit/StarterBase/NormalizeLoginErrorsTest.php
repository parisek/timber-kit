<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class NormalizeLoginErrorsTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->alias( fn( $s ) => $s );
		$this->base = $this->createStarterBase();
	}

	public function test_replaces_username_revealing_error_with_generic_message(): void {
		$generic = $this->base->normalize_login_errors(
			'<strong>Error:</strong> The username <strong>admin</strong> is not registered on this site.'
		);

		// The original confirmed a valid/invalid username; the replacement must not echo it back.
		$this->assertStringNotContainsString( 'admin', $generic );
		$this->assertNotSame( '', $generic );
	}

	public function test_returns_the_same_message_regardless_of_input(): void {
		$unknown_user = $this->base->normalize_login_errors( 'Unknown username. Check again or try your email address.' );
		$wrong_pass   = $this->base->normalize_login_errors( 'The password you entered for the username admin is incorrect.' );

		// A constant reply closes the username-confirmation oracle.
		$this->assertSame( $unknown_user, $wrong_pass );
	}
}
