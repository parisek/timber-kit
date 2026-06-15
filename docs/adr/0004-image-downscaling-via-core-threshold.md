# 4. Drive image downscaling through core's threshold; never delete originals on upload

- **Status:** Accepted
- **Date:** 2026-06-15
- **Deciders:** @parisek

## Context

`StarterBase` shipped an in-theme image downscaler (`resize_uploaded_image()`,
hooked to `wp_handle_upload`) that replaced the standalone *imsanity* plugin:
on upload it resized the file in place to `max_upload_width` × `max_upload_height`
(default 2560), discarding the oversized original to save disk.

A `pm-a` client reported that raising those to `4000` was **not honoured in
production** — uploads still appeared capped at ~2560 px. Source-reading WP core
explained why:

- `wp_handle_upload` fires **before** `wp_create_image_subsizes()`. Our hook
  shrank the file to 4000, then core's own `big_image_size_threshold` (default
  **2560**) re-scaled it down again and served that `-scaled` derivative. Two
  downscalers fighting; core's ran last and won.
- `wp_handle_upload` only covers the classic media-library path. Uploads via
  REST, WP-CLI, or programmatic `media_handle_sideload()` never hit our hook and
  were never downscaled at all.

So the in-theme resize was both **redundant** with a core mechanism and
**incomplete** relative to it. The obvious fix — drive core's
`big_image_size_threshold` filter directly — raised a second question: imsanity's
value was *deleting the oversized original* to reclaim disk. Core 5.3+ instead
*keeps* the original (the `original_image` meta + "Restore original image"
feature). Could we keep deleting it?

Deep research (WP core docs, `wp_create_image_subsizes()`, Trac #47873;
adversarially verified 3-0) answered: **no, not on upload.** WordPress
regenerates *every* intermediate sub-size from the preserved **original**, not
from the `-scaled` file — explicitly "for best quality." Deleting the original
on upload silently degrades any later regeneration (a newly-registered crop
size, a retina variant, `wp media regenerate`) to double-compressed output
sourced from the already-compressed `-scaled` image. For a *theme framework*,
where adding crop sizes mid-life is routine, that is a footgun aimed at the
whole fleet. A further subtlety: the `original_image` key is **not** a reliable
"this was downscaled for size" flag — core also sets it for EXIF rotation
(`-rotated`) and format conversion, whose originals must never be pruned.

## Decision

Split the concern in two. **Downscaling** moves entirely onto core; **disk
reclaim** becomes a deliberate, opt-in, deferred sweep — never an upload hook.

- **`$big_image_size_threshold` (int, default 2560)** is the single canonical
  knob. `big_image_size_threshold()` is registered on core's filter
  **unconditionally** and is **authoritative** — it returns the configured value
  regardless of the incoming one, overriding any other plugin's filter. Returning
  `0` disables core scaling entirely. timber-kit owns the threshold across the
  fleet; predictability beats cooperative filter-stacking for a controlled estate.
- **`$max_upload_width` / `$max_upload_height` are deprecated** (`?int`, default
  `null`). When either is non-null it still wins (larger edge becomes the square
  threshold; explicit `0` disables, preserving the legacy "both 0 = off"
  contract), so existing `Base` subclasses keep working. Removal in 2.0.
  `resize_uploaded_image()` is unhooked, kept null-safe, deprecated docblock-only
  (no runtime `trigger_error` — it would corrupt AJAX/REST responses).
- **No on-upload original deletion.** Disk reclaim is the
  `wp timber-kit prune-originals` WP-CLI command (`--dry-run`, `--older-than`,
  `--limit`, `--verbose`), a thin adapter over the unit-tested
  `OriginalImagePruner`. The pruner guards on the **`-scaled` suffix** (the only
  deterministic size-driven-downscale signal), confirms the file actually
  unlinked (`wp_delete_file()` returns void → `file_exists()` check) **before**
  stripping the `original_image` pointer, and skips EXIF-rotation / conversion
  originals. `--older-than` leaves a window in which regeneration is still
  high-quality.

## Consequences

- The client's `4000` now takes effect across **every** upload path. The fix is
  one core filter, not a parallel resizer.
- Default behaviour is unchanged for every existing project (`2560` == core
  default), and **nothing is deleted by default** — the destructive path is
  CLI-only and conservative (`--dry-run` first).
- The framework permanently trades away "reclaim disk automatically on upload."
  Reclaim is now an operator action per site. This is the deliberate price of
  not degrading future regeneration; projects that genuinely want imsanity's
  old all-in-one behaviour can install imsanity or `delete-unscaled-images`.
- **Authoritative filter is a known trade-off.** A site that *wants* another
  plugin to control `big_image_size_threshold` can't, while timber-kit is active
  — it must set `$big_image_size_threshold` instead. Documented in README.
- Guards that keep this true: `BigImageSizeThresholdTest` (0 disables,
  authoritative, legacy precedence), `RegisterMediaHooksTest` (filter
  unconditional, `wp_generate_attachment_metadata` **never** registered),
  `OriginalImagePrunerTest` (the `-scaled` guard, failed-delete pointer
  preservation, dry-run read-only). PHPStan level 8.

## Alternatives considered

- **Keep the `wp_handle_upload` resize, just raise the cap** — rejected: it
  fights core's threshold (the original bug) and misses REST/CLI/sideload paths.
- **Delete the preserved original on upload** (the first design, `true` by
  default) — rejected after research: degrades all future sub-size regeneration
  to double-compressed output, worst-case for a framework that adds crop sizes
  over time. The `-scaled`-guarded version would also still foreclose
  regeneration quality the moment it ran.
- **Cooperative (non-authoritative) threshold filter** that respects a higher
  incoming value — rejected: unpredictable across a managed fleet; an unrelated
  plugin could silently raise/lower the cap. Authoritative + documented is the
  controllable choice.
- **`big_image_size_threshold` `__return_false` to disable core entirely and own
  the whole pipeline in-theme** — rejected: re-creates the redundant in-theme
  resizer this ADR removes, and loses core's `-scaled` + regeneration machinery.
- **A scheduled WP-Cron prune instead of WP-CLI** — deferred: WP-CLI is explicit,
  auditable (`--dry-run`/`--verbose`), and safe to run from deploy tooling. A
  cron wrapper can be layered on the same `OriginalImagePruner` later without
  changing the core decision.
