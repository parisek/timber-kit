<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Updates;

class UpdateRegistry implements AppliedUpdatesRegistry {

	private const OPTION = 'timber_kit_updates_applied';

	/** @var callable(): \DateTimeImmutable */
	private $clock;

	/**
	 * @param (callable(): \DateTimeImmutable)|null $clock
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function isApplied( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}

	public function markApplied( string $id, int $duration_ms ): void {
		$all        = $this->all();
		$clock      = $this->clock;
		$all[ $id ] = [
			'applied'     => $clock()->format( \DateTimeInterface::ATOM ),
			'duration_ms' => $duration_ms,
		];

		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array<string, array{applied: string, duration_ms: int}>
	 */
	public function all(): array {
		$value = get_option( self::OPTION, [] );
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];
		foreach ( $value as $id => $entry ) {
			if ( is_string( $id ) && is_array( $entry ) && isset( $entry['applied'], $entry['duration_ms'] ) ) {
				$clean[ $id ] = [
					'applied'     => (string) $entry['applied'],
					'duration_ms' => (int) $entry['duration_ms'],
				];
			}
		}

		return $clean;
	}
}
