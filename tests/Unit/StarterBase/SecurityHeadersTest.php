<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class SecurityHeadersTest extends StarterBaseTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		parent::tearDown();
	}

	public function test_emits_the_baseline_header_set(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$headers = $this->createStarterBase()->security_headers( [] );

		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
		$this->assertSame( 'upgrade-insecure-requests', $headers['Content-Security-Policy'] );
		$this->assertSame( 'geolocation=(), microphone=(), camera=()', $headers['Permissions-Policy'] );
		$this->assertSame( '0', $headers['X-XSS-Protection'] );
	}

	public function test_preserves_existing_outgoing_headers(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$headers = $this->createStarterBase()->security_headers( [ 'Content-Type' => 'text/html; charset=UTF-8' ] );

		$this->assertSame( 'text/html; charset=UTF-8', $headers['Content-Type'] );
	}

	public function test_omits_hsts_on_plain_http(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$headers = $this->createStarterBase()->security_headers( [] );

		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
	}

	public function test_adds_hsts_when_is_ssl_true(): void {
		Functions\when( 'is_ssl' )->justReturn( true );
		$headers = $this->createStarterBase()->security_headers( [] );

		$this->assertArrayHasKey( 'Strict-Transport-Security', $headers );
		$this->assertStringContainsString( 'max-age=', $headers['Strict-Transport-Security'] );
	}

	public function test_adds_hsts_behind_tls_terminating_proxy(): void {
		// is_ssl() reports false behind a proxy that terminates TLS upstream;
		// the X-Forwarded-Proto hint is what tells us the request was really HTTPS.
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$headers = $this->createStarterBase()->security_headers( [] );

		$this->assertArrayHasKey( 'Strict-Transport-Security', $headers );
	}

	public function test_config_overrides_and_extends_defaults(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$base = $this->createStarterBase( [
			'security_headers_config' => [
				'X-Frame-Options' => 'DENY',
				'X-Custom-Header' => 'on',
			],
		] );

		$headers = $base->security_headers( [] );

		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertSame( 'on', $headers['X-Custom-Header'] );
	}

	public function test_config_null_value_drops_a_default_header(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$base = $this->createStarterBase( [
			'security_headers_config' => [ 'X-XSS-Protection' => null ],
		] );

		$headers = $base->security_headers( [] );

		// A null override removes the header entirely (array_merge can't unset).
		$this->assertArrayNotHasKey( 'X-XSS-Protection', $headers );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
	}

	public function test_hsts_omits_preload_by_default(): void {
		Functions\when( 'is_ssl' )->justReturn( true );
		$headers = $this->createStarterBase()->security_headers( [] );

		$this->assertStringNotContainsString( 'preload', $headers['Strict-Transport-Security'] );
	}

	public function test_hsts_appends_preload_when_enabled(): void {
		Functions\when( 'is_ssl' )->justReturn( true );
		$base = $this->createStarterBase( [ 'hsts_preload' => true ] );

		$headers = $base->security_headers( [] );

		$this->assertStringContainsString( '; preload', $headers['Strict-Transport-Security'] );
		$this->assertSame( 'max-age=31536000; includeSubDomains; preload', $headers['Strict-Transport-Security'] );
	}

	public function test_hsts_preload_does_not_apply_on_plain_http(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$base = $this->createStarterBase( [ 'hsts_preload' => true ] );

		$headers = $base->security_headers( [] );

		// preload rides on HSTS, which is only emitted over TLS — so no HSTS, no preload.
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
	}
}
