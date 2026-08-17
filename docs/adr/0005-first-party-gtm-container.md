# 0005. Load the GTM container from the kit, configured in code

## Context

Every Porta Design WordPress site loads Google Tag Manager through the GTM4WP
plugin. On a site without WooCommerce the plugin's entire frontend output is the
loader snippet, a `noscript` iframe, and an empty data layer — measured on one
production site, `dataLayer_content` was literally `[]` and all ~40 data-layer
options were off. The plugin's value is its ecommerce data layer, which those
sites never asked for.

The cost of that arrangement became concrete when a tagging vendor asked for the
container ID to be dropped from the loader URL. A container reached through its
own randomly generated server-side path does not need the ID, and repeating it
hands blockers the very pattern the random path exists to avoid. GTM4WP 1.22
builds the URL as one hardcoded string with `?id='+i` in it, exposes no filter
over that string, and rejects any custom path containing `?` or `=` — silently
falling back to `gtm.js`. The setting arrived in GTM4WP 2.0, which at the time of
writing ships only as a beta the author marks as not for production.

So a one-parameter change to a URL was blocked by a dependency the site was
using for nothing else. That is the trade the plugin was making all along; the
request only made it visible.

A second problem shipped with it. The plugin has no notion of environment, so
projects keep it out of local development by listing it in `DEACTIVATE_PLUGINS`
and re-deactivating it after every database pull. Measurement is therefore
governed by an operations detail, in a file that is easy to get wrong in the
direction that pollutes production data.

## Decision

The kit loads GTM itself, from configuration declared in the theme's `Base`:

```php
protected array $gtm_containers = array(
    'default' => array( 'id' => 'GTM-XXXXXXX', 'domain' => '…', 'path' => '…' ),
    'de'      => array( 'id' => 'GTM-YYYYYYY' ),
);
```

Four properties of that decision are the decision:

**Configuration lives in code, not in the database.** The container is not
content. It does not change over a site's life, it must not be editable by
accident, and when it does change the change belongs in a commit with a name and
a date on it. Earlier projects modelled it as an ACF field; that put a value
nobody should touch in front of everybody, and put it outside review.

**A stated path means the ID leaves the URL.** GTM4WP 2.0 makes this a separate
checkbox because it must stay compatible with thousands of existing installs.
The kit has no such history: one path selects one container, always, so the two
settings are one setting.

**Containers are keyed by language, with inheritance.** A WPML site reports on
one container per language. A language entry states only what differs from
`default` — normally the ID alone, because the tagging endpoint is shared —
so changing the endpoint is one edit rather than one per language.

A language written out and left blank is switched off rather than inherited.
In a model where every gap falls through to `default`, that is the only way to
say "do not measure here" at all.

Inheritance runs along the language code as well as into `default`, because
WPML codes are per-site strings an editor can type. A site may run `de`, or
`de-at` beside it, or spell either with an underscore. So resolution walks from
the most specific code to the least — `de-at`, then `de`, then `default` — and
folds case and separator first. A regional variant therefore reports with its
language's container until someone gives it one of its own, which is the wrong
answer that is cheapest to correct; falling straight to `default` would file an
Austrian visitor under the site's main language and look like data.

**The environment gate is its own constant.** `TIMBERKIT_GTM_ENABLED` decides
when defined; otherwise measurement runs on production only. It is deliberately
not `WP_DEBUG`: that flag says how errors are reported, and a developer who
quietens a log must not silently switch measurement with it. The fallback is
chosen so that forgetting the constant costs nothing on production and still
keeps every other environment out of the data.

The feature is off until configured. An empty `$gtm_containers` — the default —
makes `gtm_container()` print nothing and `gtm_container_noscript()` delegate to
GTM4WP, so upgrading the kit changes no site's markup. The delegation sits on
the `<body>` call deliberately: `gtm4wp_the_gtm_tag()` emits the plugin's
`noscript` iframe and nothing else, because the plugin injects its own script
through `wp_head`. Delegating from the `<head>` call would put an iframe there
and leave the loader printed twice. With each call standing in the same place as
the thing it replaces, one shared layout serves migrated and unmigrated projects
at once, and migrating one project is a change to its `Base` rather than to the
skeleton.

Where both sources are live — configuration present *and* the plugin still
printing a container — two loaders fire and every visit is counted twice, which
reads as growth rather than as a fault. The loader nonetheless **does not
inspect the plugin**. Reading another plugin's stored settings is a guess about
a schema this kit does not own, and the two ways of being wrong are not
symmetric: a guess that suppresses the loader stops measurement silently, while
one that does not suppress it costs a doubling that is visible in GTM's own
preview and in the data. The state is diagnosed in Site Health
(`gtm_container_not_duplicated`) instead — where the same coupling is harmless,
because a stale reading shows a wrong line on a board rather than emptying a
report.

## Consequences

GTM4WP becomes optional. Sites that need its data layer keep it; the rest drop a
dependency they were upgrading and working around for a snippet. Existing sites
change nothing until someone fills in `Base` — the migration is per project and
reversible by deleting the property.

The kit takes on the loader snippet, which means it now owns a correctness
surface Google publishes and people compare against by eye. What it emits is
that snippet verbatim — same line breaks, same comments, no vendor attributes
and no generator marks — so the page source reads as a hand-pasted installation
and carries exactly one intended difference: the id-less URL. Whole-output tests
pin both shapes, alongside every refusal path.

Copying the plugin's markup would have been the easier default and was the first
attempt. It brought `data-cfasync` and `data-pagespeed-no-defer` with it —
attributes that belong to one vendor's hosting concerns, not to GTM — and those
are precisely what marks a page as plugin-generated.

The `noscript` iframe is emitted, but conditionally, because it is the one part
of the installation that cannot follow the ID rule: `ns.html` takes the
container ID as a query parameter and has no ID-less form. So it prints by
default where the ID is already public in the loader URL, and stays silent where
a custom path exists to keep it out — a per-container `noscript` flag overrides
either way, because whether a site would rather have the no-JS fallback than the
hidden ID is the site's call, not the kit's. It is a second Twig function
rather than part of the first: the two blocks belong at different points in the
document, and only one of them is always emitted.

One capability is deliberately not carried over. GTM environments
(`gtm_auth` / `gtm_preview`) work only against the Google loader and are ignored
for a server-side path, which has no notion of them.

`TIMBERKIT_GTM_ENABLED` is the kit's first environment-aware behaviour. That is a
precedent, and the next feature that wants one should follow this shape rather
than inventing a second.
