# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.42.0] - 2026-08-26

### Added

- `$breeze_warmup_tail` — keep warming the URLs the cap excluded, a batch at a
  time, in score order, pausing whenever Breeze is draining its own preload
  queue. Batch size is `$breeze_warmup_tail_batch` (default 100 per five-minute
  tick) and filterable via `timberkit_warmup_tail_batch`; the stored tail is
  capped by `timberkit_warmup_tail_max_urls` (default 5000). Off by default,
  requires `$breeze_warmup_priority`.

## [1.41.1] - 2026-08-26

### Fixed

- `Helpers::languageFromUrl()` (new in 1.41.0) read a per-language host before
  the path prefix, and under **directory** negotiation that host is the same
  for every language — so the match succeeded for whichever language came first
  in WPML's array and every URL resolved to it. Measured on a five-language
  site whose array starts with `cs`: an Italian URL came back Czech, and the
  curated warmup list it was written for warmed five copies of the Czech pages.
  A language host now counts only where the hosts actually tell the languages
  apart; where they do not, the path is the only evidence and is used alone.

  The 1.41.0 tests missed it because neither fixture had the real shape: the
  directory one omitted `url` entirely, so the host branch never ran, and the
  domain one gave each language a distinct host. Both fixtures made the defect
  unreachable.

## [1.41.0] - 2026-08-26

### Added

- `Helpers::languageFromUrl()` — the language a URL names, read from the URL
  itself rather than from the request. Covers both WPML negotiation shapes, a
  path prefix and a per-language host.
- `Helpers::withLanguage()` — run one callback with WPML switched to a given
  language and switch back in a `finally`, so a throw cannot leave the rest of
  the request in another language.
- `$acf_json_keep_on_delete` (opt-in, default off) stops ACF from deleting a
  Local JSON file when its field group, post type or taxonomy is deleted in
  wp-admin. The database record is still removed, so the group reverts to the
  committed file on the next load instead of disappearing. All six
  `ACF_Local_JSON` delete listeners are removed, not only the field-group pair,
  because `acf_json_save_paths()` routes post-type and taxonomy JSON into the
  theme as well. Without it, a delete in the admin destroys a versioned source
  file and the loss is silent: `get_field_objects()` skips the now-unresolvable
  `_<field>` references, `Helpers::formatFields()` omits the keys, and consumer
  code reads them as "off" while the page still returns 200.

### Fixed

- **A curated warmup entry naming a translated page warmed its default-language
  sibling instead.** Two things had to be wrong at once and both were.
  `urlToPostId()` passed `wpml_object_id` two arguments, which translates
  towards the *current* language — and the refresh runs from cron, which has no
  language, so that is always the site default. Measured on a live
  five-language site: `url_to_postid()` resolved `/it/glossario-di-termini/` to
  the Italian page correctly and the translation then replaced it with the
  English one, so the step meant to repair a mismatch was discarding a correct
  answer. `get_permalink()` then compounded it: asked for an Italian page's
  permalink under an English context it answers with the English URL, so fixing
  the ID alone was not enough.

  The failure was invisible. On a 25-entry curated list covering five
  languages, all 20 non-default entries collapsed onto their 5 default siblings
  and were deduplicated away. 22 of the 25 URLs still appeared in the warmup
  queue — supplied by the sitemap, not by the curated list — so the list looked
  honoured while contributing nothing.

## [1.40.0] - 2026-08-26

### Added

- `$breeze_warmup_urls` — a curated warmup list that lives in the theme instead
  of in Breeze's settings row. Merges into the existing `manual` signal at the
  weight it already carries; no new tier, no change to ordering or the cap.
  Entries may be relative paths or absolute URLs, and both are resolved rather
  than trusted: one naming a post or page comes back as `get_permalink()`, so a
  relative path is correct on every environment and, under domain-per-language,
  on that language's own host. An entry that resolves to nothing is dropped, and
  what `url_to_postid()` cannot see is verified with one `HEAD` during the cron
  refresh — never on the purge path. Filterable as
  `timberkit_warmup_curated_urls`, its cap as
  `timberkit_warmup_curated_max_entries`.
- `Helpers::isSiteUrl()` / `Helpers::siteHosts()` — whether a URL belongs to this
  site, matching host **and** port, and counting each WPML language's own host
  under domain-per-language negotiation.
- `Helpers::urlToPostId()` — URL to post ID with the WPML prefix fallback and
  `wpml_object_id` translation. `formatLink()` had this logic privately;
  `Breadcrumb` and the new warmup list needed the same, so it is one function now
  and both call sites use it.
- `Breeze\WarmupSitemap` recognises **Yoast SEO** and asks for
  `/sitemap_index.xml`. Providers are now a list checked in order — AIOSEO,
  Yoast, core — so a project gets the right sitemap from whichever plugin it
  runs, and the order is stated rather than implied. AIOSEO stays first, so a
  site that already had it resolves exactly the path it resolved before.
- A Site Health check, `warmup_sitemap_resolved`, registered only when
  `$breeze_warmup_sitemap` is on. The module degrades silently by design — an
  empty sitemap result is a normal return value and the refresh job swallows
  throwables by contract — which is right for the purge path and wrong for the
  administrator, who was left with a warmup that preloads nothing and says
  nothing. The check reports the one fact worth acting on: the feature is on
  and the list is empty. A store that has never been refreshed is reported as
  good, because a fresh install waits for its first purge through no fault of
  its own.
- `timberkit_warmup_sitemap_url` filter, receiving the resolved URL and the
  detected provider key. It is the escape hatch for a provider the list does
  not know yet and for a site serving its sitemap from a non-default path. A
  non-string return, or a URL on another host, is ignored in favour of the
  detected path.

### Changed

- The warmup refresh no longer returns early when the sitemap is empty or
  unreachable. Curated entries are added first, so a project that names its
  pages explicitly still gets them warmed in exactly the situation where the
  sitemap cannot help. On a site with no curated entries this changes nothing;
  on one with them, a refresh that used to no-op now does work.

### Fixed

- `Helpers::formatLink()` no longer replaces a valid link with an empty string.
  `get_permalink()` answers `false` for a trashed post or a stale WPML
  translation id, and the concatenations after it coerced that to `''` — so a
  working URL silently became `''` or a bare `?query`.
- `Helpers::extract_slug_from_url()` no longer fatals when `wpml_active_languages`
  answers with `null` while the plugin is active — `array_keys( null )` is a
  TypeError, and WPML returns null before it has finished booting.
- `Breadcrumb` now resolves language-prefixed URLs. It called `url_to_postid()`
  directly, which returns 0 for a valid `/cs/…` URL, so those global links were
  silently dropped from the trail.
- The WPML prefix fallback no longer lets a foreign URL borrow a local path.
  It compares paths, so `https://someone-else.test/about/` matched this site's
  own About page and was rewritten to a local permalink — in `formatLink()`,
  where the fallback has lived for years, and in `Breadcrumb`.
- The same-host guard compared the hostname and ignored the **port**, so a
  sitemap index entry — or a filter callback — pointing at
  `https://<own-host>:9200/` passed it and was fetched. An internal service
  bound to another port on the site's own host was reachable that way. Ports
  are now compared, normalised by scheme first so one origin written two ways
  still matches. Pre-existing; the sitemap-index path carried it before this
  branch added a second way to reach the guard.
- A Yoast site produced an **empty** warmup list, silently. Yoast redirects
  `/wp-sitemap.xml` to its own index with a 301 and every fetch sends
  `redirection => 0` (the SSRF guard, deliberately kept), so the core fallback
  did not degrade to a slower answer — it degraded to no answer at all. With
  `$breeze_warmup_sitemap` on, the refresh stored nothing and reported nothing,
  because an empty result is a normal return value and `runRefresh()` swallows
  throwables by contract. Measured on a live Yoast Premium site: 0 records from
  the requested path, 1113 from the one Yoast serves.
- Yoast is detected on its sitemap switch, not on its symbol alone. Yoast can
  be loaded with its XML sitemap turned off, and core then serves
  `/wp-sitemap.xml` again — detecting on the symbol would have sent exactly
  those sites to an address that 404s, breaking a configuration that worked
  before Yoast was recognised here.

## [1.39.0] - 2026-08-24

### Changed

- Everything that knows about the Breeze plugin now lives under
  `src/Breeze/` (namespace `Parisek\TimberKit\Breeze`), so a project that
  does not run Breeze can see in one directory what is dead weight. A test
  enforces the boundary: naming Breeze anywhere else under `src/` fails the
  build. `StarterBase` is the one exception — it keeps the opt-in flags,
  whose names are unchanged.
- `Parisek\TimberKit\BreezeWarmupSitemap` is now
  `Parisek\TimberKit\Breeze\WarmupSitemap`. The old name keeps resolving
  through a class alias, so no consumer breaks on upgrade.

### Added

- `$breeze_warmup_priority` — order the Breeze warmup list by importance
  (per-language homepages, Breeze's own manual list, menu membership, post
  type and `<lastmod>` freshness) instead of leaving it in sitemap order.
  Weights are declared in `$breeze_warmup_priority_weights` and filterable via
  `timberkit_warmup_priority_weights`. Off by default.
- Site Health check `preload_chain_healthy` — reports a Breeze preload chain
  that has stopped making progress.

### Changed

- The warmup option row now stores the ordered list, the signals behind it, a
  weight fingerprint and a revision counter. A row written by an earlier
  version reads as stale and is refreshed; no migration is needed.
- `fetchSitemapUrls()` now deduplicates by canonical URL form rather than
  exact string, so two spellings of the same page (differing in trailing
  slash, scheme case, default port or fragment) collapse to one, first-seen
  spelling winning.

## [1.38.0] - 2026-08-20

### Added

- `StarterBase::$preload_headers` (default `true`) sends the page's preload hints as a `Link:` response header, and `$preconnect_origins` adds `rel=preconnect` origins to it. Core collects preload resources through `wp_preload_resources` but renders them in one place only — `<link rel="preload">` tags in `wp_head` — which the browser finds after the document it was waiting for. The header carries the identical list and arrives before the body. `send_preload_headers()` reads that same filter rather than keeping a second list, so a project that declares a font once gets both outputs and the two cannot drift apart; it appends rather than sets, because core sends its own `Link:` entries for the REST route and the shortlink. It is also the whole of an origin's part in HTTP 103 Early Hints: PHP cannot emit an informational response, so a 103 is always synthesised by an edge from `Link:` headers it saw on a previous 200 — meaning the header is worth sending whether or not any edge does that, and that the first visitor to a URL never benefits from the 103 itself. Hooked to `send_headers`, which fires before the query is run: nothing here can depend on the post being rendered, so a page's LCP image stays a `fetchpriority` attribute in the markup rather than moving into the header. Silent in the admin, on AJAX, on REST and on feeds, none of which render a document.
- `PreloadHeaders::format()` does that formatting with no hooks and no globals. It refuses a relative URL (it resolves against a document, and an edge replaying the header as a 103 has none yet), refuses an entry without `as` (a preload with no destination is usually fetched a second time by whoever needed it, which is slower than not preloading), adds `crossorigin` to every font whether or not the entry asked for it (a font is fetched in CORS mode regardless, so omitting it downloads the file twice), and drops an attribute carrying a quote or a newline rather than escaping it — every legitimate MIME type, media query and srcset contains neither. The assembled value is capped at 4 KB by dropping whole entries: proxies impose header limits the standard does not, and one that finds the block too large drops all of it, so losing the tail beats losing the head. `crossorigin` names its keyword rather than standing bare, because a valueless header parameter is not read as an empty attribute by every parser and one that reads it as absent gives the preload no-CORS mode -- the double download again, now invisible. A non-array entry is skipped the way core's own consumer skips it, and a non-scalar attribute value drops that attribute rather than the link. An entry carrying `imagesrcset` but no `href` is dropped: core accepts that shape for `as=image`, but a link header states its target between angle brackets and there is no header form of it.

## [1.37.1] - 2026-08-18

### Fixed

- **A module entry no longer carries `?ver=`**, so the browser stops
  instantiating it twice.

  1.37.0 gave the entry a content-hashed filename, which fixed the stale-bundle
  half of this problem: both references now name the same file. The identity
  half survived. `enqueueThemeScript()` still passed a version to
  `wp_enqueue_script_module()`, so the HTML asked for
  `script.<hash>.min.js?ver=<mtime>` while a Vite chunk imports the entry back
  out by its own relative path, `./script.<hash>.min.js`, with no query.

  A module's identity is its resolved URL. Two URLs are two modules: the file
  is fetched twice and its top-level code runs twice. Measured on a downstream
  site as two 46 kB requests from a single `<script type="module">` tag, at
  1300 ms and 1606 ms of a cold mobile load.

  The query bought nothing there anyway. The content hash already IS the cache
  key. So the version is now omitted only when the filename matches the Vite
  content-hash convention. The `script.js` fallback and an unhashed filename
  supplied by a manifest keep their cache buster. `null` is used rather than
  `false`, because `false` substitutes the WordPress version and would
  reintroduce the split.

  **No effect on the classic `defer` strategy**, which is not a module and
  cannot split. Its version is unchanged.

  The hash test looks for the hash itself, not for `.min`. Minifying is a
  separate Vite setting, so an unminified build still emits
  `script.<hash>.js` — requiring `.min` there would hand it a version query
  and reinstate the split it just removed.

## [1.37.0] - 2026-08-17

### Fixed

- **The theme JS entry now resolves through the Vite manifest**, so a build that
  hashes its entry stops serving a stale bundle from cache.

  `enqueueThemeScript()` addressed the bundle by a fixed path, so the entry
  could not carry a content hash and cache-busting came from `?ver=<mtime>`
  instead. That covers the reference in the HTML — not the one the bundler
  emits **inside a lazy chunk**. A module reachable from the entry graph and
  from a chunk gets hoisted into the entry, and the chunk imports it back out
  as `./script.js`: no hash, no query. The browser then holds two cache entries
  for one file, and the bare one is pinned for as long as `Cache-Control` says.

  Measured downstream: 5 of 52 chunks imported the entry, `max-age` was
  31536000, and a form silently stopped rendering with `The requested module
  './script.js' does not provide an export named 'n'`. Minified export names are
  positions in a table, not identities — adding one unrelated shared module
  reassigned `n` to a different function, so a stale entry can also answer with
  the **wrong binding and no error at all**.

  `themeScriptFile()` reads `static/dist/js/.vite/manifest.json` and looks up
  `src/js/script.js`, the Vite input path — the same key `parisek/styleguide`
  >= 1.16 and tailwind-base's `sync-styleguide` use. It requires a bare
  filename, a `.js` suffix, and presence on disk; the key itself passes through
  the `timber_kit_theme_script_manifest_key` filter.

  **Backwards compatible by construction.** A theme with no manifest keeps the
  unhashed `script.js` and nothing changes, so this is a no-op until a build
  emits one.

  If you override `enqueueThemeScript()`, call `themeScriptFile()` for the
  filename — an override keeping the old literal still works and silently gives
  up the protection.

## [1.36.0] - 2026-08-17

### Changed

- **`gutenberg-editor.css` no longer reaches the classic (TinyMCE) editor** — new `mce_css` filter, new `StarterBase::$mce_exclude_editor_styles` flag, **default on**. `add_editor_style()` registers a stylesheet for both editors, and the file is written for one: its rules are scoped to `.editor-styles-wrapper`, a class Gutenberg's body carries and TinyMCE's body does not.

  The damage is visible, not theoretical. What does reach TinyMCE is the Tailwind Preflight, which resets `a` to `color: inherit; text-decoration: inherit` and `ol`/`ul` to `list-style: none` — so every ACF `wysiwyg` field renders a link as plain body text and a bulleted list with no bullets. Found on a consent sentence whose stored value carried a correct `<a href>` in all five languages and read as unlinked prose to the editor writing it. **The underline that normally appears there comes from the browser's UA stylesheet, not from core CSS** — `wp-content.css` carries no `a` rule at all, so a reader looking for the rule we override will not find one; Preflight simply loads last and wins.

  **On by default, deliberately against the letter of `AGENTS.md` § Feature flags** — an owner decision, recorded here rather than left to be re-litigated. The rule guards against surprising a consumer, and no consumer wants a text editor that strips a link's underline and a list's bullets; shipping the fix opt-in would have spread the defect instead of ending it. `$restrict_allowed_blocks` is the standing precedent — default on, changes the editor more than this does, and keeps its flag as the escape hatch rather than the switch-on.

  **One upgrade check, once.** A theme may style the classic editor from inside that same file — `body#tinymce` / `body.mce-content-body` blocks are the usual shape — and excluding the file switches those rules off with nothing to warn about, because both states render. Grep `static/src/css/gutenberg-editor.css` for `mce-content-body`; on a match, either drop the block (the native editor now does that job) or set `$mce_exclude_editor_styles = false`. The README carries the same note.

  **This makes the release a minor, not a patch.** A changed default is not a bug fix, whatever the size of the diff.

  Matching is on the file name, not a substring of the URL: `/css/my-gutenberg-editor.css` and `/css/a.css?source=gutenberg-editor.css` are left alone. The filter is also a no-op for a theme with `$gutenberg_editor_styles = false`, which never registered the stylesheet.

  One claim from this change's first draft is retracted here rather than quietly dropped: **"nothing is lost, because nothing in it applied" was false.** A theme's `settings.css` imports reach the iframe too, so a compiled file also carries `:root` custom properties, base-layer element rules (`button { cursor: pointer }`, `html { overflow-anchor: none }`) and class-scoped block styles (`.wp-block-image`, `.wp-block-separator`). None of it styles the text a `wysiwyg` field holds, which is why the flag is safe — but the blanket claim was measurably wrong, and a project whose imports differ should read its own compiled output.

## [1.35.0] - 2026-08-17

### Added

- **`Parisek\TimberKit\GtmContainer` + `StarterBase::$gtm_containers` + the `gtm_container()` Twig function** — load Google Tag Manager from the kit, configured in code, instead of through the GTM4WP plugin. Off by default: with no `$gtm_containers`, `gtm_container()` prints nothing and `gtm_container_noscript()` delegates to the plugin, so upgrading changes no site's markup.

  The reason it exists is a measurement, not a preference. On a site without WooCommerce, GTM4WP's entire frontend output is the loader snippet, a `noscript` iframe and an empty data layer — `dataLayer_content` was literally `[]` on the production site this was measured on, with all ~40 data-layer options off. That became a problem when a tagging vendor asked for the container ID to be dropped from the loader URL, which a server-side container addressed by its own random path does not need: GTM4WP 1.22 hardcodes `?id='+i`, offers no filter over it, and rejects a custom path containing `?` or `=` by silently falling back to `gtm.js`. The setting exists in GTM4WP 2.0, which currently ships only as a beta its author marks as not for production.

  **A stated `path` means the ID leaves the URL** — one path selects one container, so it is not a second setting. The query string then starts at `?l=` rather than continuing with `&l=`; both shapes are asserted as whole-output comparisons against Google's published snippet, which is what the kit emits — line for line, comments included, with no vendor attributes and no generator marks, so the page source is indistinguishable from a hand-pasted installation and the id-less URL is the only difference to find.

  **Containers are keyed by language** with `default` as the fallback, and a language entry states only what differs — normally the ID alone, since the tagging endpoint is shared. Keys are WPML language codes as that site defines them (editable per site, so `de-at` and `deu` are as legitimate as `de`); matching ignores case and treats `_` and `-` alike. A regional variant resolves to its base language before the site default — `de-at` uses the German container until Austria states its own — so an Austrian visitor is not silently filed under the site's main language. An unknown language falls back to `default` rather than failing; a language **written out and left blank** (`'de' => ''`) is switched off instead, which is the only way to say "do not measure here" in a model where everything else inherits.

  **`TIMBERKIT_GTM_ENABLED` gates the environment**, and without it measurement runs on production only. Deliberately not `WP_DEBUG`: that flag says how errors are reported, and quietening a log must not switch measurement off as a side effect. This also replaces the `DEACTIVATE_PLUGINS` workaround projects use to keep the plugin out of local development.

  Configuration present *and* the plugin still printing its own container is the one state that double-counts every visit. The loader deliberately never inspects the plugin — a wrong guess about a schema this kit does not own would silently stop measurement — so the state is reported by a new **`gtm_container_not_duplicated`** Site Health check (`$site_health`) instead, which reads the plugin's placement numbering and its `GTM4WP_HARDCODED_GTM_ID`. The **`gtm_container_noscript()`** Twig function prints the `noscript` iframe for the `<body>` position, but only where it can be honest: `ns.html` takes the container ID as a query parameter and has no ID-less form, so it prints by default for a container without a custom path and stays silent for one with it, overridable per container with `'noscript' => true|false`. GTM environments (`gtm_auth` / `gtm_preview`) are the one capability deliberately not carried over — a tagging server has no notion of them. See [ADR 0005](docs/adr/0005-first-party-gtm-container.md).

### Deprecated

- **`StarterBase::twig_gtm4wp_the_gtm_tag()` / the `gtm4wp_the_gtm_tag()` Twig function** — replace it with **both** new calls: `gtm_container()` in `<head>` and `gtm_container_noscript()` after `<body>`. The old function emits the plugin's `noscript` iframe and nothing else (the plugin injects its own script through `wp_head`), so `gtm_container_noscript()` is the one that stands in its place — and it delegates straight back to it while the project has no `$gtm_containers`, which makes the swap safe before migrating and final afterwards. The old function keeps working.

## [1.34.0] - 2026-08-14

### Added

- **`Parisek\TimberKit\SvgDimensions` + `wp timber-kit svg-dimensions` + `StarterBase::$svg_dimensions`** — give SVG attachments the intrinsic `width`/`height` WordPress cannot measure for them, so an `<img>` reserves its box instead of shifting the layout. `getimagesize()` cannot parse SVG, so core stores no dimensions at all; the `svg-support` plugin fills part of the gap but its reader takes only the `width`/`height` attributes on the root element, so a `viewBox`-only export — the current Figma and Illustrator default — stores `intval( '' )`, i.e. `0`. Measured on one production library: 1519 of 3520 SVGs unsized, and the share **growing** by upload year (2022: 555 of 1673; 2025: 353 of 458) as export tooling moves to `viewBox`-only. All 1519 resolve, in 1.4 s.

  **The read path is the base behaviour, not the flag.** `Helpers::formatImageFrom()` and the Timber-object branch resolve a missing axis from the file, so a template gets dimensions whether or not anyone ran the sweep or flipped the upload flag. It runs for every image on a page, so the scope is asserted rather than argued: `SvgDimensions::resolveSvg()` returns on its first comparison for anything that is not an SVG missing an axis — 0.095 us, nothing opened, 0.014 ms across a 150-image page — and the tests pin it by asserting `get_attached_file` is never reached for a raster image. An SVG that does need reading costs 0.086 ms once and is memoised per request, refusals included, so a marquee repeating 30 logos across 90 `<img>` elements opens each file once. Instrumented on a real page with 152 SVG `<img>` elements: 399 calls, 271 immediate returns, 128 resolutions, ~11 ms per uncached render and nothing on a cached hit. Running the sweep removes that 11 ms entirely, which is the honest relationship between the two — the read path makes a template correct everywhere, the sweep makes it free. Stored metadata still matters and the two writers still exist, because the media library, `wp_get_attachment_image_src()` and srcset read it and never come through `Helpers`. Resolution is skipped entirely when `get_attached_file()` is absent, which is how `Helpers` runs under `parisek/styleguide` with no CMS behind it.

  **Refusing is a correct answer.** Every ambiguity returns null rather than a guess: a wrong number is written to the database, survives the package version, and is read back as authoritative by the next sweep without `--force`. So an undecodable encoding, a prolog that cannot be walked, a root tag past the 64 kB limit, a unit that cannot be converted, and a derived `1×1` (indistinguishable from core's bogus SVG 1px, which `Helpers` discards anyway) all resolve to "unreadable".

  **Reading.** The root start tag is found by walking the prolog — XML declarations and processing instructions, comments, and a DOCTYPE with its internal subset — not by searching for the first `<svg`. That search read a `<svg` quoted inside a processing instruction, an entity declaration or a CDATA section as the root and returned its numbers. A namespace-prefixed root is accepted only when the prefix resolves to the SVG namespace. Files are read incrementally and stop at the root tag, which also sidesteps libxml's 10 MB `AttValue` limit — three real uploads failed to parse whole because of an embedded base64 `<image>`, discarding a `width`/`height` pair that sat in the first hundred bytes. Entity references inside the tag are escaped to literal text, never resolved, so a hostile DOCTYPE cannot reach the parser.

  **Numbers.** The full SVG/CSS grammar: sign, decimal point, exponent, and every absolute unit (`px`, `pt`, `pc`, `in`, `cm`, `mm`, `Q`) converted to pixels — `width="72pt" height="25.4mm"` is square, not the 1:2 its `viewBox` might imply. Relative units (`em`, `%`) carry no intrinsic size and fall through. A single explicit axis is combined with the `viewBox` aspect ratio instead of being discarded. `viewBox` as a source of width and height is a deliberate **policy**, not a measurement — W3C defines it as a source of aspect ratio — recorded as such because for an image whose box comes from CSS the ratio is the whole point.

  **Coexistence.** Dimensions go in `_wp_attachment_metadata`'s own `width`/`height` keys — core's structure, the one `wp_get_attachment_image_src()` reads. Each axis is considered **separately** and a valid stored value is never replaced, so filling a missing height cannot disturb a width another plugin resolved. `0` and `1` count as absent. The upload filter hooks at priority 20, above the 10 `svg-support` uses, so it observes that result rather than racing it by load order. Metadata is read unfiltered and written back only when it actually changes; `wp_update_attachment_metadata()` returning false is confirmed against the database before it is called a failure, since it also returns false for an unchanged write.

  **CLI.** `--dry-run`, `--limit`, `--verbose`, and `--force`. A non-positive or non-numeric `--limit` is an error rather than silently meaning "all", and a run with any failed write exits non-zero instead of printing a warning and `Success:` together. `StarterBase::$svg_dimensions` (default **off**) governs only the upload filter; the command is registered unconditionally, because a sweep a human types is opt-in by being typed and the existing backlog needs fixing on projects that never flip the flag.

## [1.33.0] - 2026-08-14

### Added

- `StarterBase::$site_icon_tags` (default off) replaces WordPress's four legacy site-icon tags with the favicon set the theme ships. Core asks `get_site_icon_url()` for 32, 192, 180 and 270 px and a theme answering with one SVG gets that SVG four times — including as `apple-touch-icon`, which iOS cannot read — plus an `msapplication-TileImage` for a Windows 8 tile. The flag probes `static/images/touch/` for known filenames and writes a tag only for a file that exists, so both RealFaviconGenerator output generations (modern `favicon.svg` + `favicon-96x96.png`, and the 2017-era 16/32 set) are handled with nothing configured; PNG `sizes` come from the filename rather than from opening the file, and `theme-color` plus `apple-mobile-web-app-title` are read from the manifest's `theme_color` and `short_name`. An uploaded Site Icon now wins over the theme's files — the off-path overrides it silently, which is why the fix is a flag and not a correction in place. `safari-pinned-tab.svg` is knowingly skipped: its `mask-icon` tag needs a tint colour no file states, and a guessed one renders worse than no pinned-tab icon. Two precedence details are load-bearing, both found by an adversarial review before release: the upload wins only while it still *resolves*, so a `site_icon` option left pointing at a deleted attachment no longer suppresses the theme's set; and a manifest-only theme answers no `get_site_icon_url()` at all, because that function is public API other code reads expecting a real image (an SEO plugin's schema logo) and a `.webmanifest` would be a worse answer than none.

## [1.32.0] - 2026-08-12

### Fixed

- `Resizer` variants report the dimensions the file actually has, not the ones they asked for. `processVariant()` returned the requested width and height verbatim, and three of the encoder's branches do not produce them: a scale-only variant (`['1200', '', …]`, the most common tuple shape there is) left the other axis at `0`, a non-cropping style with both axes set let the last one win, and `smart-crop` skipped the crop entirely when the source was smaller than the target. A template writing `<img width height>` from those values emitted `height="0"` — the attribute pair that exists to prevent layout shift. The values are derived from the source dimensions already in the image array, so nothing extra is read; a requested axis is exact, while a derived one is an estimate, since Spatie's GD and Imagick drivers implement the step differently (a single step stays within a pixel); `Resizer::producedDimensions()` is public and `DevMediaProxy` uses it too, so a remote variant describes the same file the same way. A source of unknown size yields `0` for anything that would have to be derived from it, rather than a guess a consumer could not tell from a measurement.

### Added

- `SocialImage` — produces the one image variant a link-preview scraper can use: the documented 1200x630 card, in a format Facebook, LinkedIn and X actually read, never the original. `SocialImage::get( $image, $options )` returns the variant or `null`, accepting a result only once it is demonstrably the requested cut in a readable format; `SocialImage::spec()` exposes the same policy without touching an image. Filterable via `timber_kit_social_image_defaults` and `timber_kit_social_image_formats`, neither of which can move the defaults somewhere that defeats the guarantee: an unreadable format or an inexact crop style falls back to the package value rather than taking hold site-wide. It deliberately stops at the image rather than wiring an SEO plugin's hook, which needs a post type and a field name the package cannot know.
- `SocialImage::forPost()` resolves a post's preview image from a post-type → field map (`StarterBase::$social_image_fields`, filter `timber_kit_social_image_fields`), falling back to the featured image for an unmapped type. Candidates are tried until one yields a usable cut, so a value that resolves but cannot be encoded does not consume the chain. `timber_kit_social_image_post_fields` overrides the field reader for projects storing this outside ACF.
- `SocialImageBridge` hands that image to an SEO plugin's `og:image` and `twitter:image`, opt-in via `StarterBase::$social_image_bridge`: `true` detects the active plugin, a key from `SocialImageBridge::supported()` forces one, `false` wires nothing. It supplies only the image and only when there is one; otherwise the plugin's own resolution stands, and a post whose social image the editor chose by hand in the plugin's panel is skipped entirely.
- `Resizer` variants may be written as associative arrays — `['width' => …, 'height' => …, 'media' => …, 'image_style' | 'crop' => …, 'quality' => …, 'format' => …]` — alongside the positional tuples, and the two shapes mix in one call. Both the `|resizer` Twig filter and `Resizer::resizer()` accept either.
- `format` per variant, reachable only through the associative shape. The output format used to be request-wide, read once in the constructor from `timber_kit_resizer_target_format`, so a caller needing a different encoding had to add that filter immediately before constructing the instance and remove it straight after. Consumers that are not browsers need this: link-preview scrapers read JPEG, PNG, GIF and WebP but not the AVIF written by default, so an `og:image` routed through the resizer produced a preview card with no image at all. An unrecognised format falls back to the request-wide one rather than throwing.

- `StarterBase::$resizer_quality_in_cache_key` / `timber_kit_resizer_quality_in_cache_key` (default `false`) puts a variant's quality in its cache key. Quality is absent from the key today, so re-cutting the same dimensions at a different quality serves the previously generated file and the setting appears to do nothing. Opt-in rather than on by default: switching it on relocates every non-default-quality variant, orphaning the old files and changing public URLs. Once on, quality enters the directory segment only when it differs from the package default (`1200x630-center-q82`), so paths at default quality never move.

- **`outage-screen` now covers the fatal-error state.** `install` writes a third
  drop-in, `wp-content/php-error.php`, which `WP_Fatal_Error_Handler` loads in
  place of core's "There has been a critical error on this website". It serves
  the same prerendered screen as its two siblings — the state a visitor sees is
  different, what they need from it is not: a way to reach an application that
  is still running.

  It sends **`500` and no `Retry-After`**, where the other two send `503` and
  `Retry-After: 600`. `503` means a planned, bounded outage and monitoring reads
  it that way, some of it suppressing alerts on a `503` carrying `Retry-After`.
  A crash is neither planned nor bounded, and must not look routine.

  **The recovery-mode e-mail is unaffected.** `php-error.php` replaces the error
  *template*; core sends the mail in `handle()` before reaching the template.
  The drop-in that could cost an administrator that way back in is
  `fatal-error-handler.php`, which this package does not touch.

### Changed

- **`OutageScreen::DROP_INS` maps to a contract, not a string.** Each entry is
  now `array{covers, status, retry, context}`; it was the prose description
  alone. Consumers reading the old shape need updating — inside this package
  only `outage-screen status` did. `OutageScreen::source()` takes the drop-in
  filename as a third argument, defaulting to `maintenance.php`, and throws on
  a name it does not generate.

  This exists because the three files are no longer byte-identical. `install()`
  and `status()` each computed one source above their loop, which was correct
  while they were; both now compute per file. Hoisting either back out writes —
  or reports — one drop-in with another's status, and the `status` case is the
  quiet one: it would name a correct file stale, and a reinstall would not fix
  it.

- **Projects that ran `install` before this release show `php-error.php absent`
  in `status`** until they run it again. A drop-in of that name written by
  somebody else is reported `not ours` and left alone, as with the other two.

### Fixed

- `DevMediaProxy` probes the origin for the variant the Resizer would actually have written. It rebuilt the cache directory name and took the output format from the request rather than from the variant, so with per-variant formats it probed for a file that was never written, and it could not see a quality-keyed directory at all. Both values now come off the variant, which carries the cache key the Resizer resolved; the request-wide format survives only as the fallback for a variant that carries none.
- A variant's `image_style` is stripped to path-safe characters before it becomes a directory name. It reaches `wp_mkdir_p()`, so a caller could previously walk out of the cache directory with it. Every style the pipeline recognises consists of such characters already, so recognised values are untouched.


## [1.31.1] - 2026-08-11

### Documentation

- **README covers the package again.** Two CLI commands
  (`wp timber-kit wpml-cleanup-theme-domain`, `wp timber-kit outage-screen`) and
  four classes (`BreezeWarmupSitemap`, `VideoCodecs`, `MenuData`, `OutageScreen`)
  had shipped without ever reaching the file a consumer reads first. Adds a
  single command table — a missing row is visible where a missing paragraph is
  not — plus sections for the outage screen, the WPML theme-domain cleanup and
  the Breeze cache warm-up. `AGENTS.md` now requires README coverage in the same
  PR that adds a public surface.

## [1.31.0] - 2026-08-11

### Added

- **`wp timber-kit outage-screen`** installs the two WordPress drop-ins that
  serve the theme's prerendered outage screen: `wp-content/maintenance.php`
  (a `.maintenance` file in the site root — written by
  `wp maintenance-mode activate`, and by core itself during every core and
  plugin update) and `wp-content/db-error.php` (database unreachable). Both
  states run before plugins and the theme, and the second has no database at
  all, so the screen cannot be rendered at that moment — it is prerendered by
  the theme with `parisek/styleguide`'s `maintenance:render`, and these files
  only send `503` + `Retry-After` and `readfile()` it.

  The generated files depend on nothing: no Composer autoloader, no WordPress
  function beyond the `WP_CONTENT_DIR` constant, no database. They also never
  return early — WordPress `require_once`s them and then calls `die()`, so a
  return would serve a blank page — and they print a plain 503 sentence when
  the theme has not rendered its screen yet.

  `install` is idempotent, writes atomically (a live request can be reading
  the file — reinstalling during an active outage is normal), and refuses to
  overwrite a drop-in it did not generate, keyed on a machine-shaped marker
  rather than a prose attribution line anyone might copy. `status` reports
  installed/stale/absent/not-ours plus whether the screen exists; `remove`
  takes only its own files back out.

  **Multisite:** `wp-content/` is shared across the network while the theme is
  per-site, and no drop-in can resolve the current site (`db-error.php` runs
  with no database to ask). Every site therefore serves the screen of whichever
  site the command ran against; `install` says so rather than leaving it to be
  discovered during an outage. Refs portadesign/tailwind-base#569.

## [1.30.1] - 2026-08-09

### Fixed

- **The breadcrumb's listing step is no longer dropped on a theme that
  namespaces its ACF options page.** `Breadcrumb::get_global_links()` read
  `get_field( 'links', 'option' )` unconditionally, while
  `StarterBase::register_options_page()` honours an explicit `'post_id'` in
  `$options_pages` (e.g. `'mytheme_settings'`) and writes there. The package
  therefore wrote the listing links to one store and read them from another:
  `get_field()` returned `null`, `build_for_singular()` appended no listing
  item, and every singular of a `list_page_map` post type rendered a trail one
  step short — with nothing logged and no error anywhere.

  `Breadcrumb` gains an `options_post_id` config key (default `'option'`, so
  un-namespaced projects are byte-identical), and `StarterBase` passes the
  namespace it actually registered, derived from the first `$options_pages`
  entry declaring a `post_id`. Override with `$breadcrumb_options_post_id` when
  the `links` group lives on a different page than the first.

  Found downstream on a five-language WPML site whose seven listing links were
  all populated and none of which reached the breadcrumb. The failure is easy
  to misread as "the fields are empty": a term filter had been supplying a
  middle step, so the trail looked complete (`Home > AI > title`) while the
  listing step it should have carried was silently absent.

  **Not fixed here, deliberately:** the `links` group is still addressed by the
  literal selector `'links'`. Making the group name configurable is a wider
  change to `list_page_map`'s contract and no downstream project needs it yet.

## [1.30.0] - 2026-08-08

### Fixed

- **`Helpers::formatFields()` no longer drops a field with a meaningful falsy
  value.** Previously any formatted value that failed `!empty()` was omitted
  from the result — for an ACF `true_false` field, an unchecked box formats
  to `false`, which is `empty()`, so the key disappeared entirely. Consumers
  reading the switch via `array_key_exists()` / `isset()` / `??` (a common
  `?? true` "default on" pattern) could not tell "explicitly turned off"
  from "field does not exist". Measured downstream: a page-header component
  used exactly that pattern, and 280 published blocks with the switch
  explicitly off rendered as if it were on.

  A falsy field now surfaces in the result as a real key — `formatFields()`
  keeps `0`, `0.0` and `"0"` as real values for every
  field type, while still dropping `null`, `''`, and `[]`. `false` is kept
  **only for `true_false` fields** — that is the one ACF type whose stored
  value is always boolean, with no third "empty" state distinct from
  `false`, so an unfilled switch and an explicitly-off one are the same
  value by design and both are meaningfully "off". Every other type's
  `false` keeps being dropped: ACF itself uses literal `false` as an
  "empty" sentinel for a `relationship`, `gallery`, `image`, `file`,
  `link`, `date_picker`, nullable `select`, or an unfilled `repeater` /
  `flexible_content` outside preview — none of those carry a `false` that
  a consumer would ever want to distinguish from "not set", so disambiguating
  by field type (rather than by whether the source field merely carried a
  `value` key, which is true for the empty cases above too) keeps them
  absent, matching the pre-1.30 behaviour and this fix's own promise that
  an unfilled repeater stays absent. This also covers a `false` produced by
  the `field_formatter_{type}` filter, not just the raw ACF value — the
  survive/drop decision is made on the field's declared `type` and the
  *formatted* return value, regardless of what set that value to `false`:
  a filter that forces a non-`true_false` field to `false` still gets
  dropped, and — the converse, more consequential case — a filter that
  forces a `true_false` field to `false` (even from a non-falsy raw value
  like `true`) still survives, the same as a raw off-switch would.

  **Also fixed on the ACF block path specifically:** `formatFields()`
  resolves an ACF block's fields via a `block_<hash>` id, then swaps in the
  block's real post id before formatting (`str_starts_with( (string)
  $post_id, 'block_' )` in `formatFields()`) — the id-swap happens *after*
  `get_field_objects()` runs but *before* `fieldFormatter()` and the
  `field_formatter_{type}` filter see `$post_id`. The keep/drop decision
  itself never depended on this swap (it only inspects the field
  definition and the formatted value), but the swap path is exercised by
  its own regression test, since fields sourced from a block behave
  identically to fields sourced from a plain post only if the swap runs
  correctly.

  **Blast radius:** consumers using Twig truthiness (`{% if content.x %}`)
  are unaffected — `false`/`0`/`"0"` were already falsy there whether the
  key existed or not. Consumers using `isset()` / `??` (both blind to an
  explicit `null` value) or `array_key_exists()` (which is not — it treats
  a stored `null` value as present) against a `formatFields()` result will
  now see a key that was previously absent whenever the underlying field is
  a `true_false` field, or its formatted value is `0`, `0.0`, or `"0"`.

## [1.29.0] - 2026-08-05

### Changed

- **`timber_twig()` wires a locale resolver into `TypographyExtension`** so
  `|typography` picks up per-language settings (quote style, dash convention,
  single-character word spacing, …) shipped by `parisek/twig-typography`
  ^1.3, instead of only the language-neutral house defaults. The resolver
  delegates to `Helpers::getLanguage()` (WPML post/current-language filters,
  falling back to `get_locale()`) and is exposed as the overridable
  `StarterBase::typography_locale_resolver()` for themes that detect language
  through something other than WPML.
- **`Helpers::getLanguage()`'s `get_locale()` fallback no longer truncates to
  the bare two-letter language.** `de_CH` now resolves to `de_ch` (was `de`)
  — a region subtag WPML doesn't usually supply itself, but `get_locale()`
  does, and `parisek/twig-typography`'s `LocaleResolver` needs it to reach
  region-qualified typographic tables (`de-CH` Swiss guillemets, `en-GB`
  spaced en-dash) that would otherwise be permanently unreachable. Callers
  that only ever wanted the base language already narrow the result
  themselves (e.g. the read-time WPM map lookup takes the first two
  characters), so this is additive for them and unlocks the region-specific
  path for everyone else.
- Bumped the `parisek/twig-typography` floor from `^1.0` to `^1.3`. Passing the
  new second constructor argument to 1.0–1.2 does **not** fatal — PHP only
  errors on excess arguments to *internal* functions, and this constructor
  is user-land with a default value, so the extra argument is silently
  discarded. That silence is precisely why the floor has to move: an
  under-versioned consumer would get no error, no warning, and no language
  layer, with nothing to signal that per-language typesetting never
  actually engaged.

## [1.28.0] - 2026-08-04

### Added

- **Menu-level metadata from `formatMenu()`** — the returned value now carries the
  menu's own `title`, `name`, `slug`, `description` and `id`, plus any ACF fields
  attached to the `nav_menu` term. A footer column can take its heading from the
  menu name instead of a hardcoded template string, which also makes it
  translatable through WPML's normal per-language menu assignment.

  Backward compatible by construction: the return value is a `MenuData` object
  that iterates, counts, indexes and JSON-encodes exactly as the item list it
  replaced, and empty or missing menus still return a plain `[]` so template
  truthiness guards keep their meaning. An audit of 26 consuming themes found
  140 call sites, 461 `{% if %}` guards and 6 filter uses — none require a change.

  New ACF surface: attach a field group to the **Menu** location and its fields
  appear as properties on the menu (`{{ menu.menu_icon }}`). This needed a
  dedicated resolver — ACF addresses a taxonomy term as `term_<id>`, which the
  generic `formatFields()` id resolution does not produce.

### Fixed

- **`Helpers::formatFields()` resolved taxonomy terms (and users) against post
  ids** — the id-resolution branch handed `get_field_objects()` a bare integer
  (`$post->term_id` / `$post->ID`) for a term or user object, which ACF reads
  as a post id, silently resolving against whatever post happens to share that
  number instead of the actual term/user. Fixed by detecting term and user
  objects *before* the generic `->ID` fallback — `Timber\Term` aliases `->ID`
  to `->term_id`, and `Timber\User` exposes `->ID` just like a post, so both
  previously always matched the post branch first — and passing ACF's own
  `"term_<id>"` / `"user_<id>"` id forms. Detection prefers the `object_type`
  discriminator Timber's own `Term`/`User`/`Post` classes set, with an
  `instanceof \WP_Term` / `instanceof \WP_User` check and (for terms) a
  duck-typed `term_id`-without-`post_type` fallback for plain-object shims
  that don't extend either core class. No known call site is affected: an
  audit of ~90 `formatFields()` call sites across 26 consuming themes found
  none passing a term or user object today.
  ([#103](https://github.com/parisek/timber-kit/issues/103))

## [1.27.0] - 2026-08-02

### Added

- **`post_id` on an `$options_pages` entry** — sets the ACF storage namespace, so
  a theme's options no longer share the default `options_<field_name>` prefix in
  `wp_options` with every other options page on the install. Purely additive:
  omit the key and ACF's own default applies exactly as before.
  ([#100](https://github.com/parisek/timber-kit/issues/100))

  Found on a site carrying a second options page created years earlier through
  ACF's admin UI — a database row, invisible to any grep of the repo. Both sides
  owned a field named `footer`; the theme's own `links`, `announcement`, `social`
  and `exit_popup` groups never surfaced at all, while the footer rendered
  *correctly* from the other page's data because `options_footer_apps_*` happened
  to match the theme's own selector. A name collision reads as success, which is
  what made it expensive to find rather than merely wrong.

  A sub-page **inherits its parent's `post_id`** unless it declares its own, and
  the inheritance is transitive across nesting levels. ACF does not do this —
  `acf_options_page::validate_page()` applies `'post_id' => 'options'` through
  `wp_parse_args` to every page independently, parent or not — and without the
  inheritance a namespaced parent with unmarked children would split one theme's
  settings across two namespaces, with `formatFields()` returning only half of
  them. The read side needed no change: `Helpers::getFieldObjectsForOptions()`
  already matches on namespace.

  Adopting it on a live site is a data migration — stored values stay behind
  under the old prefix.

### Fixed

- **Breeze cache purge missed options pages with a custom `post_id`.**
  `clear_cache_on_options_save()` compared the incoming id against the literal
  `'options'`, but ACF's admin controller saves through
  `acf_save_post( $page['post_id'] )` — so a namespaced page (and, under WPML,
  any language-suffixed id from `acf_get_valid_post_id()`) fired the hook with
  an id the check rejected, and stale pages kept being served after a save. It
  now matches any id that `acf_decode_post_id()` classifies as an options
  namespace. The purge is site-wide either way, so widening the match can only
  flush on another page's save — the safe direction for a cache.

## [1.26.1] - 2026-08-01

### Added

- **README badges** — Packagist version, PHP version, Timber, Tests, License.
  Matches `parisek/definition-kit` and `parisek/acf-json-schema`, which already
  carried the same row; `parisek/styleguide` gains it in parallel.

### Changed

- **`fieldFormatter()` gates the repeater/flexible null-value pass-through on
  `$is_preview`** ([#98](https://github.com/parisek/timber-kit/issues/98)). An
  unfilled `repeater` / `flexible_content` returned its raw ACF field-definition
  array regardless of context. That is the block-preview contract — an unsaved
  block needs `sub_fields` to render a placeholder — but it was ungated, so it
  also fired on ordinary front-end reads, where the definition array reads as a
  populated list: `{% if x and x|length > 0 %}` passes on the definition's own
  28 keys, a template opens its wrapper, and the inner per-row guard then
  suppresses every row. The visible result is empty chrome — a bordered `<ul>`
  with nothing in it — which is why it survived review.

  Measured on a `nav_menu_item` group whose `more_items` repeater was filled on
  10 of 425 items: 67 of 69 items reported a 28-element `more_items`. Options
  pages resolve through the same gate, so an options-page repeater left unfilled
  had the same symptom and is closed by the same change.

  Outside preview the value is now `FALSE`. **Where that lands differs by
  depth:** `formatFields()` prunes on `! empty( $value )`, so a *top-level*
  field drops out of the context entirely — the key is absent. A *nested*
  repeater / flexible_content inside a populated parent row is assigned in
  place by the recursion and is not pruned, so the row carries `links => false`
  rather than omitting the key. Both read as empty in Twig; PHP consumers doing
  `array_key_exists()` on a nested row see `false` where they previously saw the
  definition array.

  **Behaviour change, narrow:** the only affected input is a `repeater` /
  `flexible_content` with `value => null` read with `$is_preview = false`.
  `BlockRenderer::buildContent()` already propagates the flag; a block render
  path that bypasses `BlockRenderer` — a legacy per-theme
  `timber_block_render_callback()` predating the `BlockRenderer` migration — and
  omits it would lose its editor placeholder (editor-only, front end
  unaffected), and is fixed by forwarding the `$is_preview` WordPress already
  hands that callback.

- **Release guard runs every test suite.** `release-stamp.yml` ran
  `composer test`, which is the Unit suite only (`--testsuite=Unit`), so a
  version could be stamped without the property suite's generative assertions.
  It now runs a new `composer check` (`test:all` + `phpstan`, since joined by `adr`) — the property
  suite takes ~1s against Unit's ~18s, so there was no cost reason to omit it.
  Also brings the `check` script in line with the sibling packages.

- **ADR practice unified across the four Composer packages.** `docs/adr/README.md`
  and the `AGENTS.md` § *Architecture decisions* section now carry the same rules
  as `parisek/styleguide`, `parisek/definition-kit` and `parisek/acf-json-schema`,
  gaining two this repo lacked: an ADR of a sibling repo is cited qualified
  (`tailwind-base ADR-0007`, never a bare number — the numbering spaces are
  per-repo), and every ADR must appear in the index.

  `scripts/check-adr-index.py` (`composer adr`, CI job *docs/adr/ index is in
  sync*, also folded into `composer check`) enforces the second: it fails on an
  ADR missing from the index, a duplicate number, a dangling index entry, or an
  off-convention filename.

### Removed

- **`docs/adr/2026-05-24-breadcrumb-design.md`** — a 429-line draft still marked
  *"awaiting user approval"*, sitting in `docs/adr/` off the `NNNN-kebab-title.md`
  convention and absent from the index. It is the design document that produced
  [ADR-0002](docs/adr/0002-breadcrumb-design.md), which is the accepted, indexed
  record of that decision; the draft remains in git history. It is also the
  finding that motivated the new check.

## [1.26.0] - 2026-07-20

### Added

- `$wpml_theme_domain_authoritative` StarterBase flag (**default on**, same deliberate exception as `$wpml_skip_empty_translation_job_fields`): keeps the theme's gettext text-domain authoritative over WPML String Translation by runtime-injecting it into `icl_sitepress_settings['st']['wpml_st_auto_reg_excluded_contexts']` via a filter on `option_icl_sitepress_settings` — no `update_option()` write, nothing persisted. Without it, WPML ST scans the theme's compiled `.mo`, registers every string, and compiles its own overriding `wp-content/languages/wpml/<domain>-<locale>.mo` that WPML's Just-In-Time MO loader serves instead of the theme's own `.mo` — so editing the theme's `.po` and rebuilding has no visible effect, the corrected string keeps rendering its stale WPML-compiled value (hit in production on fellows, 2026-07-20). No-ops without WPML. Opt out with `false` in the project's `Base` if a project wants translators managing theme strings through WPML ST instead of the `.po`/`.mo` pipeline. **Note for existing sites:** this is a default-on behavior change — sites that were relying on WPML ST to translate theme strings will see those strings stop being registered/compiled by WPML once they upgrade; see the `wpml-cleanup-theme-domain` command below for the migration path.
- `wp timber-kit wpml-cleanup-theme-domain` WP-CLI command (`--dry-run`, `--yes`, `--domain=<domain>`): the migration companion to `$wpml_theme_domain_authoritative`. Removes leftover WPML String Translation rows (`icl_strings`, `icl_string_translations`, `icl_string_positions`) and deletes the stale compiled `wp-content/languages/wpml/<domain>-*.{mo,l10n.php,json}` files left behind for a text domain that's now excluded from ST — so the theme's own `.po`/`.mo` pair is unambiguously the only place translators and developers need to look. Verified against a real site (fellows): 102 `icl_strings`, 69 `icl_string_translations`, and 27 `icl_string_positions` rows safely removed.

## [1.25.0] - 2026-07-16

### Added

- `wp timber-kit acfml-sync-preferences` deploy-time command reconciling WPML's `custom_fields_translation` dictionary with ACF field definitions: walks postmeta of translatable post types, resolves each key via its `_<key>` field-key companion, and registers the exact key with the definition's `wpml_cf_preferences`. Closes the ACFML materialisation gap where programmatically-written meta (importers, WPML duplication, direct writes) never reaches translation jobs (hit in production on fellows, 2026-07-16 — an entire flexible content of ~47 translatable fields per post missing from every job). Dry-run by default (`--apply` to write), idempotent, patch-only merge, conflicting preferences reported and skipped. Note: applying newly-translatable keys flags affected translations as needing update via WPML's ProcessNewTranslatableFields — the intended surfacing of the invisible backlog.

## [1.24.0] - 2026-07-16

### Added

- `$wpml_skip_empty_translation_job_fields` StarterBase flag (**default on** — a deliberate exception to the default-off flag doctrine, same as `$clear_cache_on_menu_update`): downgrades empty translatable fields to copy-only in WPML translation job packages via `wpml_tm_translation_job_data`. Empty source segments (an ACF `link` field's empty `target` sub-key marked translatable, an empty excerpt) become hidden ATE trans-units the translator cannot fill; ATE exports them without a `<target>` element and WPML rejects the whole XLIFF on delivery with "The uploaded xliff file does not seem to be properly formed. Missing or wrong data: target", so the completed translation silently never applies (hit in production on fellows, 2026-07-16). Default-off would make every consumer rediscover the silent loss independently; an empty field has nothing to translate, so no translator work is removed. Opt out with `false` in the project's `Base` if a project fills targets for intentionally empty sources in the classic (non-ATE) editor.

## [1.23.1] - 2026-07-15

### Fixed

- `UpdateContext::transformBlocks()` now passes `wp_slash()`ed content to `wp_update_post()`. Without it, `wp_update_post()`'s internal `wp_unslash()` stripped every backslash from the serialized block JSON, so `\u003c`/`\u0026` escapes in ACF block attributes rendered as literal `u003c`/`u0026` text on the front end after any block-data migration (hit in production on mairateam, 2026-07-15). Regression test included.

## [1.23.0] - 2026-07-14

### Added

- Native always-on video enrichment in `formatFields`: formatted video file values now include additive `codecs` keys, and repeater rows carrying a video now receive an additive `sources` cascade. Existing keys stay unchanged, `sources` never overwrites an existing key, and this supersedes the opt-in flag design discussed in #82.

## [1.22.0] - 2026-07-14

### Added

- Drupal-style update runner (`wp timber-kit updates status|run`) for run-once content/data migrations discovered from theme-wide and component-local `updates/NNNN-slug.php` files. Updates use `<component>:<NNNN>` ids, record completions in the autoload-off `timber_kit_updates_applied` option, support `--dry-run`, `--component`, and `--only`, and include WPML-aware block transforms that fan out across translations; intended as the WordPress/Timber `drush updb` analog for deploy-time data shape changes.

## [1.21.0] - 2026-07-14

### Changed

- **Video source API redesigned: bare `codecs` split from the mime type** (supersedes the 1.20.0 API, which had no consumers). `Helpers::videoSourceType()` (combined `video/mp4; codecs="…"` string) is replaced by `Helpers::videoCodecs()` returning the bare RFC 6381 string (`av01.0.01M.08`) or null; `VideoCodecs::sourceType()` is renamed to `VideoCodecs::codecsString()` and returns the bare value. `Helpers::formatVideoSources()` entries are now `{src, type, codecs}` with `type` a plain comparable mime. Rationale: the mime stays printable in double-quoted attributes and comparable in templates, and the codecs value is independently inspectable; templates compose `type='{{ type }}{% if codecs %}; codecs="{{ codecs }}"{% endif %}'`. Attachment-meta cache key moves to `_timber_kit_video_codecs` (`none` sentinel for negative results).

## [1.20.0] - 2026-07-14

### Added

- `Helpers::videoSourceType()` and `Helpers::formatVideoSources()` for video `<source>` data: AV1 MP4 attachments now derive RFC 6381 `codecs` values from the local file instead of hardcoding `av01.0.05M.08`, cache the computed type in attachment meta, and fall back to the stored mime type for non-AV1 MP4/WebM variants.
- Full Breeze page-cache flush on nav menu save (#76): new `StarterBase::$clear_cache_on_menu_update` flag wiring `wp_update_nav_menu` → `clear_cache_on_menu_update()`, mirroring the existing options-save flush (`breeze_clear_all_cache`, guarded by `has_action()` so it no-ops without Breeze). Menus render on every page, so a site-wide flush is the correct scope. Default **on** — a deliberate exception to the default-off flag doctrine, consistent with the unconditional options-save flush it mirrors; opt out with `false` in the project's `Base`.
- Breeze Cache Warmup sitemap feed (#74): new `BreezeWarmupSitemap` module hooks `breeze_preload_urls` and merges in every same-host URL from the site's XML sitemap (AIOSEO `/sitemap.xml` first, core `/wp-sitemap.xml` fallback; sitemap indexes are followed recursively, bounded by sub-sitemap count and depth caps, and every dereferenced URL — root, sub-sitemap, or page — is validated as an absolute http(s) same-host URL before fetching, closing off SSRF via a malicious index; redirects are disabled on every fetch). Activation is opt-in: new `StarterBase::$breeze_warmup_sitemap` flag (default `false` — flipping the warmup queue from ~30 URLs to up to `timberkit_warmup_sitemap_max_urls`, default 200, is a real behavior change for a project with Breeze's warmup checkbox already on) plus Breeze being active; runtime kill switch even when the flag is on via `add_filter( 'timberkit_warmup_sitemap_enabled', '__return_false' )`. The `breeze_preload_urls` filter callback never fetches — it only reads a last-known-good URL list from a `wp_options` row and, when that list is missing or older than 1h, schedules a `wp_schedule_single_event()` background refresh (guarded by a short lock so concurrent purges don't queue duplicate jobs). A failed or empty refresh never overwrites the last known good list. Sitemap responses are gzip-aware (`.gz` sub-sitemaps).

## [1.19.0] - 2026-07-11

### Added

- utf8mb4 charset audit (phase 1+2 of #72): new `utf8mb4_tables` Site Health check (category `database`) auditing prefix-scoped tables via `information_schema` — non-utf8mb4 tables (incl. the `utf8`→utf8mb3 alias), column-collation overrides, mixed utf8mb4 collations — with the database's dominant utf8mb4 collation suggested as the convert target. Plus `wp timber-kit convert-utf8mb4`: dry-run by default, `--apply` refuses to run without an explicit `--tables=<csv>` selection (or a conscious `--all`), target collation is the dominant one already in the DB (never hardcoded, `--collate=` overrides), and COMPACT/REDUNDANT tables with long indexed columns are flagged behind `--force` (767-byte index-prefix limit). Analysis lives in pure, unit-tested `Health\Db\CharsetAudit` + `Health\Db\ConversionPlan`.

## [1.18.0] - 2026-07-11

### Added

- Site Health board (phase 1 of #41): extensible health-check registry under `Parisek\TimberKit\Health\` (`HealthCheck` interface, `Result` value object, `CheckRegistry`, `SiteHealthAdapter`), seeded with five security checks (XML-RPC disabled, WP version hidden, author sitemap disabled, file editing disabled, REST users endpoint restricted via anonymous loopback). Opt-in via new `StarterBase::$site_health` flag (default `false`); projects customize through the `health_checks()` override (primary) or the `timber_kit_health_checks` filter (runs after the override). Read-only by design — the board verifies effective state against expectations declared in code and never writes.

## [1.17.0] - 2026-07-09

### Changed

- **BREAKING (behavioral):** `StarterBase::$disable_application_passwords` now
  defaults to `false` — WP Application Passwords stay **available** out of the
  box. They are the authentication mechanism for REST/MCP integrations
  (portadesign-mcp), which every project is expected to adopt. Sites that
  relied on the old hardened default must opt back in explicitly:
  `protected bool $disable_application_passwords = true;` in the theme `Base`.
  The rest of the security-hardening surface is unchanged.

## [1.16.1] - 2026-07-07

### Fixed

- `Helpers::fieldFormatter()`: a surfaced ACF field object with no saved value
  (options-page group present in the local store but never filled — `value`
  key missing or null) leaked the raw field-definition array into templates —
  string filters like `|typography` then fatalled, surfacing as a misleading
  "Component template not found" fallback. Valueless fields now read as empty
  for every type (missing `value` key included); repeater/flexible keep their
  documented pass-through only for an explicit `value => null` (block preview).

## [1.16.0] - 2026-07-04

### Added

- **`Helpers::formatAnnouncement( ?array $value ): array`** — formats an announcement-bar ACF group (`enabled`, `text`, `dates.date_from`/`dates.date_to` as date_picker "U" timestamps) into a Twig/Alpine-ready shape. Re-anchors the midnight-UTC timestamps to `wp_timezone()` day bounds (00:00:00 for `date_from`, 23:59:59 for `date_to`) and returns millisecond timestamps for JS consumption; disabled or absent input yields the empty shape (`text: '', 0, 0`). Replaces the byte-identical private `get_announcement()` carried by four downstream projects and `starter_theme` — those now reduce to `Helpers::formatAnnouncement( $global_fields['announcement'] ?? null )`.

## [1.15.0] - 2026-07-04

### Added

- **`StarterBase::$restrict_allowed_blocks` flag (bool, default `true`)** — set `false` to skip wiring the `allowed_block_types_all` filter entirely, so the editor keeps all block types. For sites whose existing content pre-dates the `$allowed_core_blocks` allowlist, where restricting after the fact would flag already published blocks as invalid. Replaces the no-op `allowed_block_types_all()` override three downstream projects carry today. Default `true` preserves current behavior.

- **`StarterBase::$render_block_passthrough_blocks` (string[], default `[]`)** — block names `render_block()` must return unchanged, bypassing the core-block wrapper. Accepts exact names (`'wpforms/form-selector'`), a namespace wildcard (`'wpforms/*'`), or `'*'` to disable the wrapper entirely. Passthrough wins over the forced contact-form wrapping. Escape hatch for third-party form/gallery blocks the wrapper breaks (WPForms, Envira, CF7 variants) and for legacy-content sites — both previously required overriding the whole `render_block()` method downstream.

- **`StarterBase::$context_privacy_policy` flag (bool, default `false`) + `$privacy_policy_context_key` (string, default `'ccnstL'`)** — opt-in population of `get_privacy_policy_url()` into the Timber context. Every audited downstream project sets this key manually in its `timber_context()` override (the non-semantic default key keeps cookie-consent markup invisible to ad-block heuristics); enabling the flag replaces that boilerplate. Off by default per the library's flag doctrine — the key typically drives a cookie-consent partial, which must not start rendering on projects that deliberately ship without one.

- **`Helpers::relabelPostType( string $post_type, array $labels )`** — merge custom labels onto a registered post type (the "rename built-in `post` to a domain term" boilerplate found copy-pasted in 12 downstream article controllers, 8 of them with untranslated starter values). Applies immediately when `init` already fired, otherwise defers to `init` priority 999; keeps the top-level `label` in sync with `labels.name`.

- **`Helpers::hideTaxonomyMetaFields( string $taxonomy = 'category', array $fields = ['description', 'slug', 'parent'], bool $hide_columns = true )`** — hide taxonomy meta fields on add/edit screens (CSS over `.term-{field}-wrap`) and drop the matching list-table columns. Replaces the recurring `manage_edit-{tax}_columns` + `{tax}_edit_form`/`{tax}_add_form` hook trio duplicated across 8–10 downstream projects.

## [1.14.1] - 2026-06-25

### Fixed

- **Resizer animation detection hardened so animated AVIF/WebP/GIF are never silently flattened (#61).** Refines #60's passthrough. `resizer()` now detects an animated source from a **union** of two signals — Imagick's decoded frame count **and** a structural byte-sniff (`avis` ftyp brand / WebP VP8X `ANIM` flag / GIF image-descriptor walk) — and passes the original through untouched (no re-encode, animation preserved). The union matters because Imagick alone can mislead: a backend that *under-decodes* an animated container to its primary frame reports a single frame, which a frame-count-only check treats as static and flattens. This is not hypothetical — animated AVIF **image-sequences** (`avis` brand, dozens of frames) decode to one frame through Imagick on **both** a libheif 1.19.8 dev box **and** an ImageMagick 7.1.2-8 production box; #60's passthrough never fired for them, so only a manual workaround preserved the animation. Consulting the sniff whenever Imagick sees ≤1 frame closes that gap. Public surface is unchanged from #60: `StarterBase::$resizer_skip_animated` / the `timber_kit_resizer_skip_animated` filter still default to `true` (passthrough); set `false` to opt out (animated sources flow into the normal resize pipeline). **Resizing animated sources while preserving animation (multi-frame re-encode) is intentionally out of scope** — no commonly-available ImageMagick build decodes these AVIF sequences as multi-frame anyway, so passthrough is the correct outcome; capability-gated multi-frame resize remains tracked in #61.

## [1.14.0] - 2026-06-25

### Changed

- **`StarterBase::$admin_resizable_sidebar` now defaults to `false`** (was `true`) — the resizable Gutenberg editor sidebar is now **opt-in**. Its JS/CSS ship inside the package and are served from `vendor/` via `packageAssetUrl()`, but the standard theme `.htaccess` blanket-denies `vendor/` (`RewriteRule ^vendor/(.*)?$ / [F,L]`). The old default therefore made **every** consumer's block editor request those assets and receive **403** — the README's "works out of the box" claim was wrong in the presence of the skeleton `.htaccess`. ⚠️ **Behavior change**: themes that want the sidebar must now set `$admin_resizable_sidebar = true` **and** add an allow rule for static assets under `vendor/` to the theme `.htaccess` (snippet in README § Resizable sidebar). `wordpress-base`'s `starter_theme` ships that allow rule so scaffolded projects only need the flag. Surfaced from downstream project `pm-a`.

- **HSTS now always carries `; preload`** when `$security_headers` is on and the request is over TLS — the header is `max-age=31536000; includeSubDomains; preload` (previously without `preload`). This package targets an HTTPS-only fleet, so `preload` is the house default rather than an opt-in flag. ⚠️ `preload` is a hard-to-reverse commitment: it advertises the domain **and every subdomain** for browsers' built-in HSTS preload list (separate submission at [hstspreload.org](https://hstspreload.org)). On the rare project that serves — or might serve — a non-HTTPS subdomain, override the value via `$security_headers_config['Strict-Transport-Security']` (the existing per-header escape hatch).

### Added

- **`StarterBase::$warn_duplicate_security_headers` flag (bool, default `true`)** + a Site Health test (**Tools → Site Health**) that warns when the live response carries a managed security header **more than once** — the signature of a second, server-level source (an Apache `.htaccess` `mod_headers` block, an nginx `add_header` directive, or a security plugin) emitting the same headers `security_headers()` already sends. Only registered when `$security_headers` is on. Mirrors the existing redundant-plugin Site Health pattern.

  **Why a Site Health check and not an inline guard:** the theme's PHP layer *cannot* see — let alone de-duplicate — a header added by the web server, because `mod_headers` / `add_header` run **after** PHP has returned the response (`headers_list()` only reports PHP-set headers). The duplicate is therefore invisible during the request and can only be detected out-of-band, by inspecting the fully-assembled response. The test does one cache-bypassing loopback request to the home URL, counts occurrences of each comma-free managed header (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, X-XSS-Protection — comma-bearing CSP / Permissions-Policy are excluded so a single header is never mis-split into a false duplicate), and caches the result in a 10-minute transient so neither repeated Site Health views nor the weekly scheduled check refetch on every run. A loopback that fails (WP_Error, non-2xx, or an unusable headers object) is reported as "could not verify" and is **not** cached — never mis-reported as a verified-clean response. Zero front-end overhead — the loopback runs only in the admin / weekly cron. Surfaced from a downstream project (`pm-a`) where a legacy `.htaccess` security block emitted `Strict-Transport-Security`, `X-Frame-Options`, etc. alongside the theme's `$security_headers`, producing duplicate/conflicting response headers (`Multiple HSTS headers`) that broke an HSTS-preload scanner.

## [1.13.0] - 2026-06-24

### Added

- **`Helpers::formatTerms()` now returns `count`** — each formatted term carries `'count' => (int) $term->count` (the term's object count) alongside `id` / `title` / `url` / `children`. Additive and backward-compatible: existing consumers reading the previous keys are unaffected. Lets callers that render term lists with a count (e.g. category-filter chips "SEA (18)") use the helper directly instead of querying `Timber::get_terms()` and reading `->count` by hand.

### Fixed

- **The resizer no longer flattens animated AVIF / WebP / GIF to a single frame — and this is now the default behaviour.** The resize pipeline is single-frame (Spatie\Image / Imagick `writeImage()`), so re-encoding an animated source dropped every frame but the first: each `|resizer` variant came out a frozen still. `resizer()` now detects an animated source and passes the **original through untouched** (served at full size — cropping/scaling becomes the consumer's CSS job); static images of the same formats are still optimized.

  ⚠️ **Behaviour change on upgrade (intentional, on by default).** This ships **on by default** (`StarterBase::$resizer_skip_animated = true`, and the `timber_kit_resizer_skip_animated` filter defaults to `true` for non-StarterBase consumers) — a **deliberate exception to the usual default-off feature-flag rule**, because the old behaviour was a bug (it destroyed animations), not a feature worth preserving silently. Any page already serving an animated image through `|resizer` will switch from a flattened resized variant to the unresized original on upgrade. To restore the legacy re-encode (e.g. on a backend that can write animated output — see [#61](https://github.com/parisek/timber-kit/issues/61)), set `protected bool $resizer_skip_animated = false;` in your `Base`, or `add_filter( 'timber_kit_resizer_skip_animated', '__return_false' )`.

  Detection runs only for animatable containers (still JPEG/PNG add zero overhead): an Imagick frame-count probe when the extension is usable, else a backend-independent, structurally-parsed byte sniff (WebP VP8X animation flag, AVIF `avis` ftyp brand, GIF image-descriptor block walk). Surfaced downstream when a theme migrated from a local resizer that excluded AVIF from its allow-list (passing animated AVIF through by accident) to this package's resizer, which decodes AVIF and therefore re-encoded it.

## [1.12.0] - 2026-06-20

### Added

- **`StarterBase::$options_pages` property (array)** — declarative config for the ACF options page(s), replacing the previously hard-coded single "Theme Settings" page. Each entry is one page: `menu_slug` + `page_title` are required; optional per-entry keys are `parent_slug` (registers the entry as a sub-page via `acf_add_options_sub_page`; top-level pages are registered first so list order doesn't matter, and the function is `function_exists`-guarded), `capability` (default `edit_posts`), `icon_url` (top-level pages only, default `dashicons-admin-generic`), and `admin_bar` (bool, default off — mark any page(s) — including multiple — to appear in the admin bar; the default "Theme Settings" page is marked by default). Defaults to a single "Theme Settings" page (no behavioural change). Set `$options_pages = []` to register no options pages at all — completely disables the feature.
- **`StarterBase::$admin_resizable_sidebar` flag (bool, default `true`)** — toggles the resizable Gutenberg editor-sidebar feature; its JS/CSS ship inside the package (see _Changed_ below), so it works out of the box. Set `false` to disable it.
- **`StarterBase::$autopopulate_breadcrumb` flag (bool, default `true`)** — when `false`, `timber_context()` skips auto-populating `$context['breadcrumb']` with a `Parisek\TimberKit\Breadcrumb`, for themes that build breadcrumbs themselves or don't render them. The legacy `! class_exists('\Breadcrumb')` escape hatch still applies on top of the flag.
- **`StarterBase::$theme_script_strategy` property (string, default `'module'`)** — selects how the theme JS bundle (`static/dist/js/script.js`) is enqueued. `'module'` uses `wp_enqueue_script_module()` (correct for a Vite/ESM build — the default); `'defer'` uses a classic `wp_enqueue_script()` with `strategy=defer` for a webpack IIFE bundle (loading an IIFE as `type="module"` changes execution mode — deferred + module scope + strict — and breaks it). Both `assets()` and `enqueue_block_editor_assets()` route through a single overridable `enqueueThemeScript()` method, so a subclass needing finer control (dependencies, async, a different handle) overrides one place.

### Changed

- **Asset enqueues no longer emit a PHP warning on a missing build artifact.** A new internal `assetVersion()` helper returns a file's `filemtime` as a cache-busting string, or `null` when the file is absent — so a not-yet-built or intentionally-unbuilt asset (`style.min.css`, `gutenberg-editor.css`, the editor scripts) degrades to an unversioned enqueue instead of a `filemtime(): No such file or directory` warning. `resolveThemeName()` now returns a guaranteed string and `$theme_name`'s docblock is corrected to `@var string`, which let the PHPStan baseline shrink from 152 to 125 entries (the string-vs-string|false noise was resolved at the source, not baselined).
- **The resizable Gutenberg editor sidebar assets now ship inside the package** (`assets/js|css/gutenberg-resizable-sidebar.*`) and are enqueued from the package URL via a new `packageAssetUrl()` resolver, instead of from each theme's `/admin/` directory. Consumer themes no longer need to vendor these identical files; `$admin_resizable_sidebar` (default `true`) still toggles the feature. The URL resolves wherever the package is installed under `wp-content`. The resolver is `protected` so subclasses can override it for non-standard hosting, applies `realpath()` for symlinked vendor dirs, and slash-terminates the wp-content boundary check to avoid false matches against paths like `/wp-content-other`. A `is_file()` guard before the enqueue prevents a PHP warning and a 404 on incomplete installs.

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
