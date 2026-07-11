<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health;

/**
 * The ONLY coupling between the check registry and WP Site Health.
 * Registry + checks stay framework-agnostic so a CLI adapter (fleet sweeps,
 * CI gates) can consume the same checks later.
 */
final class SiteHealthAdapter {

	/**
	 * Merge checks into the `site_status_tests` array.
	 *
	 * `$checks` arrives through a public filter, so entries are untrusted —
	 * anything that is not a HealthCheck is skipped rather than fataling
	 * inside wp-admin.
	 *
	 * @param mixed                $tests  Value from the site_status_tests filter.
	 * @param array<string, mixed> $checks Checks keyed by id.
	 * @return array<string, mixed>
	 */
	public static function mapTests( mixed $tests, array $checks ): array {
		if ( ! is_array( $tests ) ) {
			$tests = array(
				'direct' => array(),
				'async'  => array(),
			);
		}

		foreach ( $checks as $check ) {
			if ( ! $check instanceof HealthCheck ) {
				continue;
			}
			$key = 'timber_kit_health_' . $check->id();
			if ( isset( $tests['direct'][ $key ] ) ) {
				// Registry uniqueness only covers the kit defaults; the override
				// and the public filter can reintroduce a duplicate id. First
				// registration wins — deterministic, and never a wp-admin fatal.
				continue;
			}
			$tests['direct'][ $key ] = array(
				'label' => $check->label(),
				'test'  => static fn (): array => self::toSiteHealthResult( $check ),
			);
		}

		return $tests;
	}

	/**
	 * Run one check and shape the outcome the way Site Health expects.
	 *
	 * @return array<string, mixed>
	 */
	public static function toSiteHealthResult( HealthCheck $check ): array {
		$result = $check->run();

		return array(
			'label'       => $check->label(),
			'status'      => $result->status(),
			'badge'       => array(
				'label' => self::badgeLabel( $check->category() ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $result->summary() ) . '</p>',
			// Checks can arrive via the public filter — treat their actions HTML
			// as untrusted and reduce it to post-safe markup.
			'actions'     => wp_kses_post( $result->actions() ),
			'test'        => 'timber_kit_health_' . $check->id(),
		);
	}

	/**
	 * Translated badge label per board category; unknown categories fall back
	 * to a capitalized slug.
	 */
	private static function badgeLabel( string $category ): string {
		$labels = array(
			'security'    => __( 'Security', 'timber-kit' ),
			'caching'     => __( 'Caching', 'timber-kit' ),
			'seo'         => __( 'SEO', 'timber-kit' ),
			'performance' => __( 'Performance', 'timber-kit' ),
			'mail'        => __( 'Mail', 'timber-kit' ),
			'a11y'        => __( 'Accessibility', 'timber-kit' ),
			'database'    => __( 'Database', 'timber-kit' ),
			'timber-kit'  => 'timber-kit',
		);

		return $labels[ $category ] ?? ucfirst( $category );
	}
}
