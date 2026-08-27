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
}
