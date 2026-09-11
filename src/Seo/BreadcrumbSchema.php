<?php

declare(strict_types=1);

/**
 * Suppression of the SEO plugin's own BreadcrumbList.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Keeps one BreadcrumbList per page, and makes it the theme's.
 *
 * Every theme on this kit renders its own breadcrumb as microdata, built from
 * `timber_kit_breadcrumb_items` and `$breadcrumb_list_page_map`. The SEO plugin
 * then adds a second one to its JSON-LD graph, from data it derived itself. A
 * crawler is handed two and picks.
 *
 * The plugin's copy is the worse one, because it cannot see the trail the theme
 * decided on. Measured on two unrelated projects: on `rezidence-ponavia` the
 * page showed `Úvod > …` while AIOSEO's graph said `Home > …` — its
 * `homepageLabel` defaults to `Home` and is not per-language, so a Czech site
 * ships an English root. On `sloneek` the same shape against Yoast, whose leaf
 * was the editor's internal working title rather than the rendered heading.
 *
 * Neither plugin can be told to stop in its settings. AIOSEO's `breadcrumbs`
 * option group is display only; its `enable` key sits in the deprecated
 * namespace and gates the visual widget, while the graph is built from
 * `getBreadcrumbs()` on a path that never reads it.
 *
 * **The trap this class exists to close.** Both plugins' WebPage node points at
 * the list by `@id`. Removing the list alone leaves a `breadcrumb` property
 * referencing an id that no longer resolves — a dangling reference is worse
 * than the duplicate it replaced. Both plugins are therefore filtered on the
 * finished graph — `aioseo_schema_output` and `wpseo_schema_graph` — so node and
 * reference go in one pass and cannot fall out of step.
 */
final class BreadcrumbSchema {

	/**
	 * Whether to take the plugin's BreadcrumbList away.
	 *
	 * Tied to the plugin's own "Enable Breadcrumbs" switch, which is the point
	 * of this design. That switch is visible in the admin and already states
	 * the editor's intent; today it does not cover the schema, and AIOSEO's own
	 * settings screen says so out loud — "By default AIOSEO will automatically
	 * add breadcrumbs to the schema markup that we add to your site". So a site
	 * can have the feature switched off and still ship the markup. Measured on
	 * `rezidence-ponavia`: the switch reads `false` and the graph carries a
	 * BreadcrumbList anyway.
	 *
	 * Binding to it makes the switch mean what it says, and gives every site an
	 * escape hatch that needs no code: turn the plugin's breadcrumbs **on** and
	 * this stands aside, because an editor who asked for them wants the whole
	 * feature, markup included.
	 *
	 * The decision takes booleans rather than reading the plugins itself, for
	 * the reason {@see Plugin} records: Brain\Monkey's global function stubs
	 * outlive the test that defined them, so a `function_exists` false branch
	 * cannot be tested once any test in the run has stubbed the symbol.
	 *
	 * Three answers, not two. `null` means the plugin offers no such switch on
	 * this install — AIOSEO's exists only on sites that had breadcrumbs off
	 * before it deprecated the setting — and that is not the same as "off". A
	 * missing switch is no signal, so the caller's flag governs and the
	 * behaviour cannot silently invert on the release that removes it.
	 *
	 * @param string|null $plugin          Result of {@see Plugin::active()}.
	 * @param bool|null   $pluginOwnEnabled Whether the plugin's own breadcrumb
	 *                                      feature is switched on, or null when
	 *                                      it has no such switch here.
	 * @return bool
	 */
	public static function shouldSuppress( ?string $plugin, ?bool $pluginOwnEnabled ): bool {
		if ( null === $plugin ) {
			return false;
		}

		if ( null === $pluginOwnEnabled ) {
			return true;
		}

		return ! $pluginOwnEnabled;
	}

	/**
	 * Resolve the plugin and its switch, then wire if it applies.
	 *
	 * Called on `template_redirect`, not at construction. Two reasons, and the
	 * second is the load-bearing one: a plugin's options are not reliably
	 * readable while themes are still loading, and reading them from
	 * `StarterBase` would put an SEO plugin's symbols outside `src/Seo/`, which
	 * `SeoBoundaryTest` forbids. `template_redirect` is still well before
	 * `wp_head`, where the schema is printed.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$plugin = Plugin::active();

		$enabled = null;
		if ( 'aioseo' === $plugin ) {
			$enabled = Aioseo::breadcrumbsEnabled();
		}
		if ( 'yoast' === $plugin ) {
			$enabled = Yoast::breadcrumbsEnabled();
		}

		if ( ! self::shouldSuppress( $plugin, $enabled ) ) {
			return;
		}

		self::register( $plugin );
	}

	/**
	 * Wire the suppression for whichever plugin is running.
	 *
	 * @param string|null $plugin Result of {@see Plugin::active()}.
	 * @return void
	 */
	public static function register( ?string $plugin ): void {
		if ( 'aioseo' === $plugin ) {
			Aioseo::registerBreadcrumbSuppression( array( self::class, 'stripGraph' ) );

			return;
		}

		if ( 'yoast' === $plugin ) {
			Yoast::registerBreadcrumbSuppression( array( self::class, 'stripGraph' ) );
		}
	}

	/**
	 * Drop every BreadcrumbList node and every reference to one.
	 *
	 * Written against the finished `@graph`, so the node and the `breadcrumb`
	 * property that points at it are removed together.
	 *
	 * @param mixed $graph The schema graph, or whatever an earlier filter returned.
	 * @return mixed The graph without breadcrumbs, or the input untouched when it
	 *               is not a graph this can read.
	 */
	public static function stripGraph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		$kept = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				$kept[] = $node;
				continue;
			}
			// `@type` is a string on every node these plugins build, but the
			// spec allows an array of types, so both shapes are handled.
			if ( in_array( 'BreadcrumbList', (array) ( $node['@type'] ?? array() ), true ) ) {
				continue;
			}
			unset( $node['breadcrumb'] );
			$kept[] = $node;
		}

		return $kept;
	}

}
