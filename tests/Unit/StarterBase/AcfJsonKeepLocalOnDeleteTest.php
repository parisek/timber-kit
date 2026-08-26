<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies acf_json_keep_local_on_delete() — the guard that stops ACF from
 * deleting a local JSON file when a field group, post type or taxonomy is
 * deleted in wp-admin.
 *
 * ACF_Local_JSON is stubbed rather than loaded: the real class lives in ACF
 * Pro, which is not a dependency of this package. Only the two callback names
 * matter, and they are what the assertions pin.
 *
 * The `! function_exists( 'acf_get_instance' )` early return has no test on
 * purpose. Brain\Monkey resets call expectations but not function existence,
 * so once any earlier test in the run has mocked that function it stays
 * defined for the rest of the suite and the branch is unreachable. Documented
 * by inspection, per AGENTS.md.
 */
class AcfJsonKeepLocalOnDeleteTest extends StarterBaseTestCase {

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Stand-in for ACF's ACF_Local_JSON, carrying only the two methods the
	 * guard removes.
	 */
	private function localJsonStub(): object {
		return new class {
			public function delete_field_group( $field_group ) {}
			public function delete_internal_post_type( $post ) {}
		};
	}

	public function test_removes_every_delete_listener_acf_registers(): void {
		$json    = $this->localJsonStub();
		$removed = [];

		Functions\when( 'acf_get_instance' )->justReturn( $json );
		Functions\when( 'remove_action' )->alias( function ( $hook, $callback ) use ( &$removed ) {
			$removed[ $hook ] = $callback;
			return true;
		} );

		$this->bareInstance()->acf_json_keep_local_on_delete();

		$this->assertSame(
			array(
				'acf/trash_field_group',
				'acf/delete_field_group',
				'acf/trash_post_type',
				'acf/delete_post_type',
				'acf/trash_taxonomy',
				'acf/delete_taxonomy',
			),
			array_keys( $removed ),
			'all six delete listeners ACF_Local_JSON registers must be removed'
		);
	}

	public function test_targets_the_live_acf_instance_not_a_fresh_object(): void {
		$json    = $this->localJsonStub();
		$removed = [];

		Functions\when( 'acf_get_instance' )->justReturn( $json );
		Functions\when( 'remove_action' )->alias( function ( $hook, $callback ) use ( &$removed ) {
			$removed[ $hook ] = $callback;
			return true;
		} );

		$this->bareInstance()->acf_json_keep_local_on_delete();

		// remove_action matches on the callback identity, so the callback must
		// carry the very object ACF hooked. A new instance silently removes
		// nothing and the file is deleted anyway.
		$this->assertSame( $json, $removed['acf/delete_field_group'][0] );
		$this->assertSame( 'delete_field_group', $removed['acf/delete_field_group'][1] );
		$this->assertSame( 'delete_internal_post_type', $removed['acf/delete_taxonomy'][1] );
	}

	public function test_no_ops_when_the_instance_lacks_the_callbacks(): void {
		Functions\when( 'acf_get_instance' )->justReturn( new \stdClass() );
		Functions\when( 'remove_action' )->alias( function () {
			$this->fail( 'remove_action must not run against an unknown ACF shape' );
		} );

		$this->bareInstance()->acf_json_keep_local_on_delete();

		$this->expectNotToPerformAssertions();
	}
}
