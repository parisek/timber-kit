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
	 * Two filters, because Yoast offers no seam on the finished graph.
	 *
	 * The pieces filter removes the generator; the WebPage filter removes the
	 * `breadcrumb` property that points at what the generator would have built.
	 * Either alone is wrong: the first leaves a dangling `@id` reference, the
	 * second leaves the node it was meant to describe.
	 *
	 * Priority 11 on both, so a project filter registered at the default 10 has
	 * already run and this sees what it produced.
	 *
	 * @param callable $pieces    Callback taking the graph pieces, returning them.
	 * @param callable $reference Callback taking the WebPage node, returning it.
	 */
	public static function registerBreadcrumbSuppression( callable $pieces, callable $reference ): void {
		add_filter( 'wpseo_schema_graph_pieces', $pieces, 11 );
		add_filter( 'wpseo_schema_webpage', $reference, 11 );
	}
}
