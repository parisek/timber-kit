<?php

declare(strict_types=1);

/**
 * Document title on a 404.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit\Seo;

/**
 * Hands a 404 whose title arrived whitespace-padded back to core.
 *
 * Timber's context calls `wp_title( ' ' )` while it builds, before the head
 * renders. On a 404 that returns core's title behind its separator prefix:
 * `'   Page not found'`. An SEO plugin that hooks `wp_title`, has no 404
 * format of its own and caches the first title it sees for the request then
 * returns that string from `pre_get_document_title` too, and `<title>` prints
 * it: three spaces, no site name. Measured on every site of a fleet that runs
 * that combination.
 *
 * Returning `''` is the whole repair. Core treats an empty pre-title as "build
 * it yourself" and produces `Page not found - <site name>` in the site's
 * language, with the separator a project can already change through
 * `document_title_separator`.
 *
 * Bounded on purpose. Only a 404, and only a title whose ends carry
 * whitespace: a plugin that writes its own 404 title produces a trimmed string
 * and is left alone, and so is every non-404 request.
 */
final class NotFoundTitle {
	/**
	 * `pre_get_document_title` callback.
	 *
	 * Takes `mixed` because the filter is public: a value that is not a
	 * string is somebody else's decision and passes through.
	 *
	 * @param mixed $title Title from earlier callbacks.
	 *
	 * @return mixed `''` for a padded 404 title, the input otherwise.
	 */
	public static function filter( mixed $title ): mixed {
		if ( ! is_string( $title ) || '' === $title ) {
			return $title;
		}

		if ( ! is_404() ) {
			return $title;
		}

		return trim( $title ) === $title ? $title : '';
	}

	/**
	 * Hook the filter after every other callback. An SEO plugin registers its
	 * own at a high priority, and anything that ran later would overwrite the
	 * repair.
	 */
	public static function register(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'filter' ), PHP_INT_MAX );
	}
}
