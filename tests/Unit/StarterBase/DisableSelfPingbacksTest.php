<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class DisableSelfPingbacksTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_removes_self_pingback_links(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$links = [
			'https://example.com/post-1',
			'https://external.com/post-2',
			'https://example.com/post-3',
		];

		$this->base->disable_self_pingbacks( $links );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://external.com/post-2', $links[1] );
	}

	public function test_keeps_external_links(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$links = [
			'https://other-site.com/post-1',
			'https://another-site.com/post-2',
		];

		$this->base->disable_self_pingbacks( $links );

		$this->assertCount( 2, $links );
	}

	public function test_handles_empty_links(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$links = [];

		$this->base->disable_self_pingbacks( $links );

		$this->assertEmpty( $links );
	}
}
