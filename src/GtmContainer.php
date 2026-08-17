<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * First-party Google Tag Manager container loader.
 *
 * A project that only needs GTM to load — no data layer, no ecommerce
 * payload, no plugin-side event tracking — configures its containers on
 * `StarterBase::$gtm_containers` and gets this snippet. Everything the
 * GTM4WP plugin adds beyond the snippet stays that plugin's job; a site
 * that needs it keeps the plugin and leaves the property empty.
 *
 * Three behaviours are load-bearing and are the reason this exists rather
 * than a copy of the snippet in every theme:
 *
 * - A container reached through its own server-side path carries no
 *   container ID in the URL. The path already selects the container, so
 *   repeating the ID leaks it into every request and hands blockers the
 *   pattern the custom path exists to avoid.
 * - Containers are keyed by language, so a WPML site serves the container
 *   its editors actually report on.
 * - Measurement is off outside production unless a constant says otherwise,
 *   so a local or staging environment cannot pollute the data.
 */
final class GtmContainer {

	/** Container ID shape Google issues and the only one accepted. */
	private const ID_PATTERN = '/^GTM-[A-Z0-9]+$/';

	/** Characters a loader path may contain; a query string is not one of them. */
	private const PATH_PATTERN = '/^[a-zA-Z0-9\.\-\_\/]+$/';

	/** JavaScript identifier shape for the data layer variable name. */
	private const DATALAYER_PATTERN = '/^[A-Za-z_$][A-Za-z0-9_$]*$/';

	private const DEFAULT_HOST      = 'www.googletagmanager.com';
	private const DEFAULT_PATH      = 'gtm.js';
	private const DEFAULT_DATALAYER = 'dataLayer';

	/** Key holding the fallback container in a language-keyed map. */
	public const DEFAULT_KEY = 'default';

	/**
	 * Whether this environment may load a container at all.
	 *
	 * `TIMBERKIT_GTM_ENABLED` decides when it is defined. Without it the
	 * answer is "production only" — chosen so that forgetting the constant
	 * costs nothing on production and still keeps every other environment
	 * out of the data.
	 *
	 * The gate is deliberately not `WP_DEBUG`: that flag says how errors are
	 * reported, and a site that turns it off to quieten a log must not lose
	 * its measurement as a side effect.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( defined( 'TIMBERKIT_GTM_ENABLED' ) ) {
			return filter_var( constant( 'TIMBERKIT_GTM_ENABLED' ), FILTER_VALIDATE_BOOLEAN );
		}

		return 'production' === wp_get_environment_type();
	}

	/**
	 * Picks the container for one language out of the configured map.
	 *
	 * A language entry states only what differs from `default` and inherits
	 * the rest, because sites that split containers by language normally
	 * share one server-side endpoint and differ in the ID alone.
	 *
	 * @param array<string, array<string, string|bool>|string> $containers Language-keyed container map.
	 * @param string|null                                 $language   Current language code, if any.
	 * @return array<string, string|bool> Resolved container, empty when none applies.
	 */
	public static function resolve( array $containers, ?string $language ): array {
		$default = self::normalize( $containers[ self::DEFAULT_KEY ] ?? array() );

		if ( NULL === $language || '' === $language ) {
			return $default;
		}

		$by_code = array();
		foreach ( $containers as $code => $entry ) {
			if ( self::DEFAULT_KEY === $code ) {
				continue;
			}

			$by_code[ self::canonical_code( (string) $code ) ] = $entry;
		}

		foreach ( self::code_candidates( $language ) as $candidate ) {
			if ( ! array_key_exists( $candidate, $by_code ) ) {
				continue;
			}

			// A language written out and left blank says "do not measure
			// here". Inheritance would otherwise make that unsayable: every
			// spelling of nothing would resolve back to `default` and
			// measure anyway.
			if ( self::is_blank( $by_code[ $candidate ] ) ) {
				return array();
			}

			return array_merge( $default, self::normalize( $by_code[ $candidate ] ) );
		}

		return $default;
	}

	/**
	 * Language codes to try, most specific first.
	 *
	 * A regional variant belongs to its language before it belongs to the
	 * site default: an Austrian visitor reports into the German container
	 * unless Austria states its own. Falling straight through to `default`
	 * would silently file them under the site's main language instead.
	 *
	 * @param string $language Current language code.
	 * @return list<string>
	 */
	private static function code_candidates( string $language ): array {
		$code       = self::canonical_code( $language );
		$candidates = array( $code );

		$separator = strrpos( $code, '-' );
		while ( FALSE !== $separator ) {
			$code         = substr( $code, 0, $separator );
			$candidates[] = $code;
			$separator    = strrpos( $code, '-' );
		}

		return $candidates;
	}

	/**
	 * One spelling for a language code.
	 *
	 * WPML lets an editor type the code when adding a language, so the same
	 * regional variant arrives as `de-at`, `de_AT` or `de-AT` depending on
	 * who set the site up. Configuration and runtime value are folded to the
	 * same shape so the match does not depend on that choice.
	 *
	 * @param string $code Configured or current language code.
	 * @return string
	 */
	private static function canonical_code( string $code ): string {
		return str_replace( '_', '-', strtolower( trim( $code ) ) );
	}

	/**
	 * Builds the loader snippet for one resolved container.
	 *
	 * @param array<string, string|bool> $container      Resolved container.
	 * @param string                $datalayer_name Data layer JS variable name.
	 * @return string Script block, empty when the container is unusable.
	 */
	public static function snippet( array $container, string $datalayer_name = self::DEFAULT_DATALAYER ): string {
		$id = (string) ( $container['id'] ?? '' );
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return '';
		}

		$datalayer = 1 === preg_match( self::DATALAYER_PATTERN, $datalayer_name )
			? $datalayer_name
			: self::DEFAULT_DATALAYER;

		$host     = self::host( $container );
		$path     = self::path( $container );
		$owns_id  = self::DEFAULT_PATH !== $path;
		$base_url = 'https://' . $host . '/' . $path;

		// A path that selects the container needs no `id` parameter, so its
		// query string starts rather than continues: `?l=` instead of `&l=`.
		// The `i` argument would then be unused, and an unused argument in a
		// snippet people read and compare against Google's own is noise.
		$separator = $owns_id ? '?' : '&';
		$args      = $owns_id ? 'w,d,s,l' : 'w,d,s,l,i';
		$src       = $owns_id
			? "'" . $base_url . "'+dl"
			: "'" . $base_url . "?id='+i+dl" . self::environment( $container );
		$call_args = "'script','" . $datalayer . "'" . ( $owns_id ? '' : ",'" . $id . "'" );

		return "\n<script data-cfasync=\"false\" data-pagespeed-no-defer>\n"
			. '(function(' . $args . "){w[l]=w[l]||[];w[l].push({'gtm.start':\n"
			. "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n"
			. "j=d.createElement(s),dl=l!='" . self::DEFAULT_DATALAYER . "'?'" . $separator . "l='+l:'';j.async=true;j.src=\n"
			. $src . ";f.parentNode.insertBefore(j,f);\n"
			. '})(window,document,' . $call_args . ");\n"
			. "</script>\n";
	}

	/**
	 * Whether an entry states, in any of its spellings, that there is no
	 * container here.
	 *
	 * An entry stating an endpoint but no ID counts as blank too: the ID is
	 * the container, and an entry without one measures nothing wherever the
	 * rest of it points.
	 *
	 * @param mixed $entry Configured entry.
	 * @return bool
	 */
	private static function is_blank( $entry ): bool {
		if ( is_array( $entry ) ) {
			return array() === $entry
				|| ( array_key_exists( 'id', $entry ) && '' === trim( (string) $entry['id'] ) );
		}

		return NULL === $entry || FALSE === $entry || '' === trim( (string) $entry );
	}

	/**
	 * Builds the `noscript` iframe block for one resolved container.
	 *
	 * The iframe addresses `ns.html` and that endpoint takes the container
	 * ID as a query parameter — there is no id-less form of it. So the block
	 * costs nothing where the ID already appears in the loader URL, and
	 * defeats the purpose where a custom path exists to keep it out.
	 * It is therefore printed by default only in the first case; `noscript`
	 * on the container overrides the decision either way.
	 *
	 * @param array<string, string|bool> $container Resolved container.
	 * @return string Noscript block, empty when it should not be emitted.
	 */
	public static function noscript( array $container ): string {
		$id = (string) ( $container['id'] ?? '' );
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return '';
		}

		$hides_id = self::DEFAULT_PATH !== self::path( $container );
		$wanted   = array_key_exists( 'noscript', $container )
			? filter_var( $container['noscript'], FILTER_VALIDATE_BOOLEAN )
			: ! $hides_id;

		if ( ! $wanted ) {
			return '';
		}

		// `ns.html` is served from the host root; the loader path addresses
		// the script only, so reusing it here would build a URL nothing answers.
		$url = 'https://' . self::host( $container ) . '/ns.html?id=' . $id
			. self::environment_query( $container );

		return "\n<noscript><iframe src=\"" . esc_url( $url ) . '" height="0" width="0" '
			. 'style="display:none;visibility:hidden" aria-hidden="true"></iframe></noscript>' . "\n";
	}

	/**
	 * Reads a container entry written either as a full array or as the
	 * container ID on its own.
	 *
	 * @param array<string, string|bool>|string $entry Configured entry.
	 * @return array<string, string|bool>
	 */
	private static function normalize( $entry ): array {
		if ( is_string( $entry ) ) {
			return '' === $entry ? array() : array( 'id' => $entry );
		}

		return $entry;
	}

	/**
	 * Validated loader host, falling back to Google's own.
	 *
	 * @param array<string, string|bool> $container Resolved container.
	 * @return string
	 */
	private static function host( array $container ): string {
		$domain = strtolower( trim( (string) ( $container['domain'] ?? '' ) ) );
		if ( '' === $domain ) {
			return self::DEFAULT_HOST;
		}

		$validated = filter_var( $domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );

		return FALSE === $validated ? self::DEFAULT_HOST : $validated;
	}

	/**
	 * Validated loader path.
	 *
	 * An unusable path falls back to `gtm.js` rather than to nothing: the
	 * container stays reachable, and the ID reappearing in the URL is a
	 * visible symptom rather than a silent outage.
	 *
	 * @param array<string, string|bool> $container Resolved container.
	 * @return string
	 */
	private static function path( array $container ): string {
		$path = ltrim( trim( (string) ( $container['path'] ?? '' ) ), '/' );
		if ( '' === $path ) {
			return self::DEFAULT_PATH;
		}

		return 1 === preg_match( self::PATH_PATTERN, $path ) ? $path : self::DEFAULT_PATH;
	}

	/**
	 * GTM environment parameters, both halves or neither.
	 *
	 * Only the googletagmanager loader takes them. A container addressed by
	 * its own path is served by a tagging server that has no notion of GTM
	 * environments, so the values are ignored there rather than appended to
	 * a URL that would not honour them.
	 *
	 * @param array<string, string|bool> $container Resolved container.
	 * @return string
	 */
	private static function environment( array $container ): string {
		$query = self::environment_query( $container );

		return '' === $query ? '' : "+'" . $query . "'";
	}

	/**
	 * GTM environment parameters as a bare query-string fragment.
	 *
	 * @param array<string, string|bool> $container Resolved container.
	 * @return string
	 */
	private static function environment_query( array $container ): string {
		$auth    = trim( (string) ( $container['gtm_auth'] ?? '' ) );
		$preview = trim( (string) ( $container['gtm_preview'] ?? '' ) );

		if ( '' === $auth || '' === $preview ) {
			return '';
		}

		return '&gtm_auth=' . rawurlencode( $auth )
			. '&gtm_preview=' . rawurlencode( $preview )
			. '&gtm_cookies_win=x';
	}
}
