<?php

declare(strict_types=1);

namespace Parisek\TimberKit\BreezeWarmup;

/**
 * Reads the three signals a sitemap cannot carry: which URLs sit in a menu,
 * which are a language's homepage, and which the admin typed into Breeze's
 * own preload list.
 *
 * Runs only inside the deferred refresh job. The purge-time filter must never
 * reach this class — that is the whole reason scoring is precomputed.
 *
 * Every method returns a map keyed by canonical URL so the caller can merge
 * signals with a single isset() per record.
 */
final class SignalCollector {

	/**
	 * Canonical URLs of every item in every registered nav menu.
	 *
	 * Menu membership is the strongest cheap signal of importance there is:
	 * somebody deliberately declared these pages worth linking from every
	 * page of the site.
	 *
	 * @return array<string, bool>
	 */
	public static function menuKeys(): array {
		if ( ! function_exists( 'wp_get_nav_menus' ) || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$keys = array();

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			$id    = is_object( $menu ) && isset( $menu->term_id ) ? (int) $menu->term_id : 0;
			$items = 0 !== $id ? wp_get_nav_menu_items( $id ) : false;
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$url = is_object( $item ) && isset( $item->url ) ? trim( (string) $item->url ) : '';
				if ( '' === $url ) {
					continue;
				}

				// A menu can hold custom links that are pure fragments
				// ('#section') or non-page targets ('mailto:'). Only a real
				// http(s) page URL can ever join with a sitemap record, so
				// anything else must not become a junk key in the map.
				$scheme = strtolower( (string) ( parse_url( $url, PHP_URL_SCHEME ) ?: '' ) );
				$host   = (string) ( parse_url( $url, PHP_URL_HOST ) ?: '' );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
					continue;
				}

				$keys[ UrlCanonicalizer::canonicalize( $url ) ] = true;
			}
		}

		return $keys;
	}

	/**
	 * Homepage of every active language, keyed by canonical URL.
	 *
	 * Breeze only ever knows about one homepage — whichever language the
	 * purge request happened to run in. Every other translation lands
	 * wherever the sitemap put it, which is the gap this closes.
	 *
	 * Foreign hosts are dropped without complaint: under WPML's
	 * domain-per-language mode each domain warms itself in its own purge
	 * request, so one language remaining here is correct, not a failure.
	 *
	 * @return array<string, string> Canonical URL => language code ('' without WPML).
	 */
	public static function frontPages(): array {
		if ( ! function_exists( 'home_url' ) ) {
			return array();
		}

		$home = (string) home_url( '/' );
		$host = strtolower( (string) ( parse_url( $home, PHP_URL_HOST ) ?: '' ) );

		$languages = function_exists( 'apply_filters' )
			? apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => false ) )
			: null;

		if ( ! is_array( $languages ) || array() === $languages ) {
			return array( UrlCanonicalizer::canonicalize( $home ) => '' );
		}

		$pages = array();
		foreach ( $languages as $language ) {
			$url = is_array( $language ) && isset( $language['url'] ) ? (string) $language['url'] : '';
			if ( '' === $url ) {
				continue;
			}

			if ( strtolower( (string) ( parse_url( $url, PHP_URL_HOST ) ?: '' ) ) !== $host ) {
				continue;
			}

			$code = is_array( $language ) && isset( $language['language_code'] )
				? (string) $language['language_code']
				: '';

			$pages[ UrlCanonicalizer::canonicalize( $url ) ] = $code;
		}

		return array() === $pages ? array( UrlCanonicalizer::canonicalize( $home ) => '' ) : $pages;
	}

	/**
	 * Canonical URLs the admin typed into Breeze's Preload settings tab.
	 *
	 * Read here rather than from the filter argument: at purge time there is
	 * no room to score anything, and in the refresh job this is one cheap
	 * option read.
	 *
	 * @return array<string, bool>
	 */
	public static function manualKeys(): array {
		if ( ! function_exists( 'breeze_get_option' ) ) {
			return array();
		}

		$options = breeze_get_option( 'preload_settings', false );
		$raw     = is_array( $options ) && isset( $options['breeze-preload-cache-urls'] )
			? $options['breeze-preload-cache-urls']
			: array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keys = array();
		foreach ( $raw as $url ) {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				continue;
			}
			$keys[ UrlCanonicalizer::canonicalize( trim( $url ) ) ] = true;
		}

		return $keys;
	}

	/**
	 * Active language codes plus the default, for language attribution.
	 *
	 * @return array{codes: array<int, string>, default: string}
	 */
	public static function activeLanguages(): array {
		$languages = function_exists( 'apply_filters' )
			? apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => false ) )
			: null;

		if ( ! is_array( $languages ) || array() === $languages ) {
			return array( 'codes' => array(), 'default' => '' );
		}

		$codes   = array();
		$default = '';
		foreach ( $languages as $language ) {
			$code = is_array( $language ) && isset( $language['language_code'] )
				? strtolower( (string) $language['language_code'] )
				: '';
			if ( '' === $code ) {
				continue;
			}
			$codes[] = $code;
			if ( '' === $default ) {
				$default = $code;
			}
		}

		$currentDefault = function_exists( 'apply_filters' )
			? apply_filters( 'wpml_default_language', null )
			: null;

		if ( is_string( $currentDefault ) && '' !== $currentDefault ) {
			$default = strtolower( $currentDefault );
		}

		return array( 'codes' => $codes, 'default' => $default );
	}
}
