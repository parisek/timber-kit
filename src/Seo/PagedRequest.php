<?php

declare(strict_types=1);

/**
 * The requested page number.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Reads the page number WordPress put in the query, from whichever of its two
 * variables carries it.
 *
 * `paged` is filled on an archive, `page` on a singular page. The distinction
 * matters for any list that paginates while sitting on an ordinary page rather
 * than a post-type archive: reading only `paged` yields 0 there, and a caller
 * treating 0 as "the first page" serves page 1 for every page number, behind a
 * 200 that no status check can see.
 *
 * The one WordPress-aware member of this namespace besides `Plugin::active()`.
 * Everything else takes its number as an argument.
 */
final class PagedRequest {

	/**
	 * @return int Requested page, never below one.
	 */
	public static function current(): int {
		return max(
			(int) get_query_var( 'paged' ),
			(int) get_query_var( 'page' ),
			1
		);
	}
}
