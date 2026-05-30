# 3. Dev-media origin via env, with a self-host guard

- **Status:** Accepted
- **Date:** 2026-05-30
- **Deciders:** @parisek

## Context

`DevMediaProxy` keeps a site usable when attachment records exist in the DB
but the files are missing locally, by rewriting missing-file URLs to an
upstream origin. It was enabled only by defining the
`TIMBERKIT_MEDIA_ORIGIN` PHP constant.

The motivating case was the git-worktree workflow in `wordpress-base`: a
fresh worktree has an empty `wp-content/uploads` (gitignored bind-mount), so
every `|resizer` image renders blank. Enabling the proxy per-worktree via a
`define()` is awkward — `wp-config-ddev.php` is DDEV-regenerated and
`wp-config.php` is gitignored, so neither propagates cleanly to a new
worktree. A git-tracked `.ddev/.env` line *would* propagate for free, and
DDEV exposes `.ddev/.env` to PHP via `getenv()` — but the proxy never read
the environment.

A second hazard: pointing the origin at the site's own host (an easy
misconfiguration — e.g. leaving the env var set on the main checkout, where
origin == self) makes the proxy rewrite a missing file to a URL that
resolves back to the same missing file. A silent no-op at best.

## Decision

We will read `TIMBERKIT_MEDIA_ORIGIN` from **either** the constant or the
environment, with the **constant winning** when both are set; and we will
make `DevMediaProxy::register()` **refuse a self-referential origin**.

- `StarterBase::setup_dev_media_proxy()` reads `constant()` first, falls back
  to `getenv(..., local_only: true)`. The env read stays at the bootstrap
  edge — `register()` still receives the origin as an explicit argument, so
  the proxy's logic stays unit-testable.
- `register()` compares the origin host against the **uploads base URL host**
  (`wp_get_upload_dir()['baseurl']`, case-insensitive, native `parse_url`)
  and bails when they match. The uploads host — not `home_url()` — is the
  right comparand because `rewriteIfMissing()` only ever rewrites URLs under
  the uploads base URL; those two hosts can diverge (subdir installs, custom
  content URLs, or `ddev share` repointing `home_url` at the tunnel host
  while uploads stay local).
- `register()` also rejects any origin whose scheme is not `http(s)`.

## Consequences

- A project enables the proxy with one git-tracked line in `.ddev/.env`; it
  propagates to every worktree with no PHP edit.
- The guard covers **every** caller — constant, env, or any future source —
  because it lives in `register()`, not in the config-reading wrapper. It is
  a **host-level** check: it catches the common same-host misconfiguration
  but, by design, does not normalize `www`/non-`www`, differing ports, or IDN
  forms — those are treated as distinct origins.
- Constant-wins keeps every existing `define()`-based setup behaving exactly
  as before; the change is purely additive. An explicitly-empty constant
  (`define('TIMBERKIT_MEDIA_ORIGIN', '')`) means "disabled" and does **not**
  fall through to the env var — `defined()` short-circuits the fallback.
- The env path assumes DDEV ≥ 1.25 surfaces `.ddev/.env` to PHP; on hosts
  that don't, the env var is simply absent and the proxy stays off — which
  is the correct production behaviour anyway (files exist in production).
- **Trust boundary.** The origin is dev-only configuration. An actor who can
  set the PHP process environment (or `.ddev/.env`) can point media URLs and
  the resizer's `wp_remote_head()` probe at a host they control. This is no
  worse than the pre-existing constant path (editing PHP config), and the
  `http(s)`-scheme rejection blocks `file://`/`gopher://`-style abuse, but
  the proxy is not meant for untrusted-environment use.

## Alternatives considered

- **Compare against `home_url()` instead of the uploads host** — rejected:
  `home_url()` can diverge from the uploads host (subdir installs, custom
  content URLs, `ddev share`), so it can pass the guard while the rewrite
  still loops on the uploads host — exactly the worktree-share scenario this
  change targets.
- **Env wins over constant** — rejected: a stray env var would silently
  override a deliberate `define()`, the opposite of the WP convention where
  an explicit constant is the "I really mean this" escape hatch.
- **Guard in the wrapper (`setup_dev_media_proxy()`) instead of
  `register()`** — rejected: a constant-only caller, or any future caller,
  would then be unprotected. One gate in `register()` covers every path.
- **Use a DDEV-specific env var (`DDEV_PRIMARY_URL`) for the self-host
  comparison** — rejected: deriving the comparand from `wp_get_upload_dir()`
  keeps the guard environment-agnostic and aligned with what the proxy
  actually rewrites.
