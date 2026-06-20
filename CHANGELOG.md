# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- **`StarterBase::$options_pages` property (array)** — declarative config for the ACF options page(s), replacing the previously hard-coded single "Theme Settings" page. Each entry is one page: `menu_slug` + `page_title` are required; optional per-entry keys are `parent_slug` (registers the entry as a sub-page via `acf_add_options_sub_page`; top-level pages are registered first so list order doesn't matter, and the function is `function_exists`-guarded), `capability` (default `edit_posts`), `icon_url` (top-level pages only, default `dashicons-admin-generic`), and `admin_bar` (bool, default off — mark any page(s) — including multiple — to appear in the admin bar; the default "Theme Settings" page is marked by default). Defaults to a single "Theme Settings" page (no behavioural change). Set `$options_pages = []` to register no options pages at all — completely disables the feature.
- **`StarterBase::$admin_resizable_sidebar` flag (bool, default `true`)** — gates the resizable Gutenberg editor-sidebar enqueue (`admin/js|css/gutenberg-resizable-sidebar.*`). Set `false` on a theme that doesn't ship those assets to skip the enqueue (and its asset-version lookups on missing files).
- **`StarterBase::$autopopulate_breadcrumb` flag (bool, default `true`)** — when `false`, `timber_context()` skips auto-populating `$context['breadcrumb']` with a `Parisek\TimberKit\Breadcrumb`, for themes that build breadcrumbs themselves or don't render them. The legacy `! class_exists('\Breadcrumb')` escape hatch still applies on top of the flag.

## [1.11.0] - 2026-06-15

### Added

- **`StarterBase::$big_image_size_threshold` property (int, default `2560`)** — the single canonical max-dimension knob for upload downscaling, registered **unconditionally** on WordPress core's native `big_image_size_threshold` filter. `0` disables scaling entirely; any positive value is the longer-edge threshold core fits the image inside. Default matches WP core's own default, so projects that set nothing see no change. The filter is **authoritative** — it overrides any other plugin's `big_image_size_threshold` filter (deliberate: timber-kit owns the threshold across the fleet).
- **`Parisek\TimberKit\OriginalImagePruner` + `wp timber-kit prune-originals` WP-CLI command** — opt-in, deliberate sweep to reclaim disk space from the full-resolution originals WordPress preserves alongside `-scaled` derivatives. Supports `--dry-run`, `--older-than=<days>`, `--limit=<n>`. Crucially it is **not** an on-upload hook: WordPress regenerates thumbnail sub-sizes from the *original* (for best quality — `wp_create_image_subsizes()`), so on-upload deletion would silently degrade any later regeneration (new crop size, retina, `wp media regenerate`) to double-compressed output. The deferred sweep leaves a window for high-quality regeneration and is run per-site on purpose. The pruner guards on the `-scaled` suffix — the only deterministic signal of a *size-driven* downscale — so it never deletes originals WordPress preserved for EXIF rotation (`-rotated`) or format conversion, and never strips the `original_image` metadata pointer unless the file was actually deleted. (Research-backed: WP-core docs + Trac #47873 confirm subsizes regenerate from the original.)

### Changed

- **Image downscaling now drives WordPress core's native `big_image_size_threshold` instead of resizing on `wp_handle_upload`.** The previous in-theme resize (`resize_uploaded_image()` hooked to `wp_handle_upload`) shrank the original file in place — but ran *before* core's `wp_create_image_subsizes()`, so core's own `big_image_size_threshold` (default **2560**) then re-capped the served `-scaled` derivative. The net effect: a downstream `Base` setting `max_upload_width/height = 4000` saw uploads silently capped at 2560 on the front end, and any upload path that bypasses `wp_handle_upload` (REST, WP-CLI, programmatic `media_handle_sideload`) was never downscaled at all. `StarterBase` now registers `big_image_size_threshold()` on the core filter of the same name, so core performs the single, authoritative downscale across **every** upload path. Found in `pm-a` (client reported `4000` not honoured in production).

### Deprecated

- **`StarterBase::$max_upload_width` / `$max_upload_height`** — superseded by the single `$big_image_size_threshold`, mirroring the fact that WP core's threshold is one number (a square box), not an independent width × height. Both retyped to `?int` (default `null` = unset); when either is non-null it is still honoured for backward compatibility (the larger of the two becomes the threshold; explicit `0` disables, preserving the legacy "both 0 = off" contract), so existing downstream `Base` classes keep working unchanged. Scheduled for removal in 2.0.
- **`StarterBase::resize_uploaded_image()`** — no longer hooked (core handles downscaling now). Kept and made null-safe for any code that called it directly; will be removed in 2.0. Deprecation is docblock-only — no runtime `trigger_error`, which in a WP request would spam logs and corrupt AJAX/REST responses.

## [1.10.0] - 2026-06-10

### Added

- **`StarterBase::$acf_datastore` flag** (default `false`) — opt-in switch for the **ACF Datastore** (`acf/settings/enable_datastore`, ACF Pro 6.8.1+ / WP 6.7+). When enabled in a downstream `Base`, `registerAcfHooks()` adds `add_filter( 'acf/settings/enable_datastore', '__return_true' )`, routing ACF field saves through the REST / Gutenberg `wp.data` flow instead of the legacy metabox AJAX request. This lets ACF values participate in **post revisions** and **autosave**. Value storage is unchanged (still postmeta), ACF Local JSON is untouched, and the Timber read path (`Helpers::formatFields()` → `get_field()`) is identical. The REST save still calls `acf_save_post()` and fires the same `acf/save_post` action, so `BlockRenderer::flushPostBlockCache` (block-cache invalidation) and `acfml`'s WPML sync keep firing unchanged. Site-wide boolean by design — ACF evaluates `acf_is_using_datastore()` in no-post contexts (`rest_api_init`) where `get_post_type()` is `false`, so a per-post-type gate would silently disable the save hooks everywhere. Left **off** in the library because the feature is still in ACF's feedback phase and is not yet WPML-certified against the datastore; projects opt in deliberately (pilot on staging). Fully reversible via `__return_false`. Evaluation, source-verified backport-risk analysis, and the staging checklist live in [portadesign/wordpress-base#38](https://github.com/portadesign/wordpress-base/issues/38).

## [1.9.0] - 2026-06-10

### Added

- **`Parisek\TimberKit\WpmlBlockOverride` class** — runtime override of Copy field values in ACF Gutenberg blocks for WPML-multilingual sites. Hooks `render_block_data` at priority 20 and, for ACF blocks rendered in a non-default language, overwrites `attrs.data.<field>` for fields marked `wpml_cf_preferences = 1` (Copy) with the source-language post's value. Attachment IDs (image / file / gallery) are remapped to per-language duplicates via `wpml_object_id`. Supports nested Copy fields inside repeater / group containers at arbitrary depth via recursive `walkFields()` + path-aware key generation (e.g. `steps_N_image`, `faq_sections_N_items_M_title`). Cached as a single block-name → copy-fields index transient with per-request memo; persistent layer bypassed under `WP_DEBUG`. Filters exposed: `timber_kit/wpml_block_override/should_override`, `timber_kit/wpml_block_override/copy_fields`. Enabled via the opt-in `StarterBase::$wpml_block_override` flag (default `false` — it changes rendered output, so projects opt in), which hooks `register()` on `init`; consumers that don't extend `StarterBase` can call `WpmlBlockOverride::register()` directly. Solves the long-standing WPML pain point where changing a Copy field (typically an image) in the source language never propagates to translated `post_content` without manual ATE re-job. Reference implementation in [portadesign/atelier99#14](https://github.com/portadesign/atelier99/pull/14); research, prior art, and design discussion in [#29](https://github.com/parisek/timber-kit/issues/29).
- **`Parisek\TimberKit\Helpers::remapWpmlReference()`** — shared formatting-layer primitive that remaps an ACF reference field's id(s) to a target WPML language via `wpml_object_id`, with the element type resolved per field type (image / file / gallery → `attachment`; post_object / relationship / page_link → the referenced post's own type; taxonomy → the field's taxonomy). `WpmlBlockOverride` delegates Copy-field reference remapping here instead of carrying its own private copy, so the block-sync path and any field formatter resolve translated entities the same way. Non-reference and non-numeric values pass through unchanged.

## [1.8.1] - 2026-06-10

### Added

- **`Resizer` input allow-list is now capability-gated against the active image backend, with a public availability API and Site Health reporting.** Instead of a hardcoded five-format list, the resizer builds its allowed-input set at runtime by intersecting a desired superset (`jpeg`/`png`/`gif`/`webp`/`bmp`/`avif`/`tiff`/`heic`/`heif`) with what the active backend can actually decode — mirroring Spatie/Image's own driver pick (Imagick when loaded, else GD). On an Imagick + libheif/libavif server all formats are processed; on a GD-only server the modern formats are excluded automatically rather than failing or silently shipping the full-size original.
  - **Public API on `Resizer`:** `supportedInputFormats(): array` returns the full `[mime => bool]` capability matrix; `canDecode(string $mime): bool` checks a single format. Memoized per request. The gated list is filterable via `timber_kit_resizer_allowed_types` (`array $mimes, array $backend_formats`) to force a format on/off regardless of the probe.
  - **Site Health (`StarterBase`, flag `$resizer_format_health`, default `true`):** a Status test (`good` / `recommended`) flags any image format WordPress accepts as an upload that the backend can't decode — those uploads silently fall back to the full-size original, so the test names them and points at the missing Imagick delegate. An Info-tab section lists the full decodable-format matrix for support.

### Fixed

- **`Resizer` no longer ships `avif` / `tiff` / `heic` / `heif` sources at full size.** The previous five-format allow-list (`jpeg`/`png`/`gif`/`webp`/`bmp`) let any other type fall through `prepareDefaultImage()` and return the **original** image untouched — no crop, no downscale, no `<source>` variants. AVIF was excluded *deliberately* ("it's already the target format"), but that ignored that the resizer also **crops and downscales** — so an already-`avif` upload still needs processing. Discovered in `proficiohub`: partner logos uploaded as 1800×1050 / 2560×1707 AVIF rendered as full-size originals into a ~258 px orbit sphere (`<picture>` emitted a bare `<img src=…/uploads/…avif>` with zero `<source>` children; `cache/image/900x530-crop/…avif` 404'd). The capability gate above is the fix: decode-able modern formats (`avif` since WP 6.5, `heic`/`heif` since 6.7) are now processed. SVG stays excluded (vector — not raster-resizable); `heic-sequence` / `heif-sequence` and `ico` are out of scope. Re-encoding an AVIF source to a smaller AVIF variant costs one-time CPU per cached variant, far outweighed by serving a correctly cropped, display-sized image. The two tests that asserted `avif` / `tiff` were *not* allowed are reframed against the capability gate; `heic` / `heif` and Site Health coverage added.

## [1.8.0] - 2026-06-09

### Added

- **Typography-aware translation Twig helpers `_xt` / `__t` / `_nt` / `_nxt`.** Same signatures as WordPress's `_x` / `__` / `_n` / `_nx`, but the translated string is piped through the env's `|typography` filter — so long-form copy gets consistent typographic treatment without `|typography` on every callsite (`_x` → `_xt` is a one-character opt-in). Registered in `StarterBase::timber_twig()` with `is_safe: ['html']`; the typography filter is resolved at call time (falls back to the raw translation if absent). This is the production (Timber) side of [parisek/styleguide#21](https://github.com/parisek/styleguide/issues/21) — the authoring surface (`_xt('…', 'ctx')`) is now identical in the styleguide preview and on the live site. See [#42](https://github.com/parisek/timber-kit/issues/42).

### Fixed

- **`merge_resizer()` no longer lets a desktop empty-`media` variant shadow the mobile image.** The non-last-list filter used `isset( $image['media'] )`, but `Resizer::processVariant()` always emits a `media` key — set to `''` for tuples without a `maxWidth`. `isset('')` is `true`, so a desktop fallback tuple (`['600','800','']`) survived the filter and rendered as a media-less `<source>` that matches every viewport — shadowing the last (mobile) list's image, which then never rendered on phones. Switched to `! empty( $image['media'] )` so empty-string-media variants are dropped from non-last lists, matching the documented contract ("non-last lists contribute only their media-qualified variants"). The single-list / last-list path is unchanged, so the desktop-only fallback still works. Regression test added for the `'media' => ''` production shape (the prior tests only covered the missing-key fallback shape, which `isset` happened to handle).

- **Closed the author-sitemap username-enumeration vector + added an opt-in security-headers emitter** ([#40](https://github.com/parisek/timber-kit/issues/40)). Two new `StarterBase` flags, consistent with the existing hardening set:
  - **`$disable_author_sitemap`** (default `true`) — removes the core `/wp-sitemap-users-1.xml` provider, which leaks author slugs/usernames regardless of `?author=` or REST blocking. The third enumeration vector alongside `restrict_rest_users` (REST) and `block_author_enumeration` (`?author=N`). **Upgrade note:** set it `false` on sites that intentionally expose author archives for SEO.
  - **`$security_headers`** (default `false`) + **`$security_headers_config`** — emits a hardened baseline header set (`X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: upgrade-insecure-requests`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`, `X-XSS-Protection: 0`) on the `wp_headers` filter. HSTS is added **only over real TLS** — gated on `is_ssl()` OR an `X-Forwarded-Proto: https` hint, so it still fires behind a TLS-terminating proxy (Cloudways Nginx+Apache, Cloudflare, …) where the canonical `.htaccess` `env=HTTPS` gate silently fails. `$security_headers_config` overrides, extends, or (with a `null` value) drops individual headers — a git-versioned, host-independent alternative to `.htaccess` headers.

  Rate-limiting, payload filtering, and file-integrity monitoring stay out of scope — those remain the WAF's job.

## [1.7.7] - 2026-06-01

### Security

- Audited and patched the resolved dependency tree via a new `composer audit` CI gate: **twig/twig** v3.24 → v3.27.1 ([CVE-2026-46634](https://symfony.com/cve-2026-46634), sandbox escape) and **symfony/yaml** v7.4.6 → v7.4.13 (CVE-2026-45304/45305/45133 — Billion Laughs / ReDoS / stack exhaustion). Lockfile-level (the lock is `export-ignore`d, so a consumer's own resolution is unchanged) — `timber/timber ^2.0` already requires `twig/twig ^3.27` for downstreams. See [#37](https://github.com/parisek/timber-kit/pull/37).

## [1.7.6] - 2026-06-01

### Fixed

- **ACF ⇄ Alpine.js block-preview compatibility** — the `acf_blocks_parse_node_attr` filter emitted by `StarterBase::acf_input_admin_footer()` now passes through Alpine binds (`:`), events (`@`), and the HTML `pattern` attribute, not just `x-` directives. ACF Pro's `parseJSX` runs `JSON.parse()` on any block-preview attribute value starting with `[` or `{`; for `:class="{…}"` object binds and regex `pattern="[…]"` (e.g. a phone field) that threw, crashing the block preview and making the post **unsavable** (*"Response is not valid JSON"*). Coverage now spans `x-`, `:`, `@`, and `pattern`; `AcfBlocksParseNodeAttrCompatTest` pins each family so it can't silently narrow again. Discovered on the aleszejdl theme (expert-register / expert-profile blocks). See [#34](https://github.com/parisek/timber-kit/pull/34).

## [1.7.5] - 2026-05-30

### Added

- `DevMediaProxy` can now be enabled via the `TIMBERKIT_MEDIA_ORIGIN` environment variable, not only the constant. `StarterBase::setup_dev_media_proxy()` falls back to `getenv()` when the constant is undefined; the constant still wins when both are set, so existing `define()`-based setups are unchanged. The env path lets a project enable the proxy with a single git-tracked line in `.ddev/.env`, which propagates to every git worktree without any PHP edit — the motivating use case being fresh worktrees whose `wp-content/uploads` is empty. Design rationale recorded in [ADR 0003](docs/adr/0003-dev-media-origin-env-and-self-host-guard.md). See [#32](https://github.com/parisek/timber-kit/pull/32).

### Fixed

- `DevMediaProxy::register()` now refuses a self-referential origin: when the configured origin host equals the **uploads base URL host** (`wp_get_upload_dir()['baseurl']`), the proxy bails instead of rewriting a missing file to a URL that resolves back to the same missing file. The uploads host — not `home_url()`, which can diverge (subdir installs, custom content URLs, `ddev share`) — is the comparand because that's the host the rewrite actually keys on. Guarding inside `register()` covers every caller regardless of whether the origin came from the constant or the environment variable. It's a host-level check (no `www`/port/IDN normalization). `register()` additionally rejects non-`http(s)` origin schemes. See [#32](https://github.com/parisek/timber-kit/pull/32).

### Documentation

- **README — new `### Breadcrumbs` subsection under `## Configuration`** documenting the `$breadcrumb_*` properties and the `setup_breadcrumb_labels()` override pattern. Mirrors the `### Performance` (speculation rules) section's shape: rationale → property table → worked example. Worked example uses English source strings (`_x( 'Home', $this->theme_name, $this->theme_name )`, …) so projects in any locale can copy-paste it and substitute the source strings for their language; clarifies that `$this->theme_name` in both `_x()` slots is intentional (context + textdomain unified). Discovered during the neoli WordPress theme migration ([portadesign/neoli#17](https://github.com/portadesign/neoli/pull/17)) — the `setup_breadcrumb_labels()` hook landed in 1.7.2 but was undocumented outside the source-code docstring.
- **`StarterBase::setup_breadcrumb_labels()` docstring example** — switched the Czech example (`'Úvod'`, `'Vyhledávání: %s'`, …) to English (`'Home'`, `'Search: %s'`, …) to align with the README's locale-agnostic stance. The hook itself is unchanged; only the inline example in the PHPDoc.

## [1.7.2] - 2026-05-26

### Added

- **`StarterBase::setup_breadcrumb_labels()`** — new public hook method, registered on `init` (priority 1), that projects override to populate `$breadcrumb_labels` with translated strings. WordPress 6.7+ emits a `_load_textdomain_just_in_time` notice when `_x()` / `__()` are called before `init` (e.g. in `Base::__construct()`), because the textdomain hasn't loaded yet. The new hook gives projects a sanctioned post-`init` callsite. Default implementation is a no-op — the English defaults declared on the `$breadcrumb_labels` property remain in effect for projects that don't override. Backward-compatible: projects already setting `$breadcrumb_labels` to raw (non-translated) strings in `__construct()` keep working unchanged; only projects calling `_x()` to populate the array need to migrate that block into the new override method. Discovered during the neoli WordPress theme migration ([portadesign/neoli#17](https://github.com/portadesign/neoli/pull/17)) where the labels block had been moved to `timber_context()` as a workaround.

## [1.7.1] - 2026-05-26

### Fixed

- `Helpers::formatFields('option')` no longer silently returns `[]` when the registered options page uses ACF's default `post_id` of `'options'` (the default for any `acf_add_options_page()` call without an explicit `post_id`). The previous `decodeOptionsNamespace()` compared the caller's `'option'` (singular) against the page's `'options'` (plural) via `acf_decode_post_id()`, which on ACF Pro 6.x does NOT collapse the alias — the helper's docblock claimed it did, but the existing test fixture papered over the gap by stubbing `acf_decode_post_id` to normalize both forms. Now the alias is canonicalized inside `decodeOptionsNamespace()`, so calls with either form match the page's namespace and surface all registered fields. Discovered during the neoli WordPress theme migration to timber-kit ([portadesign/neoli#17](https://github.com/portadesign/neoli/pull/17)) — symptom was an empty footer + missing global ACF data despite the data being present in `wp_options`. New regression test (`test_options_singular_alias_matches_plural_post_id`) mirrors real ACF Pro 6.x semantics (no normalization in the stub) so the fix can't silently re-regress.

## [1.7.0] - 2026-05-25

### Added

- **`Parisek\TimberKit\Breadcrumb` class** — strategy dispatcher producing typed item arrays (`[{type, url, title, …extras}]`) for the current WordPress query state. Covers 404, search, date archives, author archives, post type archives, taxonomy (with hierarchical ancestors), singular pages/posts/CPTs (menu-trail + post_parent fallback + list_page_map injection), and pagination.
- **`StarterBase` properties** for breadcrumb configuration: `$breadcrumb_menu_name`, `$breadcrumb_list_page_map`, `$breadcrumb_menu_trail_post_types`, `$breadcrumb_include_pagination`, `$breadcrumb_labels`. Child `Base::__construct()` overrides these before calling `parent::__construct()`.
- **`StarterBase::timber_context()` auto-populates `$context['breadcrumb']`** — unless the project still ships a global `\Breadcrumb` class (legacy compatibility guard via `class_exists('\Breadcrumb', false)`).
- **Filter API**: `timber_kit_breadcrumb_items`, `timber_kit_breadcrumb_labels`, `timber_kit_breadcrumb_skip`, `timber_kit_breadcrumb_menu_trail`.
- `StarterBase` now bundles the behaviour previously provided by the standalone [Speculation Rules](https://wordpress.org/plugins/speculation-rules/) plugin so downstream projects can `wp plugin deactivate speculation-rules && wp plugin delete speculation-rules` after upgrading. Two new properties drive it:

  - `$speculation_rules` (`?array`, default `['mode' => 'prerender', 'eagerness' => 'moderate', 'authentication' => 'logged_out']`) — registers `configure_speculation_rules()` on the WordPress 6.8+ `wp_speculation_rules_configuration` filter. Defaults mirror the plugin's defaults: meaningfully faster than WP core's `prefetch` / `conservative`, but rules are emitted only for logged-out visitors (`is_user_logged_in()` short-circuits to `null`) so admin previews and editor sessions don't trigger `prerender`-driven double-firing of GA / GTM / Productive page-view events. Set to `null` to fall back to WP core defaults entirely; set `authentication` to `'any'` to emit rules for logged-in users too. Partial overrides are supported — supply only the keys you want to change (e.g. `['mode' => 'prefetch']`), the remaining keys fall back to the defaults declared in `StarterBase::SPECULATION_RULES_DEFAULTS`.
  - `$warn_speculation_rules_plugin_redundant` (`bool`, default `true`) — registers a Site Health test (`Tools > Site Health`) named `timber_kit_speculation_rules_redundant`. Returns `status: 'good'` when the standalone plugin is inactive; returns `status: 'recommended'` with a "Manage plugin" link when both code paths are active and would duplicate the `wp_speculation_rules_configuration` filter. Passive signal only — no `admin_notices` banner, no auto-deactivation; the conflict is discovered during routine Site Health audits.

  Hooks are wired through a new `registerPerformanceHooks()` private method invoked from the constructor's declarative orchestrator, matching the concern-per-method shape of `registerSecurityHardeningHooks()` and `registerCommentDisablingHooks()`. The `wp_speculation_rules_href_exclude_paths` filter is intentionally **not** re-exposed: WP 6.8+ core already excludes `/wp-login.php`, `/wp-admin/*`, `*action=*` etc., and the standalone plugin only re-emits the legacy `plsr_…` filter for backwards compatibility — no downstream project under `wordpress-base` hooked the legacy name, so dropping it is safe. See [#23](https://github.com/parisek/timber-kit/pull/23).

### Migration note for downstream consumers (breadcrumb)

If your project still uses the global `\Breadcrumb` class (typical pre-migration `wordpress-base` setup), **no action is required for the upcoming release** — the legacy compatibility guard auto-detects your class and skips the new auto-populate. Your existing `new \Breadcrumb()` call sites keep working.

To activate the new auto-populate:

1. Delete your project's `classes/Breadcrumb.php` (and its tests).
2. Remove the manual `$breadcrumbs = new \Breadcrumb(); $context['breadcrumb'] = $breadcrumbs->get();` block from your `Base::timber_context()`.
3. Override `$breadcrumb_*` properties in your `Base::__construct()` (provide `_x()`-translated `$breadcrumb_labels` matching your text domain).
4. `$context['breadcrumb']` is now populated by `parent::timber_context()` automatically.

The items shape `[{type, url, title}]` is backward-compatible with the previous `[{url, title}]` — `type` is an additive key. No Twig component changes required.

## [1.6.0] - 2026-05-24

### Changed
- `|resizer` Twig filter is now **polymorphic**: in addition to the historical variadic-tuples shape, it also accepts a single orientation-keyed map. When the input is a single associative array carrying at least one of `landscape` / `portrait` / `square` keys, the filter classifies the source image's aspect (±10 % tolerance band around 1:1, overridable via the `timber_kit_resizer_aspect_tolerance` WordPress filter) and routes to the matched bucket. Caller passes one map `{ landscape: [...], portrait: [...], square: [...] }` instead of branching on `image.width >= image.height` in Twig. Missing-metadata, non-numeric, or zero-dimension sources fall back to `landscape`. Source-element selection mirrors the tuples mode — the last entry of the image list wins. When the matched orientation bucket has no tuples (empty or absent), falls through to `landscape`; if that's also empty / absent the source is returned unchanged. Backed by `Parisek\TimberKit\Resizer::resizerAspect()` (instance method) and `Resizer::classifyAspect()` (static utility, public for callers needing the bucket without resizing). Detection lives in `Resizer::isOrientationMap()` (called from `StarterBase::timber_twig()`'s Twig filter callback): tuples have integer keys (width / height / media / image_style / quality), so the two shapes can't realistically collide. Backward-compatible — existing variadic tuple calls flow through the historical branch unchanged. Implements parisek/timber-kit#16.

  ```twig
  {# Tuples mode — historical, unchanged #}
  {{ image|resizer(['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']) }}

  {# Orientation-aware mode — new #}
  {{ component_picture({
      image: item.image|resizer({
          landscape: [['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']],
          portrait:  [['720', '960', '1280', 'crop'], ['360', '480', '', 'crop']],
          square:    [['800', '800', '1280', 'crop'], ['400', '400', '', 'crop']],
      })
  }) }}
  ```

  Mirrors the parallel unification in `parisek/styleguide` so a single Twig template renders identically against the WordPress runtime and the styleguide preview.

- `Helpers::formatImage()`: missing keys on the associative-array, numeric-ID, and URL-string input branches now resolve to `null` silently instead of emitting `Undefined index` notices. The WordPress SVG-1px width/height workaround is now applied consistently across all three branches (previously only the array branch had the guard). `formatImageFrom()` (and therefore those three `formatImage()` branches) now explicitly casts `id` / `width` / `height` to `int|null` to match its documented return type — ACF sometimes hands numeric strings, which the pre-refactor inline branch would propagate untouched.

- `StarterBase::timber_twig()` — all six inline closures (`|resizer` filter, `component_*` / `page_*` / `template_exists` / `merge_resizer` / `gtm4wp_the_gtm_tag` functions) extracted into named public methods so each callback is directly callable from unit tests without fishing closures out of the Twig environment via reflection. No behaviour change; closures are internal implementation detail and downstream Twig templates calling these filters/functions continue to work unchanged. Shared body of `component_*` and `page_*` (identical control flow modulo the Twig namespace and the error label) consolidated into a single private helper `render_namespaced_twig_template()`. Stacktraces now point to named methods (`StarterBase::twig_resizer_filter`, etc.) instead of `Closure::__invoke`.

### Removed (vs. parisek/timber-kit#18 pre-release)
- `|resizer_aspect` Twig filter — never released, folded into `|resizer` per above before the first tag.

### Added
- `timber_kit_resizer_aspect_tolerance` WordPress filter — overrides the 0.1 default tolerance band used by `Resizer::classifyAspect()`. Returning a smaller value (e.g. `0.05`) tightens the square band, returning a larger value (e.g. `0.2`) loosens it.
- Property-based test suite (`tests/Property/`, runnable via `composer test:property`) powered by `giorgiosironi/eris`. Pilot covers structural invariants of `Resizer::normalizeVariants` (type stability, ordering, count preservation, determinism) and contract invariants of the new `Helpers::formatImageFrom()` pure core (non-throw + no PHP notices, shape contract with value-type checks, null propagation). See [#19](https://github.com/parisek/timber-kit/issues/19).
- `Helpers::formatImageFrom( ?array $raw ): ?array` — public static pure-core formatter extracted from `Helpers::formatImage()`'s associative-array branch. Behaviour preserved for well-formed inputs.

- Test coverage hardening for the changeset above:
  - `tests/Unit/StarterBase/TwigResizerFilterTest.php` — locks the `|resizer` polymorphic dispatch (orientation map → `resizerAspect()`, positional tuples → `resizer()`, zero variants → `resizer()`, orientation map with unrelated keys still routes to `resizerAspect()`). Uses observable behaviour (empty-variant return shape) as the routing oracle so the test stays a thin contract check.
  - `tests/Unit/StarterBase/TwigComponentTemplateTest.php`, `TwigPageTemplateTest.php`, `TwigTemplateExistsTest.php`, `TwigMergeResizerTest.php`, `TwigGtm4wpTagTest.php` — coverage for the other extracted `timber_twig()` callbacks: path resolution, `_` → `-` slug normalisation, the two-tier fallback chain (alert template → bare `<div>`), the page-vs-component label switch, `template_exists` true/false, `merge_resizer`'s media-qualified-only filter on non-last lists, and the GTM4WP function_exists guard. Each test file uses a real `Twig\Environment` backed by `ArrayLoader` so route/render assertions run end-to-end rather than against mocks (which PHPUnit 11 can't reliably build around `Environment::load()`'s union-typed argument).
  - `tests/Unit/Helpers/FormatImageTest.php` — extended with regression-locking tests on the numeric-ID and URL-string branches of `formatImage()` for the three [Unreleased] guarantees: (a) SVG 1×1 → null/null applied via `formatImageFrom`, (b) partial ACF payloads (missing `alt` / `caption` / `description`) resolve to `null` without emitting PHP notices — installs an error handler that converts any `E_NOTICE`/`E_WARNING` into a thrown `ErrorException`, (c) numeric-string ACF values for `ID` / `width` / `height` round-trip as PHP `int` via strict identity (`assertSame`).
  - `tests/Unit/Resizer/ClassifyAspectTest.php` — happy-path companion to the existing spy test: when the matched orientation bucket carries tuples, `resizerAspect()` hands THOSE tuples to `resizer()` (not landscape's, not an empty list). Closes the "always routes to landscape regardless of source orientation" implementation-bug class that the fallback test alone wouldn't catch.

### Fixed
- Custom `@font-face` declarations now reach both the iframed and non-iframed Gutenberg editor canvas. Previously fonts enqueued on `enqueue_block_assets` only loaded into the admin chrome — never the editor canvas — so brand fonts silently fell back to system fonts inside the editor regardless of canvas mode.

  New mechanism: `StarterBase::inject_font_editor_styles()`, hooked to `block_editor_settings_all`, walks `$font_stylesheets` and injects `@import url('<absolute>?v=<filemtime>')` entries into editor settings. Mirrors the production-validated [Sage 11 / Roots](https://roots.io/sage/docs/gutenberg/) pattern. `add_editor_style()` was deliberately *not* used because it inlines CSS into the iframe and strips the originating baseURL — relative `@font-face src` paths then resolve against the iframe's `blob:` document and font files silently fail to load ([Gutenberg #41035](https://github.com/WordPress/gutenberg/issues/41035)).

  Mode-agnostic by design: `block_editor_settings_all` fires for both iframed canvases (modern, all blocks `apiVersion: 3` — including pure ACF v3 setups) and non-iframed legacy canvases (any ACF v2 block present on WP < 7.0). Absolute URLs in `$font_stylesheets` (e.g. Google Fonts) pass through unchanged; relative paths are resolved under `static/` and cache-busted via `filemtime`. Missing files are skipped silently.

## [1.5.0] - 2026-05-15

### Added
- `Parisek\TimberKit\BlockRenderer` — new class hosting the ACF Gutenberg block render callback previously carried in every downstream theme's `functions.php`. Faithful behavioural port: same cache key composition (`acf_block_` + md5 of `wp_json_encode([name, data, anchor, className, post_id, lang, paged])`), same per-post cache group naming (`acf_block_{$real_post_id}`), same `wp_scripts`/`wp_styles` queue snapshot for side-effect detection, same `has_filter()` gate that skips Redis cache for dynamic blocks, same `acf_get_valid_post_id()` → global `$post` fallback for real-post-id resolution, same `HOUR_IN_SECONDS` TTL on frontend cache writes. **One new behavior**: the `block_<name>_content` filter is now skipped when the inserter-preview discriminator fires — fake example-data renders no longer trigger filter callbacks that would distort inserter-library thumbnails with derived enrichments. Five WordPress filters exposed for downstream customization: `timber_kit/block_renderer/cache_key`, `timber_kit/block_renderer/use_cache`, `timber_kit/block_renderer/content_data`, `timber_kit/block_renderer/context`, `timber_kit/block_renderer/empty_alert_html`. Wire as `"renderCallback": "Parisek\\TimberKit\\BlockRenderer::render"` in `block.json`, or call from the existing `timber_block_render_callback` wrapper in downstream themes.
- `timber_kit/block_renderer/content_data` filter — fifth package filter, called before `Helpers::formatFields()` so downstream projects can inject custom content data without ACF. Tests and storybook-style block previews benefit. Returning `null` (default) preserves the ACF code path.
- `src/templates/empty-alert.twig` — first package-shipped Twig template, rendered when render output is empty for a logged-in user. Uses WordPress Gutenberg's native `.block-editor-warning` classes so the editor styles it without any package CSS. Stable contract: `.timber-kit-block-empty` class + `data-block` attribute + optional `<strong>` block-label prefix from `$attributes['title']` (falls back to `$attributes['name']`). Defensive inline-HTML fallback in PHP when the `@timber-kit/` Twig namespace isn't registered (e.g. projects using `BlockRenderer` without `StarterBase`).
- `BlockRenderer::flushPostBlockCache($post_id)` — the handler `StarterBase` wires to `acf/save_post` at priority 20 to flush the per-post cache group `acf_block_{$post_id}`. Migrated from the freestanding `add_action` in the original `functions.php`; both writer (`writeToCache`) and invalidator now live on `BlockRenderer` so they can't drift, while `StarterBase` does the registration consistent with how every other hook in its constructor is wired.
- `StarterBase::register_timber_kit_namespace()` — registers the `@timber-kit/` Twig namespace pointing at `src/templates/` via the `timber/locations` filter at priority 20 (after WP default 10), appending the package path as a fallback so downstream themes' paths registered under the same namespace take precedence regardless of whether they prepend, append, or replace the entry.

### Changed
- `StarterBase::__construct()` — adds the `timber/locations` filter registration for `register_timber_kit_namespace()` and the `add_action('acf/save_post', [BlockRenderer::class, 'flushPostBlockCache'], 20)` registration alongside the existing `timber/*` filter registrations.
- `composer.json` — raised PHPStan script memory limit from `1G` to `2G`; the parallel analysis worker was crashing with OOM on the current source set.

## [1.4.1] - 2026-05-15

### Fixed
- `$disable_comments` no longer breaks the WordPress 6.9+ block-editor "post notes" feature (the sidebar that fetches `/wp/v2/comments?type=note&_locale=user` to populate internal editorial notes). The previous absolute removal of all `/wp/v2/comments` REST routes via `rest_endpoints` 404'd those reads/writes, leaving the editor notes panel silently broken on disabled-comment installs.
  - `disable_comments_rest_endpoints()` removed; replaced by `disable_comments_rest_pre_dispatch()` hooked to `rest_pre_dispatch`. The new filter inspects the request's `type` param and only short-circuits with a 404 for the standard public-comment surface this flag exists to block (`type=comment` / `type=pingback` / `type=trackback` / no `type` param). Non-standard `comment_type` values pass through untouched.
  - `disable_comments_rest_insertion()` updated symmetrically — POST inserts with `comment_type` outside the standard trio (`note`, `review`, `editorial-comment`, `order_note`, …) are passed through instead of returning a `403 rest_comment_closed` error. Standard public comment inserts continue to be blocked exactly as before.
  - Side benefit: WooCommerce order notes / reviews and editorial-workflow plugin comments (Edit Flow, PublishPress, …) also stop 404'ing on installs with `$disable_comments = true`, since they all use non-standard `comment_type` values too.

### Added
- Release automation: `.github/workflows/release-stamp.yml` (manual `workflow_dispatch` entrypoint that validates input, runs PHPUnit + PHPStan as guards, stamps `[Unreleased]` → `[X.Y.Z] - DATE`, commits, tags, pushes) and `.github/workflows/release.yml` (auto-fires on `vX.Y.Z` tag push, derives release notes from the matching CHANGELOG section + merged-PR list between tags, marks the release Latest only when it's the highest semver). README "Releasing" section documents the flow plus per-PR Keep-a-Changelog conventions and the existing `.gitattributes`-based distribution scope.
- `AGENTS.md` — tool-agnostic operational notes for any AI coding agent (Claude Code, Codex, Cursor, …) working on this repo: project shape, commands, per-PR conventions (CHANGELOG entries + squash-merge `(#N)` suffix the auto-release workflow depends on), the release process (don't bypass), and Brain\Monkey testing gotchas. Excluded from dist via `.gitattributes` `export-ignore`.
- `CLAUDE.md` — one-line stub pointing to `AGENTS.md` so Claude Code's default discovery still works without duplicating the operational notes. Same `.gitattributes` exclusion.

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
