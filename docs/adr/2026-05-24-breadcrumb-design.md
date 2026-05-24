# Breadcrumb refactor — migration to `parisek/timber-kit`

**Status:** Draft (awaiting user approval)
**Date:** 2026-05-24
**Author:** Petr Parimucha + Claude
**Triggered from:** `wordpress-base` audit of WordPress 7.0 vs. existing `\Breadcrumb` class

---

## 1. Goal

Move the project-local `\Breadcrumb` class (`wp-content/themes/starter_theme/classes/Breadcrumb.php`) **upstream into `parisek/timber-kit`** so every downstream WordPress project gets:

- Strategy-based coverage (404, search, date, author, post-type-archive, taxonomy hierarchy, pagination) the current class misses.
- Defensive guards (`WP_Error` from `get_term_link`, ACF Pro absence, WPML normalization in menu trail).
- A `tk`-style configuration surface that mirrors the existing `StarterBase` property idiom (`$menus`, `$article_post_types`, …).
- A filter API (`timber_kit_breadcrumb_*`) for per-project extensions without forking the class.

The project's `classes/Breadcrumb.php` is **deleted entirely**. `wordpress-base` is a create-project template (canonical source new projects clone from), not a long-running site — there are no historical `new \Breadcrumb()` call sites that need a graceful migration path. The class moves upstream cleanly. (See §9 for the rationale and the one-line `Base::timber_context()` migration.)

---

## 2. Scope

| Repo | Changes | Version |
|---|---|---|
| **`parisek/timber-kit`** | `src/Breadcrumb.php` (new), `src/StarterBase.php` (+5 properties + auto-context behind legacy-class guard), `tests/Unit/BreadcrumbTest.php` (~35 brain/monkey tests), `CHANGELOG.md` | 1.6.0 (minor, additive) |
| **`wordpress-base`** | `classes/Base.php` (use `$breadcrumb_*` properties), **delete** `classes/Breadcrumb.php`, **delete** `tests/unit/BreadcrumbTest.php`, `.claude/rules/wordpress/timber-kit.md` (new property rows) | n/a |
| **`tailwind-base`** | **None.** Items shape stays backward-compatible (`type` key is additive) | n/a |

---

## 3. Items shape — typed structured data + hydrated `title`

The class produces an array of items, each carrying a `type` discriminator. Hydration (resolving `title` from labels + structured fields) happens **inside the class** before return — so the consumer (Twig) sees the existing `[{url, title}]` shape with `type` as an extra key.

### Discriminators

| `type` | Extra keys | Hydrated `title` |
|---|---|---|
| `home` | `url` | `$labels['home']` |
| `item` | `url`, `title` (raw from DB) | unchanged |
| `404` | — | `$labels['404']` |
| `search` | `query`, `url` | `sprintf($labels['search'], $query)` |
| `pagination` | `page`, `url` | `sprintf($labels['pagination'], $page)` |
| `author` | `display_name`, `url` | `sprintf($labels['author'], $display_name)` |
| `date_year` | `year`, `url` (linked when month follows, else null) | `(string) $year` |
| `date_month` | `year`, `month`, `url` (linked when day follows, else null) | `wp_date('F', mktime(0,0,0,$month,1,$year))` |
| `date_day` | `year`, `month`, `day` (always leaf, no `url`) | `(string) $day` |

After hydration, every item has `type`, `url` (or `null`), and `title` — Twig reads `item.url` and `item.title` exactly as today.

### Backward-compat invariant

Items shape stays `[{url, title}, …]` *with* extra `type` key. Existing Twig component `breadcrumb.twig` reads `item.url` + `item.title` and ignores `type` — **no template change needed**. Hide rule (`items|length <= 2`) keeps working because Home is now an explicit item in the array (not auto-prepended in view).

---

## 4. `StarterBase` configuration surface

Five new `protected` properties in `Parisek\TimberKit\StarterBase`, mirroring the existing `$menus` / `$article_post_types` idiom:

```php
/** @var string Nav menu location for breadcrumb menu-trail strategy */
protected string $breadcrumb_menu_name = 'main-menu';

/** @var array<string,string> Post type → ACF option key for "listing page" injection */
protected array $breadcrumb_list_page_map = [];

/** @var ?array Post types eligible for menu-trail; null = auto-detect hierarchical */
protected ?array $breadcrumb_menu_trail_post_types = null;

/** @var bool Whether to add "Page N" item on paginated views */
protected bool $breadcrumb_include_pagination = false;

/** @var array<string,string> Default English labels (project overrides via _x() in __construct()) */
protected array $breadcrumb_labels = [
    'home'       => 'Home',
    '404'        => 'Page not found',
    'search'     => 'Search: %s',
    'pagination' => 'Page %d',
    'author'     => 'Author: %s',
];
```

`StarterBase::timber_context()` automatically instantiates `Breadcrumb` and populates `$context['breadcrumb']` — **unless** the project still ships a global `\Breadcrumb` class (legacy convention from pre-migration `wordpress-base` versions). See §4.1 *Legacy compatibility guard* below.

Downstream Base.php overrides properties — never instantiates `Breadcrumb` directly:

```php
// downstream Base.php
class Base extends StarterBase {
    public function __construct() {
        // ... other property overrides ...
        $this->breadcrumb_list_page_map = [ 'post' => 'article_list' ];
        $this->breadcrumb_labels = [
            'home'       => _x('Home', 'starter_theme', 'starter_theme'),
            '404'        => _x('Page not found', 'starter_theme', 'starter_theme'),
            'search'     => _x('Search: %s', 'starter_theme', 'starter_theme'),
            'pagination' => _x('Page %d', 'starter_theme', 'starter_theme'),
            'author'     => _x('Author: %s', 'starter_theme', 'starter_theme'),
        ];
        parent::__construct();
    }
}
```

**No breadcrumb-related code in `Base::timber_context()`** — the parent populates `$context['breadcrumb']` automatically. This is the "full TimberKit" architecture user requested.

`wordpress-base` is a **create-project template** (canonical source new projects clone from), not a running site with historical call sites. Therefore the project drops `classes/Breadcrumb.php` and `tests/unit/BreadcrumbTest.php` **entirely** — no deprecated shim, no shim tests. See §9.

### 4.1 Legacy compatibility guard

To avoid double-computation in projects that consume TimberKit 1.6.0 but haven't yet migrated their `Base.php` away from `new \Breadcrumb()`, the auto-populate is gated on a class-existence check:

```php
public function timber_context( $context ) {
    // ... existing context population ...

    // Populate $context['breadcrumb'] automatically — unless the project
    // still ships a global \Breadcrumb class (legacy convention from
    // wordpress-base versions before this migration). Projects that haven't
    // yet migrated keep their own manual `new \Breadcrumb()` call site
    // in Base::timber_context(); skipping here avoids wasted compute when
    // child::timber_context() overwrites anyway.
    if ( ! class_exists( '\Breadcrumb', false ) ) {
        $bc = new Breadcrumb([
            'menu_name'             => $this->breadcrumb_menu_name,
            'list_page_map'         => $this->breadcrumb_list_page_map,
            'menu_trail_post_types' => $this->breadcrumb_menu_trail_post_types,
            'include_pagination'    => $this->breadcrumb_include_pagination,
            'labels'                => $this->breadcrumb_labels,
        ]);
        $context['breadcrumb'] = $bc->get();
    }

    return $context;
}
```

**`class_exists( '\Breadcrumb', false )`** — the second argument `false` disables the autoloader so the check is a plain class-map lookup, not a filesystem probe. If the project autoloads its own `classes/Breadcrumb.php` (typical Composer classmap setup), the class is already loaded by the time `timber_context()` fires (Base.php constructor runs first), so the check finds it without triggering I/O.

### Behavior matrix

| Project | `\Breadcrumb` defined? | Auto-populate | Outcome |
|---|---|---|---|
| New (post-migration template clone) | ❌ | runs | `$context['breadcrumb']` ready, project just overrides properties |
| Legacy, unmigrated (composer update only) | ✅ | skipped | Child's manual `new \Breadcrumb()` line keeps working; no wasted compute |
| Mid-migration (class deleted, properties not set) | ❌ | runs with English defaults | Functional, slightly unpolished — fix during migration |
| Power user with non-`\Breadcrumb` custom class | ❌ (different namespace) | runs | Child override in `timber_context()` wins; small wasted compute. If common enough later, add opt-out property `$breadcrumb_auto_populate` |

### Rationale: class existence over opt-out property

A `protected bool $breadcrumb_auto_populate = true` opt-out was considered and rejected. Legacy unmigrated projects can't explicitly set the property without touching Base.php — which defeats the "composer update is safe" guarantee. The class-existence check **auto-opts out** for projects shipping the legacy convention, requiring zero code change.

The corollary: if a future use case demands explicit opt-out (e.g., custom non-`\Breadcrumb` class names), add the property then. Start with minimal complexity.

### English defaults rationale

TimberKit ships English raw strings (`'Home'`, `'Page not found'`) as defaults — **not** `_x()` calls. Reason: TimberKit doesn't know the project's text domain. Projects translate by replacing the labels array in their own `__construct()` with `_x()` calls using their own domain. The English defaults guarantee the class never returns `null` titles even if a project forgets to configure labels (graceful degradation).

---

## 5. `Breadcrumb` class — internal architecture

### Public API

```php
namespace Parisek\TimberKit;

class Breadcrumb {
    public function __construct( array $config = [] );
    public function get(): array;
}
```

Constructor accepts a config array with keys matching the property names (`menu_name`, `list_page_map`, `menu_trail_post_types`, `include_pagination`, `labels`). Unknown keys ignored.

### Strategy dispatcher

`get()` is a thin dispatcher; per-query-state logic lives in `protected` methods so each is unit-testable in isolation:

```php
public function get(): array {
    if ( apply_filters( 'timber_kit_breadcrumb_skip', false, $GLOBALS['wp_query'] ?? null ) ) {
        return [];
    }
    if ( is_front_page() && ! is_paged() ) {
        return [];
    }

    $home  = $this->build_home_item();
    $items = match ( true ) {
        is_404()                              => $this->build_for_404(),
        is_search()                           => $this->build_for_search(),
        is_date()                             => $this->build_for_date_archive(),
        is_author()                           => $this->build_for_author_archive(),
        is_post_type_archive()                => $this->build_for_post_type_archive(),
        is_tax() || is_category() || is_tag() => $this->build_for_taxonomy(),
        is_singular()                         => $this->build_for_singular(),
        default                               => [],
    };

    if ( $this->include_pagination && is_paged() ) {
        $items[] = $this->build_pagination_item();
    }

    $all = array_merge( [ $home ], $items );
    $all = apply_filters( 'timber_kit_breadcrumb_items', $all, $this );

    return $this->hydrate( $all );
}
```

### Per-strategy responsibility table

| Method | Returns items for | Notes |
|---|---|---|
| `build_home_item()` | `[{type: 'home', url}]` | Always returned first |
| `build_for_404()` | `[{type: '404'}]` | No URL |
| `build_for_search()` | `[{type: 'search', query, url}]` | Query from `get_search_query()` |
| `build_for_date_archive()` | year/month/day chain | Year linked when month present; month linked when day present |
| `build_for_author_archive()` | `[{type: 'author', display_name, url}]` | |
| `build_for_post_type_archive()` | `[{type: 'item', title, url}]` | Title from `post_type_object->labels->archives` |
| `build_for_taxonomy()` | ancestor terms + current term | Hierarchical ancestors via `get_ancestors()`; `is_string()` guard on `get_term_link()` for `WP_Error` |
| `build_for_singular()` | dispatch by post type | `page` → menu-trail OR ancestors; `post` (or post type in `list_page_map`) → list-page + title; hierarchical CPT → menu-trail OR ancestors; flat CPT → CPT archive label + title |
| `build_pagination_item()` | `[{type: 'pagination', page, url}]` | `url` = page-1 link via `get_pagenum_link(1)` |

### Hydrate

```php
protected function hydrate( array $items ): array {
    $labels = apply_filters( 'timber_kit_breadcrumb_labels', $this->labels, $items );
    foreach ( $items as &$item ) {
        $item['title'] = match ( $item['type'] ?? 'item' ) {
            'home'       => $labels['home']       ?? 'Home',
            '404'        => $labels['404']        ?? 'Page not found',
            'search'     => sprintf( $labels['search']     ?? '%s', $item['query']        ?? '' ),
            'pagination' => sprintf( $labels['pagination'] ?? '%d', $item['page']         ?? 1  ),
            'author'     => sprintf( $labels['author']     ?? '%s', $item['display_name'] ?? '' ),
            'date_year'  => (string) ( $item['year'] ?? '' ),
            'date_month' => wp_date( 'F', mktime( 0, 0, 0, $item['month'] ?? 1, 1, $item['year'] ?? (int) date( 'Y' ) ) ),
            'date_day'   => (string) ( $item['day']  ?? '' ),
            default      => $item['title'] ?? '',  // 'item' carries title from DB
        };
    }
    return $items;
}
```

### Helpers (ported from existing class)

| Helper | Role |
|---|---|
| `by_menu_trail( string $menu_name )` | Walks the named WP nav menu for the parent chain. `apply_filters('wpml_object_id', $queried_id, 'page')` added vs. current implementation |
| `get_menu_item( string $field, mixed $object_id, array $items )` | Pure helper; type-normalization for `wp_get_nav_menu_items` string/int mismatch — unchanged from current class |
| `get_global_links()` | Reads ACF option for `list_page_map` resolution. `function_exists('get_field')` guard added |

---

## 6. Filter API

| Filter | Args | When | Use case |
|---|---|---|---|
| `timber_kit_breadcrumb_items` | `(array $items, Breadcrumb $self)` | After strategy dispatch, before hydrate | Modify the structured item array (add/remove items, transform types) |
| `timber_kit_breadcrumb_labels` | `(array $labels, array $items)` | Inside hydrate, before match | Per-request label override (WPML language-aware, per-page customization) |
| `timber_kit_breadcrumb_skip` | `(bool $skip, ?\WP_Query $query)` | First check in `get()` | Suppress breadcrumb on landing pages |
| `timber_kit_breadcrumb_menu_trail` | `(array $items, string $menu_name)` | After `by_menu_trail()` returns | Customize menu-derived chain |

Naming matches existing `timber_kit_<feature>_<concern>` pattern from `Resizer`, `Helpers::*`, `DevMediaProxy` (14:1 majority vs. the slash-style used by `BlockRenderer`).

---

## 7. Defensive guards (vs. current class)

| Issue (current class) | Location | Fix |
|---|---|---|
| `get()` returns `null` on front_page, `array` otherwise | `get():22` | Sjednotit na `array` return type + `: array` annotation |
| `get_term_link()` returning `WP_Error` not filtered out | `get():60-62` | `is_string($url)` guard before append |
| `get_global_links()` runs always, used only for `post` | `get():25` | Lazy: call inside `case 'post'` branch only |
| Hard ACF dependency (`get_field` fatal if ACF deactivated) | `get_global_links():154` | `function_exists('get_field')` guard |
| `by_menu_trail` doesn't normalize queried object via WPML | `by_menu_trail():97` | `apply_filters('wpml_object_id', $queried_id, 'page')` |
| Hardcoded `'main-menu'` menu name | `by_menu_trail():81` | Configurable via `$menu_name` property |
| No filter hook for downstream override | n/a | Four `timber_kit_breadcrumb_*` filters (§6) |
| Hardcoded translation domain | constructor | Removed — TimberKit ships English defaults, project overrides via `_x()` in own domain |
| `isset()` + `is_countable()` redundancy | `get_global_links():156` | Drop `isset()` — `is_countable(null)` is `false` |
| `array_unshift` in loop (O(n²)) | `by_menu_trail():108` | Append + `array_reverse()` at end (academic, low priority) |

---

## 8. Twig component contract — unchanged

`tailwind-base/static/templates/component/breadcrumb/breadcrumb.twig` consumes `[{url, title}, …]`. The new shape `[{type, url, title}, …]` adds a key Twig doesn't read. **Zero changes to the component.**

The existing hide rule `items|length <= 2 ? 'hidden' : ''` keeps working: Home is now an explicit item (was auto-seeded by the constructor before), so "Home + 1 = 2 items = hide" matches today's behavior.

`aria-label="{{ _x('Breadcrumb', 'tailwind-base', 'tailwind-base') }}"` stays in the template (template-owned translation in `tailwind-base` text domain).

---

## 9. Project: delete `theme/classes/Breadcrumb.php` entirely

After TimberKit 1.6.0 release, the project's `Breadcrumb.php` is **removed** — no deprecated shim, no compatibility bridge.

**Rationale**: `wordpress-base` is the canonical create-project template. New projects clone from it; there are no long-running downstream sites with their own `new \Breadcrumb()` call sites that need a migration period. The only consumer of the class is `Base::timber_context()`, which is updated in the same PR to use property overrides.

**Migration in `Base::timber_context()`**:

```diff
-// breadcrumb
-$breadcrumbs           = new \Breadcrumb();
-$context['breadcrumb'] = $breadcrumbs->get();
+// $context['breadcrumb'] is auto-populated by parent via $breadcrumb_* properties
```

**Files deleted** in PR #2:

- `wp-content/themes/starter_theme/classes/Breadcrumb.php`
- `wp-content/themes/starter_theme/tests/unit/BreadcrumbTest.php`

**Optional check at PR time**: `git grep -n "new \\\\Breadcrumb\|new Breadcrumb" wp-content/themes/starter_theme/` to confirm no other call sites exist. Expected: only `classes/Base.php:88` (the line being removed in the same PR).

---

## 10. Test plan

### TimberKit `tests/Unit/BreadcrumbTest.php` (~35 tests, brain/monkey)

| Group | Count | Coverage |
|---|---|---|
| Helper methods (ported) | 8 | `get_menu_item` (4), `by_menu_trail` (2), `get_global_links` (2) |
| Strategy dispatchers | 15 | Each `build_for_*` gets 1-3 tests covering its known branches (see §5 table) |
| Configuration | 3 | constructor with config, defaults, partial override |
| Filters | 4 | `_items`, `_labels`, `_skip`, `_menu_trail` |
| Edge cases | 5 | `WP_Error` from `get_term_link`, ACF unavailable, WPML object_id normalization, pagination, flat CPT fallthrough |

**Mocking**: `brain/monkey` (TimberKit convention) replaces `WP_Mock` (current project convention). `Functions\when()` for stubs, `Functions\expect()` for assertions, `Filters\expectApplied()` for filter integration.

### Project `tests/unit/BreadcrumbTest.php` — **deleted entirely**

No shim, no shim tests. All 8 existing helper tests are deleted from the project; equivalent functional coverage is in TimberKit's `tests/Unit/BreadcrumbTest.php` (rewritten via brain/monkey).

If a future need arises to assert that `Base` correctly wires `$breadcrumb_*` properties (e.g., specific list_page_map value), that becomes an integration test under a different name — out of scope here.

---

## 11. Migration mechanics — PR sequencing

### PR #1 — `parisek/timber-kit` (draft, assignee parisek)

- `src/Breadcrumb.php` (new)
- `src/StarterBase.php` (+5 properties + auto-populate in `timber_context()`)
- `tests/Unit/BreadcrumbTest.php` (~35 brain/monkey tests)
- `CHANGELOG.md` entry with migration note for downstream consumers
- **Git tag `v1.6.0`** at merge time. (Library `composer.json` doesn't carry a `version` field — Composer reads tags. Setting `version` in `composer.json` for a library is an anti-pattern.)

Draft + assignee per `tailwind-base`-style doctrine (chat-first → draft → owner review). User merges + tags `v1.6.0` when satisfied.

### PR #2 — `wordpress-base` project (after #1 merged + tagged)

- `composer.json`: `parisek/timber-kit: ^1.6`
- `composer update parisek/timber-kit`
- `classes/Base.php`:
  - Add `$this->breadcrumb_list_page_map` + `$this->breadcrumb_labels` overrides in `__construct()`
  - Remove `$breadcrumbs = new \Breadcrumb(); $context['breadcrumb'] = $breadcrumbs->get();` from `timber_context()`
- **Delete** `classes/Breadcrumb.php` (no shim — see §9 rationale)
- **Delete** `tests/unit/BreadcrumbTest.php` (helpers moved upstream)
- `.claude/rules/wordpress/timber-kit.md`: add 5 new property rows to the "Base.php configurable properties" table

Pre-flight check: `git grep "new \\\\Breadcrumb\|new Breadcrumb" wp-content/themes/starter_theme/` should return only the `classes/Base.php` line being removed in the same PR.

### PR #3 — `tailwind-base` Twig component

**Not needed.** No changes per §8.

---

## 12. Risks & open questions

### Risks

| Risk | Mitigation |
|---|---|
| Other downstream projects depend on items shape *not* containing `type` key (e.g. JSON-encode for JS) | Additive key is non-breaking for `json_encode()` — extra field. No known consumer reads via `array_keys()` strict check. Spot-check at sync time. |
| `wp_date` not available pre-WP-5.3 | Project targets WP 7.0; non-issue. TimberKit's existing code already uses modern WP APIs (PHP 8.3+, Timber 2.0+). |
| `is_singular()` for paginated singles double-counts pagination | Pagination is appended only when `include_pagination` is `true` (default `false`) — opt-in keeps default safe. |
| `breadcrumb_labels` left as English defaults in a non-EN project | Class never returns `null` title; falls back to English. Visible but graceful. CHANGELOG migration note flags this for review. |
| Two filter prefixes co-exist (`tk_*` rejected → `timber_kit_*`) | Confirmed during brainstorming: `timber_kit_` matches existing 14:1 majority in TimberKit. Single style going forward. |
| Legacy unmigrated project composer-updates TimberKit, doubles compute on breadcrumb | Class-existence guard (`class_exists('\Breadcrumb', false)`) skips auto-populate when legacy convention detected. See §4.1. |

### Open questions (deferred to PR #1 implementation)

| Question | Default if unanswered |
|---|---|
| TimberKit repo URL (GitHub org/name) | Determine from `composer show -s parisek/timber-kit` or ask user before cloning |
| Should `Breadcrumb` register a Twig function (e.g. `{{ breadcrumb_items() }}` in pages without `$context`) | Out of scope for 1.6.0 — Twig context wiring is StarterBase's job |
| Should TimberKit ship a default `breadcrumb.twig` component? | No — view layer lives in `tailwind-base`. TimberKit is PHP runtime, not template provider. |

---

## 13. Decisions made during brainstorming

| Decision | Choice | Rationale |
|---|---|---|
| Where does the class live? | `parisek/timber-kit` only; project copy deleted entirely | Matches "upstream first" doctrine; `wordpress-base` is a create-project template with no legacy call sites to preserve |
| Items shape | `[{type, url, title, …extras}]` | `type` discriminator is additive (no Twig change); structured extras allow view-agnostic data flow |
| Translation strategy | Class accepts label dict in config; project provides `_x()`-translated dict | TimberKit translation-free; project owns text domain |
| Filter prefix | `timber_kit_breadcrumb_*` | Match existing `timber_kit_*` convention in Resizer / Helpers / DevMediaProxy (14:1 majority) |
| Where do project-level labels live? | `$breadcrumb_labels` property on Base (override in `__construct`) | Matches existing `$menus` / `$article_post_types` property idiom |
| Where does StarterBase populate `$context['breadcrumb']`? | Inside `timber_context()`, no Base.php call needed | "Full TimberKit" — project just configures properties |
| Twig component change | None | Items shape is shape-compatible; component reads `url` + `title`, ignores `type` |
| Test framework in TimberKit | `brain/monkey` | TimberKit convention; project's WP_Mock tests get rewritten, not ported |
| Version bump | `v1.6.0` git tag (minor, additive) | Class is new in TimberKit; StarterBase additions are property-additive; no existing API breaks. Library `composer.json` doesn't carry `version` — Composer reads git tags. |
| PR sequencing | TimberKit first → tag → project follows | Standard upstream-first; project's `composer require ^1.6` needs the tag to exist |
| Project-side deprecated shim | **None** — class deleted outright | `wordpress-base` is a create-project template, not a long-running site; no historical call sites to preserve. Trades graceful migration for cleaner skeleton. |
| Legacy projects on composer-only update | `class_exists('\Breadcrumb', false)` guard in `StarterBase::timber_context()` | Legacy convention auto-opt-out; no Base.php change required for safety. Migrated projects (where class is deleted) get auto-populate. See §4.1. |

---

## 14. References

- `wp-content/themes/starter_theme/classes/Breadcrumb.php` — current implementation
- `wp-content/themes/starter_theme/tests/unit/BreadcrumbTest.php` — current 8 WP_Mock helper tests
- `wp-content/themes/starter_theme/vendor/parisek/timber-kit/src/StarterBase.php` — property idiom + `timber_context()` host
- `wp-content/themes/starter_theme/vendor/parisek/timber-kit/src/Helpers.php` — existing `timber_kit_*` filter naming
- `wp-includes/blocks/breadcrumbs.php` — WP 7.0 native block (reviewed during brainstorming; rejected as data source due to HTML-only render contract)
- `.claude/rules/wordpress/timber-kit.md` — `Base.php` configurable properties doctrine
- `.claude/rules/meta/changelog.md` — CHANGELOG entry format for refs/footer
- `AGENTS.md` § Rules upstream-first doctrine — applies in spirit (TimberKit is a separate package but same upstream concept)
