<?php

declare(strict_types=1);

/**
 * Yoast SEO adapter.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Everything this package knows about Yoast's tag filters.
 *
 * Deliberately not sharing a base class with {@see Aioseo}. The two plugins
 * agree on the canonical filter's arity and on nothing else — Yoast's may
 * return `false` to drop the tag, AIOSEO's may not — so a common signature
 * would have to be the union of both and would describe neither.
 *
 * Canonical only. Yoast is the plugin being migrated away from, and writing its
 * title and og:image adapters would be work invested in a dependency on its way
 * out. `README.md` records the gap as deliberate.
 */
final class Yoast {

	/**
	 * @param callable $filter Callback taking the canonical, returning it.
	 */
	public static function register( callable $filter ): void {
		add_filter( 'wpseo_canonical', $filter );
	}

	/**
	 * Whether Yoast's own breadcrumb feature is switched on.
	 *
	 * Read through `WPSEO_Options`, which holds a validated set, rather than the
	 * option row. Returns null when Yoast is not loaded, matching
	 * {@see Aioseo::breadcrumbsEnabled()} — the caller's flag then governs.
	 *
	 * Unlike AIOSEO's, this setting is current rather than deprecated, so a
	 * `null` here means the plugin is absent and nothing more.
	 *
	 * @return bool|null
	 */
	public static function breadcrumbsEnabled(): ?bool {
		if ( ! class_exists( 'WPSEO_Options' ) ) {
			return null;
		}

		return (bool) \WPSEO_Options::get( 'breadcrumbs-enable', false );
	}

	/**
	 * One filter on the finished graph, the same shape as {@see Aioseo}.
	 *
	 * `wpseo_schema_graph` hands over the array of rendered pieces, so the
	 * BreadcrumbList node and the `breadcrumb` property that references it by
	 * `@id` are removed in the same pass and cannot fall out of step.
	 *
	 * The alternative — `wpseo_schema_graph_pieces` plus `wpseo_schema_webpage`
	 * — needs two filters that must agree, and matches Yoast's generator by its
	 * fully-qualified class name, which is internal and free to move. Verified
	 * against the plugin: `webpage.php` is the only generator that sets
	 * `breadcrumb`, so nothing is missed by working one level up.
	 *
	 * Priority 11, so a project filter registered at the default 10 has already
	 * run and this sees what it produced.
	 *
	 * @param callable $filter Callback taking the graph, returning it.
	 */
	public static function registerBreadcrumbSuppression( callable $filter ): void {
		add_filter( 'wpseo_schema_graph', $filter, 11 );
	}
}
