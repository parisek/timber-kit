# 0011. Test the JavaScript in assets/ with a Node tier

## Context

The kit ships JavaScript that runs in the block editor:
`assets/js/gutenberg-resizable-sidebar.js`. Until now nothing ran it. The only
guard was a PHP test that checks the file is enqueued. `AGENTS.md` says
JavaScript embedded in PHP is tested by asserting on the emitted source string.

That guard could not catch the defect that shipped. The script called
`.resizable()` from a `setInterval` every 500 ms. On a page whose sidebar held a
large ACF block form (about 11,000 DOM nodes), each tick blocked the main thread
for 230-280 ms. The source looked fine. Only running it against a large document
shows the cost.

The same file is enabled in `wordpress-base` and in 15 of the 28 projects that
use the kit, so a defect in it reaches every one of them.

## Decision

The repository gets a small Node test tier for the JavaScript in `assets/`.

- `package.json` is test tooling only. It is `export-ignore`, so a Composer
  install never ships it. `package-lock.json` is git-ignored, as `composer.lock`
  is.
- Tests live in `tests/js/` and use `node:test` with jsdom. They load the same
  jQuery (3.7.1) and jQuery UI (1.14) that WordPress ships, then run the real
  script.
- A script that runs in the editor gets a contract for its cost, not only for its
  behaviour. The tests count the calls it makes while the page is idle and while
  a large DOM changes, and they check that its own writes end the cycle they
  start.
- `composer test:js` runs the tier by hand. CI does not run it. The script changes
  rarely, so a CI job would cost a Node setup on every PR to guard a file that
  stays still. The file header and `AGENTS.md` say that a change to it must pass
  the tests.

jsdom has no layout engine, so the tier cannot prove pixel positions or measure
real frame time. It proves what the script calls and writes. A claim about real
time needs a browser run, and the PR that introduced the tier records one.

## Consequences

- Contributors who touch `assets/` need Node 22.22+, 24.15+ or 26+.
- The package now has a second dependency tree to keep current. Nothing audits
  it automatically: run `npm audit` when you touch the tier.
  jQuery and jQuery UI are pinned to the versions WordPress ships, so a test
  run matches production. The pin moves with WordPress.
- A jsdom upgrade can change timing or `MutationObserver` details. The tests wait
  on the 60 Hz frame clock, not on fixed sleeps inside the script, to stay stable.
- New JavaScript in `assets/` is expected to arrive with tests in this tier.
  `AGENTS.md` and `CONTRIBUTING.md` say so.
- Because CI does not run the tier, a change to the script that skips the tests
  is not caught before merge. The note in the script header is the only guard.
