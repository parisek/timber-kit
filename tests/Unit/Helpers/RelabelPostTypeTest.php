<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Verifies Helpers::relabelPostType() — merging custom labels onto an already
 * registered post type (the "rename the built-in `post` type" boilerplate
 * previously copy-pasted into every project's article controller).
 */
class RelabelPostTypeTest extends HelpersTestCase {

	private function postTypeObject(): object {
		$object = new \stdClass();
		$object->label = 'Posts';
		$object->labels = new \stdClass();
		$object->labels->name = 'Posts';
		$object->labels->singular_name = 'Post';
		$object->labels->add_new = 'Add New';
		return $object;
	}

	public function test_merges_labels_onto_registered_post_type_after_init(): void {
		$object = $this->postTypeObject();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_post_type_object' )->justReturn( $object );

		Helpers::relabelPostType( 'post', [
			'name' => 'Články',
			'singular_name' => 'Článek',
		] );

		$this->assertSame( 'Články', $object->labels->name );
		$this->assertSame( 'Článek', $object->labels->singular_name );
		$this->assertSame( 'Add New', $object->labels->add_new, 'labels not passed stay untouched' );
		$this->assertSame( 'Články', $object->label, 'top-level label mirrors labels.name' );
	}

	public function test_label_untouched_when_name_not_among_overrides(): void {
		$object = $this->postTypeObject();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_post_type_object' )->justReturn( $object );

		Helpers::relabelPostType( 'post', [ 'add_new' => 'Přidat článek' ] );

		$this->assertSame( 'Přidat článek', $object->labels->add_new );
		$this->assertSame( 'Posts', $object->label );
	}

	public function test_unknown_post_type_is_a_no_op(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_post_type_object' )->justReturn( null );

		Helpers::relabelPostType( 'nonexistent', [ 'name' => 'X' ] );

		$this->expectNotToPerformAssertions();
	}

	public function test_defers_to_init_hook_when_called_before_init(): void {
		$object = $this->postTypeObject();
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'get_post_type_object' )->justReturn( $object );

		$captured = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$captured ) {
			$captured[] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
		} );

		Helpers::relabelPostType( 'post', [ 'name' => 'Články' ] );

		$this->assertSame( 'Posts', $object->labels->name, 'nothing applied before init fires' );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'init', $captured[0]['hook'] );
		$this->assertSame( 999, $captured[0]['priority'], 'must run after post types register on default init priority' );

		( $captured[0]['callback'] )();

		$this->assertSame( 'Články', $object->labels->name );
	}
}
