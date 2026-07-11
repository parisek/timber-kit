<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health;

/**
 * Outcome of a single health check, aligned with WP Site Health statuses.
 *
 * Constructor is private — static constructors are the only way in, so an
 * invalid status string is unrepresentable by construction.
 */
final class Result {

	public const GOOD        = 'good';
	public const RECOMMENDED = 'recommended';
	public const CRITICAL    = 'critical';

	private function __construct(
		private readonly string $status,
		private readonly string $summary,
		private readonly string $actions,
	) {
	}

	public static function good( string $summary ): self {
		return new self( self::GOOD, $summary, '' );
	}

	public static function recommended( string $summary, string $actions = '' ): self {
		return new self( self::RECOMMENDED, $summary, $actions );
	}

	public static function critical( string $summary, string $actions = '' ): self {
		return new self( self::CRITICAL, $summary, $actions );
	}

	public function status(): string {
		return $this->status;
	}

	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Optional HTML for Site Health's "Actions" area. Points to remediation
	 * docs ("fix it in code like this") — never to an admin screen; the board
	 * is read-only by design.
	 */
	public function actions(): string {
		return $this->actions;
	}
}
