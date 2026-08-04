<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\MenuData;

/**
 * Contract for the MenuData return object.
 *
 * MenuData carries no WordPress dependency by design — it is constructed from
 * already-formatted arrays, which is what keeps it unit- and property-testable
 * without a WP bootstrap.
 */
class MenuDataTest extends TestCase {

	private function make( array $items = [ [ 'id' => 1, 'title' => 'A' ] ], array $meta = [] ): MenuData {
		return new MenuData( $items, $meta + [
			'id'          => 7,
			'title'       => 'Channels',
			'name'        => 'Channels',
			'slug'        => 'channels',
			'description' => 'Footer column',
		] );
	}

	public function test_exposes_metadata_as_properties(): void {
		$menu = $this->make();

		$this->assertSame( 7, $menu->id );
		$this->assertSame( 'Channels', $menu->title );
		$this->assertSame( 'Channels', $menu->name );
		$this->assertSame( 'channels', $menu->slug );
		$this->assertSame( 'Footer column', $menu->description );
	}

	public function test_exposes_items_as_a_property(): void {
		$menu = $this->make( [ [ 'id' => 1, 'title' => 'A' ] ] );

		$this->assertSame( [ [ 'id' => 1, 'title' => 'A' ] ], $menu->items );
	}

	public function test_iterates_as_its_item_list(): void {
		$items = [ [ 'id' => 1 ], [ 'id' => 2 ] ];
		$menu = $this->make( $items );

		$this->assertSame( $items, iterator_to_array( $menu ) );
	}

	public function test_is_countable(): void {
		$this->assertCount( 2, $this->make( [ [ 'id' => 1 ], [ 'id' => 2 ] ] ) );
	}

	public function test_supports_index_access(): void {
		$menu = $this->make( [ [ 'id' => 1 ], [ 'id' => 2 ] ] );

		$this->assertSame( [ 'id' => 2 ], $menu[1] );
		$this->assertTrue( isset( $menu[0] ) );
		$this->assertFalse( isset( $menu[9] ) );
	}

	public function test_is_read_only(): void {
		$menu = $this->make();

		$this->expectException( \LogicException::class );
		$menu[0] = [ 'id' => 99 ];
	}

	public function test_unset_is_rejected(): void {
		$menu = $this->make();

		$this->expectException( \LogicException::class );
		unset( $menu[0] );
	}

	public function test_json_encodes_to_the_item_list(): void {
		$items = [ [ 'id' => 1, 'title' => 'A' ] ];
		$menu = $this->make( $items );

		$this->assertSame( json_encode( $items ), json_encode( $menu ) );
	}

	public function test_extra_meta_keys_are_reachable_as_properties(): void {
		$menu = $this->make( [ [ 'id' => 1 ] ], [ 'icon' => 'ico-ads' ] );

		$this->assertTrue( isset( $menu->icon ) );
		$this->assertSame( 'ico-ads', $menu->icon );
	}

	public function test_unknown_property_is_null_and_not_set(): void {
		$menu = $this->make();

		$this->assertFalse( isset( $menu->nope ) );
		$this->assertNull( $menu->nope );
	}

	public function test_is_iterable(): void {
		$this->assertTrue( is_iterable( $this->make() ) );
	}
}
