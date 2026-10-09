# Contributing

Thank you for helping with `parisek/timber-kit`.
[AGENTS.md](AGENTS.md) is the full rule set. This page is the short version.
AI coding agents must read `AGENTS.md` first.

## Set up

PHP 8.3 or newer is required.

```bash
composer install
```

DDEV is the expected local setup: `ddev exec "composer test"`.

## Checks

CI runs these checks. Run them before you open a PR.

```bash
composer test            # unit suite
composer test:property   # property suite
composer phpstan         # static analysis, level 8
composer adr             # docs/adr/ index is in sync
composer validate --strict
composer audit
composer normalize --dry-run
```

`composer test:integration` needs a real WordPress and a database you can lose.

## Test first

Write a failing test first. Watch it fail for the right reason. Then write the code.
This applies to bug fixes too.

## Pull requests

- Title: [Conventional Commits](https://www.conventionalcommits.org/), for example `fix(media): ...`. A CI check lints the title.
- Add an entry under `## [Unreleased]` in `CHANGELOG.md` for every change that affects behavior.
- Add a row or entry to `README.md` when you add a CLI command, a public class or a public method.
- New behavior that changes output ships behind a `StarterBase` flag, default off.
- We squash-merge. The merge commit subject must end with `(#N)`.

## Decisions

Record a hard-to-reverse decision as an ADR in `docs/adr/`. Ask first. See `docs/adr/README.md`.

## Releases

Maintainers release with the Stamp Release workflow. See [RELEASING.md](RELEASING.md).

## Vulnerabilities

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).
