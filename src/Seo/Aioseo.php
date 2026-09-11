<?php

declare(strict_types=1);

/**
 * All in One SEO adapter.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Everything this package knows about All in One SEO's tag filters.
 *
 * See {@see Yoast} for why the two adapters share no base class.
 *
 * Note that AIOSEO's og:image is bridged elsewhere, by
 * {@see \Parisek\TimberKit\SocialImageBridge}. That class predates this
 * namespace and is left where it is; moving it would change the package's
 * public surface for no behavioural gain.
 */
final class Aioseo {

	/**
	 * @param callable $filter Callback taking the canonical, returning it.
	 */
	public static function register( callable $filter ): void {
		add_filter( 'aioseo_canonical_url', $filter );
	}

	/**
	 * Whether AIOSEO's own "Enable Breadcrumbs" switch is on.
	 *
	 * The value lives in a deprecated namespace and is only live while
	 * `breadcrumbsEnable` is listed in the plugin's deprecated options, so it is
	 * read through the plugin's API rather than off the option row. Unreadable
	 * counts as off: the caller's flag then governs.
	 */
	public static function breadcrumbsEnabled(): bool {
		if ( ! function_exists( 'aioseo' ) ) {
			return false;
		}

		// No `??` here, and that is load-bearing. AIOSEO resolves options through
		// `__get()`, and its `__isset()` is not an existence check — it walks
		// and RESETS the traversal state (`setGroupKey()`, `resetGroups()`) in
		// `Common/Traits/Options.php`. The null-coalescing operator calls
		// `__isset()` first, so `??` both takes a different path and disturbs
		// the chain it is reading. Written with `??` this returned false while
		// the switch read true, and every unit test still passed, because they
		// hand the decision a boolean. Measured on rezidence-ponavia.
		return (bool) aioseo()->options->deprecated->breadcrumbs->enable;
	}

	/**
	 * One filter, deliberately.
	 *
	 * `aioseo_schema_output` hands over the finished `@graph`, so the
	 * BreadcrumbList node and the `breadcrumb` property that references it by
	 * `@id` are removed in the same pass. Filtering `aioseo_schema_graphs`
	 * instead would drop the node and leave the reference dangling.
	 *
	 * @param callable $filter Callback taking the graph, returning it.
	 */
	public static function registerBreadcrumbSuppression( callable $filter ): void {
		add_filter( 'aioseo_schema_output', $filter );
	}
}
