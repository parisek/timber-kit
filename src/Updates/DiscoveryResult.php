<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Updates;

/**
 * @phpstan-type UpdateDefinition array{id: string, component: string, number: int, description: string, path: string, run: callable}
 */
class DiscoveryResult {

	/**
	 * @param list<UpdateDefinition> $updates
	 * @param list<string>           $errors
	 */
	public function __construct(
		public readonly array $updates,
		public readonly array $errors = []
	) {}
}
