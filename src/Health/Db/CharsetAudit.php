<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Db;

/**
 * Pure charset/collation analysis over information_schema-shaped rows.
 * No WordPress dependencies — the Utf8mb4Tables check and the conversion
 * CLI feed it and stay thin, so all decision logic is unit-testable.
 */
final class CharsetAudit {

	/**
	 * Core WP table basenames (without prefix) used as the tie-break anchor
	 * when two utf8mb4 collations are equally common: converting stragglers
	 * to anything other than the core tables' collation just moves the
	 * "illegal mix of collations" error around.
	 */
	private const CORE_TABLES = array(
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'term_relationships',
		'term_taxonomy',
		'termmeta',
		'terms',
		'usermeta',
		'users',
	);

	/**
	 * @param list<array{name: string, collation: string, row_format?: string}>            $tables  Table rows.
	 * @param list<array{table_name: string, column_name: string, collation: string}>      $columns Text-column rows.
	 * @param string                                                                       $prefix  WP table prefix.
	 */
	public function __construct(
		private readonly array $tables,
		private readonly array $columns,
		private readonly string $prefix = 'wp_',
	) {
	}

	/**
	 * Table name → collation, for plan building.
	 *
	 * @return array<string, string>
	 */
	public function tableCollations(): array {
		$map = array();
		foreach ( $this->tables as $table ) {
			$map[ $table['name'] ] = $table['collation'];
		}
		return $map;
	}

	/**
	 * Table name → ROW_FORMAT (only where the source rows carried one).
	 *
	 * @return array<string, string>
	 */
	public function rowFormats(): array {
		$map = array();
		foreach ( $this->tables as $table ) {
			if ( isset( $table['row_format'] ) ) {
				$map[ $table['name'] ] = $table['row_format'];
			}
		}
		return $map;
	}

	public function clean(): bool {
		return array() === $this->offendingTables()
			&& array() === $this->columnOverrides()
			&& array() === $this->mixedCollations();
	}

	/**
	 * Tables whose charset is not utf8mb4, grouped by offending charset
	 * (charset keys and table lists sorted for stable output).
	 *
	 * @return array<string, list<string>>
	 */
	public function offendingTables(): array {
		$grouped = array();
		foreach ( $this->tables as $table ) {
			$charset = self::charsetOf( $table['collation'] );
			if ( 'utf8mb4' === $charset ) {
				continue;
			}
			$grouped[ $charset ][] = $table['name'];
		}
		ksort( $grouped );
		foreach ( $grouped as &$names ) {
			sort( $names );
		}
		return $grouped;
	}

	/**
	 * Columns whose collation deviates from their table's default — these
	 * survive a table-level CONVERT and are easy to miss.
	 *
	 * @return list<array{table: string, column: string, collation: string}>
	 */
	public function columnOverrides(): array {
		$table_collations = array();
		foreach ( $this->tables as $table ) {
			$table_collations[ $table['name'] ] = $table['collation'];
		}

		$overrides = array();
		foreach ( $this->columns as $column ) {
			$table_default = $table_collations[ $column['table_name'] ] ?? null;
			if ( null === $table_default || $column['collation'] === $table_default ) {
				continue;
			}
			$overrides[] = array(
				'table'     => $column['table_name'],
				'column'    => $column['column_name'],
				'collation' => $column['collation'],
			);
		}
		return $overrides;
	}

	/**
	 * When more than one utf8mb4 collation is in use, every collation with
	 * its tables (sorted) — mixed collations produce "illegal mix" errors in
	 * JOINs even when everything is already utf8mb4. Empty when uniform.
	 *
	 * @return array<string, list<string>>
	 */
	public function mixedCollations(): array {
		$by_collation = array();
		foreach ( $this->tables as $table ) {
			if ( 'utf8mb4' !== self::charsetOf( $table['collation'] ) ) {
				continue;
			}
			$by_collation[ $table['collation'] ][] = $table['name'];
		}
		if ( count( $by_collation ) < 2 ) {
			return array();
		}
		ksort( $by_collation );
		foreach ( $by_collation as &$names ) {
			sort( $names );
		}
		return $by_collation;
	}

	/**
	 * Suggested convert target: the utf8mb4 collation already used by most
	 * tables; ties break toward the collation core WP tables use. Null when
	 * no utf8mb4 baseline exists (caller must supply a target explicitly).
	 */
	public function dominantCollation(): ?string {
		$counts = array();
		$core   = array();
		foreach ( $this->tables as $table ) {
			$collation = $table['collation'];
			if ( 'utf8mb4' !== self::charsetOf( $collation ) ) {
				continue;
			}
			$counts[ $collation ] = ( $counts[ $collation ] ?? 0 ) + 1;
			if ( $this->isCoreTable( $table['name'] ) ) {
				$core[ $collation ] = ( $core[ $collation ] ?? 0 ) + 1;
			}
		}
		if ( array() === $counts ) {
			return null;
		}

		$max        = max( $counts );
		$candidates = array_keys( $counts, $max, true );
		if ( 1 === count( $candidates ) ) {
			return $candidates[0];
		}

		usort( $candidates, function ( string $a, string $b ) use ( $core ): int {
			return ( $core[ $b ] ?? 0 ) <=> ( $core[ $a ] ?? 0 );
		} );
		return $candidates[0];
	}

	private function isCoreTable( string $name ): bool {
		foreach ( self::CORE_TABLES as $base ) {
			if ( $name === $this->prefix . $base ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Charset component of a collation name ("utf8mb4_unicode_ci" → "utf8mb4").
	 * MySQL/MariaDB report the pre-8.0 alias "utf8" for utf8mb3 — normalized
	 * here so both spellings group under one key.
	 */
	private static function charsetOf( string $collation ): string {
		$charset = strstr( $collation, '_', true );
		$charset = false === $charset ? $collation : $charset;
		return 'utf8' === $charset ? 'utf8mb3' : $charset;
	}
}
