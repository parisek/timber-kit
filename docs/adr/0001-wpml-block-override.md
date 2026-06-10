# 0001. Sync ACF Copy fields into translated blocks at render time

## Context

When an editor changes a field marked **Copy** (`wpml_cf_preferences = 1`) —
typically an image or file in an ACF Gutenberg block — in the source language,
the change never reaches the frontend of translated pages. The translated
`post_content` keeps the old attachment id indefinitely.

The cause is structural. Block instances are serialised into `post_content` as
HTML comments, and each language owns its own copy. WPML only flags a
translation `needs_update` when *translatable* (text) content changes; a Copy
field change does not trigger that flow, and even when it does, translated
`post_content` is only rewritten during an explicit ATE import job — never on
save.

This is a long-standing WPML pain point. The reviewed prior art was all
insufficient: the `acfml_*` preference filters change defaults not runtime
values; `wpml-config.xml` `<gutenberg-blocks>` only remaps ids already present
in the translation; "Translate Everything" only fires on translatable changes.
WPML's own workaround — reprocess every translation through the Translation
Editor on each Copy change — is not viable for an editorial workflow. Full
research and decision log: parisek/timber-kit#29.

## Decision

Make **ACF configuration the single source of truth for Copy fields**, applied
at render time. `Parisek\TimberKit\WpmlBlockOverride` hooks `render_block_data`
(priority 20, after WPML's own handlers) and, for ACF blocks rendered in a
non-default language, overwrites `attrs.data.<field>` for Copy-marked fields
with the source post's value, remapping reference ids to their target-language
equivalents via `wpml_object_id`. No DB writes, no admin UI, no drift. Translate
fields (`wpml_cf_preferences = 2`) stay entirely with ACFML/ATE.

A translation block is paired to its source counterpart by **block name +
ordinal position** (the Nth occurrence of a name maps to the Nth in the source),
because no stable per-instance id exists in serialised `attrs` at this filter
stage. The per-name counter resets at the start of each `the_content` pass so a
secondary `do_blocks()` run cannot desync it. Before matching, a
**structural-integrity gate** verifies the source and translation hold the same
count of that block name; on any mismatch the whole name is skipped — a safe
no-op. Copy-field metadata is cached in a transient invalidated on field-group
save; block content is read fresh each request and memoised per-request only.

## Consequences

- Translated pages reflect source Copy values with zero editor action and no DB
  drift. Supported: top-level and nested repeater/group Copy at arbitrary depth;
  reference remap for attachment / post / term ids.
- Not supported: `flexible_content` sub-fields (need layout-name awareness) and
  REST output (`render_block_data` doesn't fire for raw REST).
- Cache invalidation does **not** fire for programmatic
  `acf_add_local_field_group()`; code-only changes to `wpml_cf_preferences`
  serve stale cache up to the TTL (`WP_DEBUG` bypasses the transient in dev).
  Production workaround: clear the transient on deploy.
- Equal-count-but-manually-reordered translations are skipped rather than
  risk landing a Copy value on the wrong instance — an accepted limitation.
- Guard: `tests/Unit/WpmlBlockOverride/` (ApplyCopyFields, WalkFields,
  GetCopyFieldsIndex). The `WP_DEBUG`-bypass branch can't be exercised in the
  harness (the constant is fixed `false` for the whole run) — it's covered by
  integration testing in the atelier99 reference implementation (atelier99#14).
