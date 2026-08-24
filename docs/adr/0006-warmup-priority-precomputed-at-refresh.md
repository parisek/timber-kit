# 0006. Precompute warmup priority at refresh, never at purge

## Context

Breeze processes its preload queue strictly front to back, at roughly a
second per URL. Position in the array is the schedule: whatever sits at
index 0 is warm within a second of the purge, and whatever sits near the end
may stay cold for minutes. `BreezeWarmupSitemap` already merges thousands of
sitemap-sourced URLs into that queue, and the merge runs inside the
`breeze_preload_urls` filter — which fires **synchronously inside the purge
request**, the same request an editor is sitting in front of after clicking
Save.

A sitemap can hold thousands of URLs on a real site. Anything this filter
does costs that editor wall-clock time, once per purge, for as long as the
site exists. That rules out any per-purge step whose cost scales with the
sitemap's size — sorting by a computed score chief among them.

## Decision

Ordering is computed once, during the existing deferred refresh job, and
stored as a finished list. The purge-time filter only reads that list and
splices it against Breeze's own entries positionally — homepage, then
Breeze's unscored entries, then the stored ordering — a cost independent of
how many URLs the sitemap holds.

Two alternatives were rejected:

- **Sort at purge time.** Cheapest to build, but it is exactly the cost this
  decision exists to avoid: an O(n log n) sort of a sitemap-sized list,
  paid synchronously by a user waiting on the request.
- **Drop menu membership as a signal.** Menu membership is the strongest
  cheap signal of importance available — a human deliberately linked that
  page from every page of the site — and it costs one option read during the
  refresh. Dropping it to simplify the hot path would have thrown away a free
  signal to protect a path that was never going to use it anyway.

## Consequences

The ordering is only ever as fresh as the last refresh, not as fresh as the
last purge. That forced three separate invalidation paths, because no single
one covers every way the ordering can go stale: a TTL for the ordinary case
of time passing, a weight fingerprint so a config change (a deploy changing
`$breeze_warmup_priority_weights`, or the `timberkit_warmup_priority_weights`
filter) is noticed without polling, and an in-place rescore on
`wp_update_nav_menu` so a menu edit — the single fastest way to invalidate
the strongest signal — doesn't wait for the TTL to catch up.

Two writers now share one `wp_options` row: the cron refresh and the
menu-change rescore. WordPress options are last-write-wins, so a slow cron
refresh that started before a menu edit and finishes after it could silently
overwrite the newer ordering with a stale one. A revision counter guards the
case that actually happens in practice — the refresh re-reads the revision
immediately before writing and discards its result if it has moved. It does
not guard two writers landing in the same instant; closing that would need a
conditional `UPDATE` matched against the serialized option value, which is
disproportionate to a race between an hourly cron job and a human saving a
menu.

The cap became soft. Every language's homepage and every menu item are
guaranteed a slot even when that pushes the total past
`timberkit_warmup_sitemap_max_urls` — a language losing its homepage from the
warmup list is a worse outcome than a cap overrun bounded by the number of
menu items.
