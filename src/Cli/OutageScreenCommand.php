<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\OutageScreen;

/**
 * `wp timber-kit outage-screen` — install, inspect or remove the drop-ins
 * that serve the theme's prerendered outage screen.
 *
 * Thin adapter over {@see OutageScreen}, which owns the generated source and
 * is unit-tested. The WP_CLI I/O here is intentionally not unit-tested.
 *
 * The screen itself is rendered by the theme, ahead of time, with
 * `vendor/bin/styleguide maintenance:render`. This command only wires it to
 * the moments WordPress needs it.
 */
class OutageScreenCommand {

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
	 *     wp timber-kit outage-screen install
	 *     wp timber-kit outage-screen status
	 *     wp timber-kit outage-screen remove
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$action = $args[0] ?? '';
		$screen = isset( $assoc_args['screen'] ) ? (string) $assoc_args['screen'] : OutageScreen::SCREEN_RELATIVE;
		$theme  = basename( (string) get_template_directory() );

		// The screen path is baked into a file that runs with no WordPress
		// around it, so a `..` segment is not a permission question — nothing
		// is there to ask — it is a typo that silently serves whatever it
		// lands on during an outage.
		if ( str_contains( $screen, '..' ) ) {
			\WP_CLI::error( '--screen must stay inside the theme: no `..` segments.' );
		}

		if ( 'status' === $action ) {
			$this->status( $theme, $screen );
			return;
		}

		if ( 'remove' === $action ) {
			foreach ( OutageScreen::remove( WP_CONTENT_DIR ) as $filename => $result ) {
				$this->report( $filename, $result );
			}
			return;
		}

		if ( 'install' !== $action ) {
			\WP_CLI::error( 'Action must be install, status, or remove.' );
		}

		// Drop-ins are per-INSTALLATION; the theme is per-SITE. On multisite
		// with more than one theme in play, every site gets whichever screen
		// was baked in here — and no drop-in can resolve the current site,
		// because `db-error.php` runs with no database to ask. Say so rather
		// than let one network's screen quietly speak for another's.
		if ( is_multisite() ) {
			\WP_CLI::warning( sprintf(
				'Multisite: wp-content/ is shared, so every site will serve %s\'s screen. '
				. 'Run this against the site whose branding should win.',
				$theme
			) );
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

		foreach ( OutageScreen::install( WP_CONTENT_DIR, $theme, $screen ) as $filename => $result ) {
			$this->report( $filename, $result );
		}
	}

	/**
	 * @return void
	 */
	private function status( string $theme, string $screen ) {
		foreach ( OutageScreen::DROP_INS as $filename => $contract ) {
			// Per file: the three drop-ins differ, and one expected source
			// shared across them would report two of the three as permanently
			// stale — which a reinstall would not fix, because they are not.
			$expected = OutageScreen::source( $theme, $screen, $filename );
			$covers   = $contract['covers'];
			$path     = WP_CONTENT_DIR . '/' . $filename;

			if ( ! file_exists( $path ) ) {
				\WP_CLI::line( sprintf( '%-16s absent          (%s)', $filename, $covers ) );
				continue;
			}

			$contents = (string) file_get_contents( $path );

			if ( ! str_contains( $contents, OutageScreen::MARKER ) ) {
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
