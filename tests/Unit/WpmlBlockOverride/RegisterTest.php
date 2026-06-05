<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

class RegisterTest extends WpmlBlockOverrideTestCase {

	public function test_registers_render_block_data_after_wpml_at_priority_20(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias( function ( $hook, $cb = null, $priority = 10, $args = 1 ) use ( &$filters ) {
			$filters[ $hook ] = $priority;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		WpmlBlockOverride::register();

		// Priority 20 is load-bearing: it must run AFTER WPML's own
		// render_block_data handlers, or our overrides get reverted.
		$this->assertArrayHasKey( 'render_block_data', $filters );
		$this->assertSame( 20, $filters['render_block_data'] );
	}

	public function test_registers_field_group_cache_flush(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		WpmlBlockOverride::register();

		$this->assertContains( 'acf/update_field_group', $actions );
	}
}
