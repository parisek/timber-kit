# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- `$disable_comments` now also closes off comment access at the API layer. New public methods `StarterBase::disable_comments_rest_endpoints()` and `StarterBase::disable_comments_xmlrpc_methods()` remove `/wp/v2/comments` REST routes (anonymous reads and authenticated writes both return 404) and strip comment + pingback methods from XML-RPC. The shared `remove_x_pingback_header()` is now also wired when `$disable_comments = true` so the `X-Pingback` header disappears even with XML-RPC enabled site-wide
- `pre_option_default_comment_status` and `pre_option_default_ping_status` filters force `closed` defaults so post types registered by plugins after `StarterBase` do not silently re-enable comments
- Standalone `feed_links_show_comments_feed` short-circuit inside the `$disable_comments` block, so the comments-only RSS link is suppressed even when `$disable_feeds = false`
- Unit tests for `disable_comments_rest_endpoints()` and `disable_comments_xmlrpc_methods()`

### Changed
- `disable_comments` action priority raised from `100` to `PHP_INT_MAX` so `remove_post_type_support()` also covers custom post types registered late on the `init` hook by plugins
- `disable_comments()` foreach over `get_post_types()` removes `comments` and `trackbacks` support from every registered post type and `unregister_widget('WP_Widget_Recent_Comments')`, instead of only handling `post` and `page`
- Dropped redundant `$accepted_args = 2` from `comments_open`, `pings_open`, and `comments_array` filters — `__return_false` and `__return_empty_array` ignore their arguments anyway

### Fixed
- Unit test for `disable_comments_admin_redirect()` no longer kills the PHPUnit process: the mocked `wp_safe_redirect` now throws to short-circuit before the production `exit;` runs (language constructs cannot be caught by `try/catch`)

## [1.2.0] - 2026-05-05

### Added
- `WPFormsConfigBridge` to override entries of the `wpforms_settings` option from `wp-config.php` constants. Setting key `turnstile-site-key` is bridged from `WPFORMS_TURNSTILE_SITE_KEY` (hyphens become underscores, name uppercased), letting per-env values such as Cloudflare Turnstile test keys live in environment config instead of the database. Hooks both `option_wpforms_settings` and `default_option_wpforms_settings` so the bridge fires on fresh installs where the option has never been saved. Activated automatically by `StarterBase` when WPForms is loaded
- Admin notice on WPForms admin screens listing each setting key currently overridden by a `WPFORMS_*` constant, so editors are not confused when their saved value is replaced at runtime. Re-registered from `in_admin_header` so it survives WPForms wiping the `admin_notices` callback list on its own admin screens

## [1.1.2] - 2026-04-25

### Fixed
- `get_site_icon_url` filter is now only registered when the configured favicon file exists on disk, so WordPress falls back to the default site icon instead of producing a broken URL when the theme favicon is missing

## [1.1.1] - 2026-04-16

### Added
- `Helpers::sanitizeEditorContent()` to strip TinyMCE artifacts such as bookmark spans and bogus line breaks from editor content
- `Helpers::getEditorAllowedHtml()` shared allow-list for ACF WYSIWYG content, permitting common editorial markup including `<span class="…">` but excluding inline styles and embedded `<script>` / `<iframe>`
- `Helpers::isEditorContentEmpty()` to detect visually empty editor content (handles non-breaking spaces, zero-width and bidi control characters)
- `acf/update_value/type=wysiwyg` wired to `StarterBase::sanitize_acf_editor_value()` — sanitize content on save via `wp_kses()` with the shared allow-list
- Client-side TinyMCE sanitization on `BeforeSetContent`, `PastePreProcess`, and `GetContent` events plus `verify_html`, `invalid_elements=script,iframe`, and `paste_webkit_styles=none` in `tiny_mce_before_init`

### Changed
- `Helpers::fieldFormatter()` now strips TinyMCE artifacts from rendered `wysiwyg` values, not just during the emptiness check, while avoiding editor-specific sanitization for `textarea` to prevent destructive cleanup. Tag and attribute filtering for saved WYSIWYG content is enforced via `wp_kses()`

## [1.1.0] - 2026-04-13

### Added
- `DevMediaProxy` for development environments with missing local uploads
- automatic activation from `StarterBase` via `TIMBERKIT_MEDIA_ORIGIN`
- domain-only media origins, e.g. `https://example.com`, with automatic reuse of the local uploads path
- Resizer integration through `timber_kit_resizer_missing_source_variants` for remote variant fallback
- Composer scripts for `composer test` and `composer phpstan`

### Changed
- Resizer missing-source handling now delegates to a filter so dev media behavior stays isolated in `DevMediaProxy`

### Fixed
- Media Library JS payload rewriting now covers nested `sizes[*].url` and `icon`
- DevMediaProxy hardens missing-file resolution against traversal-style relative paths
- configured media origins strip URL userinfo before rendering rewritten URLs
- remote Resizer variant probing is bounded per request and configurable via filter

## [1.0.4] - 2026-04-07

### Added
- ACF JSON save/load support for `user_form` location rule — field groups assigned to user forms are saved to `templates/user/`

## [1.0.0] - 2026-03-21

### Added
- `StarterBase` — WordPress/Timber base class with 25 configurable properties and 45+ methods
- `Helpers` — ACF field formatting, menu helpers, image/link/video formatters
- `Resizer` — Image resizing via Spatie/Image with AVIF support
- Security & cleanup: wp_head cleanup, XML-RPC disable, emoji removal, feed/comment disable
- Media processing: filename sanitization, upload resize (replaces clean-image-filenames + imsanity plugins)
- Gutenberg: align-wide, responsive-embeds, editor-styles, disable core patterns
- Editor role enhancements: login redirect, WPML translate, privacy page cap
- REST API users endpoint protection
- 293 unit tests, PHPStan level 5
- DDEV config for standalone development (PHP 8.3, no DB)
