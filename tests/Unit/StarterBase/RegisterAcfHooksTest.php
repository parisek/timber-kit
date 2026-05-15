<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerAcfHooks() registers ACF JSON sync filters and
 * field-value formatting hooks.
 */
class RegisterAcfHooksTest extends StarterBaseTestCase {

	private function invokeRegisterAcfHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerAcfHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_acf_json_sync_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterAcfHooks( $this->bareInstance() );

		$this->assertContains( 'acf/settings/load_json', $filters );
		$this->assertContains( 'acf/json/save_paths', $filters );
		$this->assertContains( 'acf/json/save_file_name', $filters );
	}

	public function test_registers_acf_field_formatting_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterAcfHooks( $this->bareInstance() );

		$this->assertContains( 'acf/update_value/type=wysiwyg', $filters );
		$this->assertContains( 'acf/format_value/type=post_object', $filters );
	}

	public function test_registers_exactly_five_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterAcfHooks( $this->bareInstance() );

		$this->assertCount( 5, $filters, 'registerAcfHooks should register exactly 5 filters' );
	}
}
