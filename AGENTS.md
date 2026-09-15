# AGENTS.md

Operational notes for AI coding agents (Claude Code, Codex, Cursor, …) working on this repo. Treat as authoritative — overrides default assumptions where they conflict.

Tool-specific entrypoint files (`CLAUDE.md`, `.cursorrules`, etc.) just point here so the source of truth stays in one place.

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

PHP 8.3 minimum. PHPStan level 8 (existing findings grandfathered in phpstan-baseline.neon — new code must be clean).

## Commands

```bash
composer test           # Unit suite (Brain\Monkey, fast — default)
composer test:property  # Eris property suite (invariant-based, ~100 iterations/test)
composer test:all       # both suites
composer phpstan        # static analysis
composer normalize      # tidy composer.json (CI checks it with --dry-run)
composer audit          # scan the dependency tree for known advisories
```

DDEV is the local-dev expectation (`ddev exec "composer test"`). CI runs the suites on PHP 8.3 + 8.4, plus a `composer` hygiene job (validate + audit + normalize check). `config.platform.php` is pinned to 8.3 so the lock resolves for the supported floor.

## TDD — non-negotiable

Always work test-first. The discipline, not just the coverage:

- **Failing test first.** Write it, run it, watch it go red *for the right reason*, then write the minimal code to green. No production change lands without a test that failed before it existed.
- **Bug fixes too** — reproduce the bug as a failing test first; it doubles as the regression guard (e.g. `AcfBlocksParseNodeAttrCompatTest` pins the ACF↔Alpine attribute filter so it can't silently narrow back to `x-`-only).
- **Keep output pristine.** `composer test` must stay green *and notice/deprecation-free* for code you touch. PHPUnit 12 deprecates **all** doc-comment metadata — use attributes: `#[DataProvider('method')]` (static provider), `#[RunInSeparateProcess]` + `#[PreserveGlobalState(false)]`, etc. Use `createStub()` (not `createMock()`) for objects you only stub return values on, or PHPUnit 12 emits a "no expectations configured" notice.
- **JS embedded in PHP** (admin-footer shims etc.) has no JS runtime here — assert against the emitted source string, the same way the sibling `acf_input_admin_footer` test does.

## Per-PR conventions

- **CHANGELOG.md**: every behavior-affecting PR adds an entry under `## [Unreleased]` with [Keep a Changelog](https://keepachangelog.com/) categories (`### Added`, `### Changed`, `### Deprecated`, `### Removed`, `### Fixed`, `### Security`). The release workflow relies on this.
- **README.md coverage**: a PR that adds a CLI command, a public class, or a
  public method updates `README.md` in the same PR. The changelog records that
  something changed; the README is where a consumer finds out the thing exists
  at all. An undocumented feature is not merely undocumented — it is
  re-implemented by hand in a theme, because the package did not appear to
  offer it. Two commands and four classes went missing this way before the rule
  existed (#112). Where a feature needs more than ~20 lines to explain, the
  README gets the short entry plus a pointer, and the depth goes in `docs/`.
  Command tables are the enforcement mechanism worth copying: a missing row is
  visible, a missing paragraph is not.
- **Squash-merge PRs** into `main` so the merge commit subject ends with `(#N)`. The auto-release workflow scrapes `(#N)` suffixes from `git log <prev_tag>..<tag>` to assemble the release's Pull Requests section. Merge commits without the suffix won't show up.
- **Stacked PRs**: when a PR depends on another, target the parent branch (not main). After the parent merges, GitHub auto-retargets — but if `--delete-branch` runs on the parent merge, the child PR auto-closes (chicken-and-egg, recovery requires recreating the deleted branch). Either skip `--delete-branch` until the whole stack lands, or retarget the child to `main` *before* merging the parent.

## Feature flags & breaking changes

New behavior that changes rendered output, admin behavior, or anything a consumer could be surprised by ships **behind a `StarterBase` flag, default off** — never on by default. The library stays backwards-compatible; projects opt in.

- **Pattern.** Declare `protected bool $feature_name = false;` on `StarterBase` (grouped with related flags, docblock stating *what it changes* and *why it's opt-in*), then gate the wiring inside the relevant `registerXxxHooks()` method: `if ( $this->feature_name ) { add_action( … ); }`. Cover both branches (off → not wired, on → wired) in the matching `RegisterXxxHooksTest`. Examples: `$security_headers`, `$wpml_block_override`.
- **Breaking changes are allowed — but only this way.** A breaking or behavior-changing change may land *provided* it's behind such a flag and defaults to off, so existing consumers are unaffected until they flip it. No silent behavior changes on upgrade.
- **Opinionated defaults live downstream.** The `wordpress-base` project template enables these flags (`true`) in its own `Base` — that's where Porta Design's best-practice config is expressed, not in the library defaults.
- **Approved exception:** `$seo_canonical_pagination` defaults `true`, not `false`. The owner reviewed it explicitly because `Pagination::append()` is idempotent and reads the site's own pagination base — on a site whose listings are real post-type archives the filter re-derives the identical canonical, so flipping the default changes nothing there. This is a named exception to the rule above, not a case of the rule being skipped.
- **Approved exception:** `$disable_author_archives` defaults `true`, not `false`. The owner reviewed it explicitly against a fleet audit: across the 26 projects on this kit, **none has a working author archive** — two route `is_author()` but neither ships the `author.twig` its branch points at, no theme anywhere links to an author URL, and the one project with an `Author` class reads a taxonomy rather than users. Default-off would have been safe and useless, because the sites carrying the open URL are the ones nobody looks at, and they are exactly the ones that would never flip it. Note that `$block_author_enumeration` (1.4.0) and `$disable_author_sitemap` (1.8.0) also default `true` — they predate this rule, which landed 2026-06-10, one day after 1.8.0, so they are not precedent for skipping it. This is a named exception to the rule above.
- **Approved exception:** `$seo_suppress_plugin_breadcrumb` defaults `true`, not `false`. The owner reviewed it explicitly: a page carrying two `BreadcrumbList` nodes hands a crawler a choice, and the plugin's copy is measurably the worse one — it cannot see the trail the theme built, so on a Czech site it opens with an English `Home` while the page shows `Úvod`. What makes default-on acceptable here rather than merely convenient is that the suppression defers to the plugin's own "Enable Breadcrumbs" switch wherever that switch exists, so those sites get the list back from wp-admin. On a site without it — AIOSEO only keeps it where breadcrumbs were switched off before the setting was deprecated — the escape hatch is the flag itself. This is a named exception to the rule above.

- **Approved exception:** `Helpers::formatAnnouncement()` sanitises its `text`
  with `wp_kses()` unconditionally — no flag. The owner reviewed it explicitly.
  The same allow-list already runs at save time via
  `acf/update_value/type=wysiwyg`, so for content written through the ACF UI
  this is a no-op, measured byte-for-byte against a live site's announcement;
  and the double pass is idempotent, verified across eight shapes including
  entities, malformed markup and already-encoded `&lt;script&gt;`. What it
  actually changes is content written by the paths that bypass ACF — WP-CLI, a
  SQL import, a migration script, a revision restore, a WPML copy — and only
  where that content carries markup outside the editor list. Default-off would
  have been safe and useless for the same reason `$disable_author_archives` was:
  the sites carrying unsanitised imported text are precisely the ones nobody
  would think to flip a flag on, and the bar renders this field through
  `x-html`. A project that needs the raw value back overrides the
  `announcement` context in its own `Base`. This is a named exception to the
  rule above, not a case of the rule being skipped.

- **Approved exception:** `Helpers::getEditorAllowedHtml()` permits `target`
  (restricted to `_blank`/`_self`) and `aria-label` on `<a>` unconditionally —
  no flag. The owner reviewed it explicitly. The change is strictly permissive:
  nothing that rendered before stops rendering, and no tag or attribute
  previously allowed was removed (verified mechanically — 28 tags before and
  after, nothing lost). What makes default-on right here rather than merely
  convenient is *which way the old behaviour fails*: this list runs at SAVE
  time, so an editor who typed `target="_blank"` was not seeing it ignored at
  render, they were **losing it from the database**, silently, with no error.
  A default-off flag would leave that data loss running on every site that did
  not flip it — and nobody flips a flag for a bug they cannot see. `target`
  carries a wp_kses value restriction rather than a bare `true`, so `_top`,
  `_parent` and named browsing contexts stay refused. This is a named exception
  to the rule above, not a case of the rule being skipped.


## Architecture decisions (ADRs)

Significant decisions live in `docs/adr/` — the only tracked subtree under the
otherwise git-ignored `docs/`. See `docs/adr/README.md` for the template and index.

- Record **sparingly** — only when a decision is (1) hard to reverse, (2) surprising without context, and (3) the result of a real trade-off. Most changes warrant none.
- Propose and get a yes **before** writing one. Don't auto-create.
- One file per decision, `NNNN-kebab-title.md`, sequential and permanent (never renumber/reuse).
- Structure is the Nygard triad: `## Context` / `## Decision` / `## Consequences`. No status line.
- To reverse a past decision, write a new ADR linking back — don't edit the old one.
- The ADR lands in the **same PR** as the work it describes — a merge gate, not a follow-up.
- Citing a sibling repo's ADR: **always qualify it with the repo** — `tailwind-base ADR-0007`, never a bare `ADR 0007`. Numbering spaces are per-repo.
- Every ADR belongs in the index in `docs/adr/README.md`; `composer adr` (and CI) enforces it.

## Release process — DO NOT bypass

Two GitHub Actions automate releases. **Never stamp + tag manually** unless the workflow is broken:

1. Trigger **Stamp Release** workflow (Actions tab → workflow_dispatch → enter `X.Y.Z` without `v` prefix).
2. The workflow validates input, requires non-empty `[Unreleased]` content, runs full `phpunit` + `phpstan` as guards, stamps `[Unreleased]` → `[X.Y.Z] - DATE` (UTC, leaves a fresh empty `[Unreleased]` on top), commits "Release X.Y.Z", tags `vX.Y.Z`, pushes both.
3. Tag push auto-triggers `release.yml`, which extracts the matching CHANGELOG section, derives the merged-PR list, and creates the GitHub Release with the standard structure (`## What's Changed` / `## Pull Requests` / `**Full Changelog**: <compare link>`).
4. **Latest** badge is auto-set only when the new tag is the highest semver — back-dated patch tags (e.g. `v1.3.1` after `v1.4.0` exists) won't steal it.

If you ever need to back-fill a missing GitHub Release for an older tag manually: use `gh release create vX.Y.Z --latest=false`, otherwise it will steal the Latest badge. Then `gh release edit v<highest> --latest` to restore.
- **Approved exception:** AVIF encoding honours `timber_kit_resizer_target_quality`
  unconditionally — no flag. The owner reviewed it explicitly. The old
  behaviour was a defect that failed invisibly: a site that set quality 80
  shipped quality-20 pixels, and a site on the default shipped the coder's
  default, with no error anywhere. A default-off flag would keep that running
  on every site that did not flip it, and nobody flips a flag for a defect they
  cannot see. Nothing changes until a site clears its AVIF cache, and the
  CHANGELOG entry tells a site on the default quality to set an explicit value.
  This is a named exception to the rule above, not a case of the rule being
  skipped.

## Testing notes

- **CI AVIF encoder:** the Unit job's ImageMagick cannot write AVIF, so
  `EncoderQualityTest` skips its AVIF cases there. The `AVIF encoder` job
  installs libheif with an AV1 encoder and sets `TIMBERKIT_REQUIRE_AVIF_ENCODER`,
  which turns that skip into a failure.
- Tests use `Brain\Monkey` to mock WordPress functions. **Function definitions persist across tests in the same run** (Brain\Monkey resets call expectations but not function existence). So `function_exists('xxx')` returns `true` for the rest of the suite once any earlier test has mocked `xxx` — designing tests that exercise `function_exists`-fail paths is unreliable. Document such guards by inspection instead.
- The `WP_Term` / `WP_Post` stubs use `#[\AllowDynamicProperties]` mirroring WP core, so tests can hydrate arbitrary properties via the constructor without PHP 8.2+ deprecations.
- Property tests (`tests/Property/`) are isolated from Brain\Monkey by convention — they target pure functions only. If a property test needs a WP/ACF stub, add it as a plain `function_exists`-guarded function to `tests/bootstrap.property.php` rather than reaching for `Functions\when()`. The Property suite uses its own `phpunit.property.xml` config because Brain\Monkey's Patchwork raises "DefinedTooEarly" if WP function stubs live in the shared `tests/bootstrap.php`. CI pins `ERIS_SEED` to the Actions run ID (`github.run_id`); reproduce a failing build locally with `ERIS_SEED=<actual-run-id-integer> composer test:property`.

## Style

- Tabs for indentation in PHP (matches WordPress coding style baseline). Spaces in YAML.
- No emojis in code, comments, or commits unless the user explicitly asks.
- Comments should explain *why*, not *what*. Avoid changelog-style comments referencing specific tasks/PRs (those rot).
- When fixing review feedback that suggests a change which would break an unrelated integration (e.g. `instanceof WP_Post` would regress `Timber\MenuItem`), don't blindly apply — find the layered fix that satisfies the concern without the regression.
