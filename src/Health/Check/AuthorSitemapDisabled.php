<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: /wp-sitemap-users-1.xml lists author slugs (a username-
 * enumeration vector) — verified against the real provider registry via
 * wp_get_sitemap_providers(), so third-party wp_sitemaps_add_provider
 * callbacks only ever see genuine provider objects.
 */
final class AuthorSitemapDisabled implements HealthCheck {

	public function id(): string {
		return 'author_sitemap_disabled';
	}

	public function label(): string {
		return __( 'Author sitemap is disabled', 'timber-kit' );
	}

	public function category(): string {
		return 'security';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		if ( false === apply_filters( 'wp_sitemaps_enabled', true ) ) {
			return Result::good( __( 'Core sitemaps are disabled entirely — no author sitemap is exposed.', 'timber-kit' ) );
		}

		$providers = wp_get_sitemap_providers();

		if ( ! isset( $providers['users'] ) ) {
			return Result::good( __( 'The users sitemap provider is removed — author slugs are not enumerable via /wp-sitemap-users-1.xml.', 'timber-kit' ) );
		}

		return Result::recommended(
			__( 'The author (users) sitemap is exposed and lists usernames. Enable the $disable_author_sitemap flag in the project Base class.', 'timber-kit' )
		);
	}
}
