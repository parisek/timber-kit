<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health;

/**
 * Collects health checks keyed by their stable id. Duplicate ids throw
 * instead of silently overwriting — a collision means two features claim
 * the same board slot and one of them would vanish unnoticed.
 */
final class CheckRegistry {

	/** @var array<string, HealthCheck> */
	private array $checks = array();

	public function add( HealthCheck $check ): void {
		$id = $check->id();
		if ( isset( $this->checks[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Health check "%s" is already registered.', $id )
			);
		}
		$this->checks[ $id ] = $check;
	}

	/**
	 * @return array<string, HealthCheck>
	 */
	public function all(): array {
		return $this->checks;
	}
}
