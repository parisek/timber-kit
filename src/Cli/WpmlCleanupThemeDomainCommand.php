<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\Wpml\ThemeDomainCleanupPlan;

/**
 * `wp timber-kit wpml-cleanup-theme-domain` — purge leftover WPML String
 * Translation rows and compiled translation files for a text domain that
 * `$wpml_theme_domain_authoritative` has excluded from ST.
 *
 * Once a project runs with `$wpml_theme_domain_authoritative` ON (the
 * default — see
 * {@see \Parisek\TimberKit\StarterBase::wpml_exclude_theme_domain_from_st()}),
 * WPML stops registering new strings for the theme's domain and stops
 * compiling an overriding `.mo` for it. Rows String Translation had already
 * registered *before* the exclusion took effect are left behind: inert
 * (WPML's Just-In-Time MO loader skips the excluded domain, and ST no
 * longer surfaces them to translators) but still occupying
 * `icl_strings` / `icl_string_translations` / `icl_string_positions`, plus
 * whatever compiled `.mo` / `.l10n.php` / `.json` files WPML already wrote
 * to `wp-content/languages/wpml/`. This command removes both, so the
 * theme's own `.po`/`.mo` pair is unambiguously the only place translators
 * and developers need to look.
 *
 * Verified against a real site (fellows): 102 `icl_strings`,
 * 69 `icl_string_translations`, and 27 `icl_string_positions` rows existed
 * for the theme's domain, all safely removable once the domain was
 * excluded — the numbers this command's own report line format is modelled
 * on.
 *
 * Thin adapter over {@see ThemeDomainCleanupPlan}: counting and the
 * has-work / report decision are computed by a unit-tested pure class fed
 * with already-queried numbers and an already-globbed file list; the
 * WP_CLI I/O, SQL DELETEs, and filesystem unlinks here are intentionally
 * not unit-tested (same doctrine as the other `Cli/*` commands).
 */
class WpmlCleanupThemeDomainCommand {

	/**
	 * Remove leftover WPML String Translation rows and compiled translation
	 * files for a text domain excluded via `$wpml_theme_domain_authoritative`.
	 *
	 * ## OPTIONS
	 *
	 * [--domain=<domain>]
	 * : Text domain to clean up. Default: the active theme's `TextDomain` —
	 *   the same domain `$wpml_theme_domain_authoritative` excludes.
	 *
	 * [--dry-run]
	 * : Count and report what would be removed. Deletes nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt before a real (non-dry-run) deletion.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit wpml-cleanup-theme-domain --dry-run
	 *     wp timber-kit wpml-cleanup-theme-domain
	 *     wp timber-kit wpml-cleanup-theme-domain --domain=fellows --yes
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		if ( ! $this->stringTranslationActive() ) {
			\WP_CLI::error( 'WPML String Translation is not active — icl_strings table not found.' );
			return;
		}

		$domain = isset( $assoc_args['domain'] ) && '' !== trim( (string) $assoc_args['domain'] )
			? trim( (string) $assoc_args['domain'] )
			: $this->defaultDomain();

		if ( '' === $domain ) {
			\WP_CLI::error( 'No text domain resolved — pass --domain=<domain> explicitly.' );
			return;
		}

		$dry_run = isset( $assoc_args['dry-run'] );

		$string_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_strings WHERE context = %s", $domain ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix, value goes through prepare().
		);
		$translation_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_string_translations t" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix, value goes through prepare().
				. " JOIN {$wpdb->prefix}icl_strings s ON s.id = t.string_id WHERE s.context = %s",
				$domain
			)
		);
		$position_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_string_positions p" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix, value goes through prepare().
				. " JOIN {$wpdb->prefix}icl_strings s ON s.id = p.string_id WHERE s.context = %s",
				$domain
			)
		);

		$compiled_files = $this->compiledFiles( $domain );

		$plan = new ThemeDomainCleanupPlan( $domain, $string_count, $translation_count, $position_count, $compiled_files );

		foreach ( $plan->reportLines() as $line ) {
			\WP_CLI::log( $line );
		}

		if ( ! $plan->hasWork() ) {
			\WP_CLI::success( 'Nothing to clean up — domain is already clear.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( 'Dry-run only. Re-run without --dry-run to delete.' );
			return;
		}

		\WP_CLI::confirm(
			sprintf( 'Delete these WPML String Translation rows and compiled files for "%s"?', $domain ),
			$assoc_args
		);

		// Children before parent — icl_string_translations / icl_string_positions
		// carry a string_id FK into icl_strings. JOIN-style deletes (rather than
		// a `string_id IN (SELECT id FROM icl_strings WHERE …)` subquery) avoid
		// MySQL's "can't specify target table for update in FROM clause" trap.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE t FROM {$wpdb->prefix}icl_string_translations t" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix, value goes through prepare().
				. " JOIN {$wpdb->prefix}icl_strings s ON s.id = t.string_id WHERE s.context = %s",
				$domain
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE p FROM {$wpdb->prefix}icl_string_positions p" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix, value goes through prepare().
				. " JOIN {$wpdb->prefix}icl_strings s ON s.id = p.string_id WHERE s.context = %s",
				$domain
			)
		);
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_strings WHERE context = %s", $domain ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix, value goes through prepare().
		);

		$deleted_files = 0;
		foreach ( $compiled_files as $file ) {
			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unlink() emits a warning on failure; the branch below already reports it via WP_CLI::warning().
				++$deleted_files;
			} else {
				\WP_CLI::warning( sprintf( 'Failed to delete %s.', $file ) );
			}
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'wpml_cache_clear' );
		}

		\WP_CLI::success(
			sprintf(
				'Removed %d icl_strings, %d icl_string_translations, %d icl_string_positions, %d compiled file(s) for "%s".',
				$string_count,
				$translation_count,
				$position_count,
				$deleted_files,
				$domain
			)
		);
	}

	/**
	 * WPML String Translation active check — table presence, not a class or
	 * constant, since ST doesn't advertise itself as reliably as SitePress
	 * does via `ICL_SITEPRESS_VERSION`.
	 */
	private function stringTranslationActive(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_strings';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $table === $found;
	}

	/**
	 * Default text domain — the active theme's `TextDomain` header, the same
	 * value `StarterBase::resolveThemeName()` resolves for
	 * `$wpml_theme_domain_authoritative`.
	 */
	private function defaultDomain(): string {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return '';
		}

		$name = wp_get_theme()->get( 'TextDomain' );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Compiled WPML translation files for the domain, globbed from
	 * `wp-content/languages/wpml/` — `<domain>-<locale>.mo`, the newer
	 * `.l10n.php` PHP-array export, and `.json` (Jed) variants.
	 *
	 * @return list<string>
	 */
	private function compiledFiles( string $domain ): array {
		if ( ! defined( 'WP_LANG_DIR' ) ) {
			return array();
		}

		$dir = rtrim( WP_LANG_DIR, '/' ) . '/wpml';
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$patterns = array(
			$dir . '/' . $domain . '-*.mo',
			$dir . '/' . $domain . '-*.l10n.php',
			$dir . '/' . $domain . '-*.json',
		);

		$files = array();
		foreach ( $patterns as $pattern ) {
			$matches = glob( $pattern );
			if ( is_array( $matches ) ) {
				$files = array_merge( $files, $matches );
			}
		}

		return array_values( array_unique( $files ) );
	}
}
