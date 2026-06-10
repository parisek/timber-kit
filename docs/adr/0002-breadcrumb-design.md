# 0002. Move Breadcrumb upstream into the kit with typed items

## Context

Each downstream project carried its own copy of a `\Breadcrumb` class in the
theme. That copy missed coverage every project eventually needs (404, search,
date, author, post-type archive, taxonomy hierarchy, pagination) and lacked
defensive guards (`WP_Error` from `get_term_link`, ACF Pro absence, WPML
normalisation of the menu trail). Fixing it once per project meant the same bugs
recurred across the fleet.

The kit (`parisek/timber-kit`) is the natural upstream: it already owns the
`StarterBase` property idiom (`$menus`, `$article_post_types`, …) and a
`timber_kit_*` filter convention. The downstream `wordpress-base` is a
*create-project template* — new projects clone it; there are no long-running
sites with historical `new \Breadcrumb()` call sites that need a migration shim.

## Decision

Move the class into the kit as `src/Breadcrumb.php` and **delete the project
copy outright** — no deprecated shim. Key shape decisions:

- **Typed items.** Each item carries a `type` discriminator (`home`, `item`,
  `404`, `search`, `pagination`, `author`, `date_year/month/day`). The class
  hydrates `title` from labels internally before returning, so a Twig component
  still reads the existing `{url, title}` shape and ignores the extra `type`
  key — no template change.
- **Configuration via `StarterBase` properties** (`$breadcrumb_*`), mirroring
  the existing property idiom. `StarterBase::timber_context()` auto-populates
  `$context['breadcrumb']`, gated on `class_exists('\Breadcrumb', false)` so an
  unmigrated project that still ships the legacy global class auto-opts-out and
  keeps its own call site working with no Base.php change.
- **Translation-free kit.** It ships English-default labels; projects override
  them in their own text domain. The kit can't know a project's domain, and a
  default guarantees titles are never `null`.
- **Extension via `timber_kit_breadcrumb_*` filters** (items, labels, skip,
  menu-trail), matching the existing `timber_kit_*` majority over the
  slash-style used elsewhere.

## Consequences

- One upstream Breadcrumb with full strategy coverage and guards; downstream
  projects configure properties instead of forking.
- Backward-compatible: the additive `type` key is ignored by existing
  components, and the `items|length <= 2` hide rule still holds because Home is
  now an explicit item in the array.
- The `class_exists` guard prevents double-compute for projects that
  composer-update the kit before migrating their `Base.php`; migrated projects
  (legacy class deleted) get auto-populate for free.
- English-default labels mean a project that forgets to configure labels
  degrades gracefully (visible English) rather than rendering empty titles.
- Guard: `tests/Unit/BreadcrumbTest.php` (Brain\Monkey).
- This record distils what began as a long-form migration plan; the full
  blow-by-blow (per-strategy tables, PR sequencing, risk matrix) lives in git
  history and the originating discussion, not here.
