<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Db;

/**
 * Pure conversion planning over a CharsetAudit. Selection is first-class:
 * the CLI never converts implicitly — a plan is narrowed to explicitly
 * requested tables via select() before statements() is executed.
 */
final class ConversionPlan {

	/**
	 * Below this VARCHAR length a utf8mb4 index always fits the 767-byte
	 * limit of COMPACT/REDUNDANT InnoDB row formats (191 * 4 = 764).
	 */
	private const SAFE_INDEX_CHARS = 191;

	/**
	 * @param CharsetAudit                                                                              $audit   Source audit.
	 * @param list<array{table_name: string, column_name: string, max_len: int|null, sub_part: int|null}> $indexed Indexed text-column rows.
	 * @param string|null                                                                               $target  Explicit target collation (falls back to the audit's dominant).
	 * @param list<string>|null                                                                         $selection Narrowed table set (null = all).
	 */
	public function __construct(
		private readonly CharsetAudit $audit,
		private readonly array $indexed = array(),
		private readonly ?string $target = null,
		private readonly ?array $selection = null,
	) {
		// The target lands raw in ALTER TABLE statements — reject anything
		// that is not shaped like a utf8mb4 collation before any SQL exists.
		if ( null !== $target && 1 !== preg_match( '/^utf8mb4_[a-z0-9_]+$/i', $target ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Not a utf8mb4 collation: %s', $target )
			);
		}
	}

	/**
	 * The collation conversions will target: explicit override, else the
	 * audit's dominant utf8mb4 collation. Null means the caller must pass
	 * one explicitly (no utf8mb4 baseline in the database).
	 */
	public function targetCollation(): ?string {
		return $this->target ?? $this->audit->dominantCollation();
	}

	/**
	 * Plan rows, sorted by table name: every table whose default collation
	 * differs from the target, plus every table carrying a column-collation
	 * override off the target (CONVERT TO rewrites all text columns, so a
	 * table-level statement remediates both). `warning` is a non-empty
	 * sentence when the table's row format could hit the 767-byte
	 * index-prefix limit after conversion, '' otherwise; `note` flags
	 * tables that are planned (also) because of column overrides.
	 *
	 * @return list<array{table: string, from: string, to: string, warning: string, note: string}>
	 */
	public function entries(): array {
		$target = $this->targetCollation();
		if ( null === $target ) {
			return array();
		}

		$override_tables = array();
		foreach ( $this->audit->columnOverrides() as $override ) {
			if ( $override['collation'] !== $target ) {
				$override_tables[ $override['table'] ] = true;
			}
		}

		$entries = array();
		foreach ( $this->audit->tableCollations() as $name => $collation ) {
			$has_override = isset( $override_tables[ $name ] );
			if ( $collation === $target && ! $has_override ) {
				continue;
			}
			if ( null !== $this->selection && ! in_array( $name, $this->selection, true ) ) {
				continue;
			}
			$entries[] = array(
				'table'   => $name,
				'from'    => $collation,
				'to'      => $target,
				'warning' => $this->warningFor( $name ),
				'note'    => $has_override ? 'column collation overrides' : '',
			);
		}
		usort( $entries, static fn ( array $a, array $b ): int => strcmp( $a['table'], $b['table'] ) );
		return $entries;
	}

	/**
	 * Narrow the plan to explicitly requested tables. Unknown names (not part
	 * of this plan) throw so a typo can never silently convert nothing — or
	 * the wrong thing.
	 *
	 * @param list<string> $tables
	 */
	public function select( array $tables ): self {
		$known   = array_column( $this->entries(), 'table' );
		$unknown = array_diff( $tables, $known );
		if ( array() !== $unknown ) {
			throw new \InvalidArgumentException(
				sprintf( 'Not in the conversion plan: %s', implode( ', ', $unknown ) )
			);
		}
		return new self( $this->audit, $this->indexed, $this->target, array_values( $tables ) );
	}

	public function hasWarnings(): bool {
		foreach ( $this->entries() as $entry ) {
			if ( '' !== $entry['warning'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	public function statements(): array {
		$statements = array();
		foreach ( $this->entries() as $entry ) {
			$statements[] = sprintf(
				'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE %s',
				str_replace( '`', '``', $entry['table'] ),
				$entry['to']
			);
		}
		return $statements;
	}

	private function warningFor( string $table ): string {
		$row_format = $this->audit->rowFormats()[ $table ] ?? '';
		if ( ! in_array( strtoupper( $row_format ), array( 'COMPACT', 'REDUNDANT' ), true ) ) {
			return '';
		}
		foreach ( $this->indexed as $index ) {
			if ( $index['table_name'] !== $table ) {
				continue;
			}
			if ( null !== $index['sub_part'] ) {
				continue;
			}
			if ( null !== $index['max_len'] && $index['max_len'] > self::SAFE_INDEX_CHARS ) {
				return sprintf(
					'%s row format with indexed column `%s` (%d chars) may exceed the 767-byte index prefix limit under utf8mb4.',
					strtoupper( $row_format ),
					$index['column_name'],
					$index['max_len']
				);
			}
		}
		return '';
	}
}
