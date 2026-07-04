<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies the opt-in privacy-policy context population in timber_context():
 * off by default, and when enabled it exposes get_privacy_policy_url() under
 * the configurable (deliberately non-semantic) context key.
 */
class TimberContextPrivacyPolicyTest extends StarterBaseTestCase {

	private function mockContextDependencies(): void {
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.com/wp-content/themes/t' );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'cs-CZ' );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy' );
	}

	public function test_privacy_policy_key_absent_by_default(): void {
		$this->mockContextDependencies();
		$base = $this->createStarterBase( [ 'autopopulate_breadcrumb' => false ] );

		$context = $base->timber_context( [] );

		$this->assertArrayNotHasKey( 'ccnstL', $context, 'privacy-policy context is opt-in — must not appear while the flag is off (default)' );
	}

	public function test_flag_on_populates_default_key_with_privacy_policy_url(): void {
		$this->mockContextDependencies();
		$base = $this->createStarterBase( [
			'autopopulate_breadcrumb' => false,
			'context_privacy_policy' => true,
		] );

		$context = $base->timber_context( [] );

		$this->assertSame( 'https://example.com/privacy', $context['ccnstL'] );
	}

	public function test_flag_on_honors_custom_context_key(): void {
		$this->mockContextDependencies();
		$base = $this->createStarterBase( [
			'autopopulate_breadcrumb' => false,
			'context_privacy_policy' => true,
			'privacy_policy_context_key' => 'privacy_url',
		] );

		$context = $base->timber_context( [] );

		$this->assertSame( 'https://example.com/privacy', $context['privacy_url'] );
		$this->assertArrayNotHasKey( 'ccnstL', $context );
	}
}
