<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Updates;

interface AppliedUpdatesRegistry {

	public function isApplied( string $id ): bool;

	public function markApplied( string $id, int $duration_ms ): void;

	/**
	 * @return array<string, array{applied: string, duration_ms: int}>
	 */
	public function all(): array;
}
