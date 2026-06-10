<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class DisableAuthorSitemapProviderTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_drops_the_users_provider(): void {
		$this->assertFalse( $this->base->disable_author_sitemap_provider( 'provider-object', 'users' ) );
	}

	public function test_passes_through_other_providers(): void {
		$this->assertSame( 'provider-object', $this->base->disable_author_sitemap_provider( 'provider-object', 'posts' ) );
		$this->assertSame( 'provider-object', $this->base->disable_author_sitemap_provider( 'provider-object', 'taxonomies' ) );
	}
}
