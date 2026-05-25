# Releasing `parisek/timber-kit`

Tag-driven release flow — Composer reads git tags directly via Packagist's auto-sync webhook. No manual Packagist upload.

## Prerequisites

- `main` branch is green:

  ```bash
  composer test
  composer phpstan
  ```

- `CHANGELOG.md` `[Unreleased]` section lists the changes for the new version.
- All review threads on PRs merged into this version are resolved.

## Procedure

### 1. Pick the version number (semver)

| Bump | When | Examples |
|---|---|---|
| **MAJOR** (`2.0.0`) | Breaking API change | Signature change, removed feature, namespace move, behavior change consumers may depend on |
| **MINOR** (`1.7.0`) | Additive | New feature, new optional property, new public method, expanded filter signature with default argument |
| **PATCH** (`1.6.1`) | Bug fix or doc-only | Bug-only fix, perf improvement, internal refactor, doc-only change |

### 2. Finalize `CHANGELOG.md`

Rename `[Unreleased]` → `[X.Y.Z] - YYYY-MM-DD` and insert a fresh empty `[Unreleased]` heading above it. Commit + push to `main`:

```bash
git checkout main
git pull origin main
$EDITOR CHANGELOG.md
git add CHANGELOG.md
git commit -m "docs(changelog): finalize X.Y.Z"
git push origin main
```

### 3. Create the annotated tag

```bash
git tag -a vX.Y.Z -m "vX.Y.Z: <one-line summary>"
git push origin vX.Y.Z
```

`-a` (annotated) is **mandatory** — lightweight tags lack metadata Packagist needs for proper version display. Use the actual `main` HEAD; never tag a feature branch.

### 4. GitHub release (auto-created by workflow)

Pushing a `vX.Y.Z` tag fires `.github/workflows/release.yml`, which:

- Derives release notes from the matching CHANGELOG section + a PR list between tags.
- Marks the release Latest only when it's the highest semver.

The workflow creates the release automatically — **don't run `gh release create` manually** unless the workflow fails. If it does fail, check the Actions log at `https://github.com/parisek/timber-kit/actions` and re-trigger or create manually:

```bash
gh release create vX.Y.Z --repo parisek/timber-kit \
  --title "vX.Y.Z — <one-line summary>" \
  --notes "$(<release-notes.md)"
```

### 5. Verify Packagist sync (~30s after tag push)

```bash
sleep 30
curl -s https://repo.packagist.org/p2/parisek/timber-kit.json \
  | python3 -c "import json,sys; print(json.load(sys.stdin)['packages']['parisek/timber-kit'][0]['version'])"
```

Should print `X.Y.Z`. If not, check https://packagist.org/packages/parisek/timber-kit "Last update" — if it lags more than a few minutes, the GitHub → Packagist webhook may be misconfigured.

### 6. Bump consumer projects

- `wordpress-base`: `composer require parisek/timber-kit:^X.Y` (theme root composer)
- Any other downstream sites with their own composer files: same command
- Sync via `wordpress-base/ddev-sync.sh` flow when applicable

## Gotchas

- **Tag at the head of `main`.** Tagging a feature branch's HEAD before merge ships unmerged code. Always `git checkout main && git pull` first.
- **Composer's `^X.Y` is permissive.** `^1.6` accepts `1.6.0` through anything below `2.0.0`, so a spec written before the actual tag is known still works — but write `^X.Y` matching the actual minor for clarity.
- **Lightweight tags break Packagist version display.** Always use `-a`.
- **Don't reuse a tag number** that's already on Packagist. Force-updating a synced tag won't refresh Packagist's cache. Bump the patch instead.
- **`[Unreleased]` must exist before tagging.** Future changes need a landing spot. Re-create it the moment you rename the previous one.
- **Release workflow auto-fires on tag push.** Don't `gh release create` unless the workflow failed — you'll get a 422 conflict error.

## ADRs (Architecture Decision Records)

Major features land with an ADR in `docs/adr/YYYY-MM-DD-<topic>.md` — a copy of the design doc from the consuming project that drove the work. See `docs/adr/2026-05-24-breadcrumb-design.md` as the template.
