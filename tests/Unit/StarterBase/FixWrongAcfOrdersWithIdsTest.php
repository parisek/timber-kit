<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class FixWrongAcfOrdersWithIdsTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
		// is_plugin_active must be stubbed once for all tests in this class
		Functions\when( 'is_plugin_active' )->justReturn( true );
	}

	public function test_returns_value_when_function_not_exists(): void {
		Functions\when( 'function_exists' )->justReturn( false );

		$value = [ 10, 20, 30 ];
		$result = $this->base->fix_wrong_acf_orders_with_ids( $value, 'field_123', [] );

		$this->assertSame( $value, $result );
	}

	public function test_returns_non_array_value_unchanged(): void {
		$result = $this->base->fix_wrong_acf_orders_with_ids( 'string_value', 'field_123', [] );

		$this->assertSame( 'string_value', $result );
	}

	public function test_translates_post_ids_with_wpml(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $id ) {
			return $id + 100;
		} );

		$value = [ 10, 20, 30 ];
		$result = $this->base->fix_wrong_acf_orders_with_ids( $value, 'field_123', [] );

		$this->assertSame( [ 110, 120, 130 ], $result );
	}

	public function test_skips_non_integer_translated_ids(): void {
		Functions\when( 'apply_filters' )->justReturn( null );

		$value = [ 10, 20 ];
		$result = $this->base->fix_wrong_acf_orders_with_ids( $value, 'field_123', [] );

		$this->assertSame( [], $result );
	}
}
