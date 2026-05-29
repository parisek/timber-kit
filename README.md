# timber-kit

WordPress/Timber starter kit — configurable base class, ACF helpers, image resizer, dev media proxy, WPForms config bridge, ACF block renderer, WPML Copy-field override.

## Installation

```bash
composer require parisek/timber-kit
```

## What's Included

### StarterBase

Extends `Timber\Site` with 25 configurable properties. Handles theme setup, Twig extensions, security hardening, Gutenberg blocks, media processing, and admin cleanup — all opt-in via boolean flags.

### Helpers

Static methods for formatting ACF data into clean arrays for Twig templates:

- `formatImage()`, `formatFile()`, `formatVideo()` — media formatting
- `formatFields()`, `fieldFormatter()` — ACF field processing
- `formatLink()` — link/button formatting
- `formatMenu()` — navigation menus
- `formatTerms()` — taxonomy terms
- `formatLanguageSwitcher()` — WPML language switcher
- `resizeImage()` — responsive image variants
- `pagination()` — pagination formatting
- `readTime()` — estimated reading time in minutes (Unicode-aware word counting, image budget, WPML-aware per-language WPM)
- `getLanguage()` — normalized (lowercased, trimmed) language code for a post or the current request, with WPML per-post / site-wide / locale fallbacks. WPML region/script subtags are preserved (e.g. `pt-br`, `zh-hans`); only the locale fallback is strictly 2 letters
- `formatImageFrom( ?array $raw ): ?array` — pure-core formatter extracted from `formatImage()`'s associative-array branch. No WordPress dependencies, safe for unit / property tests; missing keys resolve to `null` silently, `id` / `width` / `height` are cast to `int|null`, and the WordPress SVG-1px workaround is applied uniformly

### Resizer

Image resizing via [Spatie/Image](https://github.com/spatie/image). AVIF output, responsive variants with breakpoints, crop positions, and cache management. Exposed as a single polymorphic Twig filter, `|resizer`, that detects its argument shape and routes to one of two underlying methods.

#### Tuples mode (positional, variadic)

Caller passes the variant tuples directly, in order. Each tuple is `[width, height, media-min-width, image_style, quality?]` — same shape `Resizer::resizer()` consumes:

```twig
{{ component_picture({
    image: item.image|resizer(
        ['960', '720', '1280', 'crop'],
        ['480', '360', '',     'crop'],
    ),
}) }}
```

#### Orientation-aware mode (single map arg)

When the single argument is an associative array carrying at least one of `landscape` / `portrait` / `square` keys, the filter classifies the source image's aspect (±10 % tolerance band around 1:1, overridable via the `timber_kit_resizer_aspect_tolerance` WP filter) and dispatches the matching tuple set to the standard resize pipeline:

```twig
{{ component_picture({
    image: item.image|resizer({
        landscape: [['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']],
        portrait:  [['720', '960', '1280', 'crop'], ['360', '480', '', 'crop']],
        square:    [['800', '800', '1280', 'crop'], ['400', '400', '', 'crop']],
    }),
}) }}
```

Lets templates drop the inline `image.width >= image.height` branch.

**Fallbacks.** Missing-metadata / non-numeric / zero-dimension sources classify as `landscape` (preserves the historical wide-crop default for legacy assets). When the matched bucket has no tuples (empty array or absent key), the helper falls through to the `landscape` bucket; if that's also empty / absent, the source passes through unchanged rather than crashing with an empty `<picture>`.

**Detection (how the two shapes coexist).** The dispatch lives in `Resizer::isOrientationMap()`: a single arg that's an associative array with at least one recognised key flips into orientation mode. Tuples have integer keys (width / height / media / image_style / quality), so the two shapes can't realistically collide. PHP callers wanting the bucket without the resize step can call `Resizer::classifyAspect()` directly.

### DevMediaProxy

Development-only media proxy for projects that do not keep `wp-content/uploads` synchronized locally. When `TIMBERKIT_MEDIA_ORIGIN` is configured, missing local media URLs are rewritten to the upstream origin for common WordPress media surfaces and Media Library payloads.

It also integrates with `Resizer` through the `timber_kit_resizer_missing_source_variants` filter, so missing local source images can fall back to already-generated remote variants before returning the original image URL.

### WPFormsConfigBridge

Bridges `wp-config.php` constants to entries of the `wpforms_settings` option, so per-environment values such as Cloudflare Turnstile test keys can be stored in environment config rather than the WordPress database.

A setting key `turnstile-site-key` is overridden by a constant `WPFORMS_TURNSTILE_SITE_KEY` (hyphens become underscores, the whole name uppercased). The bridge is activated automatically by `StarterBase` when WPForms is loaded.

### BlockRenderer

Render callback for ACF Gutenberg blocks defined via `block.json`. Migrated from per-theme `functions.php` so projects derived from `portadesign/wordpress-base` carry one versioned source of truth instead of duplicating ~140 lines per theme.

Wire as `block.json` `renderCallback`:

```json
{
    "acf": {
        "renderCallback": "Parisek\\TimberKit\\BlockRenderer::render"
    }
}
```

Or call from a wrapper in your theme's `functions.php` for backwards-compatible block.json files:

```php
function timber_block_render_callback( ...$args ): void {
    \Parisek\TimberKit\BlockRenderer::render( ...$args );
}
```

What it does:

- Resolves ACF block.json schema to a Twig template path
- Hydrates content via `Helpers::formatFields()`
- Two-tier cache: in-request memo for editor previews + external object cache (Redis with `flush_group`) for the frontend, gated by `has_filter()` (dynamic blocks skip frontend cache)
- Detects asset-enqueueing side effects (CF7, WPForms, …) and skips cache writes for those blocks so forms keep working
- Skips frontend cache writes for the editor-only empty-block warning so anonymous visitors don't see warnings meant for editors
- Renders a `.block-editor-warning` template for empty blocks when a logged-in user views them — uses Gutenberg's native classes so the editor styles it without shipping CSS
- Wraps inserter-library previews in a 16:9 aspect-ratio box for consistent thumbnails
- Skips the `block_<name>_content` filter during inserter-library previews so example data isn't enriched with derived values that would distort thumbnails

The class is `final` with three public static methods: `render()`, `isInserterPreview()`, `flushPostBlockCache()`.

#### Filters

**Package-level filters** (stable across versions, prefixed `timber_kit/`):

| Filter | Args | Purpose |
|--------|------|---------|
| `timber_kit/block_renderer/cache_key` | `(string $key, array $cache_data, string $block_name)` | Override the cache key composition (e.g. add user role / segment to the variation vectors). Default: `'acf_block_' . md5(wp_json_encode($cache_data))` with `$cache_data` = `[name, data, anchor, className, post_id, lang, paged]`. |
| `timber_kit/block_renderer/use_cache` | `(bool $enabled, string $block_name, array $attributes)` | Override the cache-enabled decision per block. Default: `true` when the block has no registered `block_<name>_content` filter and the site uses an external object cache with `flush_group` support. |
| `timber_kit/block_renderer/content_data` | `(?array $content_data, int|string $post_id, bool $is_preview, array $attributes)` | Override the content data ACF would have hydrated. Return a non-null array to short-circuit `Helpers::formatFields()` — useful for tests, storybook-style block fixtures, or projects that don't use ACF. Returning `null` (default) preserves the ACF code path. |
| `timber_kit/block_renderer/context` | `(array $context, string $block_name, bool $is_preview)` | Last-chance Twig context modification before `Timber::compile()` runs. |
| `timber_kit/block_renderer/empty_alert_html` | `(string $html, string $block_name, array $attributes)` | Replace the empty-block warning HTML entirely. Themes can return their own Twig render here (see migration example below). |

**Per-block legacy filters** (preserved from the original `timber_block_render_callback` for backwards compatibility — `<slug>` is the block name with `acf/` stripped and dashes converted to underscores, e.g. `acf/article-featured` → `article_featured`):

| Filter | Args | Purpose |
|--------|------|---------|
| `block_<slug>_content` | `(array $content_data)` | Per-block content transform (legacy hook preserved for backwards compatibility). Skipped during inserter-library previews so example data isn't enriched with derived values that would distort thumbnails. |
| `block_<slug>_template` | `(string $template_path, array $content_data)` | Per-block template path override (legacy hook). Runs in all modes including inserter previews. Default path: `@component/<slug>/<slug>.twig`. |

#### Twig template

`empty-alert.twig` is shipped under the `@timber-kit/` Twig namespace, registered automatically by `StarterBase` at priority 20 (so theme paths under the same namespace take precedence). It uses Gutenberg's `.block-editor-warning` classes for native editor styling and exposes a stable `.timber-kit-block-empty` class + `data-block` attribute for theme overrides.

#### Cache invalidation

`BlockRenderer::flushPostBlockCache($post_id)` is the handler `StarterBase` wires to `acf/save_post` at priority 20. When ACF saves a post, the cache group `acf_block_{$post_id}` is flushed — invalidating exactly the cached blocks tied to that post without touching others. The handler guards against non-numeric ids (ACF options-page strings, opaque `block_*` ids) and against environments without `wp_cache_supports('flush_group')`.

### WpmlBlockOverride

Runtime override of Copy field values in ACF Gutenberg blocks for WPML-multilingual sites. Hooks `render_block_data` at priority 20 (after WPML's own handlers) and, for ACF blocks rendered in a non-default language, overwrites `attrs.data.<field>` for fields marked `wpml_cf_preferences = 1` (Copy) with the source-language post's value. Attachment IDs (image / file / gallery) are remapped to per-language duplicates via `wpml_object_id`.

Solves the long-standing WPML problem where changing a Copy field (typically an image) in the source language never propagates to translated `post_content` without a manual ATE re-job. ACF configuration becomes the single source of truth for Copy fields — no DB writes, no admin UI, no drift.

Wire from your theme's `functions.php`:

```php
add_action( 'init', static function (): void {
    if ( class_exists( \Parisek\TimberKit\WpmlBlockOverride::class ) ) {
        \Parisek\TimberKit\WpmlBlockOverride::register();
    }
} );
```

Requirements (verified at `register()`):

- WPML active (`ICL_SITEPRESS_VERSION` defined)
- ACF Pro active (`acf_get_field_groups` available)

#### What it does

- Bypasses non-ACF blocks, admin context, REST requests, and the default language
- Walks ACF field definitions recursively to find every leaf marked `wpml_cf_preferences = 1` — top-level, plus nested inside repeater / group containers at arbitrary depth
- Generates ACF's flattened block-data key pattern for each Copy field (`items_N_image`, `faq_sections_N_items_M_title`, …) and overrides each from source
- Remaps reference ids to their target-language equivalents via `wpml_object_id`, so a translated page points at translated entities — not the source-language ones:

  | ACF field type | Remapped as | Notes |
  |---|---|---|
  | `image`, `file`, `gallery` | attachment | |
  | `post_object`, `relationship`, `page_link` | post | element type resolved per id via `get_post_type()` (a `page_link` holding a raw URL passes through) |
  | `taxonomy` | term | element type is the field's `taxonomy` |
  | `user`, `link`, scalar fields | — | not remapped (`user`: WPML doesn't translate users; `link`: URL handled by WPML's own link conversion) |
- Caches the full block-name → copy-fields index as a single transient with per-request memo
- Skips the persistent transient entirely under `WP_DEBUG` so dev iteration doesn't need manual invalidation
- Emits diagnostic `error_log` lines (`[timber_kit/wpml_block_override] …`) under `WP_DEBUG` for override events and missing source-block matches

#### Filters

| Filter | Args | Purpose |
|---|---|---|
| `timber_kit/wpml_block_override/should_override` | `(bool $default, array $block, string $current_lang, string $default_lang)` | Per-block veto. Default `true` after non-ACF / admin / REST / default-language guards have passed. |
| `timber_kit/wpml_block_override/copy_fields` | `(array $copy_fields, string $block_name)` | Extend or trim the Copy-field discovery for a block. `$block_name` is the **short** name (no `acf/` prefix). `$copy_fields` shape: `[ ['field' => array, 'path' => array<int, array{name,type}>], … ]`. |

Note the two filters receive the block name differently: `should_override` gets the full parsed block (`$block['blockName']` is `acf/foo`), while `copy_fields` gets the short name (`foo`).

#### Disabling / opting out

**Per project** — the simplest opt-out is to not call `register()` from the theme. To force it off at runtime even where `register()` already ran (e.g. a shared bootstrap), veto every block:

```php
add_filter( 'timber_kit/wpml_block_override/should_override', '__return_false' );
```

**Per block** — skip specific block types via `should_override` (full `acf/` name here):

```php
add_filter( 'timber_kit/wpml_block_override/should_override', function ( $enabled, $block ) {
    $off = [ 'acf/hero-text', 'acf/booking-form' ];
    return in_array( $block['blockName'] ?? '', $off, true ) ? false : $enabled;
}, 10, 2 );
```

**Per field** — keep the block syncing but drop one field from the Copy set via `copy_fields` (short block name here; the returned list is re-normalized, so re-indexing isn't required):

```php
add_filter( 'timber_kit/wpml_block_override/copy_fields', function ( $copy_fields, $block_name ) {
    if ( $block_name !== 'jumbotron-video' ) {
        return $copy_fields;
    }
    return array_values( array_filter(
        $copy_fields,
        fn ( $entry ) => $entry['field']['name'] !== 'background_image'
    ) );
}, 10, 2 );
```

#### Not supported (this iteration)

- `flexible_content` sub-fields — per-layout `sub_fields` require layout-name awareness
- REST API output — `render_block_data` doesn't fire for raw REST responses; out of scope for server-rendered themes

#### Known limitation

Cache invalidation hooks (`acf/update_field_group` + `save_post_acf-field-group`) do **not** fire for programmatic field registration via `acf_add_local_field_group()`. Code-only changes to `wpml_cf_preferences` will serve stale cache for up to 24 hours on production. Under `WP_DEBUG` the persistent transient is bypassed entirely so dev iteration is unaffected. Production workaround: `wp transient delete timber_kit_wpml_copy_fields_index` in the deploy script, or include a theme-version constant in the cache key.

#### Design context

See [`docs/adr/2026-05-28-wpml-block-override.md`](docs/adr/2026-05-28-wpml-block-override.md) for the full design rationale, prior-art comparison, and cache architecture decisions.

## Usage

Create a `Base` class in your theme that extends `StarterBase`:

```php
<?php

use Parisek\TimberKit\StarterBase;
use Parisek\TimberKit\Helpers;

class Base extends StarterBase {

    public function __construct() {
        $this->menus = [
            'main-menu' => 'Main Menu',
            'footer-menu' => 'Footer Menu',
        ];
        $this->font_stylesheets = [
            'poppins' => 'fonts/poppins/stylesheet.css',
        ];
        $this->disable_search = false;

        parent::__construct();
    }
}
```

## Configuration

Override these properties in your child constructor before calling `parent::__construct()`:

### Theme

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$menus` | array | `[]` | Registered navigation menus |
| `$font_stylesheets` | array | `[]` | CSS files to enqueue on the frontend. Also forwarded into the Gutenberg editor canvas (both iframed and non-iframed) via `block_editor_settings_all`, so custom `@font-face` declarations render in the editor without falling back to system fonts. Relative paths are resolved under `static/` and cache-busted with `filemtime`; absolute URLs pass through |
| `$preload_fonts` | array | `[]` | Font files to preload |
| `$search_post_types` | array | `['post']` | Post types for search |
| `$article_post_types` | array | `['post']` | Post types treated as articles |
| `$block_category` | array | `['slug' => 'custom', 'title' => 'Custom']` | Custom block category |
| `$favicon_path` | string | `'images/touch/favicon.svg'` | Favicon path |

### Security & Cleanup

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$cleanup_wp_head` | bool | `true` | Remove unnecessary wp_head output |
| `$disable_xmlrpc` | bool | `true` | Disable XML-RPC |
| `$disable_emojis` | bool | `true` | Remove emoji scripts/styles |
| `$disable_feeds` | bool | `true` | Disable RSS feeds |
| `$disable_comments` | bool | `true` | Disable comments site-wide: removes `comments`/`trackbacks` support from every registered post type (including those registered later via `registered_post_type`); closes `comments_open`/`pings_open`; redirects the Edit Comments admin page and Discussion Settings to the dashboard; unregisters the `WP_Widget_Recent_Comments` sidebar widget; removes `/wp/v2/comments` REST routes; rejects REST comment insertion with `403` even if a route is re-registered; removes comment + pingback XML-RPC methods; drops the `X-Pingback` header; and forces `default_comment_status`/`default_ping_status` to `closed`. Removal of the admin-bar `comments` node and the `dashboard_recent_comments` admin widget is controlled separately by `$cleanup_admin_bar` and `$cleanup_dashboard`. |
| `$disable_search` | bool | `true` | Disable search |
| `$cleanup_dashboard` | bool | `true` | Remove dashboard widgets |
| `$cleanup_admin_bar` | bool | `true` | Clean up admin bar |
| `$editor_role_enhancements` | bool | `true` | Enhanced editor role caps |
| `$disable_self_pingbacks` | bool | `true` | Disable self-pingbacks |
| `$restrict_rest_users` | bool | `true` | Protect REST API users endpoint |
| `$disable_application_passwords` | bool | `true` | Disable WordPress application passwords so the `application-passwords` REST endpoint cannot issue long-lived API credentials |
| `$block_author_enumeration` | bool | `true` | Turn numeric `?author=N` requests into a 404 on `template_redirect` (before `redirect_canonical`), so the `/?author=1` → `/author/{username}/` username-disclosure attack is blocked. Path-based `/author/{slug}/` URLs, admin author filters, and alphanumeric slugs are left alone |
| `$disable_file_editing` | bool | `true` | Define `DISALLOW_FILE_EDIT` so the Theme Editor and Plugin Editor screens are removed from `wp-admin` |
| `$remove_wp_generator` | bool | `true` | Strip the WordPress version from the `the_generator` filter (covers both `<meta name="generator">` and RSS/Atom feed generators) |

### Media Processing

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$clean_image_filenames` | bool | `true` | Sanitize uploaded filenames |
| `$max_upload_width` | int | `2560` | Max upload image width (px) |
| `$max_upload_height` | int | `2560` | Max upload image height (px) |

### Dev Media Proxy

Configure the proxy in environment config such as VPConfig:

```php
define( 'TIMBERKIT_MEDIA_ORIGIN', 'https://example.com' );
```

Behavior:

- if a local uploads file exists, its local URL is kept
- if a local uploads file is missing, the URL is rewritten to the configured origin
- a domain-only origin such as `https://example.com` automatically reuses the local uploads path
- a full origin such as `https://example.com/wp-content/uploads` is used verbatim
- Resizer can use the same origin to probe already-generated remote variants when local source files are missing

Available hooks:

- `timber_kit_resizer_missing_source_variants` — extension point used by `DevMediaProxy` to provide remote Resizer variants
- `timber_kit_resizer_probe_remote_variants` — enable/disable remote variant probing, default `true`
- `timber_kit_resizer_remote_variant_probe_timeout` — HTTP timeout for variant probes, default `2.0`
- `timber_kit_resizer_remote_variant_probe_limit` — max remote variant probes per request, default `50`
- `timber_kit_resizer_aspect_tolerance` — tolerance band around 1:1 used by `Resizer::classifyAspect()` to decide whether a source qualifies as `square`, default `0.1`. Returning a smaller value (e.g. `0.05`) tightens the square band; returning a larger value (e.g. `0.2`) loosens it.

### WPForms Config Bridge

Define overrides in `wp-config.php`:

```php
define( 'WPFORMS_CAPTCHA_PROVIDER',     'turnstile' );
define( 'WPFORMS_TURNSTILE_SITE_KEY',   '1x00000000000000000000AA' );
define( 'WPFORMS_TURNSTILE_SECRET_KEY', '1x0000000000000000000000000000000AA' );
```

Bridged keys:

- `WPFORMS_<UPPER_SNAKE>` for any key already saved in the `wpforms_settings` option
- common captcha keys are bridged even on fresh installs without saved settings: `captcha-provider`, `turnstile-site-key`, `turnstile-secret-key`, `recaptcha-type`, `recaptcha-site-key`, `recaptcha-secret-key`, `hcaptcha-site-key`, `hcaptcha-secret-key`

The Cloudflare always-pass test sitekey/secret pair above (`1x000…AA` / `1x000…AA`) is recommended for staging/CI to avoid headless detection blocking the challenge widget.

When any override is active, an admin notice on WPForms admin screens lists which setting keys are read from `wp-config.php`, so values saved through the WP admin do not silently disappear at runtime without explanation.

### Gutenberg

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$gutenberg_align_wide` | bool | `true` | Enable wide/full alignment |
| `$gutenberg_responsive_embeds` | bool | `true` | Responsive video embeds |
| `$gutenberg_editor_styles` | bool | `true` | Load editor stylesheet |
| `$gutenberg_disable_core_patterns` | bool | `true` | Remove core block patterns |

### Breadcrumbs

Breadcrumb data (`$context['breadcrumb']`) is auto-populated by `StarterBase::timber_context()` from the properties below — projects only override these to customise behaviour. A legacy compatibility guard (`class_exists('\Breadcrumb', false)`) skips auto-populate when a project still ships the pre-1.7 global `\Breadcrumb` class.

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$breadcrumb_labels` | `array<string, string>` | `['home' => 'Home', '404' => 'Page not found', 'search' => 'Search: %s', 'pagination' => 'Page %d', 'author' => 'Author: %s']` | Pre-translated labels for typed items. Defaults are English raw strings — override via `setup_breadcrumb_labels()` (not `__construct()`), see below. |
| `$breadcrumb_menu_name` | `string` | `'main-menu'` | Nav-menu location slug for the menu-trail strategy (`by_menu_trail`). Set to a different menu's location slug if breadcrumbs should follow a non-main navigation. |
| `$breadcrumb_list_page_map` | `array<string, string>` | `[]` | Post type → ACF option key for "listing page" injection between Home and a single post of that type. Example: `['post' => 'article_list']` injects `links.article_list` (from the ACF Global Options Page) as the parent crumb on every single `post`. |
| `$breadcrumb_menu_trail_post_types` | `?array` | `null` | Post types eligible for menu-trail. `null` = auto-detect via `is_post_type_hierarchical()`. Pass an explicit list to opt-in / opt-out specific CPTs regardless of hierarchy. |
| `$breadcrumb_include_pagination` | `bool` | `false` | Append a `"Page N"` item on paginated archive views. Off by default — opt in per project. |

#### Localising labels — override `setup_breadcrumb_labels()`, not `__construct()`

Calling `_x()` from `Base::__construct()` to populate `$breadcrumb_labels` triggers WordPress 6.7+'s `_load_textdomain_just_in_time` notice — the constructor runs before `init`, but the theme's textdomain has not loaded yet. `StarterBase` registers `setup_breadcrumb_labels()` on `init` (priority 1) as the project-side hook for translated labels:

```php
class Base extends \Parisek\TimberKit\StarterBase {

    public function setup_breadcrumb_labels() {
        $this->breadcrumb_labels = array(
            'home'       => _x( 'Home', $this->theme_name, $this->theme_name ),
            '404'        => _x( 'Page not found', $this->theme_name, $this->theme_name ),
            'search'     => _x( 'Search: %s', $this->theme_name, $this->theme_name ),
            'pagination' => _x( 'Page %d', $this->theme_name, $this->theme_name ),
            'author'     => _x( 'Author: %s', $this->theme_name, $this->theme_name ),
        );
    }
}
```

`$this->theme_name` in both `_x()` slots is intentional — it doubles as the translation context and the textdomain, so a single project identifier scopes everything. Substitute the source strings with the project's locale (Czech, German, …) and the WPML / Polylang stack picks the right translation at render time.

Projects that don't need translated labels (single-locale English sites) can skip the override entirely — the English defaults declared on `$breadcrumb_labels` apply unchanged.

### Performance

Replaces the standalone [Speculation Rules](https://wordpress.org/plugins/speculation-rules/) plugin. After upgrading, downstream projects can `wp plugin deactivate speculation-rules && wp plugin delete speculation-rules` — the same prerender / moderate / logged-out behaviour ships from the theme.

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$speculation_rules` | `?array` | `['mode' => 'prerender', 'eagerness' => 'moderate', 'authentication' => 'logged_out']` | Hooks `configure_speculation_rules()` onto the WP 6.8+ `wp_speculation_rules_configuration` filter. Defaults mirror the standalone plugin's defaults — faster than WP core's `prefetch` / `conservative`, with rules emitted only for logged-out visitors so editors browsing the frontend from `wp-admin` don't trigger `prerender`-driven double-fires of GA / GTM / Productive page-views. Override individual keys per project (e.g. drop to `prefetch` if Consent Mode v2 is configured for imperative tracking), or set the whole property to `null` to fall back to WP core defaults (no override, no auth gate). |
| `$warn_speculation_rules_plugin_redundant` | bool | `true` | Registers a Site Health test (`Tools > Site Health` → `timber_kit_speculation_rules_redundant`). Returns `status: 'good'` when the standalone plugin is inactive; returns `status: 'recommended'` with a "Manage plugin" link when both code paths are running and would duplicate the `wp_speculation_rules_configuration` filter. Passive signal only — no admin-notice banner, no auto-deactivation. |

The companion `wp_speculation_rules_href_exclude_paths` filter is intentionally **not** wrapped — WP 6.8+ core already excludes `/wp-login.php`, `/wp-admin/*`, query-string action URLs, etc., and the standalone plugin only re-emitted a legacy `plsr_…` filter for backwards compatibility. Downstream projects can still hook the WordPress core filter directly when a project-specific URL needs to be excluded.

```php
// Override mode/eagerness in your Base.php (extends StarterBase)
class Base extends \Parisek\TimberKit\StarterBase {
    protected ?array $speculation_rules = [
        'mode'           => 'prefetch',     // safer when Consent Mode v2 fires on pageview
        'eagerness'      => 'moderate',
        'authentication' => 'logged_out',
    ];
}
```

## Block renderer migration guide

If you're upgrading from a theme that carried `timber_block_render_callback()` inline in `functions.php`:

1. Bump the Composer constraint to `^1.5`:

    ```json
    {
        "require": {
            "parisek/timber-kit": "^1.5"
        }
    }
    ```

2. Replace the inline `timber_block_render_callback()` body with a wrapper:

    ```php
    function timber_block_render_callback( ...$args ): void {
        \Parisek\TimberKit\BlockRenderer::render( ...$args );
    }
    ```

    `block.json` files referencing the old function name keep working.

3. Remove the freestanding `add_action( 'acf/save_post', … 'acf_block_…' flush )` hook from `functions.php` — the package now owns it: `StarterBase::__construct()` wires `BlockRenderer::flushPostBlockCache()` to `acf/save_post` at priority 20.

4. (Optional) If you want to keep your existing Tailwind alert template for the empty-block warning, register an override:

    ```php
    add_filter(
        'timber_kit/block_renderer/empty_alert_html',
        static function (string $default, string $block_name, array $attributes): string {
            $block_label = $attributes['title'] ?? $attributes['name'];
            return Timber::compile('@component/alert/alert.twig', [
                'content' => [
                    'message'   => '<strong>' . esc_html($block_label) . ':</strong> ' .
                                   esc_html(__('Pro zobrazení vyplňte požadované údaje v pravém panelu.', 'starter_theme')),
                    'type'      => 'warning',
                    'container' => 'container',
                ],
            ]);
        },
        10,
        3
    );
    ```

    Without this filter the package renders its own Twig template (`@timber-kit/empty-alert.twig`) using Gutenberg's native `.block-editor-warning` classes — no theme styling required.

## Testing

```bash
ddev start
ddev exec "composer test"           # Unit suite (Brain\Monkey, fast — default)
ddev exec "composer test:property"  # Eris property suite (invariant-based)
ddev exec "composer test:all"       # both suites
ddev exec "composer phpstan"
```

The property suite (`tests/Property/`, powered by `giorgiosironi/eris`) targets pure functions only and runs under its own `phpunit.property.xml` config to stay isolated from Brain\Monkey's Patchwork hooks. CI pins `ERIS_SEED` to the Actions run ID — reproduce a failing build locally with `ERIS_SEED=<run-id> composer test:property`.

## Releasing

Releases are automated through two GitHub Actions workflows:

- `.github/workflows/release-stamp.yml` — manual trigger (Actions tab → **Stamp Release** → **Run workflow** → enter the new semver, e.g. `1.5.0`). The workflow validates the version, requires non-empty `[Unreleased]` content in `CHANGELOG.md`, runs the full PHPUnit + PHPStan suite as a guard, then stamps `[Unreleased]` to `[X.Y.Z] - DATE` (UTC) — leaving a fresh empty `[Unreleased]` block for the next cycle — commits, tags `vX.Y.Z`, and pushes both.
- `.github/workflows/release.yml` — fires automatically on the `vX.Y.Z` tag push. Extracts the matching CHANGELOG section, derives the merged-PR list from squash-merge commit subjects between this tag and the previous tag, and creates the GitHub Release with structured notes (`What's Changed` / `Pull Requests` / `Full Changelog` comparison link). Marks the release as **Latest** only when the new tag is the highest semver, so back-dated patch tags don't steal the badge.

### Per-PR conventions

Add entries under `## [Unreleased]` in `CHANGELOG.md` with [Keep a Changelog](https://keepachangelog.com/) categories (`### Added`, `### Changed`, `### Deprecated`, `### Removed`, `### Fixed`, `### Security`). Squash-merge PRs into `main` so the merge commit subject ends with `(#N)` — the auto-release workflow uses that to assemble the Pull Requests section.

### Distribution scope

`.gitattributes` marks `CHANGELOG.md`, `tests/`, `.github/`, `.ddev/`, `phpunit.xml`, `phpstan.neon`, and other dev-only files as `export-ignore`, so `composer require parisek/timber-kit` only pulls `src/`, `composer.json`, `LICENSE`, and `README.md` into the consumer's `vendor/` tree. No Composer-side `archive.exclude` config is needed — `.gitattributes` covers both `composer archive` and GitHub source-zip downloads.

## License

[GPL-3.0-or-later](LICENSE)
