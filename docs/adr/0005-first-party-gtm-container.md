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

**The environment gate is its own constant.** `TIMBERKIT_GTM_ENABLED` decides
when defined; otherwise measurement runs on production only. It is deliberately
not `WP_DEBUG`: that flag says how errors are reported, and a developer who
quietens a log must not silently switch measurement with it. The fallback is
chosen so that forgetting the constant costs nothing on production and still
keeps every other environment out of the data.

The feature is off until configured. An empty `$gtm_containers` — the default —
makes the new `gtm_container()` Twig function delegate to GTM4WP exactly as
before, so upgrading the kit changes no site's markup. That single call site is
what lets a shared layout serve migrated and unmigrated projects at once, and
makes migrating one project a change to its `Base` rather than to the skeleton.

Where both sources are live — configuration present *and* the plugin still
printing a container — the kit stands down and says so under `WP_DEBUG`. Two
loaders would double-count every visit, and a doubling reads as growth.

## Consequences

GTM4WP becomes optional. Sites that need its data layer keep it; the rest drop a
dependency they were upgrading and working around for a snippet. Existing sites
change nothing until someone fills in `Base` — the migration is per project and
reversible by deleting the property.

The kit takes on the loader snippet, which means it now owns a correctness
surface Google publishes and people compare against by eye. The snippet is
asserted byte-for-byte in tests, including the `?l=` / `&l=` difference that the
ID's absence forces.

Two capabilities are deliberately not carried over. GTM environments
(`gtm_auth` / `gtm_preview`) work only against the Google loader and are ignored
for a server-side path, which has no notion of them. The `noscript` iframe is not
emitted: it requires the container ID, so it cannot honour the same privacy
property, and it serves visitors who have JavaScript disabled and therefore
cannot be measured by GTM anyway.

`TIMBERKIT_GTM_ENABLED` is the kit's first environment-aware behaviour. That is a
precedent, and the next feature that wants one should follow this shape rather
than inventing a second.
