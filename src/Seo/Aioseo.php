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
}
