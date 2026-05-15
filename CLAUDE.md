# CLAUDE.md

Operational notes for Claude Code sessions on this repo. Treat as authoritative — overrides default assumptions where they conflict.

## Maintaining this file

Go-style brevity. Bullets, not paragraphs. Add only what saves the next session real time:

- **Add** a note when you hit a non-obvious gotcha (e.g. "tool X returns false when Y", "test Z is flakey because of W"), or pin a convention the codebase relies on (e.g. squash-merge `(#N)` suffix powering release notes).
- **Don't add** restatement of README content, narration of what the codebase does, or one-off task context. README owns "what the project does"; CLAUDE.md owns "how to work on it".
- **Cap ~150 lines.** Past that, the whole file gets skimmed instead of read. If a section grows, prune adjacent stale notes first.

## Project shape

WordPress/Timber starter-kit library distributed via Composer (`parisek/timber-kit`).

- `src/` — all production code (PSR-4 `Parisek\TimberKit\`)
- `tests/` — PHPUnit + Brain\Monkey, no real WordPress boot (minimal `WP_Post` / `WP_Term` / `WP_Query` stubs in `tests/bootstrap.php`)
- `.github/workflows/` — CI (`tests.yml`) + release automation (`release-stamp.yml`, `release.yml`)
- `.gitattributes` controls dist scope — `composer require` only ships `src/`, `composer.json`, `LICENSE`, `README.md`. Everything else (`tests/`, `.github/`, `CHANGELOG.md`, `CLAUDE.md`, `.ddev/`, lint configs) is `export-ignore`.

PHP 8.3 minimum. PHPStan level 5.

## Commands

```bash
composer test       # vendor/bin/phpunit
composer phpstan    # vendor/bin/phpstan analyse
```

DDEV is the local-dev expectation (`ddev exec "composer test"`). Both run in CI matrix on PHP 8.3 + 8.4.

## Per-PR conventions

- **CHANGELOG.md**: every behavior-affecting PR adds an entry under `## [Unreleased]` with [Keep a Changelog](https://keepachangelog.com/) categories (`### Added`, `### Changed`, `### Deprecated`, `### Removed`, `### Fixed`, `### Security`). The release workflow relies on this.
- **Squash-merge PRs** into `main` so the merge commit subject ends with `(#N)`. The auto-release workflow scrapes `(#N)` suffixes from `git log <prev_tag>..<tag>` to assemble the release's Pull Requests section. Merge commits without the suffix won't show up.
- **Stacked PRs**: when a PR depends on another, target the parent branch (not main). After the parent merges, GitHub auto-retargets — but if `--delete-branch` runs on the parent merge, the child PR auto-closes (chicken-and-egg, recovery requires recreating the deleted branch). Either skip `--delete-branch` until the whole stack lands, or retarget the child to `main` *before* merging the parent.

## Release process — DO NOT bypass

Two GitHub Actions automate releases. **Never stamp + tag manually** unless the workflow is broken:

1. Trigger **Stamp Release** workflow (Actions tab → workflow_dispatch → enter `X.Y.Z` without `v` prefix).
2. The workflow validates input, requires non-empty `[Unreleased]` content, runs full `phpunit` + `phpstan` as guards, stamps `[Unreleased]` → `[X.Y.Z] - DATE` (UTC, leaves a fresh empty `[Unreleased]` on top), commits "Release X.Y.Z", tags `vX.Y.Z`, pushes both.
3. Tag push auto-triggers `release.yml`, which extracts the matching CHANGELOG section, derives the merged-PR list, and creates the GitHub Release with the standard structure (`## What's Changed` / `## Pull Requests` / `**Full Changelog**: <compare link>`).
4. **Latest** badge is auto-set only when the new tag is the highest semver — back-dated patch tags (e.g. `v1.3.1` after `v1.4.0` exists) won't steal it.

If you ever need to back-fill a missing GitHub Release for an older tag manually: use `gh release create vX.Y.Z --latest=false`, otherwise it will steal the Latest badge. Then `gh release edit v<highest> --latest` to restore.

## Testing notes

- Tests use `Brain\Monkey` to mock WordPress functions. **Function definitions persist across tests in the same run** (Brain\Monkey resets call expectations but not function existence). So `function_exists('xxx')` returns `true` for the rest of the suite once any earlier test has mocked `xxx` — designing tests that exercise `function_exists`-fail paths is unreliable. Document such guards by inspection instead.
- The `WP_Term` / `WP_Post` stubs use `#[\AllowDynamicProperties]` mirroring WP core, so tests can hydrate arbitrary properties via the constructor without PHP 8.2+ deprecations.

## Style

- Tabs for indentation in PHP (matches WordPress coding style baseline). Spaces in YAML.
- No emojis in code, comments, or commits unless the user explicitly asks.
- Comments should explain *why*, not *what*. Avoid changelog-style comments referencing specific tasks/PRs (those rot).
- When fixing review feedback that suggests a change which would break an unrelated integration (e.g. `instanceof WP_Post` would regress `Timber\MenuItem`), don't blindly apply — find the layered fix that satisfies the concern without the regression.
