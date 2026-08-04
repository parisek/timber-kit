<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Return value of {@see Helpers::formatMenu()} for a non-empty menu.
 *
 * Behaves as its item list under every array-shaped operation a template or
 * a PHP consumer can perform — iteration, `count()`, index access, JSON
 * encoding — while additionally exposing the menu's own metadata as
 * properties. That equivalence is what lets `formatMenu()` gain menu-level
 * metadata without touching any of the existing call sites.
 *
 * `formatMenu()` returns a plain `[]` (not an empty MenuData) for an empty or
 * missing menu. An object is always truthy in PHP — `Countable` does not
 * change the boolean cast — so an empty MenuData would silently flip every
 * `{% if menu %}` guard in consuming templates. See the design spec.
 *
 * Read-only by construction: `offsetSet()` / `offsetUnset()` throw.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 * @implements \ArrayAccess<int, array<string, mixed>>
 */
final class MenuData implements \IteratorAggregate, \ArrayAccess, \Countable, \JsonSerializable {

	public readonly int $id;
	public readonly string $title;
	public readonly string $name;
	public readonly string $slug;
	public readonly string $description;

	/** @var array<int, array<string, mixed>> */
	public readonly array $items;

	/**
	 * Metadata beyond the fixed properties — in practice the ACF fields
	 * attached to the `nav_menu` term. Reachable as properties via __get().
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $extra;

	/**
	 * @param array<int, array<string, mixed>> $items Formatted menu items.
	 * @param array<string, mixed>             $meta  Menu metadata; unknown keys
	 *                                                become __get()-reachable.
	 *
	 * @internal Metadata values are coerced ( (int)/(string) ), not validated —
	 *           safe today only because the sole caller, `Helpers::formatMenu()`,
	 *           passes scalars sourced from a `Timber\Menu`. A future second call
	 *           site must not assume this coercion protects it from bad input.
	 */
	public function __construct( array $items, array $meta = [] ) {
		$this->items = $items;

		$this->id = (int) ( $meta['id'] ?? 0 );
		$this->title = (string) ( $meta['title'] ?? '' );
		$this->name = (string) ( $meta['name'] ?? '' );
		$this->slug = (string) ( $meta['slug'] ?? '' );
		$this->description = (string) ( $meta['description'] ?? '' );

		unset( $meta['id'], $meta['title'], $meta['name'], $meta['slug'], $meta['description'], $meta['items'] );
		$this->extra = $meta;
	}

	public function __get( string $name ): mixed {
		return $this->extra[ $name ] ?? null;
	}

	public function __isset( string $name ): bool {
		return isset( $this->extra[ $name ] );
	}

	public function getIterator(): \Traversable {
		return new \ArrayIterator( $this->items );
	}

	public function offsetExists( mixed $offset ): bool {
		return isset( $this->items[ $offset ] );
	}

	public function offsetGet( mixed $offset ): mixed {
		return $this->items[ $offset ] ?? null;
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		throw new \LogicException( 'MenuData is read-only.' );
	}

	public function offsetUnset( mixed $offset ): void {
		throw new \LogicException( 'MenuData is read-only.' );
	}

	public function count(): int {
		return count( $this->items );
	}

	/**
	 * Serializes to the item list, matching what `formatMenu()` returned before
	 * this object existed. Without this, `json_encode()` would emit the public
	 * properties instead — a silent shape change for any consumer that adds
	 * serialization later.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function jsonSerialize(): array {
		return $this->items;
	}
}
