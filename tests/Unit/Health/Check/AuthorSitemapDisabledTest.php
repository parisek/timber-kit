<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\AuthorSitemapDisabled;
use Parisek\TimberKit\Health\HealthCheck;
use Tests\Unit\Health\HealthTestCase;

class AuthorSitemapDisabledTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_identity(): void {
		$check = new AuthorSitemapDisabled();

		$this->assertSame( 'author_sitemap_disabled', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_good_when_users_provider_is_absent(): void {
		Functions\when( 'wp_get_sitemap_providers' )->justReturn( [ 'posts' => new \stdClass() ] );

		$this->assertSame( 'good', ( new AuthorSitemapDisabled() )->run()->status() );
	}

	public function test_good_when_sitemaps_are_disabled_entirely(): void {
		Filters\expectApplied( 'wp_sitemaps_enabled' )->once()->andReturn( false );

		$this->assertSame( 'good', ( new AuthorSitemapDisabled() )->run()->status() );
	}

	public function test_recommended_when_users_provider_is_registered(): void {
		// wp_sitemaps_enabled passes through by default (sitemaps on).
		Functions\when( 'wp_get_sitemap_providers' )->justReturn( [ 'posts' => new \stdClass(), 'users' => new \stdClass() ] );

		$this->assertSame( 'recommended', ( new AuthorSitemapDisabled() )->run()->status() );
	}
}
