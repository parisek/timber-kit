<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Wpml;

/**
 * Pure computation for `wp timber-kit wpml-cleanup-theme-domain`.
 *
 * Given pre-counted row counts (already queried from `icl_strings` /
 * `icl_string_translations` / `icl_string_positions`) and a pre-globbed list
 * of compiled WPML translation files for a text domain, decides whether
 * there is anything left to remove and renders the human-readable report
 * lines the command prints before acting.
 *
 * All I/O — the SQL SELECT/DELETE statements and the filesystem glob/unlink
 * calls — happens in
 * {@see \Parisek\TimberKit\Cli\WpmlCleanupThemeDomainCommand}; this class only
 * reasons about numbers and paths it's handed, so it is unit-testable
 * without a database or filesystem (same split as
 * {@see \Parisek\TimberKit\Health\Db\ConversionPlan} and
 * {@see \Parisek\TimberKit\Acfml\PreferenceSyncPlan}).
 */
final class ThemeDomainCleanupPlan {

	/**
	 * @param list<string> $compiled_files Absolute paths of compiled WPML
	 *                                     translation files (`.mo`,
	 *                                     `.l10n.php`, `.json`) matched for
	 *                                     this domain.
	 */
	public function __construct(
		private readonly string $domain,
		private readonly int $string_count,
		private readonly int $string_translation_count,
		private readonly int $string_position_count,
		private readonly array $compiled_files
	) {
	}

	public function domain(): string {
		return $this->domain;
	}

	public function stringCount(): int {
		return $this->string_count;
	}

	public function stringTranslationCount(): int {
		return $this->string_translation_count;
	}

	public function stringPositionCount(): int {
		return $this->string_position_count;
	}

	/**
	 * @return list<string>
	 */
	public function compiledFiles(): array {
		return $this->compiled_files;
	}

	/**
	 * Whether there is anything left for this domain — either in String
	 * Translation's tables or on disk as a compiled file.
	 */
	public function hasWork(): bool {
		return $this->string_count > 0
			|| $this->string_translation_count > 0
			|| $this->string_position_count > 0
			|| array() !== $this->compiled_files;
	}

	/**
	 * Human-readable report lines, printed regardless of --dry-run.
	 *
	 * @return list<string>
	 */
	public function reportLines(): array {
		return array(
			sprintf( 'Text domain: %s', $this->domain ),
			sprintf( '  icl_strings rows: %d', $this->string_count ),
			sprintf( '  icl_string_translations rows: %d', $this->string_translation_count ),
			sprintf( '  icl_string_positions rows: %d', $this->string_position_count ),
			sprintf( '  compiled WPML files: %d', count( $this->compiled_files ) ),
		);
	}
}
