<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health;

/**
 * A single entry on the Porta recommended-settings board.
 *
 * Each check declares its verification method:
 * - METHOD_EFFECT — probes the real outcome, plugin-agnostic (preferred:
 *   survives plugin swaps, verifies results, not a vendor's checkbox).
 * - METHOD_CONFIG — reads stored config when there is no observable effect.
 * - METHOD_BOTH — cross-checks effect against config ("checkbox on but not
 *   actually working").
 */
interface HealthCheck {

	public const METHOD_EFFECT = 'effect';
	public const METHOD_CONFIG = 'config';
	public const METHOD_BOTH   = 'both';

	/**
	 * Stable slug, unique across the registry (e.g. "xmlrpc_disabled").
	 */
	public function id(): string;

	/**
	 * Human label shown as the Site Health test heading.
	 */
	public function label(): string;

	/**
	 * Board category: security|caching|seo|performance|mail|a11y|timber-kit.
	 */
	public function category(): string;

	/**
	 * One of the METHOD_* constants.
	 */
	public function method(): string;

	public function run(): Result;
}
