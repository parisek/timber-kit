<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\Db\CharsetAudit;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Config check over information_schema (cheap, synchronous, no plugin
 * coupling): every prefix-scoped table should be utf8mb4 with one uniform
 * collation. Plugin tables keep their install-time charset forever, so
 * utf8mb3 stragglers are near-universal historical debt — they degrade
 * 4-byte characters to `?` and produce "illegal mix of collations" in JOINs.
 */
final class Utf8mb4Tables implements HealthCheck {

	public function id(): string {
		return 'utf8mb4_tables';
	}

	public function label(): string {
		return __( 'Database tables use utf8mb4', 'timber-kit' );
	}

	public function category(): string {
		return 'database';
	}

	public function method(): string {
		return self::METHOD_CONFIG;
	}

	public function run(): Result {
		$audit = $this->audit();

		if ( null === $audit ) {
			return Result::recommended(
				__( 'Could not inspect table charsets — the database connection is unavailable.', 'timber-kit' )
			);
		}

		if ( $audit->clean() ) {
			return Result::good( __( 'All tables use utf8mb4 with a uniform collation.', 'timber-kit' ) );
		}

		$problems = array();
		foreach ( $audit->offendingTables() as $charset => $tables ) {
			$problems[] = sprintf( '%s: %s', $charset, self::listTables( $tables ) );
		}
		if ( array() !== $audit->mixedCollations() ) {
			$parts = array();
			foreach ( $audit->mixedCollations() as $collation => $tables ) {
				$parts[] = sprintf( '%s (%d)', $collation, count( $tables ) );
			}
			$problems[] = sprintf(
				/* translators: %s: comma-separated list of collations with table counts. */
				__( 'mixed utf8mb4 collations: %s', 'timber-kit' ),
				implode( ', ', $parts )
			);
		}
		foreach ( $audit->columnOverrides() as $override ) {
			$problems[] = sprintf( '%s.%s (%s)', $override['table'], $override['column'], $override['collation'] );
		}

		$dominant = $audit->dominantCollation();
		$hint     = null !== $dominant
			? sprintf(
				/* translators: %s: target collation. */
				__( 'Suggested target: %s. Preview the conversion with `wp timber-kit convert-utf8mb4 --dry-run`; conversion only ever runs on explicitly selected tables.', 'timber-kit' ),
				$dominant
			)
			: __( 'No utf8mb4 baseline exists — pick a target collation explicitly via `wp timber-kit convert-utf8mb4 --collate=<collation>`.', 'timber-kit' );

		return Result::recommended(
			sprintf(
				/* translators: 1: found problems, 2: remediation hint. */
				__( 'Charset debt found — %1$s. %2$s', 'timber-kit' ),
				implode( '; ', $problems ),
				$hint
			)
		);
	}

	/**
	 * Read prefix-scoped table + text-column rows and build the audit.
	 * Null when $wpdb is not available (non-WP context).
	 */
	public function audit(): ?CharsetAudit {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';

		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_COLLATION AS collation, ROW_FORMAT AS row_format'
				. ' FROM information_schema.TABLES'
				. " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s",
				$like
			),
			ARRAY_A
		);

		$columns = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLLATION_NAME AS collation'
				. ' FROM information_schema.COLUMNS'
				. ' WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL AND TABLE_NAME LIKE %s',
				$like
			),
			ARRAY_A
		);

		return new CharsetAudit(
			is_array( $tables ) ? $tables : array(),
			is_array( $columns ) ? $columns : array(),
			(string) $wpdb->prefix
		);
	}

	/**
	 * @param list<string> $tables
	 */
	private static function listTables( array $tables ): string {
		$shown = array_slice( $tables, 0, 10 );
		$rest  = count( $tables ) - count( $shown );
		return implode( ', ', $shown ) . ( $rest > 0 ? sprintf( ' (+%d)', $rest ) : '' );
	}
}
