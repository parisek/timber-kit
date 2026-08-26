# Architecture Decision Records

Short, immutable notes on decisions that shaped this repo — the *why* behind
choices a future reader would otherwise have to reverse-engineer from the code.

`docs/` is git-ignored repo-wide; only this `adr/` subtree is tracked (see
`.gitignore`). So ADRs commit, scratch docs don't.

This practice is shared verbatim with `parisek/styleguide`,
`parisek/definition-kit` and `parisek/acf-json-schema` — four Composer packages,
one set of rules. Change it in one and change it in all four.

**Citing a sibling repo's ADR**: always qualify it with the repo —
`tailwind-base ADR-0007`, never a bare `ADR 0007`. The numbering spaces are
independent, so a bare number sends the reader to this repo's `docs/adr/`, where
it either does not exist or is a different decision entirely.

**Every ADR is listed in the Index below.** `scripts/check-adr-index.py`
(`composer adr`, and a CI job) fails the build otherwise: an ADR nothing links to
reads as a decision nobody recorded.

## When to write one

Offer an ADR **sparingly** — only when **all three** are true:

1. **Hard to reverse** — the cost of changing your mind later is meaningful.
2. **Surprising without context** — a future reader will wonder *"why did they
   do it this way?"*
3. **The result of a real trade-off** — there were genuine alternatives and one
   was picked for specific reasons.

Most changes are none of these. A routine helper tweak, a typo fix, a test — no
ADR. If you're unsure, it probably doesn't need one.

Propose the ADR, get a yes, *then* write it. Don't auto-create.

## Format

Classic Nygard triad — Context / Decision / Consequences. No status line, no
ceremony. Keep it to what the three headings demand.

- One file per decision: `NNNN-kebab-title.md`, zero-padded, sequential.
- Numbers are permanent — never renumber or reuse, even if an ADR is later
  superseded. To reverse a decision, write a new ADR and link back to the old
  one (leave the old file in place as history).

```markdown
# NNNN. Short title in the imperative

## Context

What forces are at play — the problem, constraints, and what made the obvious
path unworkable.

## Decision

What we decided, stated plainly.

## Consequences

What follows — the good, the bad, and what now has to stay true. Name the
guard (test, CI check, convention) that keeps it from drifting, if any.
```

## Index

- [0001](0001-wpml-block-override.md) — Sync ACF Copy fields into translated blocks at render time
- [0002](0002-breadcrumb-design.md) — Move Breadcrumb upstream into the kit with typed items
- [0003](0003-dev-media-origin-env-and-self-host-guard.md) — Dev-media origin via env, with a self-host guard
- [0004](0004-image-downscaling-via-core-threshold.md) — Drive downscaling through core's threshold; never delete originals on upload
- [0005](0005-first-party-gtm-container.md) — Load the GTM container from the kit, configured in code
- [0006](0006-warmup-priority-precomputed-at-refresh.md) — Precompute warmup priority at refresh, never at purge
- [0007](0007-prove-cache-purity-from-inputs.md) — Prove cache purity from inputs, never from a render
- [0007](0007-resizer-source-path-cache-key.md) — Scope the resizer cache key by the source's upload path
