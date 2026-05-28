# WpmlBlockOverride — runtime Copy-field sync for ACF Gutenberg blocks

**Status:** Accepted
**Date:** 2026-05-28
**Author:** Petr Parimucha + Claude
**Triggered from:** atelier99 reference implementation ([portadesign/atelier99#14](https://github.com/portadesign/atelier99/pull/14))

---

## 1. Problem

When an editor changes the value of a field marked **Copy** (`wpml_cf_preferences = 1`) in the source language — typically an image or file in an ACF Gutenberg block — the change **does not propagate to the frontend of translated pages**. Translated `post_content` keeps the old attachment ID indefinitely.

### Why

1. Block instances are serialised into `post_content` as HTML comments (`<!-- wp:acf/<name> {"data":{…}} -->`). Each language has its own copy of `post_content` with its own block instances.
2. WPML marks a translation as `needs_update` only when *translatable* content (text) changes. A change in a Copy field (image, file) does **not** trigger this flow.
3. Even if it did, the translated `post_content` is only rewritten during an explicit ATE import job — never on save.

This is a documented WPML pain point across multiple forum threads since 2019. WPML's official workaround is "re-process the translation through Translation Editor for every Copy field change" — not realistic for an editorial workflow.

### Reviewed prior art (insufficient)

| Mechanism | Why insufficient |
|---|---|
| `acfml_field_group_mode_field_translation_preference` filter | Changes default preference only, not runtime values |
| `acfml_should_translate_acf_entity` filter | Binary gate, not a sync mechanism |
| `wpml-config.xml` `<gutenberg-blocks>` | Handles ID remapping at render, but doesn't pull source values — only translates IDs *already* in translation `post_content` |
| WPML "Translate Everything Automatically" | Only triggers on translatable changes; Copy-field changes don't trigger |
| `vitaliikaplia/wp-loc` plugin | Opposite paradigm (write-time sync); replaces WPML entirely |

See [issue #29](https://github.com/parisek/timber-kit/issues/29) for the full research and decision log.

---

## 2. Goal

Make **ACF configuration the single source of truth for Copy fields**. At render time, for ACF blocks rendered in a non-default language, override `attrs.data.<field>` for Copy-marked fields with the source-language post's value. No DB writes. No admin UI. No drift.

The Translate flow (`wpml_cf_preferences = 2`) stays entirely handled by ACFML/ATE — unchanged.

---

## 3. Design

### Single `final class` in the kit's flat namespace

`Parisek\TimberKit\WpmlBlockOverride` — matches the kit's existing `BlockRenderer` / `Breadcrumb` pattern (one class per feature, no sub-namespaces until a domain has 2+ classes per RFC [#30](https://github.com/parisek/timber-kit/issues/30)).

### Hook strategy

```
render_block_data filter (priority 20 — after WPML's own handlers)
  ↓ shouldOverride()      bypass non-ACF / admin / REST / default language
  ↓ getCopyFields()       cached index of {block_name → copy_fields[]}
  ↓ getSourcePostId()     wpml_object_id → default-language post id
  ↓ getSourceBlocks()     parse_blocks() on source post_content, memoized
  ↓ findSourceBlock()     match by attrs.id (safe degrade if missing)
  ↓ applyCopyFields()     overwrite attrs.data + remap attachment IDs
  → return modified block to WP render pipeline
```

### Cache architecture

| Layer | Scope | Invalidation |
|---|---|---|
| Per-request memo (`$copyFieldsIndex`) | Single PHP request | Automatic on request end |
| Persistent transient (`timber_kit_wpml_copy_fields_index`) | 24h TTL | `acf/update_field_group` + `save_post_acf-field-group` actions |
| WP_DEBUG bypass | Dev environments | N/A — transient skipped entirely |

The persistent transient holds **field metadata** (`{block_name → copy_fields[]}` with `wpml_cf_preferences = 1`), not content. Content is read fresh each request through `parse_blocks(get_post($id)->post_content)` and memoized only per-request.

### Hook priority decision

Priority **20** (not the default 10) so we run *after* WPML's own `render_block_data` handlers (documented as "highest priority"). Ensures our overrides aren't reverted by later filters.

---

## 4. Scope and non-goals

**Supported:**
- Top-level Copy fields (image / file / gallery / scalar)
- Repeater sub-field Copy at arbitrary nesting depth (`steps_N_image`, `faq_sections_N_items_M_title`, …)
- Group sub-field Copy (`contact_email` inside a `contact` group)
- Mixed nesting (repeater × group × repeater)
- Attachment ID remap to per-language duplicate via `wpml_object_id`

**Not yet supported:**
- `flexible_content` sub-fields (per-layout `sub_fields` require layout-name awareness)
- REST API output (`render_block_data` doesn't fire for raw REST responses; out of scope for server-rendered themes)

**Documented limitations:**
- Cache invalidation does **not** fire for programmatic field registration via `acf_add_local_field_group()`. Code-only changes to `wpml_cf_preferences` serve stale cache for up to `CACHE_TTL`. WP_DEBUG bypasses the transient entirely in dev. Production workaround: `wp transient delete timber_kit_wpml_copy_fields_index` in deploy script, or include a theme-version constant in the cache key.

---

## 5. Public API

```php
\Parisek\TimberKit\WpmlBlockOverride::register();
```

Registers two hooks:
- `render_block_data` (priority 20) → `filter()`
- `acf/update_field_group` + `save_post_acf-field-group` → `invalidateCopyFieldsCache()`

Early-returns if either WPML (`ICL_SITEPRESS_VERSION`) or ACF (`acf_get_field_groups`) is not present.

### Filters exposed (package-owned, stable across versions)

| Filter | Args | Purpose |
|---|---|---|
| `timber_kit/wpml_block_override/should_override` | `(bool $default, array $block, string $current_lang, string $default_lang)` | Per-block veto from theme code. Default `true` after all bypass guards passed. |
| `timber_kit/wpml_block_override/copy_fields` | `(array $copy_fields, string $block_name)` | Extend or trim the Copy field discovery for a block — applied after `walkFields()` returns. |

---

## 6. Testing

The kit's existing PHPUnit + Brain Monkey harness is used. Tests live in `tests/Unit/WpmlBlockOverride/`:

- `ApplyCopyFieldsTest` — top-level Copy / Translate preservation / no-op / scalar guards / repeater (single + nested) / zero-row skip (9 tests)
- `WalkFieldsTest` — underscore skip / repeater descent / two-level descent / flexible_content skip (4 tests)
- `GetCopyFieldsIndexTest` — index memoization, WP_DEBUG bypass (1 active + 1 skipped — see deviation below)

Real-world fixtures pulled from a sibling project (fellows.ddev.site) so the repeater handling tests run against actual block JSON shapes:
- `how-it-works.json` — single-level repeater with image sub-field
- `package-list.json` — single-level with text / url / group sub-fields
- `faq-group-nested.json` — two-level nested repeater
- `service-overview.json` — varied structure

### Deviation: WP_DEBUG bypass test is skipped

PHP constants cannot be redefined and the kit's `tests/bootstrap.php` defines `WP_DEBUG = false` for every test process. The bypass branch (`$skip_transient = \defined('WP_DEBUG') && WP_DEBUG`) therefore evaluates to `false` in the harness. The behavior is verified by integration testing in atelier99 instead. Could be enabled in a separate `phpunit-debug.xml` profile with `<php><const name="WP_DEBUG" value="true"/>` — left for a follow-up if the gap matters in CI.

---

## 7. Migration path for kit consumers

Wire from your theme's `functions.php`:

```php
add_action( 'init', static function (): void {
    if ( class_exists( \Parisek\TimberKit\WpmlBlockOverride::class ) ) {
        \Parisek\TimberKit\WpmlBlockOverride::register();
    }
} );
```

For projects with existing inline copies (e.g. atelier99 during the Timber 1 → 2 transition): when adopting the kit version, **delete the inline copy in the same commit** that adds the `require: parisek/timber-kit` constraint. Otherwise classmap autoload may collide with PSR-4 autoload.

---

## 8. Cross-references

- Reference implementation: [portadesign/atelier99#14](https://github.com/portadesign/atelier99/pull/14)
- Research, prior art, design discussion: [#29](https://github.com/parisek/timber-kit/issues/29)
- Long-term `src/` structure RFC: [#30](https://github.com/parisek/timber-kit/issues/30)
