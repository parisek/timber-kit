<?php

declare(strict_types=1);

/**
 * Self-referencing canonical on paginated routes.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Makes a paginated route's canonical point at itself.
 *
 * Every SEO plugin resolves a singular page's canonical to `get_permalink()`,
 * which carries no pagination. A listing rendered by a block on an ordinary
 * page is therefore singular as far as the plugin is concerned, and every page
 * of it claims to be page one — telling a crawler that nine tenths of the
 * listing is a duplicate of its first page.
 *
 * The rule is applied blind: any singular request past page 1 gets the segment,
 * with no check for which block is on the page. A registry of paginating blocks
 * would drift the moment a second one was written, and could not see a block
 * nested inside a pattern or a reusable block. The tempting alternative — let
 * the block announce itself while rendering — is impossible by ordering:
 * `wp_head` has already run by the time content renders.
 */
final class Canonical {

	/**
	 * Filter callback shared by every adapter.
	 *
	 * Takes `mixed` rather than `string` because Yoast's filter may carry
	 * `false`, meaning "emit no canonical at all". Anything that is not a
	 * string is somebody else's decision and passes through untouched.
	 *
	 * @param mixed $canonical Canonical as the plugin resolved it.
	 *
	 * @return mixed Canonical carrying the pagination segment, or the input.
	 */
	public static function filter( mixed $canonical ): mixed {
		if ( ! is_string( $canonical ) ) {
			return $canonical;
		}

		return Pagination::append( $canonical, PagedRequest::current() );
	}

	/**
	 * Hang {@see self::filter()} on whichever plugin is running.
	 *
	 * No plugin, nothing to hang on: this returns without registering rather
	 * than warning, because a site with no SEO plugin has no canonical for this
	 * to correct.
	 */
	public static function register(): void {
		match ( Plugin::active() ) {
			'aioseo' => Aioseo::register( array( self::class, 'filter' ) ),
			'yoast'  => Yoast::register( array( self::class, 'filter' ) ),
			default  => null,
		};
	}
}
