<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class AcfGoogleMapApiTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_sets_api_key_when_constant_defined(): void {
		\Brain\Monkey\Functions\when( 'defined' )->alias( function ( $name ) {
			return $name === 'GOOGLE_MAPS_API_KEY';
		} );

		define( 'GOOGLE_MAPS_API_KEY', 'test-api-key-123' );

		$api = [];
		$result = $this->base->acf_google_map_api( $api );

		$this->assertSame( 'test-api-key-123', $result['key'] );
	}

	public function test_returns_unchanged_when_constant_not_defined(): void {
		\Brain\Monkey\Functions\when( 'defined' )->justReturn( false );

		$api = [ 'existing' => 'value' ];
		$result = $this->base->acf_google_map_api( $api );

		$this->assertSame( $api, $result );
		$this->assertArrayNotHasKey( 'key', $result );
	}
}
