<?php

declare(strict_types=1);

/**
 * Which SEO plugin is running.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * The one file in this namespace that names an SEO plugin's symbols.
 *
 * Detection was already written twice in this package, in two shapes that
 * disagreed: `SocialImageBridge::aioseoActive()` asks `function_exists`, while
 * `WarmupSitemap::detectSitemapProvider()` also asks `class_exists` and then
 * reads a Yoast option. This consolidates both, and keeps the harder lesson
 * `WarmupSitemap` paid for: a symbol proves the plugin is LOADED, never that it
 * SERVES the thing being asked about.
 *
 * The judgment is split out of the symbol checks deliberately. Brain\Monkey
 * defines real global functions that outlive the test that defined them, so a
 * `function_exists` false branch cannot be tested once any test in the run has
 * stubbed the symbol. Everything decidable therefore lives in `detect()`, which
 * takes booleans; `active()` is the thin, inspected layer above it.
 */
final class Plugin {

	/**
	 * Decide which plugin owns the SEO tags, given which ones are loaded.
	 *
	 * Two plugins at once is a defect, not a configuration: both render their
	 * own `rel=canonical` and the page ships two. This picks one rather than
	 * refusing to act, and picks it in a fixed order so the outcome never
	 * depends on which plugin loaded first. AIOSEO wins because it is the
	 * migration target — a half-finished migration should behave like the
	 * destination, not like the plugin being removed.
	 *
	 * @param array{yoast: bool, aioseo: bool} $present Which symbols resolved.
	 *
	 * @return 'aioseo'|'yoast'|null
	 */
	public static function detect( array $present ): ?string {
		if ( true === ( $present['aioseo'] ?? false ) ) {
			return 'aioseo';
		}

		if ( true === ( $present['yoast'] ?? false ) ) {
			return 'yoast';
		}

		return null;
	}

	/**
	 * The loaded SEO plugin, or null.
	 *
	 * Kept to one statement per plugin on purpose — see the class docblock for
	 * why this layer is verified by reading rather than by testing. Each plugin
	 * is detected by a symbol it defines, never by the active-plugin list: a
	 * must-use load, a renamed directory or a bundled copy all keep the symbol
	 * and lose the list entry.
	 *
	 * @return 'aioseo'|'yoast'|null
	 */
	public static function active(): ?string {
		return self::detect(
			array(
				'aioseo' => function_exists( 'aioseo' ) || class_exists( '\AIOSEO\Plugin\AIOSEO' ),
				'yoast'  => defined( 'WPSEO_VERSION' ) || class_exists( '\WPSEO_Options' ),
			)
		);
	}

	/**
	 * Whether Yoast serves its own XML sitemap.
	 *
	 * Pure, so the branch is testable: the caller reads `get_option( 'wpseo' )`
	 * and passes the result. An absent or unreadable value counts as on, which
	 * is Yoast's own default — guessing "off" would send a working site to a
	 * sitemap path that does not exist.
	 *
	 * @param array<string, mixed>|null $options The `wpseo` option, or null.
	 */
	public static function supportsYoastSitemap( ?array $options ): bool {
		if ( null === $options || ! array_key_exists( 'enable_xml_sitemap', $options ) ) {
			return true;
		}

		return (bool) $options['enable_xml_sitemap'];
	}
}
