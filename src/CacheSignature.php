<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * One answer to one question: when are two renders interchangeable?
 *
 * Anything cached across requests needs that answer, and needs the same one —
 * a menu and a block that disagree about what makes two visitors equivalent
 * will disagree about who may be served whose content. So the shared part of
 * every cross-request cache key is composed here, once, and callers append only
 * what is specific to them (a menu name, a block's attributes).
 *
 * Four dimensions, each because it changes the correct answer for an otherwise
 * identical render:
 *
 * | Dimension | Source | Why |
 * | --- | --- | --- |
 * | Site | `get_current_blog_id()` | menus, blocks and field groups are per site |
 * | Language | {@see Helpers::getLanguage()} | WPML changes permalinks and strings |
 * | Audience | the current user's roles | plugins routinely filter menus by role |
 * | Content version | `posts` + `terms` last-changed | anything the render read may have moved |
 *
 * **The content version is what makes invalidation impossible to forget.**
 * WordPress bumps `last_changed` for a cache group whenever anything in it is
 * invalidated, so a saved post or an edited term changes the key rather than
 * requiring a hook to notice. The alternative — a list of actions to flush on —
 * is only as complete as its longest-serving maintainer remembers, and every
 * plugin that writes content adds a row to it. A stale menu is a wrong link on
 * every page, with no error and no log.
 *
 * The cost is that any content change rebuilds every keyed entry once. That is
 * the trade: one rebuild per save, against a class of staleness bug that cannot
 * be tested for.
 *
 * @see docs/adr/0007-cross-request-cache-signature.md
 */
final class CacheSignature {

	/**
	 * Memoized signature for this request.
	 *
	 * Every input is meant to hold still for the length of one render, so
	 * computing it per lookup would buy nothing. A process that outlives a
	 * render — WP-CLI, a worker — must call {@see flush()}; there the inputs do
	 * move, and a stale signature would hand it the answer from before its own
	 * write.
	 *
	 * @var string|null
	 */
	private static ?string $memo = null;

	/**
	 * The shared part of a cross-request cache key.
	 *
	 * Opaque by design: callers concatenate it, never parse it. The order and
	 * the separators are an implementation detail, and the only property they
	 * must have is that two different worlds cannot produce one string.
	 *
	 * @return string
	 */
	public static function shared(): string {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		self::$memo = sprintf(
			'b%d|l%s|a%s|%s',
			$blog_id,
			Helpers::getLanguage(),
			self::audience(),
			self::contentVersion()
		);

		return self::$memo;
	}

	/**
	 * Whether a value stored under this signature can outlive the request.
	 *
	 * Two things have to hold. Without a persistent object cache there is
	 * nothing to outlive it, so a caller should skip the read and the write
	 * rather than pay for both and always miss. Without `wp_cache_get_last_changed()`
	 * there is no content version, and a key that cannot go stale on its own is
	 * worse than no key at all.
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool {
		return function_exists( 'wp_using_ext_object_cache' )
			&& wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_get_last_changed' );
	}

	/**
	 * Drop the memoized signature.
	 *
	 * @internal
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * Who is looking, to the precision that changes what they may see.
	 *
	 * Roles, not the user id. Role is the axis plugins actually gate menus on,
	 * and it keeps the number of stored variants to the number of roles rather
	 * than the number of accounts — an editorial team shares one entry instead
	 * of filling the cache with a copy each. A site whose menu varies per
	 * individual user is beyond what this models; it caches nothing correct and
	 * should not cache at all.
	 *
	 * Sorted, so two users with the same roles in a different order agree.
	 *
	 * @return string
	 */
	private static function audience(): string {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return 'anon';
		}

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return 'anon';
		}

		$user = wp_get_current_user();
		$roles = ( isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : [];
		$roles = array_values( array_filter( array_map( 'strval', $roles ) ) );

		if ( [] === $roles ) {
			// Logged in with no role is not anonymous: a plugin can show it
			// something an anonymous visitor must not see.
			return 'norole';
		}

		sort( $roles );

		return implode( ',', $roles );
	}

	/**
	 * A token that changes whenever stored content might have moved.
	 *
	 * Both groups matter and for different reasons: `posts` covers a page whose
	 * slug changed under a menu link, `terms` covers a menu itself, which is a
	 * taxonomy term.
	 *
	 * @return string
	 */
	private static function contentVersion(): string {
		if ( ! function_exists( 'wp_cache_get_last_changed' ) ) {
			return 'none';
		}

		return 'p' . wp_cache_get_last_changed( 'posts' )
			. '|t' . wp_cache_get_last_changed( 'terms' );
	}
}
