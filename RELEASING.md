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

See § [Public API surface](#public-api-surface) for exactly what counts as "API" when deciding the bump type, and § [Conventional Commits](#conventional-commits) for how commit prefixes map to bump types.

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

---

## Conventional Commits

All commit messages in this repo follow the [Conventional Commits](https://www.conventionalcommits.org/) format:

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

### Type → version bump mapping

| Commit type | Bump | Notes |
|---|---|---|
| `feat` | **MINOR** | New feature, new `protected` property, new public method |
| `feat!` or `BREAKING CHANGE:` footer | **MAJOR** | Any change that breaks the public API |
| `fix` | **PATCH** | Bug fix in existing public behaviour |
| `perf` | **PATCH** | Performance improvement, no behaviour change |
| `refactor` | **PATCH** | Internal-only; if public API changes, use `feat` or `feat!` |
| `docs` | **PATCH** | Doc-only, no code change |
| `test` | **PATCH** | Test-only, no production-code change |
| `chore` | **PATCH** | Build, tooling, CI — no src change |
| `style` | **PATCH** | Formatting only |

**Breaking-change flag.** A `!` after the type (`feat!:`, `fix!:`) OR a `BREAKING CHANGE:` trailer in the commit footer both signal MAJOR, regardless of the type prefix.

**One commit, one bump.** When a PR contains both a `feat` and a `fix`, the highest bump wins (MINOR in this case). Choose the bump that reflects the PR as a whole.

**Deprecations use `feat`, not a `deprecated` type.** `deprecated` is not a Conventional Commits type and the PR-title lint (`.github/workflows/commitlint.yml`) rejects it. In this package a deprecation always ships alongside its additive replacement, so it is a `feat` (**MINOR**) — record the deprecation under `### Deprecated` in `CHANGELOG.md`. See § [Deprecation lifecycle](#deprecation-lifecycle).

### Examples

```
feat(media): add $big_image_size_threshold property

Replaces deprecated $max_upload_width / $max_upload_height pair.
Registers big_image_size_threshold WP filter unconditionally so
timber-kit is authoritative over the upload size cap.
```

```
fix(media): big_image_size_threshold=0 now correctly disables scaling

Previously the filter was only registered when the value was > 0.
Now registered unconditionally; callback returns 0 directly.
```

```
feat!: rename StarterBase::timber_context() parameter $ctx → $context

BREAKING CHANGE: callers overriding timber_context() must update the
parameter name in their method signature.
```

```
feat(media): deprecate $max_upload_width / $max_upload_height

Use $big_image_size_threshold instead. Legacy pair still honoured via
BC shim; will be removed in 2.0. Logged under ### Deprecated in CHANGELOG.
```

---

## Public API surface

The bump type (MAJOR / MINOR / PATCH) is determined by whether the change touches the **public API**. The following are public API in this package:

### Protected properties on `StarterBase`

Every `protected` property that a `Base` subclass is expected to set in `__construct()` before calling `parent::__construct()`. Examples: `$menus`, `$article_post_types`, `$big_image_size_threshold`, `$acf_datastore`, `$wpml_block_override`.

- **Adding** a new property → MINOR
- **Renaming or removing** a property → MAJOR
- **Changing the type** (e.g. `int` → `?int`) in a backward-compatible way → PATCH; in a breaking way → MAJOR

### Public and protected methods

Methods that downstream `Base` subclasses are expected to override (e.g. `timber_context()`, `theme_page_templates()`, `setup_breadcrumb_labels()`) and methods callable from template code.

- **Adding** a new overridable method → MINOR
- **Changing a parameter signature** in a way that breaks existing overrides → MAJOR
- **Removing** a method → MAJOR

### WordPress filters and actions

Filters and actions that `StarterBase` registers — and that downstream code may hook into — are public API once documented. Example: `block_{name}_content`, `timber_block_render_callback`.

- **Adding** a new filter/action → MINOR
- **Changing the number or type of arguments** passed to existing hooks → MAJOR
- **Removing** a hook → MAJOR

### PHP class public API (`Helpers::*`)

All `public static` methods on `Helpers` and any other class shipped with the package.

- **Adding** a new static method → MINOR
- **Changing an existing signature** → MAJOR

### What is NOT public API

The following change freely without a version bump beyond PATCH:

- `private` methods and properties on `StarterBase` or any other class
- Internal class interactions not documented in `README.md` or an ADR
- Test code, CI config, GitHub Actions workflows
- The `@internal` tag on a class/method signals "not public API" — callers who hook into these are on their own

---

## Deprecation lifecycle

Deprecated API stays in the package for **at least one MINOR version** before removal. Removal requires a **MAJOR** bump.

### Deprecating a property or method

1. Add a `@deprecated since X.Y — use $replacement instead` docblock tag.
2. Keep the old behaviour working (a BC shim). **Deprecation is docblock-only — do NOT add a runtime `trigger_error( E_USER_DEPRECATED )` / `_doing_it_wrong()` call** in any code path that runs during a normal WordPress request. Those paths emit JSON / headers (AJAX, REST, the block-render pipeline), and a stray notice corrupts the response and spams logs in production. A runtime notice is acceptable *only* in a path that is unambiguously CLI- or debug-only. This is doctrine — see [ADR 0004](docs/adr/0004-image-downscaling-via-core-threshold.md); `resize_uploaded_image()` was deprecated exactly this way in 1.11.0.
3. Document the deprecation in `CHANGELOG.md` under `### Deprecated`.
4. Commit as `feat` (see § Conventional Commits) and release as **MINOR** — the replacement is additive.

Example pattern for a deprecated property:

```php
/**
 * Maximum upload width in pixels.
 *
 * @deprecated 1.11.0 Use $big_image_size_threshold instead.
 * @var int|null
 */
protected ?int $max_upload_width = null;
```

And in the callback that reads it:

```php
public function big_image_size_threshold( int $threshold ): int {
    if ( null !== $this->max_upload_width || null !== $this->max_upload_height ) {
        // BC shim — honours the legacy pair until 2.0 removes it.
        return max( (int) $this->max_upload_width, (int) $this->max_upload_height );
    }
    return $this->big_image_size_threshold;
}
```

### Removing deprecated API

1. Grep downstream projects for usages before removal: `rg 'max_upload_width|max_upload_height' ~/Sites/wordpress/*/wp-content/themes/`.
2. Document removal in `CHANGELOG.md` under `### Removed` with a migration note.
3. Release as **MAJOR**.

### Current deprecations

| Deprecated | Since | Removal target | Replacement |
|---|---|---|---|
| `$max_upload_width` | 1.11.0 | 2.0.0 | `$big_image_size_threshold` |
| `$max_upload_height` | 1.11.0 | 2.0.0 | `$big_image_size_threshold` |
| `resize_uploaded_image()` | 1.11.0 | 2.0.0 | Handled automatically via `big_image_size_threshold` WP filter |

---

## ADRs (Architecture Decision Records)

Major features land with an ADR in `docs/adr/YYYY-MM-DD-<topic>.md` — a copy of the design doc from the consuming project that drove the work. See `docs/adr/2026-05-24-breadcrumb-design.md` as the template.
