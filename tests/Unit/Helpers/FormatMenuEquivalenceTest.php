<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\Unit\HelpersTestCase;
use Parisek\TimberKit\Helpers;
use Brain\Monkey\Functions;

/**
 * Observational equivalence between MenuData and the plain array it replaced.
 *
 * This is the layer that substantiates the "zero migration" claim: it adds no
 * new functionality, it asserts that nothing observable changed. An audit of
 * the 26 consuming themes found 461 `{% if %}` truthiness guards, 6 filter
 * call sites and 0 `is_array()` uses; every axis those depend on is asserted
 * here.
 */
class FormatMenuEquivalenceTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_field_objects' )->justReturn( [] );
		// Brain\Monkey's function patching is global, not per-test: once another
		// test file in the suite has mocked is_plugin_active(), function_exists()
		// reports it as defined for the rest of the process even after tearDown()
		// resets the expectation. Mock it explicitly so this file doesn't depend
		// on suite execution order (same pattern as FormatMenuTest::setUp()).
		Functions\when( 'is_plugin_active' )->justReturn( false );
	}

	private function makeMenu( int $count ): object {
		$items = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$item = new \stdClass();
			$item->ID = $i;
			$item->name = 'Item ' . $i;
			$item->url = '/item-' . $i . '/';
			$item->description = '';
			$item->target = '';
			$item->classes = [];
			$item->current_item_ancestor = false;
			$item->current = false;
			$item->children = [];
			$items[] = $item;
		}

		$menu = new \stdClass();
		$menu->id = 7;
		$menu->name = 'Channels';
		$menu->slug = 'channels';
		$menu->description = '';
		$menu->items = $items;

		return $menu;
	}

	public function test_foreach_yields_the_item_list(): void {
		$result = Helpers::formatMenu( $this->makeMenu( 3 ) );

		$collected = [];
		foreach ( $result as $item ) {
			$collected[] = $item;
		}

		$this->assertSame( $result->items, $collected );
	}

	public function test_count_matches_item_count(): void {
		$this->assertCount( 3, Helpers::formatMenu( $this->makeMenu( 3 ) ) );
	}

	public function test_index_access_matches(): void {
		$result = Helpers::formatMenu( $this->makeMenu( 3 ) );

		$this->assertSame( $result->items[2], $result[2] );
	}

	public function test_non_empty_menu_is_truthy(): void {
		$this->assertTrue( (bool) Helpers::formatMenu( $this->makeMenu( 1 ) ) );
	}

	public function test_empty_menu_is_falsy(): void {
		// The single most load-bearing assertion in this file: 461 audited
		// `{% if menu %}` guards depend on it.
		$this->assertFalse( (bool) Helpers::formatMenu( $this->makeMenu( 0 ) ) );
	}

	public function test_result_is_iterable_in_both_states(): void {
		$this->assertTrue( is_iterable( Helpers::formatMenu( $this->makeMenu( 2 ) ) ) );
		$this->assertTrue( is_iterable( Helpers::formatMenu( $this->makeMenu( 0 ) ) ) );
	}

	public function test_json_encode_matches_the_item_list(): void {
		$result = Helpers::formatMenu( $this->makeMenu( 2 ) );

		$this->assertSame( json_encode( $result->items ), json_encode( $result ) );
	}
}
