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
	 * Returns null when the switch does not exist on this install, which is a
	 * third answer and not the same as off.
	 *
	 * **The switch is a legacy remnant, not a current setting.** AIOSEO's
	 * upgrade routine `deprecateBreadcrumbsEnabledSetting()` adds
	 * `breadcrumbsEnable` to the deprecated-options list **only** on a site that
	 * had breadcrumbs explicitly switched off; a site that never touched it gets
	 * nothing, and breadcrumbs are simply on. So membership in that list is the
	 * question, and the plugin's own code asks it before every read of the value
	 * — see `Breadcrumbs\Frontend::display()` and `Breadcrumbs\Block.php`.
	 * Reading the value without the guard is reading a key that may not exist.
	 *
	 * It also sits in `allDeprecatedOptions`, which is a removal list. When the
	 * plugin drops it, membership goes false everywhere and this returns null —
	 * the caller's flag then governs, and the behaviour does not silently
	 * invert.
	 *
	 * @return bool|null True or false when the switch exists, null when it does not.
	 */
	public static function breadcrumbsEnabled(): ?bool {
		if ( ! function_exists( 'aioseo' ) ) {
			return null;
		}

		if ( ! in_array( 'breadcrumbsEnable', (array) aioseo()->internalOptions->deprecatedOptions, true ) ) {
			return null;
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
