# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.4.0] - 2026-05-15

### Fixed
- `Helpers::formatFields()` now resolves ACF field groups in two contexts where ACF's default `get_field_objects()` location matcher silently drops registered groups:
  - **`nav_menu_item` posts** — ACF's screen detection from a bare post id resolves to `{type: post, id}`, which can't match `nav_menu_item` location rules (they require `nav_menu_item` + `nav_menu` in the screen). The function now detects menu-item posts (via `post_type` on the input object, or `get_post_type()` on a numeric id) and builds the explicit screen, querying matching groups via `acf_get_field_groups()` and reading each top-level field via `get_field()`. Side-effect: `Helpers::formatMenu()` now correctly merges any ACF field group registered on `nav_menu_item == all` / `location/<slug>` / `<menu_id>` into each menu item (e.g. a per-item `featured_card` group).
  - **Options-page string ids (`'option'`, `'options'`, custom keys)** — same root cause: ACF decodes these to `{type: option, id: 'option'}` without the `options_page` key location rules need. `get_field_objects('option')` then returns *some* matching groups while silently dropping others (the exact set is non-deterministic across ACF versions). The function now detects options post ids via `acf_decode_post_id()`, iterates every registered options page via `acf_get_options_pages()`, and queries `acf_get_field_groups(['options_page' => $menu_slug])` per page — surfacing every registered group reliably. When a project registers multiple options pages the result is a union keyed by field name; collisions across pages should be avoided by giving each page's top-level group a unique name.

  Detection paths short-circuit early — nav-menu-item detection skips the extra `get_post_type()` lookup when the input object already carries `post_type` (typical `Timber\MenuItem` path); options detection skips `block_*` strings so Gutenberg block ids aren't misclassified.

  Hardening following the post-merge review pass:
  - **Term-object false positive guarded.** A term object whose `term_id` coincidentally equals a `nav_menu_item` post id no longer routes through the menu-item path. Detection now bails early on `WP_Term` instances and on plain objects exposing `term_id` without `post_type`, preventing a silent wrong-fields regression for `formatFields($term)` callers.
  - **`acf_get_field_groups()` returning non-array no longer crashes the options walk.** Added an `is_array()` guard so a misbehaving ACF response (`false` / `null` from a corrupt page) skips that page instead of triggering a PHP 8 `TypeError`.
  - **`get_field()` guarded by `function_exists()`.** Both `getFieldObjectsForNavMenuItem()` and `getFieldObjectsForOptions()` now check `function_exists('get_field')` in their top-level early return alongside the existing `acf_get_field_groups` / `acf_get_fields` checks, so partial-ACF / mocked-ACF environments don't fatal mid-walk.

### Changed
- Internal refactor: the duplicated group→field→value walk shared by `getFieldObjectsForNavMenuItem()` and `getFieldObjectsForOptions()` is consolidated into a private `getFieldObjectsByScreen($screen, $value_post_id)` helper. Both context-specific resolvers now keep only their screen-construction logic and delegate the ACF walk. No behavior change — same outputs for the same inputs, verified by the existing 31-test FormatFieldsTest suite.
- **Options-page namespace filter.** `Helpers::formatFields()` now filters `acf_get_options_pages()` by the caller's post-id namespace before walking field groups. Previously a `formatFields('option')` call unioned fields across *every* registered options page regardless of each page's `post_id`, so a custom-namespace page registered via `acf_add_options_page(['post_id' => 'company_settings'])` would silently bleed its fields into the default `'option'` lookup (and vice versa). Both sides are normalized through `acf_decode_post_id()`, so the `'option'` / `'options'` alias still maps to the same canonical namespace. Multiple pages sharing the same namespace are still unioned, and same-namespace field-name collisions keep the documented last-writer-wins behavior. Projects that intentionally relied on the cross-namespace union now need to call `formatFields()` per namespace and merge themselves.

### Added
- `Helpers::readTime()` returns an estimated reading time (minutes) for a post or arbitrary content. Accepts a `\WP_Post`-loadable post ID, an HTML string, or `null` (uses the current post). Counts words via `preg_match_all('/\p{L}+/u', …)` so non-ASCII alphabets (Czech diacritics, Cyrillic, Greek, etc.) are not undercounted by locale-dependent `str_word_count()`. Adds `$secondsPerImage` (default `12`) to the budget for `<img>` tags before stripping HTML, and rounds the final minutes up with `ceil()`, clamped to a minimum of `1`. Auto-detects the post language via `Helpers::getLanguage()` and picks a per-language WPM (Slavic 170, German 190, Romance/English 220, fallback 200) so Czech/Polish/Slovak posts get a realistic estimate. The language → WPM map is filterable via `timber_kit_read_time_wpm_per_language`, and the final minute count via `timber_kit_read_time_minutes`. An explicit `$wpm` argument bypasses auto-detection entirely
- `Helpers::getLanguage( \WP_Post|int|null $post = null )` returns a normalized (lowercased, trimmed) language code for a post (or the current request), preferring WPML's `wpml_post_language_details` per-post data, then `wpml_current_language` for site-wide context, then `get_locale()` as a final fallback. WPML region/script subtags such as `pt-br` or `zh-hans` are preserved; only the locale fallback is strictly 2 letters. Reusable building block for `readTime()`, breadcrumbs, hreflang, SEO meta, and any other language-aware helper
- `$disable_application_passwords` (default `true`) disables WordPress application passwords via `wp_is_application_passwords_available` so the `/wp-json/wp/v2/users/<id>/application-passwords` endpoint cannot be used to issue long-lived API credentials
- `$block_author_enumeration` (default `true`) hooks `template_redirect` at priority 9 (before `redirect_canonical`) and turns numeric `?author=N` requests into a 404, blocking the classic username-disclosure attack where `/?author=1` redirects to `/author/{username}/`. Path-based `/author/{slug}/` requests, admin author-filter dropdowns (`is_admin()`), and mixed alphanumeric slugs are left untouched
- `$disable_file_editing` (default `true`) defines `DISALLOW_FILE_EDIT` if not already defined, removing the Theme Editor and Plugin Editor screens from `wp-admin`
- `$remove_wp_generator` (default `true`) returns an empty string from the `the_generator` filter, suppressing the WordPress version both in `<meta name="generator">` and in RSS/Atom feed generators (the existing `$cleanup_wp_head` already drops the `<meta>` tag via `remove_action('wp_head', 'wp_generator')`; this filter additionally covers feed surfaces)
- Unit tests for `Helpers::readTime()` (11 cases including Czech locale auto-detect, Unicode word counting, image budget, filter overrides, negative image-budget clamping, post-ID source), `Helpers::getLanguage()` (5 cases covering WPML per-post precedence, site-wide fallback, invalid payload handling), and `StarterBase::block_author_enumeration()` (6 cases including admin skip, multi-digit IDs, mixed alphanumeric)
- Minimal `WP_Query` and `WP_Post` stubs in `tests/bootstrap.php` so production `instanceof` checks pass without booting WordPress

## [1.3.0] - 2026-05-13

### Added
- `$disable_comments` now also closes off comment access at the API layer. New public methods `StarterBase::disable_comments_rest_endpoints()` and `StarterBase::disable_comments_xmlrpc_methods()` remove `/wp/v2/comments` REST routes (anonymous reads and authenticated writes both return 404) and strip comment + pingback methods from XML-RPC. The shared `remove_x_pingback_header()` is now also wired when `$disable_comments = true` so the `X-Pingback` header disappears even with XML-RPC enabled site-wide
- `StarterBase::disable_comments_for_post_type()` hooked to `registered_post_type` removes `comments`/`trackbacks` support from each post type as it registers, so post types added on later hooks or higher priorities than the `init` sweep are also covered
- `StarterBase::disable_comments_rest_insertion()` hooked to `rest_pre_insert_comment` rejects comment insertion with a `403 rest_comment_closed` error as defense in depth — comments cannot be created via REST even if a plugin re-registers a comments route after the `rest_endpoints` filter
- `pre_option_default_comment_status` and `pre_option_default_ping_status` filters force `closed` defaults so post types registered by plugins after `StarterBase` do not silently re-enable comments
- Standalone `feed_links_show_comments_feed` short-circuit inside the `$disable_comments` block, so the comments-only RSS link is suppressed even when `$disable_feeds = false`
- Unit tests for `disable_comments_rest_endpoints()`, `disable_comments_xmlrpc_methods()`, `disable_comments_for_post_type()`, and `disable_comments_rest_insertion()`

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
