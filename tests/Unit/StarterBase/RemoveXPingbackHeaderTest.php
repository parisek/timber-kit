<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class RemoveXPingbackHeaderTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_removes_x_pingback_header(): void {
		$headers = [
			'X-Pingback' => 'https://example.com/xmlrpc.php',
			'Content-Type' => 'text/html',
		];

		$result = $this->base->remove_x_pingback_header( $headers );

		$this->assertArrayNotHasKey( 'X-Pingback', $result );
	}

	public function test_preserves_other_headers(): void {
		$headers = [
			'X-Pingback' => 'https://example.com/xmlrpc.php',
			'Content-Type' => 'text/html',
			'X-Frame-Options' => 'SAMEORIGIN',
		];

		$result = $this->base->remove_x_pingback_header( $headers );

		$this->assertSame( 'text/html', $result['Content-Type'] );
		$this->assertSame( 'SAMEORIGIN', $result['X-Frame-Options'] );
	}

	public function test_handles_empty_headers(): void {
		$result = $this->base->remove_x_pingback_header( [] );
		$this->assertSame( [], $result );
	}
}
