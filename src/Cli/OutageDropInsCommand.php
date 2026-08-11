<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\OutageDropIns;

/**
 * `wp timber-kit outage-drop-ins` — install, inspect or remove the two
 * drop-ins that serve the theme's prerendered outage screen.
 *
 * Thin adapter over {@see OutageDropIns}, which owns the generated source and
 * is unit-tested. The WP_CLI I/O here is intentionally not unit-tested.
 *
 * The screen itself is rendered by the theme, ahead of time, with
 * `vendor/bin/styleguide maintenance:render`. This command only wires it to
 * the two moments WordPress needs it.
 */
class OutageDropInsCommand {

	/**
	 * Install, inspect or remove the outage drop-ins.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : install, status, or remove.
	 *
	 * [--screen=<path>]
	 * : Screen path relative to the theme root. Defaults to where
	 *   `maintenance:render` writes it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit outage-drop-ins install
	 *     wp timber-kit outage-drop-ins status
	 *     wp timber-kit outage-drop-ins remove
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$action = $args[0] ?? '';
		$screen = isset( $assoc_args['screen'] ) ? (string) $assoc_args['screen'] : OutageDropIns::SCREEN_RELATIVE;
		$theme  = basename( (string) get_template_directory() );

		if ( 'status' === $action ) {
			$this->status( $theme, $screen );
			return;
		}

		if ( 'remove' === $action ) {
			foreach ( OutageDropIns::remove( WP_CONTENT_DIR ) as $filename => $result ) {
				$this->report( $filename, $result );
			}
			return;
		}

		if ( 'install' !== $action ) {
			\WP_CLI::error( 'Action must be install, status, or remove.' );
		}

		// Warn, do not refuse. Installing before the first render is a
		// reasonable order of work, and the drop-in prints a plain 503 until
		// the screen exists — the site is never worse off for having them.
		$rendered = get_template_directory() . '/' . ltrim( $screen, '/' );
		if ( ! is_readable( $rendered ) ) {
			\WP_CLI::warning( sprintf(
				'No screen at %s yet — run `vendor/bin/styleguide maintenance:render` in the theme.',
				$rendered
			) );
		}

		foreach ( OutageDropIns::install( WP_CONTENT_DIR, $theme, $screen ) as $filename => $result ) {
			$this->report( $filename, $result );
		}
	}

	/**
	 * @return void
	 */
	private function status( string $theme, string $screen ) {
		$expected = OutageDropIns::source( $theme, $screen );

		foreach ( OutageDropIns::DROP_INS as $filename => $covers ) {
			$path = WP_CONTENT_DIR . '/' . $filename;

			if ( ! file_exists( $path ) ) {
				\WP_CLI::line( sprintf( '%-16s absent          (%s)', $filename, $covers ) );
				continue;
			}

			$contents = (string) file_get_contents( $path );

			if ( ! str_contains( $contents, OutageDropIns::MARKER ) ) {
				\WP_CLI::line( sprintf( '%-16s not ours        (%s)', $filename, $covers ) );
				continue;
			}

			\WP_CLI::line( sprintf(
				'%-16s %s (%s)',
				$filename,
				$contents === $expected ? 'installed      ' : 'stale, reinstall',
				$covers
			) );
		}

		$rendered = get_template_directory() . '/' . ltrim( $screen, '/' );
		\WP_CLI::line( sprintf(
			'%-16s %s',
			'screen',
			is_readable( $rendered ) ? $rendered : $rendered . ' (missing)'
		) );
	}

	/**
	 * @return void
	 */
	private function report( string $filename, string $result ) {
		if ( 'failed' === $result ) {
			\WP_CLI::error( sprintf( '%s: could not write.', $filename ), false );
			return;
		}

		if ( 'foreign' === $result ) {
			\WP_CLI::warning( sprintf( '%s: left alone, this file is not ours.', $filename ) );
			return;
		}

		\WP_CLI::success( sprintf( '%s: %s.', $filename, $result ) );
	}
}
