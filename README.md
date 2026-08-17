# timber-kit

[![Packagist Version](https://img.shields.io/packagist/v/parisek/timber-kit.svg)](https://packagist.org/packages/parisek/timber-kit)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/timber-kit.svg)](https://packagist.org/packages/parisek/timber-kit)
[![Timber](https://img.shields.io/badge/Timber-2.x-blue.svg)](https://timber.github.io/docs/v2/)
[![Tests](https://github.com/parisek/timber-kit/actions/workflows/tests.yml/badge.svg)](https://github.com/parisek/timber-kit/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/parisek/timber-kit.svg)](LICENSE)

WordPress/Timber starter kit — configurable base class, ACF helpers, image resizer, dev media proxy, WPForms config bridge, ACF block renderer, WPML Copy-field override.

## Installation

```bash
composer require parisek/timber-kit
```

## What's Included

### StarterBase

Extends `Timber\Site` with dozens of configurable properties. Handles theme setup, Twig extensions, security hardening, Gutenberg blocks, media processing, and admin cleanup — all opt-in via boolean flags.

### Helpers

Static methods for formatting ACF data into clean arrays for Twig templates:

- `formatImage()`, `formatFile()`, `formatVideo()` — media formatting. `formatVideo()`
  derives the `codecs=` part of the `type` attribute through `VideoCodecs::codecsString()`,
  which sniffs the file rather than trusting the container extension — a browser
  skips a `<source>` whose codec string is wrong, so guessing it means a video
  that silently never plays.
- `formatMenu()` returns a `MenuData`, which behaves as its item list under every
  array-shaped operation — iteration, `count()`, index access, JSON encoding —
  while exposing the menu's own metadata as properties. That equivalence is what
  let the return value gain metadata without touching a single call site.
- `formatFields()`, `fieldFormatter()` — ACF field processing
- `formatLink()` — link/button formatting
- `remapWpmlReference( $value, array $field, string $target_lang )` — remaps an ACF reference field's id(s) to a target WPML language via `wpml_object_id`, with the element type resolved per ACF field type (`image`/`file`/`gallery` → attachment, `post_object`/`relationship`/`page_link` → post, `taxonomy` → term; non-reference and non-numeric values pass through). Shared formatting-layer primitive that `WpmlBlockOverride` delegates to, reusable by any field formatter
- `formatMenu( $menu_or_name )` — navigation menus. Returns a `MenuData` object exposing the menu's own metadata (`title`, `name`, `slug`, `description`, `id`) alongside `items`, **plus any ACF fields attached to the `nav_menu` term** — so a project can give a menu an icon, a colour or a visibility flag without a kit release. The object iterates, counts, indexes and JSON-encodes exactly as the plain item list it replaced, so existing `{% for item in menu %}` call sites need no change. An empty or missing menu returns a plain `[]`, keeping `{% if menu %}` guards falsy. Note: `id`, `title`, `name`, `slug`, `description` and `items` are reserved metadata keys — an ACF field on the `nav_menu` term with one of those names is silently dropped in favour of the built-in value
- `formatTerms()` — taxonomy terms
- `formatLanguageSwitcher()` — WPML language switcher
- `resizeImage()` — responsive image variants
- `pagination()` — pagination formatting
- `readTime()` — estimated reading time in minutes (Unicode-aware word counting, image budget, WPML-aware per-language WPM)
- `getLanguage()` — normalized (lowercased, trimmed) language code for a post or the current request, with WPML per-post / site-wide / `get_locale()` fallbacks, in that order. Region/script subtags are preserved verbatim at every layer — WPML values (e.g. `pt-br`, `zh-hans`) and the `get_locale()` fallback alike (e.g. `de_ch`, not truncated to `de`) — so consumers that need the full dialect (per-language typography tables, hreflang) get it, and ones that only want the base language take the first two characters themselves
- `formatImageFrom( ?array $raw ): ?array` — pure-core formatter extracted from `formatImage()`'s associative-array branch. No WordPress dependencies, safe for unit / property tests; missing keys resolve to `null` silently, `id` / `width` / `height` are cast to `int|null`, and the WordPress SVG-1px workaround is applied uniformly
- `formatAnnouncement( ?array $value )` — announcement-bar ACF group (`enabled`, `text`, `dates.date_from`/`date_to` as date_picker "U" timestamps) → Twig/Alpine shape. Re-anchors midnight-UTC timestamps to `wp_timezone()` day bounds (00:00:00 / 23:59:59) and returns millisecond timestamps; disabled or absent input yields `['text' => '', 'date_from' => 0, 'date_to' => 0]`
- `relabelPostType( string $post_type, array $labels )` — merge custom labels onto a registered post type (rename the built-in `post` to Články/News/…). Applies immediately after `init`, otherwise defers to `init` priority 999; keeps the top-level `label` in sync with `labels.name`
- `hideTaxonomyMetaFields( string $taxonomy = 'category', array $fields = ['description', 'slug', 'parent'], bool $hide_columns = true )` — hide taxonomy form fields (CSS on add/edit screens) and drop the matching list-table columns, for taxonomies where editors should only pick a name

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

Each returned entry's `width` and `height` describe the file that was written, not the values that were requested — they differ whenever the variant scales rather than crops, so `['1200', '', …]` reports the derived height instead of `0`. They are derived from the source dimensions, not measured, so nothing extra is read. A **requested** axis is exact; a **derived** one is an estimate, because Spatie's GD and Imagick drivers implement the step differently — a single step stays within a pixel, and the two-axis non-cropping path scales that disagreement. A source of unknown size yields `0` rather than a guess. `Resizer::producedDimensions()` exposes the same derivation.

#### Named-variant mode (associative)

A variant may name its values instead of ordering them. Both shapes work, and mix in one call:

```twig
{{ component_picture({
    image: item.image|resizer(
        ['960', '720', '1280', 'crop'],
        { width: 480, height: 360, crop: 'top', quality: 82 },
    ),
}) }}
```

Recognised keys: `width`, `height`, `media`, `image_style` (or `crop`), `quality`, `format`. Anything omitted falls back to the same defaults the positional form uses.

`format` is reachable only this way — deliberately not a sixth positional slot. Use it when a consumer needs a specific encoding rather than the project default:

```php
// og:image — scrapers read JPEG, PNG, GIF and WebP, but not the AVIF written by default.
$variants = ( new Resizer() )->resizer( $image, [
    [ 'width' => 1200, 'height' => 630, 'crop' => 'center', 'quality' => 95, 'format' => 'jpeg' ],
] );
```

An unrecognised format falls back to the request-wide one rather than throwing, so one bad variant cannot take down a page render.

### SocialImage

The one image variant a link-preview scraper can use. `Resizer` writes AVIF by default and Facebook, LinkedIn and X do not read it, so an `og:image` cut through the resizer alone is a preview card with no image — and nothing looks broken until someone shares a link.

```php
use Parisek\TimberKit\SocialImage;

$preview = SocialImage::get( $image );              // 1200x630 JPEG, quality 85
$preview = SocialImage::get( $image, [ 'quality' => 95 ] );

if ( $preview ) {
    $url = $preview['src'];
}
```

Returns `null` rather than something unusable: a caller falling back to its own default still has a working card, while a wrong or oversized image only looks like an answer. A variant is accepted only when it is the requested cut in a format scrapers read, which is also what rejects the untouched original (`resizer()` returns the source alone when it cannot process it).

Options: `width`, `height`, `crop`, `quality`, `format` — unknown keys are dropped, a format no scraper reads falls back to JPEG, and `crop` is restricted to the styles that cut to exact dimensions (`center`, `crop`, `top`, `bottom`, `left`, `right`). `smart-crop` is not among them: it degrades to a plain resize when the source is smaller than the target, while the resizer still reports the dimensions that were asked for, so the entry would claim a cut it did not make. `SocialImage::spec()` returns what would be cut, without touching an image.

#### Resolving a post's image

`SocialImage::forPost( $post )` finds the image itself, from a map of post type to field:

```php
// In the theme's Base class.
protected array $social_image_fields = array(
    'project' => 'hero_image',
    'post'    => array( 'lead_image', 'hero_image' ),  // first usable cut wins
);
```

A post type left out of the map falls back to its featured image. Every candidate is tried until one yields a usable cut, not until one merely looks like an image — resolving and cutting are separate steps, and an SVG or an undecodable format passes the first while failing the second. Fields are read through `Helpers::formatFields()`; `timber_kit_social_image_post_fields` overrides that reader for projects storing this outside ACF.

#### Wiring it to an SEO plugin

```php
protected string|bool $social_image_bridge = true;      // detect the active plugin
protected string|bool $social_image_bridge = 'aioseo';  // or name it
```

The plugin keeps rendering its own tags; the bridge only supplies the image, and only when there is one — otherwise the plugin's own resolution stands, because a working card from the site default beats a wrong one.

Two separate claims worth keeping apart. **Leaving the bridge off changes nothing on upgrade**, since no hook is registered. **Turning it on changes the tag** — that is the point — and with an empty map it hands over the featured image, replacing whatever the plugin resolved. Fill the map for the post types that keep their hero elsewhere. `true` detects the SEO plugin active on the site — one per site is the norm, so naming it is configuration the package can derive. `SocialImageBridge::supported()` lists the keys if you would rather be explicit.

**A post whose social image the editor chose by hand is left alone.** AIOSEO's filter is named for the *default* image but fires at the end of resolution, so it also sees an explicit per-post choice; overwriting that would be the plugin equivalent of ignoring the editor, and silent, since the panel still shows their pick.

Both `og:image` and `twitter:image` are covered. Twitter resolves on a separate path with no filter of its own, so without that second hook the feature only half works and the rest has to be clicked together in the admin. With AIOSEO's "Use Data from Facebook Tab" enabled the Twitter tag already carries the Open Graph result, so the bridge leaves it alone rather than deciding twice.

Why it is needed for AIOSEO specifically: it resolves the OG image from one global source option plus a per-post override, with no per-post-type layer in between, so without this every post of a type shares one image.

Filters:

- `timber_kit_social_image_defaults` — project-wide width/height/crop/quality/format
- `timber_kit_social_image_formats` — the formats considered scraper-readable, for the day a platform adds AVIF

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

### GtmContainer

First-party Google Tag Manager loader for projects that need GTM to load and nothing else — no data layer, no ecommerce payload, no plugin-side event tracking. Containers are declared in the theme's `Base` (see [Google Tag Manager](#google-tag-manager) under Configuration), keyed by language, and printed by the `gtm_container()` Twig function.

Off until configured: with no `$gtm_containers`, `gtm_container()` delegates to the GTM4WP plugin exactly as before, so a project can adopt the call site before it adopts the configuration. Sites that need GTM4WP's ecommerce data layer keep the plugin and leave the property empty.

See [ADR 0005](docs/adr/0005-first-party-gtm-container.md) for the rationale.

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

### Site Health board

Opt-in check-list of Porta recommended settings surfaced in Tools → Site Health
(`$site_health` flag, default `false`). Read-only by design: the board verifies
the real, effective state of each recommendation — it never writes anything,
has no options page, and its "Actions" hints point to code fixes. The expected
state lives versioned in code; Site Health only reports drift.

Each check declares its verification method: `effect` (probe the real outcome,
plugin-agnostic — survives plugin swaps), `config` (read stored config when
there is no observable effect), or `both`. Seed set (security): XML-RPC
disabled, WP version hidden, author sitemap disabled, file editing disabled,
REST users endpoint restricted (anonymous loopback probe).

Customize in the project `Base` class — conscious exceptions stay visible in
code review:

```php
protected bool $site_health = true;

protected function health_checks( array $checks ): array {
    unset( $checks['rest_users_restricted'] ); // host blocks loopbacks — verified at the edge instead
    $checks['my_check'] = new MyCheck();       // implements Parisek\TimberKit\Health\HealthCheck
    return $checks;
}
```

The `timber_kit_health_checks` filter runs after the override for mu-plugin /
per-environment tweaks. Custom checks implement `Health\HealthCheck` (`id`,
`label`, `category`, `method`, `run(): Result`) and return
`Result::good()` / `Result::recommended()` / `Result::critical()`.

#### utf8mb4 charset audit + conversion

The `utf8mb4_tables` check (category `database`) audits every prefix-scoped
table via `information_schema`: non-utf8mb4 tables (plugin tables keep their
install-time charset forever), column-collation overrides, and mixed utf8mb4
collations — the classic source of *Illegal mix of collations* errors and
silent `?` degradation of 4-byte characters (emoji, some CJK).

Remediation is a separate, explicit WP-CLI command that **never converts
implicitly** — `--apply` requires selecting concrete tables:

```bash
wp timber-kit convert-utf8mb4                                  # dry-run plan
wp timber-kit convert-utf8mb4 --apply --tables=wp_foo,wp_bar   # convert exactly these
wp timber-kit convert-utf8mb4 --apply --all                    # conscious full convert
```

The target collation is the **dominant utf8mb4 collation already present in
the database** (majority vote, tie-break toward core tables; `--collate=`
overrides). Tables with COMPACT/REDUNDANT row formats and long indexed
columns are flagged (767-byte index-prefix limit) and require `--force`.

### WpmlBlockOverride

Runtime override of Copy field values in ACF Gutenberg blocks for WPML-multilingual sites. Hooks `render_block_data` at priority 20 (after WPML's own handlers) and, for ACF blocks rendered in a non-default language, overwrites `attrs.data.<field>` for fields marked `wpml_cf_preferences = 1` (Copy) with the source-language post's value. Attachment IDs (image / file / gallery) are remapped to per-language duplicates via `wpml_object_id`.

Solves the long-standing WPML problem where changing a Copy field (typically an image) in the source language never propagates to translated `post_content` without a manual ATE re-job. ACF configuration becomes the single source of truth for Copy fields — no DB writes, no admin UI, no drift.

Enable it with the `$wpml_block_override` flag on your `Base extends StarterBase` — opt-in (default off) because it changes rendered output. Set it before `parent::__construct()`:

```php
class Base extends StarterBase {
    public function __construct() {
        $this->wpml_block_override = true;
        parent::__construct();
    }
}
```

StarterBase then hooks `WpmlBlockOverride::register()` on `init` when the flag is on. `register()` self-guards on WPML + ACF Pro, so it no-ops where they're absent. If you don't extend `StarterBase`, call it yourself:

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
- Remaps reference ids to their target-language equivalents via the shared `Helpers::remapWpmlReference()` primitive (so this and the field formatters resolve translated entities the same way), so a translated page points at translated entities — not the source-language ones:

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

**`should_override` and duplicate blocks.** The veto runs *before* positional pairing, so it must be deterministic per block **name**, not per **instance**. If a page has 2+ blocks of the same name and you veto only some instances, the surviving ones' ordinals shift and pair with the wrong source block (silently applying a sibling's Copy value). Decide per block *type*, as the examples below do — never per individual occurrence.

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

#### Known limitations

**Stale cache on programmatic field registration.** Cache invalidation hooks (`acf/update_field_group` + `save_post_acf-field-group`) do **not** fire for programmatic field registration via `acf_add_local_field_group()`. Code-only changes to `wpml_cf_preferences` will serve stale cache for up to 24 hours on production. Under `WP_DEBUG` the persistent transient is bypassed entirely so dev iteration is unaffected. Production workaround: `wp transient delete timber_kit_wpml_copy_fields_index` in the deploy script, or include a theme-version constant in the cache key.

**Reordered duplicate blocks / rows.** Both same-named blocks and a repeater's rows within a matched block are paired by position, relying on source and translation sharing the same order and count. Add/remove is guarded at **both** levels — if the counts of a block name differ, that name is skipped; if a repeater's row count differs between source and translation, that nested field is skipped (no-op). The one unguarded case is an *equal-count manual swap*: a translation edited independently (not through ATE, which rebuilds from the source and preserves order) where two same-named blocks — or two rows of the same repeater — are reordered without changing the count. Positional matching would then apply one instance's Copy value to the other. There is no stable per-instance id in `post_content` to detect this, and the blast radius is bounded — a Copy value from a sibling of the *same type*, read-time only (no DB writes). If you reorder duplicate blocks or rows in a translation independently, re-run it through the WPML translation editor to restore source order.

### ACFML preference sync

WPML packs custom fields into translation jobs by **exact meta-key lookup**
against one global dictionary (`custom_fields_translation` in
`icl_sitepress_settings`). ACFML fills that dictionary only event-driven — on
admin field-group save (never fires for JSON-only groups) or on value save
through the ACF pipeline (the only producer of indexed keys like
`blocks_0_items_1_title`). ACF meta written **programmatically** (importers,
WPML post duplication, direct `update_post_meta()`) therefore never gets
dictionary entries and is silently excluded from translation jobs, even when
every field declares a correct `wpml_cf_preferences` in its JSON definition.

`wp timber-kit acfml-sync-preferences` reconciles the dictionary with the
code-defined truth: it walks existing postmeta, resolves each key's field
definition via the `_<key>` field-key companion, and registers the **exact**
key with the definition's preference — the same result a manual admin re-save
of every post would produce. Intended as a deploy step after
`wp timber-kit updates`.

```bash
wp timber-kit acfml-sync-preferences                          # dry-run report
wp timber-kit acfml-sync-preferences --apply                  # write the entries
wp timber-kit acfml-sync-preferences --apply --post_type=room_type
```

Dry-run by default; idempotent (a second run writes nothing); patch-only merge
(never rebuilds or prunes the dictionary, existing `_<key>` companion entries
are never overwritten). Keys resolving to **different** preferences across
posts are reported as conflicts and skipped — never guessed. Scope is postmeta
of the current site; on multisite run per-site via `wp --url=…`.

Applying newly-translatable keys triggers WPML's ProcessNewTranslatableFields
background task — affected translations get flagged as needing update, which
is the point: translators see the previously invisible backlog.

## Command-line

Every command `StarterBase` registers, in one place. A command missing from this
table is visibly missing; a command missing from prose is not, which is how two
of these went undocumented for several releases.

| Command | What it does |
|---|---|
| `wp timber-kit updates` | Runs pending block-data migrations. See § Block data migrations. |
| `wp timber-kit prune-originals` | Deletes preserved full-resolution originals of `-scaled` images. See § Media processing. |
| `wp timber-kit svg-dimensions` | Derives and stores intrinsic `width`/`height` for SVG attachments that have none. See § SVG dimensions. |
| `wp timber-kit convert-utf8mb4` | Converts legacy `utf8` tables and columns to `utf8mb4`. |
| `wp timber-kit acfml-sync-preferences` | Reconciles WPML translation preferences for programmatically written ACF meta. See § ACFML preference sync. |
| `wp timber-kit wpml-cleanup-theme-domain` | Purges WPML String Translation rows and compiled files left behind for a text domain that is no longer registered with ST. See § WPML theme-domain cleanup. |
| `wp timber-kit outage-screen` | Installs the drop-ins that serve the theme's prerendered outage screen. See § Outage screen. |

---

## Outage screen

WordPress shows a fallback screen in three states, and in none of them can the
theme render one:

| State | Entry point | Status | What is alive |
|---|---|---|---|
| A `.maintenance` file in the site root — written by `wp maintenance-mode activate`, **and by core itself during every core and plugin update** | `wp_maintenance()` in `wp-settings.php`, before plugins and theme | `503` + `Retry-After` | PHP and the filesystem |
| The database is unreachable | `wpdb::dead_db()` | `503` + `Retry-After` | PHP and the filesystem. No options, no translations |
| A fatal PHP error | `WP_Fatal_Error_Handler::display_error_template()` | `500`, no `Retry-After` | A crashed WordPress — which the drop-in still does not use |

All three `require_once` a drop-in in `wp-content/`, and none sends a status
header first. This command generates those three files:

```bash
wp timber-kit outage-screen install   # write maintenance.php + db-error.php + php-error.php
wp timber-kit outage-screen status    # installed / stale / not ours / absent, and is the screen there
wp timber-kit outage-screen remove    # take back only the files it generated
```

### Why the fatal-error state carries a different status

`503` means a planned, bounded outage, and monitoring reads it that way — some
of it suppresses alerts on a `503` that carries `Retry-After`. A crash is
neither planned nor bounded, and the one thing it must not do is look routine.
`Retry-After` is dropped for the same reason: nobody knows when a crash clears,
so the header would be a guess presented as a promise.

The three states cannot collide. `WP_Fatal_Error_Handler::handle()` returns
immediately while `wp_is_maintenance_mode()` holds, so an update always serves
`maintenance.php` and never a `500`.

### The recovery e-mail survives

`php-error.php` replaces the error **template**, not the handler. Core sends the
recovery-mode mail in `handle()` before it reaches the template, so the drop-in
cannot cost an administrator the way back in. Replacing
`wp-content/fatal-error-handler.php` would be the change that could — this is
not that change.

The generated files send their status + no-cache headers and
`readfile()` the screen the **theme** rendered ahead of time — with
`parisek/styleguide` >= 1.13's `vendor/bin/styleguide maintenance:render`, which
writes `static/templates/component/maintenance/maintenance.html`. This package
serves the file; it does not produce it.

Two properties of the generated files are load-bearing, which is why they are
generated rather than described in a wiki somewhere:

- **They depend on nothing** — no Composer autoloader, no WordPress function
  beyond the `WP_CONTENT_DIR` constant, no database. For two of the three that
  is all there is. `php-error.php` runs inside a loaded WordPress, where a WP
  function call would appear to work, and holds to the same rule anyway: an
  outage screen may not depend on the thing that just crashed.
- **They never return early.** WordPress prints nothing of its own after the
  `require_once`, so a drop-in that returns when the screen is missing serves a
  blank page. Every path prints something; without the theme's screen, one
  plain sentence.

`install` is idempotent, writes atomically (a live request can be reading the
file — reinstalling during an active outage is normal), and refuses to touch a
drop-in it did not generate.

**Multisite:** `wp-content/` is shared across the network while the theme is
per-site, and no drop-in can resolve the current site — `db-error.php` has no
database to ask. Every site therefore serves the screen of whichever site the
command ran against, and `install` says so rather than leaving it to be found
during an outage.

**The 10-minute expiry.** WordPress ignores `.maintenance` once its timestamp is
600 seconds old. That cannot be extended from a drop-in or from the
`enable_maintenance_mode` filter — `wp_is_maintenance_mode()` checks the expiry
before both, and the filter can only turn maintenance mode *off*. The only lever
is the timestamp itself, which is the deploy script's business (`wordpress-base`
writes it forward-dated).

---

## WPML theme-domain cleanup

Once a project runs with `$wpml_theme_domain_authoritative` on — the default —
WPML stops registering the theme's own strings with String Translation, and the
`.mo` files the theme ships become the single source. Rows registered before
that switch stay behind, and a stale ST row can still win at runtime.

```bash
wp timber-kit wpml-cleanup-theme-domain             # dry-run report
wp timber-kit wpml-cleanup-theme-domain --apply     # delete the rows and compiled files
```

---

## Cache warm-up (Breeze)

`BreezeWarmupSitemap` feeds Breeze's Cache Warmup preloader with every URL from
the site's XML sitemap via the `breeze_preload_urls` filter.

Breeze 2.5 re-warms the cache after a full purge, but its own URL sources are
the homepage, a few auto-detected pages, and a manual list capped at 30 entries
— so on a real site most pages are rebuilt by the first visitor to ask for them.
The class discovers the sitemap (AIOSEO's `/sitemap.xml` when active, otherwise
core's `/wp-sitemap.xml`), follows a sitemap index recursively within bounded
limits, and merges the result in.

---

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

## Site icon tags

WordPress emits four site-icon tags: `icon` at 32 and 192 px, `apple-touch-icon`,
and `msapplication-TileImage`. It asks `get_site_icon_url()` for each size, so a
theme that answers with one SVG gets that SVG in all four — including
`apple-touch-icon`, which iOS cannot read, and a Windows 8 tile nobody wants.
Themes work around it by hardcoding their own `<link rel="icon">` block in the
layout, and then both sets render.

`$site_icon_tags` replaces core's set with the files the theme actually ships:

```php
class Base extends StarterBase {
    public function __construct() {
        $this->site_icon_tags = true;

        parent::__construct();
    }
}
```

Nothing is configured. `static/images/touch/` is probed for known filenames and
a tag is written only for a file that exists, so both RealFaviconGenerator
output generations work unchanged:

| Slot | Filenames, first hit wins | Tag |
| --- | --- | --- |
| SVG icon | `favicon.svg` | `<link rel="icon" type="image/svg+xml">` |
| PNG icon | `favicon-96x96.png`, `favicon-32x32.png`, `favicon-16x16.png` | `<link rel="icon" type="image/png" sizes>` — sizes read from the filename |
| Shortcut | `favicon.ico` | `<link rel="shortcut icon">` |
| Apple touch | `apple-touch-icon.png` | `<link rel="apple-touch-icon" sizes="180x180">` |
| Manifest | `site.webmanifest`, `manifest.json` | `<link rel="manifest">`, plus `theme-color` and `apple-mobile-web-app-title` read from its `theme_color` and `short_name` |

Two deliberate omissions. `safari-pinned-tab.svg` gets no `mask-icon` tag,
because that tag needs a tint colour no file states and a guessed one renders
worse than no pinned-tab icon. `msapplication-TileImage` is dropped outright.

An uploaded Site Icon wins. When one is set in Settings → General, this filter
steps aside and WordPress uses it — the flag's off-path does the opposite, and
overrides the editor's upload silently.

A theme that shipped no favicon file wires nothing, so core keeps whatever it
would have done.

## Configuration

Override these properties in your child constructor before calling `parent::__construct()`:

### Internationalisation

timber-kit treats configurable labels and titles (e.g. `$breadcrumb_labels`, `$options_pages[*]['page_title']`) as **plain values used verbatim** — it never wraps them in `__()`. Translating them is the consuming theme's responsibility.

**Why not in the library:** these are assigned in the child `__construct()` (before `parent::__construct()`), which runs on `setup_theme` — *before* `init` and before the text domain is loaded. Calling `__()` there is too early: it returns the string untranslated, and WordPress 6.7+ raises a *"Translation loading triggered too early"* `_doing_it_wrong` notice. Wrapping a dynamic config value in `__()` at use time also defeats string extraction — `xgettext`/`makepot` can't read a variable.

**Localise at `init`, with static string literals.** Where a config surface has a dedicated setup hook, override it — e.g. `setup_breadcrumb_labels()` (hooked to `init`):

```php
public function setup_breadcrumb_labels() {
    $this->breadcrumb_labels = [
        'home' => _x( 'Home', 'breadcrumb', 'my-theme' ),
        // …
    ];
}
```

For an admin label without a dedicated setup hook (e.g. an options-page `page_title`), set the value to your already-localised string, or leave the English default.

### Theme

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$menus` | array | `[]` | Registered navigation menus |
| `$font_stylesheets` | array | `[]` | CSS files to enqueue on the frontend. Also forwarded into the Gutenberg editor canvas (both iframed and non-iframed) via `block_editor_settings_all`, so custom `@font-face` declarations render in the editor without falling back to system fonts. Relative paths are resolved under `static/` and cache-busted with `filemtime`; absolute URLs pass through |
| `$theme_script_strategy` | string | `'module'` | How `static/dist/js/script.js` is enqueued: `'module'` → `wp_enqueue_script_module()` (Vite/ESM); `'defer'` → classic deferred `wp_enqueue_script()` for a webpack IIFE bundle. Override `enqueueThemeScript()` for finer control |
| `$preload_fonts` | array | `[]` | Font files to preload |
| `$search_post_types` | array | `['post']` | Post types for search |
| `$article_post_types` | array | `['post']` | Post types treated as articles |
| `$block_category` | array | `['slug' => 'custom', 'title' => 'Custom']` | Custom block category |
| `$favicon_path` | string | `'images/touch/favicon.svg'` | Favicon path. Read only while `$site_icon_tags` is off |
| `$site_icon_tags` | bool | `false` | Emit the favicon set found in `static/images/touch/` instead of WordPress's four legacy site-icon tags. Opt-in — it changes rendered `<head>` output. See [Site icon tags](#site-icon-tags) |
| `$context_privacy_policy` | bool | `false` | Opt-in: populate the site's privacy-policy URL (`get_privacy_policy_url()`) into the Timber context under `$privacy_policy_context_key`. Off by default — the key typically drives a cookie-consent partial, which must not appear on projects that ship without one |
| `$privacy_policy_context_key` | string | `'ccnstL'` | Context key for the privacy-policy URL. The default is deliberately non-semantic so cookie-consent markup keyed off it stays invisible to ad-block heuristics |

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
| `$big_image_size_threshold` | int | `2560` | Max image dimension (px) for uploads. Drives WordPress core's native `big_image_size_threshold` filter — images whose longer edge exceeds it are downscaled by core on upload and served as a `-scaled` derivative. `0` disables scaling entirely. **This is the single canonical knob.** |
| `$max_upload_width` | ?int | `null` | **Deprecated** — use `$big_image_size_threshold`. Honoured only when non-null; the larger of width/height becomes the (square) threshold (explicit `0` disables, preserving the legacy contract). |
| `$max_upload_height` | ?int | `null` | **Deprecated** — use `$big_image_size_threshold`. |

> **Why a single dimension, not width × height?** WordPress core's `big_image_size_threshold` is one number — it caps the longer edge and fits the image inside a square box (`resize($n, $n)`), exactly as the old in-theme resize did. Mirroring it with one property keeps the kit honest and lets downscaling run through core's pipeline, which (unlike the previous `wp_handle_upload` hook) doesn't fight core's own 2560 cap and covers **every** upload path (REST, WP-CLI, programmatic), not just the media library. The filter is registered **unconditionally and is authoritative** — timber-kit owns the threshold across the fleet, overriding any other plugin's `big_image_size_threshold` filter. The deprecated width/height pair is read for backward compatibility (larger edge wins) until removed in 2.0.

#### Reclaiming disk space from preserved originals

When core downscales an upload it **keeps the full-resolution original** on disk (the `original_image` / "Restore original image" mechanism). timber-kit deliberately does **not** delete it on upload: WordPress regenerates every thumbnail sub-size from the *original* (for best quality), so deleting it on upload would silently degrade any later regeneration — a new crop size, retina variant, or `wp media regenerate` — to double-compressed output sourced from the `-scaled` file.

Instead, reclaim space with a deliberate, opt-in sweep once the redesign window (when new crop sizes are likely added) has passed:

```bash
wp timber-kit prune-originals --dry-run            # report reclaimable space, delete nothing
wp timber-kit prune-originals --older-than=30      # prune originals of uploads older than 30 days
wp timber-kit prune-originals --limit=500          # cap the batch
```

The command only prunes genuine size-driven `-scaled` downscales — it leaves originals preserved for EXIF rotation or format conversion untouched, and never strips the `original_image` pointer unless the file was actually deleted. The trade-off it makes permanent: future regeneration of those images falls back to the `-scaled` file. See `\Parisek\TimberKit\OriginalImagePruner`.

#### SVG dimensions

An SVG attachment usually reaches the browser as an `<img>` with no `width` and no `height`, so nothing reserves its box and every one of them shifts the layout as it lands.

Two layers cause it, and neither is timber-kit's. `getimagesize()` cannot parse SVG, so core's `wp_generate_attachment_metadata()` stores no dimensions for `image/svg+xml` at all. The `svg-support` plugin fills part of the gap, but its reader takes only the `width` and `height` attributes on the root element — an SVG exported with just a `viewBox`, the current Figma and Illustrator default, yields an empty string it stores as `0`. Measured on one production library: **1519 of 3520** SVGs unsized, and the share growing each year as export tooling moves to `viewBox`-only.

**Nothing to configure for templates.** `Helpers::formatImage()` resolves a missing axis from the file, so a `<picture>` gets its dimensions on any project, immediately. Nothing is written during a render.

It runs for every image, so the cost is bounded deliberately and is asserted rather than argued:

| | |
| --- | --- |
| Not an SVG, or an SVG that already has both axes | returns on the first comparison, opens nothing — **0.095 us** |
| An SVG missing an axis, first time in the request | one bounded read of the file head — **0.086 ms** |
| The same attachment again | memoised, including a refusal |

Instrumented on a real page carrying 152 SVG `<img>` elements: **399 calls, 271 of them returning immediately, 128 resolutions, ~11 ms** total per uncached render. Nothing on a cached hit, since no PHP runs.

**Run the sweep and that 11 ms goes away** — with dimensions in metadata the read path skips every image. The two are not redundant: the read path is the safety net that makes a template correct everywhere, the sweep is what makes it free.

`get_attached_file()` is the only route to the filesystem here, and the tests assert it is never called for a raster image — a measurement drifts, an assertion does not.

Stored metadata is still worth having — the media library, `wp_get_attachment_image_src()` and srcset read it and never go through `Helpers` — so there are two writers:

```php
// Base.php — new uploads
protected bool $svg_dimensions = true;
```

```bash
# existing attachments
wp timber-kit svg-dimensions --dry-run     # report what would be written
wp timber-kit svg-dimensions               # fill in what is missing
wp timber-kit svg-dimensions --force       # re-derive over a wrong stored value
```

All three share one resolver: the `width`/`height` attributes first, converted from any CSS absolute unit (`px`, `pt`, `pc`, `in`, `cm`, `mm`, `Q`) and accepting the full SVG number grammar including exponents; then the `viewBox`. A single explicit axis is combined with the `viewBox` ratio rather than discarded. Relative units (`em`, `%`) are not intrinsic sizes and fall through.

Using `viewBox` as a source of width and height is a deliberate **policy**, not a measurement — W3C defines it as a source of aspect *ratio*. For an image whose box comes from CSS, which is every call site this exists for, the ratio is the whole point. The cost is that an `<img>` with no CSS sizing renders at these numbers instead of the SVG's own default.

**Refusing is a correct answer.** Every ambiguity resolves to nothing rather than a guess, because a wrong number gets written to the database, outlives the package version, and is read back as authoritative by the next sweep. An encoding it cannot decode, a prolog it cannot walk, a root tag beyond 64 kB, an unconvertible unit, a derived `1×1` — all report the attachment as unreadable and leave it alone.

The root start tag is found by walking the prolog rather than searching for the first `<svg`: that search read a `<svg` quoted inside a processing instruction, an entity declaration or a CDATA section as if it were the root. Files are read incrementally and stop at that tag, which also sidesteps libxml's 10 MB attribute-value limit that made three real uploads fail to parse whole. Entity references are escaped to literal text, never resolved, so a hostile DOCTYPE never reaches the parser.

**It is designed to coexist, not compete.** Dimensions are written to core's own `_wp_attachment_metadata` `width`/`height` keys, so every consumer benefits. Each axis is considered separately and a valid stored value is never replaced — filling a missing height cannot disturb a width another plugin resolved. `0` and `1` count as absent (`intval( '' )`, and core's bogus SVG 1px). The upload filter hooks at priority 20 so it observes other plugins rather than racing them. `--force` is the single deliberate exception.

The sweep is a deliberate command rather than a lazy write during rendering, for the same reason `prune-originals` is: a page render must not write to the database, or the healing becomes a property of who happened to request an uncached page — and stops entirely on a read-only replica. See `\Parisek\TimberKit\SvgDimensions`.

### Dev Media Proxy

Off by default. Enable it by pointing it at an upstream origin's uploads URL, via **either** an environment variable **or** a PHP constant:

```bash
# .ddev/.env — preferred: one line, no PHP, git-tracked so it
# propagates to every git worktree automatically (DDEV >= 1.25
# surfaces it to PHP via getenv()).
TIMBERKIT_MEDIA_ORIGIN=https://example.com/wp-content/uploads
```

```php
// wp-config.php — alternative / override (the constant always wins)
define( 'TIMBERKIT_MEDIA_ORIGIN', 'https://example.com' );
```

Behavior:

- if a local uploads file exists, its local URL is kept
- if a local uploads file is missing, the URL is rewritten to the configured origin
- a domain-only origin such as `https://example.com` automatically reuses the local uploads path
- a full origin such as `https://example.com/wp-content/uploads` is used verbatim
- Resizer can use the same origin to probe already-generated remote variants when local source files are missing

Configuration source & safety:

- **Constant wins.** When both the constant and the env var are set, the constant is used — an existing `define()` keeps its exact behaviour. An explicitly-empty constant (`define( 'TIMBERKIT_MEDIA_ORIGIN', '' )`) means "disabled" and does **not** fall through to the env var.
- **Self-reference is refused.** If the origin host equals the site's own uploads host, the proxy stays off — a missing-file rewrite would just resolve back to the same missing file. Host-level check (no `www`/port/IDN normalization).
- **`http(s)` only.** Origins with any other scheme are ignored.
- **Dev-only / trusted config.** Anyone who can set the origin can point media URLs (and the remote probe) at a host they choose. Don't enable it in untrusted environments.

See [ADR 0003](docs/adr/0003-dev-media-origin-env-and-self-host-guard.md) for the design rationale.

Available hooks:

- `timber_kit_resizer_missing_source_variants` — extension point used by `DevMediaProxy` to provide remote Resizer variants
- `timber_kit_resizer_probe_remote_variants` — enable/disable remote variant probing, default `true`
- `timber_kit_resizer_remote_variant_probe_timeout` — HTTP timeout for variant probes, default `2.0`
- `timber_kit_resizer_remote_variant_probe_limit` — max remote variant probes per request, default `50`
- `timber_kit_resizer_quality_in_cache_key` — put a variant's quality in its cache key, default `false` (also settable as `StarterBase::$resizer_quality_in_cache_key`). Without it, re-cutting the same dimensions at a different quality serves the previously generated file. Opt-in because switching it on relocates every non-default-quality variant: old cache files orphan and public URLs change.
- `timber_kit_resizer_aspect_tolerance` — tolerance band around 1:1 used by `Resizer::classifyAspect()` to decide whether a source qualifies as `square`, default `0.1`. Returning a smaller value (e.g. `0.05`) tightens the square band; returning a larger value (e.g. `0.2`) loosens it.

### Google Tag Manager

The kit prints two blocks and the theme decides where they go — the loader in `<head>`, the `noscript` iframe right after `<body>`. Nothing is hooked into `wp_head` on your behalf, because the loader's position relative to consent-mode defaults is the theme's call.

#### 1. Declare the containers

Plain GTM, no server-side tagging — `domain` and `path` are both optional:

```php
class Base extends StarterBase {

	protected array $gtm_containers = array(
		'default' => 'GTM-XXXXXXX',
	);
}
```

That yields Google's standard loader, `https://www.googletagmanager.com/gtm.js?id=GTM-XXXXXXX`, plus the matching `noscript` iframe. Server-side tagging only adds `domain` and `path` to the same shape.

#### 2. Call it from the layout

`templates/layout/layout.twig`, or wherever the theme owns `<head>` and `<body>`:

```twig
<head>
	<script>
		window.dataLayer = window.dataLayer || [];
		function gtag() { window.dataLayer.push(arguments); }
		gtag('consent', 'default', { /* … */ });
	</script>

	{{ gtm_container() }}  {# after the consent defaults, never before #}

	{{ function('wp_head') }}
</head>
<body class="{{ body_class }}">
	{{ gtm_container_noscript() }}  {# first thing inside <body> #}
	…
</body>
```

Order in `<head>` is not cosmetic: GTM reads the consent state at load, so a loader placed above `gtag('consent', 'default', …)` starts before the defaults exist and tags fire with the wrong state.

What lands in the page is Google's published snippet, line for line — same line breaks, same `<!-- Google Tag Manager -->` comments, no vendor attributes and no generator marks. A person diffing the page source against Google's documentation should find exactly one difference: the id-less URL, where a custom path is configured. Whole-output tests pin that.

#### 3. Set the environment constant

```php
// wp-config.php on production
define( 'TIMBERKIT_GTM_ENABLED', true );
```

Optional — without it, production measures and nothing else does. Set it explicitly where an environment is not what `wp_get_environment_type()` reports, or where staging should measure too.

#### Migrating a project off GTM4WP

Both call sites are safe to add **before** the project has any configuration: `gtm_container()` prints nothing and `gtm_container_noscript()` delegates to the plugin, so an unmigrated project renders exactly as it does today. That is what lets one shared layout serve both.

1. Replace `{{ gtm4wp_the_gtm_tag() }}` in the layout with the two calls above. Nothing changes yet.
2. Fill in `$gtm_containers` on the project's `Base`. The kit takes over.
3. Set the plugin's **Container code placement** to *OFF*, or deactivate it if nothing on the site uses its data layer. Check Tools → Site Health — `gtm_container_not_duplicated` reports a critical while both are printing.
4. Drop the plugin from `DEACTIVATE_PLUGINS` in `.ddev/.env`; the environment gate replaces that workaround.

#### Reference

Declare the containers on your `Base`, keyed by language:

```php
class Base extends StarterBase {

	protected array $gtm_containers = array(
		'default' => array(
			'id'     => 'GTM-XXXXXXX',
			'domain' => 'windstream.example.com', // optional — server-side endpoint
			'path'   => 'aBcDeF/',                // optional — see below
		),
		'de' => array( 'id' => 'GTM-YYYYYYY' ),   // inherits domain + path
	);
}
```

A single-language project can write the ID on its own: `array( 'default' => 'GTM-XXXXXXX' )`.

Behavior:

- **No `path`** — the standard `https://www.googletagmanager.com/gtm.js?id=…` loader.
- **A `path`** — the container is addressed by that path, so the ID is left out of the URL entirely. Repeating it would hand blockers the pattern a randomly generated path exists to avoid. The query string then starts at `?l=` instead of continuing with `&l=`.
- **`default` is the fallback**; a language entry states only what differs and inherits the rest. An unknown language falls back to `default`, quietly — a missing translation must not stop measurement.
- **A language written out and left blank is switched off.** `'de' => ''` (or `null`, `false`, `array()`, or an entry with a blank `id`) means *do not measure here* and does **not** inherit from `default` — without it, turning one language off would be unsayable, since every spelling of nothing resolves back to the fallback.
- **Keys are WPML language codes** — what `apply_filters( 'wpml_current_language', null )` returns (`cs`, `de`, `pt-br`), **not** the WordPress locale (`de_DE`, which WPML keeps separately as `default_locale`). The code is a free-text field in WPML → Languages, so read the site's actual value instead of assuming. Matching ignores case and treats `_` and `-` alike, so a key spelled either way still matches — which also means `de-at` and `de_AT` are the same key, and writing both leaves the later one in effect.
- **A regional variant inherits from its base language.** WPML ships `pt-br` / `pt-pt` / `zh-hans` / `zh-hant`; anything else regional — German-Austria, English-UK — is a custom language whose code someone typed. With `de` configured and no `de-at`, an Austrian visitor reports into the German container; add a `de-at` entry when Austria needs its own. Longest match wins, so the specific entry always beats the general one.
- **A malformed value never reaches the page.** The container ID is matched against `GTM-[A-Z0-9]+`, the domain through `FILTER_VALIDATE_DOMAIN`, the path against a charset that excludes `?` and `=`. An invalid ID prints nothing; an invalid domain or path falls back to the Google default, so the container stays reachable and the fallback is visible.

Environment gate:

```php
// wp-config.php — decides when defined
define( 'TIMBERKIT_GTM_ENABLED', true );
```

Without the constant, measurement runs when `wp_get_environment_type()` is `production` and nowhere else. Deliberately not tied to `WP_DEBUG` — turning a log off must not turn measurement off with it.

Configured *and* the plugin still printing its own container is the one broken state: the page loads GTM twice and counts every visit twice, which reads as growth rather than as a fault. The **loader never inspects the plugin** — guessing at a schema this kit does not own can only go wrong in the direction that stops measurement. The state is reported instead by the `gtm_container_not_duplicated` Site Health check (needs `$site_health`), which reads GTM4WP's placement and its `GTM4WP_HARDCODED_GTM_ID` and tells you to switch the plugin off.

**The `noscript` iframe prints only where it can.** `ns.html` takes the container ID as a query parameter and has no ID-less form, so the block is free where the ID already appears in the loader URL and self-defeating where a custom path exists to keep it out. `gtm_container_noscript()` therefore prints by default for a container **without** a custom path and stays silent for one **with** it. Override either way per container:

```php
'default' => array( 'id' => 'GTM-XXXXXXX', 'path' => 'aBcDeF/', 'noscript' => true ),  // print it anyway
'default' => array( 'id' => 'GTM-XXXXXXX', 'noscript' => false ),                      // never print it
```

The iframe always addresses the host root (`https://<domain>/ns.html?id=…`), never the loader path — that path addresses the script only.

One thing the kit does not emit: GTM environments (`gtm_auth` / `gtm_preview`) apply to the Google loader only and are ignored for a server-side path, which has no notion of them.

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
| `$restrict_allowed_blocks` | bool | `true` | Restrict the editor to `$allowed_core_blocks` + ACF blocks via `allowed_block_types_all`. Set `false` on sites whose existing content pre-dates the allowlist — the filter is then not wired at all, so no no-op `allowed_block_types_all()` override is needed |
| `$render_block_passthrough_blocks` | string[] | `[]` | Block names `render_block()` returns unchanged, bypassing the core-block wrapper. Exact names (`'wpforms/form-selector'`), namespace wildcards (`'wpforms/*'`), or `'*'` to disable wrapping entirely. Escape hatch for third-party form/gallery blocks the wrapper would break |
| `$admin_resizable_sidebar` | bool | `false` | Opt-in resizable Gutenberg editor sidebar. Default **off** — the JS/CSS ship inside the package and are served from its `vendor/` dir, which the standard theme `.htaccess` denies, so enabling it also requires an `.htaccess` allow rule (see below). Set `true` to enable |

> **Enabling `$admin_resizable_sidebar` — `.htaccess` requirement.** The sidebar's JS/CSS are served from the package's `vendor/` directory (`vendor/parisek/timber-kit/assets/…`) via `packageAssetUrl()`. The standard theme `.htaccess` blanket-denies `vendor/` for security (`RewriteRule ^vendor/(.*)?$ / [F,L]`), so the browser would get **403** for those assets. When you set `$admin_resizable_sidebar = true`, also allow static assets under `vendor/` in the project's theme `.htaccess`, **before** the blanket deny:
>
> ```apache
> # Allow static assets shipped inside vendor (e.g. parisek/timber-kit admin
> # CSS/JS enqueued via packageAssetUrl()) — must precede the blanket deny.
> RewriteRule ^vendor/.+\.(css|js|mjs|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif)$ - [L]
> RewriteRule ^vendor/(.*)?$ / [F,L]
> ```
>
> PHP / source / config under `vendor/` stay forbidden. Projects scaffolded from `wordpress-base` (current `starter_theme`) already ship this allow rule.

### Options Pages

`$options_pages` declares the ACF options page(s). Each entry requires `menu_slug` + `page_title`; optional per-entry keys are `parent_slug` (sub-page), `capability` (default `edit_posts`), `icon_url` (top-level pages only, default `dashicons-admin-generic`), and `admin_bar` (bool, default off — add an admin-bar shortcut to this page; any number of entries may carry this key, including sub-pages).

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$options_pages` | array | one "Theme Settings" page | List of ACF options pages. `parent_slug` => sub-page; `admin_bar => true` => add admin-bar link for this entry; `post_id` => ACF storage namespace (see below); `[]` disables the feature entirely (no page, no admin-bar link). The default "Theme Settings" entry has `admin_bar => true` |

```php
// one top-level page with an admin-bar shortcut + two sub-pages under it
$this->options_pages = [
    [ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'admin_bar' => true ],
    [ 'menu_slug' => 'footer', 'page_title' => 'Footer', 'parent_slug' => 'settings' ],
    [ 'menu_slug' => 'social', 'page_title' => 'Social', 'parent_slug' => 'settings' ],
    [ 'menu_slug' => 'dev', 'page_title' => 'Dev Settings', 'capability' => 'manage_options' ],
];

// disable completely
$this->options_pages = [];
```

#### `post_id` — storage namespace

Omitted, ACF applies its own default of `'options'`: values land in `wp_options` as `options_<field_name>`, **keyed by field name, not by page**. That holds fine while an install has exactly one set of options pages — and stops holding the moment a second one appears, which it can without any repo change (a plugin's page, or one created through ACF's admin UI, which lives in the database as an `acf-ui-options-page` post and shows up in no grep of the theme).

Two pages then share one namespace, and where their field names overlap they read each other's values. Nothing errors: `get_field('links_login', 'option')` returns the *other* page's stored value, `get_field('links', 'option')` returns `null`, and `Helpers::formatFields()` sees nothing — an options group that resolved to nothing is indistinguishable from one nobody filled in. The colliding case is the worse of the two, because the page renders and looks correct.

```php
$this->options_pages = [
    [ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'post_id' => 'mytheme_settings' ],
    [ 'menu_slug' => 'footer', 'page_title' => 'Footer', 'parent_slug' => 'settings' ],
];
```

Values are stored as `mytheme_settings_<field_name>` and read with `Helpers::formatFields('mytheme_settings')`.

**A sub-page inherits its parent's `post_id`** unless it declares its own. ACF does not — `acf_options_page::validate_page()` applies `'post_id' => 'options'` through `wp_parse_args` to every page independently, parent or not — so without the inheritance a namespaced parent with unmarked children would split one theme's settings across two namespaces and `formatFields()` would return only half of them. Declare `post_id` on the child to opt out.

Inheritance is **transitive**: ACF's `add_sub_page()` accepts a `parent_slug` pointing at another sub-page, so a page nested two levels down takes the namespace of its nearest ancestor that declares one. A `parent_slug` referencing a page *outside* `$options_pages` (a plugin's) inherits nothing — this class cannot know what namespace that page registered with.

Adopting `post_id` also widens what `clear_cache_on_options_save()` matches: it purges on a save to any options namespace rather than the literal `'options'`, since ACF saves through `acf_save_post( $page['post_id'] )` and would otherwise stop purging for exactly the projects that namespace their storage.

Adopting this on a live site is a **data migration**: existing values stay behind under the old prefix and have to be copied to the new one. Set it from the start on new projects.

### Breadcrumbs

Breadcrumb data (`$context['breadcrumb']`) is auto-populated by `StarterBase::timber_context()` from the properties below — projects only override these to customise behaviour. A legacy compatibility guard (`class_exists('\Breadcrumb', false)`) skips auto-populate when a project still ships the pre-1.7 global `\Breadcrumb` class.

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$breadcrumb_labels` | `array<string, string>` | `['home' => 'Home', '404' => 'Page not found', 'search' => 'Search: %s', 'pagination' => 'Page %d', 'author' => 'Author: %s']` | Pre-translated labels for typed items. Defaults are English raw strings — override via `setup_breadcrumb_labels()` (not `__construct()`), see below. |
| `$breadcrumb_menu_name` | `string` | `'main-menu'` | Nav-menu location slug for the menu-trail strategy (`by_menu_trail`). Set to a different menu's location slug if breadcrumbs should follow a non-main navigation. |
| `$breadcrumb_list_page_map` | `array<string, string>` | `[]` | Post type → ACF option key for "listing page" injection between Home and a single post of that type. Example: `['post' => 'article_list']` injects `links.article_list` (from the ACF Global Options Page) as the parent crumb on every single `post`. |
| `$breadcrumb_menu_trail_post_types` | `?array` | `null` | Post types eligible for menu-trail. `null` = auto-detect via `is_post_type_hierarchical()`. Pass an explicit list to opt-in / opt-out specific CPTs regardless of hierarchy. |
| `$breadcrumb_include_pagination` | `bool` | `false` | Append a `"Page N"` item on paginated archive views. Off by default — opt in per project. |
| `$autopopulate_breadcrumb` | bool | `true` | Auto-populate `$context['breadcrumb']`. Set `false` if the theme builds breadcrumbs itself |

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
