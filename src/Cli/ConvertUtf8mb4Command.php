<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\Health\Check\Utf8mb4Tables;
use Parisek\TimberKit\Health\Db\ConversionPlan;

/**
 * `wp timber-kit convert-utf8mb4` — convert charset-debt tables to the
 * database's dominant utf8mb4 collation.
 *
 * Thin adapter over {@see ConversionPlan}: detection and planning live in
 * unit-tested pure classes; the WP_CLI I/O here is intentionally not
 * unit-tested (same doctrine as PruneOriginalsCommand).
 *
 * Conversion is NEVER implicit: `--apply` refuses to run without an explicit
 * `--tables=<csv>` selection (or a conscious `--all`), and refuses tables
 * carrying index-prefix warnings unless `--force` acknowledges them.
 */
class ConvertUtf8mb4Command {

	/**
	 * Audit table charsets and convert explicitly selected tables to utf8mb4.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Explicit alias of the default behavior — print the plan, change nothing.
	 *
	 * [--apply]
	 * : Execute the conversion. Without it the command is a dry-run and only
	 *   prints the plan. Requires --tables or --all.
	 *
	 * [--tables=<csv>]
	 * : Comma-separated list of tables to convert. Only tables present in the
	 *   dry-run plan are accepted; anything else aborts before any ALTER runs.
	 *
	 * [--all]
	 * : Convert every table in the plan. Deliberately explicit — omitting a
	 *   selection never means "everything".
	 *
	 * [--collate=<collation>]
	 * : Target utf8mb4 collation override. Default: the dominant utf8mb4
	 *   collation already present in the database (tie-break toward core
	 *   tables) — never a hardcoded constant.
	 *
	 * [--force]
	 * : Proceed even when selected tables carry index-prefix warnings
	 *   (COMPACT/REDUNDANT row format with long indexed columns).
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit convert-utf8mb4
	 *     wp timber-kit convert-utf8mb4 --apply --tables=wp_aryo_activity_log
	 *     wp timber-kit convert-utf8mb4 --apply --all --force
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$apply  = isset( $assoc_args['apply'] );
		$all    = isset( $assoc_args['all'] );
		$force  = isset( $assoc_args['force'] );
		$target = isset( $assoc_args['collate'] ) ? (string) $assoc_args['collate'] : null;
		$tables = isset( $assoc_args['tables'] )
			? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['tables'] ) ) ) )
			: array();

		$audit = ( new Utf8mb4Tables() )->audit();
		if ( null === $audit ) {
			\WP_CLI::error( 'Database connection unavailable.' );
			return;
		}

		try {
			$plan = new ConversionPlan( $audit, $this->indexedColumns(), $target );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( null === $plan->targetCollation() ) {
			\WP_CLI::error( 'No utf8mb4 baseline exists in this database — pass --collate=<collation> explicitly.' );
			return;
		}

		if ( array() !== $tables ) {
			try {
				$plan = $plan->select( $tables );
			} catch ( \InvalidArgumentException $e ) {
				\WP_CLI::error( $e->getMessage() );
				return;
			}
		}

		$entries = $plan->entries();
		if ( array() === $entries ) {
			\WP_CLI::success( 'Nothing to convert — all tables already match the target collation.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Target collation: %s', (string) $plan->targetCollation() ) );
		foreach ( $entries as $entry ) {
			$note = '' !== $entry['note'] ? sprintf( ' [%s]', $entry['note'] ) : '';
			\WP_CLI::log( sprintf( '  %s: %s -> %s%s', $entry['table'], $entry['from'], $entry['to'], $note ) );
			if ( '' !== $entry['warning'] ) {
				\WP_CLI::warning( sprintf( '  %s: %s', $entry['table'], $entry['warning'] ) );
			}
		}

		if ( ! $apply ) {
			\WP_CLI::log( 'Dry-run only. Re-run with --apply --tables=<csv> (or --apply --all) to convert.' );
			return;
		}

		// Selection is first-class: --apply never converts implicitly.
		if ( array() === $tables && ! $all ) {
			\WP_CLI::error( 'Refusing to convert without an explicit selection. Pass --tables=<csv> for the tables you actually want, or --all to consciously convert the whole plan.' );
			return;
		}

		if ( $plan->hasWarnings() && ! $force ) {
			\WP_CLI::error( 'Selected tables carry index-prefix warnings — review them, then acknowledge with --force.' );
			return;
		}

		foreach ( $plan->statements() as $statement ) {
			\WP_CLI::log( $statement );
			if ( false === $wpdb->query( $statement ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers from information_schema, no user input.
				\WP_CLI::error( sprintf( 'ALTER failed: %s', (string) $wpdb->last_error ) );
				return;
			}
		}

		\WP_CLI::success( sprintf( 'Converted %d table(s) to %s.', count( $plan->statements() ), (string) $plan->targetCollation() ) );
	}

	/**
	 * Indexed text columns (no index sub-part) with their character lengths,
	 * for the 767-byte index-prefix warning on COMPACT/REDUNDANT tables.
	 *
	 * @return list<array{table_name: string, column_name: string, max_len: int|null, sub_part: int|null}>
	 */
	private function indexedColumns(): array {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.TABLE_NAME AS table_name, s.COLUMN_NAME AS column_name,'
				. ' c.CHARACTER_MAXIMUM_LENGTH AS max_len, s.SUB_PART AS sub_part'
				. ' FROM information_schema.STATISTICS s'
				. ' JOIN information_schema.COLUMNS c'
				. '   ON c.TABLE_SCHEMA = s.TABLE_SCHEMA AND c.TABLE_NAME = s.TABLE_NAME AND c.COLUMN_NAME = s.COLUMN_NAME'
				. ' WHERE s.TABLE_SCHEMA = DATABASE() AND c.CHARACTER_MAXIMUM_LENGTH IS NOT NULL AND s.TABLE_NAME LIKE %s',
				$like
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map(
			static fn ( array $row ): array => array(
				'table_name'  => (string) $row['table_name'],
				'column_name' => (string) $row['column_name'],
				'max_len'     => null === $row['max_len'] ? null : (int) $row['max_len'],
				'sub_part'    => null === $row['sub_part'] ? null : (int) $row['sub_part'],
			),
			$rows
		) );
	}
}
