<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\Updates\UpdateDiscovery;
use Parisek\TimberKit\Updates\UpdateRegistry;
use Parisek\TimberKit\Updates\UpdateRunner;

/**
 * `wp timber-kit updates` — run component-local content/data migrations once.
 */
class UpdatesCommand {

	/**
	 * List discovered updates and registry state.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit updates status
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (unused).
	 * @return void
	 */
	public function status( $args, $assoc_args ): void {
		$discovery = ( new UpdateDiscovery() )->discover();
		$registry  = new UpdateRegistry();
		$applied   = $registry->all();

		$rows = [];
		foreach ( $discovery->updates as $update ) {
			$rows[] = [
				'id'          => $update['id'],
				'description' => $update['description'],
				'state'       => isset( $applied[ $update['id'] ] ) ? $applied[ $update['id'] ]['applied'] : 'pending',
				'path'        => $update['path'],
			];
		}

		foreach ( $discovery->errors as $index => $error ) {
			$rows[] = [
				'id'          => 'error:' . ( $index + 1 ),
				'description' => $error,
				'state'       => 'error',
				'path'        => '',
			];
		}

		$this->table( $rows, [ 'id', 'description', 'state', 'path' ] );

		if ( [] !== $discovery->errors ) {
			\WP_CLI::error( 'Update discovery reported errors.' );
			return;
		}
	}

	/**
	 * Run pending updates once.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Execute update callbacks without writing registry entries. Helpers skip
	 *   content writes where supported and log would-be output.
	 *
	 * [--component=<name>]
	 * : Run pending updates only for one component.
	 *
	 * [--only=<id>]
	 * : Run one pending update id, e.g. `card:0001`. Already-applied updates
	 *   are still skipped; delete the registry entry manually to re-run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit updates run --dry-run
	 *     wp timber-kit updates run --component=card
	 *     wp timber-kit updates run --only=theme:0001
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function run( $args, $assoc_args ): void {
		$result = ( new UpdateRunner() )->run(
			dryRun: isset( $assoc_args['dry-run'] ),
			component: isset( $assoc_args['component'] ) ? (string) $assoc_args['component'] : null,
			only: isset( $assoc_args['only'] ) ? (string) $assoc_args['only'] : null
		);

		foreach ( $result['discovery_errors'] as $error ) {
			\WP_CLI::warning( $error );
		}
		if ( [] !== $result['discovery_errors'] ) {
			\WP_CLI::error( 'Refusing to run updates until discovery errors are fixed.' );
			return;
		}

		foreach ( $result['executed'] as $entry ) {
			\WP_CLI::log( sprintf( '%s completed in %d ms.', $entry['id'], $entry['duration_ms'] ) );
			if ( is_array( $entry['summary'] ) ) {
				\WP_CLI::log( sprintf( '  %s', wp_json_encode( $entry['summary'] ) ) );
			}
		}

		foreach ( $result['failed'] as $failure ) {
			\WP_CLI::error( sprintf( '%s failed: %s', $failure['id'], $failure['message'] ) );
			return;
		}

		if ( [] === $result['executed'] ) {
			\WP_CLI::success( 'No pending updates.' );
			return;
		}

		\WP_CLI::success( sprintf( 'Executed %d update(s).', count( $result['executed'] ) ) );
	}

	/**
	 * @param list<array<string, string>> $rows
	 * @param list<string>               $fields
	 */
	private function table( array $rows, array $fields ): void {
		if ( class_exists( '\WP_CLI\Utils' ) ) {
			// @phpstan-ignore-next-line WP-CLI is intentionally optional in this library.
			\WP_CLI\Utils\format_items( 'table', $rows, $fields );
			return;
		}

		\WP_CLI::log( implode( "\t", $fields ) );
		foreach ( $rows as $row ) {
			\WP_CLI::log( implode( "\t", array_map( static fn ( string $field ): string => $row[ $field ] ?? '', $fields ) ) );
		}
	}
}
