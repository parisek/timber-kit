<?php

declare(strict_types=1);

namespace Tests\Property\Helpers;

use Eris\Generator;
use Parisek\TimberKit\MenuData;
use Tests\Property\Support\PropertyTestCase;

/**
 * Invariant: MenuData is observationally equivalent to its item list.
 *
 * The unit suite asserts this on hand-picked fixtures. Here it is checked
 * across generated shapes — arbitrary item counts, arbitrary metadata, and in
 * particular the empty/non-empty boundary where the dual return type of
 * formatMenu() lives.
 *
 * MenuData is constructed directly rather than through formatMenu(), because
 * property tests run without Brain\Monkey and formatMenu() needs WordPress
 * functions mocked. The invariants under test belong to the object anyway.
 */
class MenuDataPropertyTest extends PropertyTestCase {

	private function itemsGenerator(): \Eris\Generator {
		return Generator\oneOf(
			Generator\constant( [] ),
			Generator\seq(
				Generator\associative( [
					'id'    => Generator\nat(),
					'title' => Generator\string(),
					'url'   => Generator\string(),
				] )
			)
		);
	}

	/**
	 * The empty/non-empty boundary is where formatMenu()'s dual return type
	 * lives (MenuData object vs. plain []), and 461 audited Twig
	 * `{% if menu %}` guards depend on it — so it must not be left to the
	 * probabilistic draw of itemsGenerator()'s oneOf(). This deterministic
	 * pass guarantees the invariants above also hold for the empty case on
	 * every run, not just when Eris happens to draw it.
	 */
	public function test_invariants_hold_for_the_empty_item_list(): void {
		$menu = new MenuData( [] );

		$this->assertSame( [], array_values( iterator_to_array( $menu ) ) );
		$this->assertSame( 0, count( $menu ) );
		$this->assertSame( json_encode( [] ), json_encode( $menu ) );
		$this->assertTrue( is_iterable( $menu ) );
		$this->assertInstanceOf( \Countable::class, $menu );
	}

	public function test_iteration_always_yields_the_item_list(): void {
		$this->forAll( $this->itemsGenerator() )
			->then( function ( array $items ): void {
				$menu = new MenuData( $items );

				$this->assertSame( array_values( $items ), array_values( iterator_to_array( $menu ) ) );
			} );
	}

	public function test_count_always_matches(): void {
		$this->forAll( $this->itemsGenerator() )
			->then( function ( array $items ): void {
				$menu = new MenuData( $items );

				$this->assertSame( count( $items ), count( $menu ) );
			} );
	}

	public function test_json_encoding_always_matches_the_item_list(): void {
		$this->forAll( $this->itemsGenerator() )
			->then( function ( array $items ): void {
				$menu = new MenuData( $items );

				$this->assertSame( json_encode( $items ), json_encode( $menu ) );
			} );
	}

	public function test_index_access_always_matches(): void {
		$this->forAll( $this->itemsGenerator() )
			->then( function ( array $items ): void {
				$menu = new MenuData( $items );

				foreach ( array_keys( $items ) as $i ) {
					$this->assertSame( $items[ $i ], $menu[ $i ] );
				}
			} );
	}

	public function test_metadata_never_leaks_into_the_item_list(): void {
		$this->forAll( $this->itemsGenerator(), Generator\string(), Generator\string() )
			->then( function ( array $items, string $title, string $slug ): void {
				$menu = new MenuData( $items, [ 'title' => $title, 'slug' => $slug ] );

				// Metadata is exposed as properties, never appended as an item.
				$this->assertSame( count( $items ), count( $menu ) );
				$this->assertSame( $title, $menu->title );
				$this->assertSame( $slug, $menu->slug );
			} );
	}

	public function test_is_always_iterable_and_countable(): void {
		$this->forAll( $this->itemsGenerator() )
			->then( function ( array $items ): void {
				$menu = new MenuData( $items );

				$this->assertTrue( is_iterable( $menu ) );
				$this->assertInstanceOf( \Countable::class, $menu );
			} );
	}
}
