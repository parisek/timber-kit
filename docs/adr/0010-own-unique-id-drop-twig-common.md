# 0010. Own `uniqueId()` in the kit and drop `parisek/twig-common`

## Context

`parisek/twig-common` was a small Twig extension from 2021. It offered four
things: the `uniqueId()` function, the `yaml_parse` and `t` filters, and a
`{% trans %}` tag. The kit required it and `StarterBase::timber_twig()`
registered it from the first commit of `StarterBase`.

The package aged out. It had no CI, no tests and no changelog. Its
`{% trans with {...} %}` form called the `\Twig_Token` class, which Twig 3
removed, so that form fatalled. The owner archived the repository.

Only `uniqueId()` is still in use: the accordion, dialog, faq and footer
components call it, and a lint rule (`UniqueIdRequiredRule`) enforces it. The
styleguide and `drupal-kit` each have their own copy. The kit did not, so on
WordPress `twig-common` was the only source. `yaml_parse`, `t` and `trans`
were Drupal-style helpers from older projects. The owner confirmed that no
project in the fleet uses them.

## Decision

The kit owns `uniqueId()`: `Parisek\TimberKit\Twig\UniqueIdExtension`, a
`final` class registered by `StarterBase`. The behaviour is the same: one
letter, then six hex digits, never repeated within one extension instance.

The kit no longer requires `parisek/twig-common`. The `yaml_parse` and `t`
filters and the `{% trans %}` tag are removed without a replacement.

`Parisek\Twig\CommonExtension` stays resolvable as a `class_alias` of the new
class (`compat/aliases.php`). The alias is set only when the name does not
already resolve, so a project that still installs `twig-common` keeps the
original class.

Rejected: keeping the dependency (an archived package gets no fixes), and
copying the whole extension (it would carry three unused features and a tag
that is broken on Twig 3).

## Consequences

- No archived package in the dependency tree. One class less to trust.
- The removal of the three Twig features is a behaviour change without a flag.
  `AGENTS.md` asks for a flag. This is an owner-approved exception: the
  features have no users, so a flag would protect nobody. It is recorded as
  `Removed` in `CHANGELOG.md`.
- A theme that registers `Parisek\Twig\CommonExtension` itself still works,
  but only if `StarterBase` does not register the extension too. With both,
  Twig throws "already registered", as it did before this change.
- A theme that uses `yaml_parse`, `|t` or `{% trans %}` gets an "Unknown
  filter" or "Unknown tag" error from Twig at render time. A new project that
  needs one writes a local extension.
- Guards: `UniqueIdExtensionTest` pins the id format, the uniqueness and the
  legacy class name. `UniqueIdWiringTest` pins the registration in
  `timber_twig()`.
