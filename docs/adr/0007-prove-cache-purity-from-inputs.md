# 0007. Prove cache purity from inputs, never from a render

## Context

Storing formatted output across requests needs one guarantee: that the
formatting is a function of the data, not of the request that happened to run
it. `Helpers::fieldFormatter()` cannot give that guarantee for free. It expands
shortcodes, hands every field to a `field_formatter_{$type}` filter, and renders
Contact Form 7 and WPForms embeds — each of which may read the global post, the
current query, the current user or a nonce.

Get it wrong and nothing looks wrong. One visitor's rendered shortcode is served
to every other visitor, on a page that returns 200 and logs nothing. The stored
value serializes perfectly, so no type check sees it.

Three designs were tried against this, and the first two are the reason the
third is written down.

**Observing the render.** Format the field, then ask whether the output differs
from the input; refuse to store what moved. It survived one review and was
broken in three lines by the next:

```php
add_shortcode( 'contextual', fn () => is_singular( 'product' ) ? 'Buy now' : '[contextual]' );
```

The general failure is duller and more common than that example. An
**unregistered** shortcode is handed straight back by `do_shortcode()`. The
render is a fixed point, the check reports "nothing moved", and the literal
`[foo]` is stored. The day a plugin registers it, every page serves the source
text instead of its output. Observing one render cannot distinguish a pure
formatter from a formatter that happened not to fire.

**Reading the stored meta.** Prove in advance that a menu item's stored strings
hold no `[`, so `do_shortcode()` has nothing to act on. Sound in shape, wrong in
its source. Values arrive through `get_field()`, and ACF can supply one that no
meta row holds: `acf/pre_load_value` short-circuits the database entirely,
`default_value` fills in for an absent row, `acf/load_value` replaces what was
read, and a group or clone sub-field takes its own default the same way. A menu
with provably bracket-free meta could still put `[contextual]` through
`do_shortcode()`.

Both failures share a shape. The first proved the wrong thing; the second proved
the right thing about the wrong data.

## Decision

**Every purity claim is decided from an input, before the work that could
violate it — and from the input the dynamic call actually receives.**

Concretely, for each surface that can be dynamic:

| Surface | Where the claim is decided |
| --- | --- |
| `do_shortcode()` on editor content | the value handed to it, checked for `[` at the call site |
| `field_formatter_{$type}` filters | any callback registered — refuse |
| ACF `acf/load_value` / `acf/pre_load_value`, including variation tags | the file each callback is defined in, against a trusted-root list |
| CF7 / WPForms embeds | counted during the build; the markup carries a nonce, and no input predicts it |
| Field definitions | a hash of the `modified` stamps of the groups read, carried in the key |

Two rules follow from the table rather than sitting beside it.

**Where no static proof exists, refuse rather than assume.** A
`field_formatter_*` callback may read anything and nothing about the value says
which, so its presence ends the matter. `timber_kit_cache_menu_fields` lets a
project overrule that with a claim only its author can honestly make, and
receives the reason so the claim can be specific.

**Where refusing on presence would be wrong, judge by origin.** ACF occupies
`acf/load_value` itself — `_acf_apply_hook_variations()` is what makes
`acf/load_value/type=…` fire at all — and ACFML occupies it on every WPML site.
Refusing on presence would disable the cache almost everywhere. So callbacks are
judged by the directory they are defined in, the same test
`navMenuItemSharingIsSafe()` already applies to ACF location types, and
`timber_kit_trusted_value_load_roots` extends the list.

**Checks are blunt on purpose.** A `[` in a plain-text field is not a shortcode
and the menu is refused anyway. A false refusal costs one uncached menu; a false
accept is wrong on every page.

## Consequences

A cache under this rule refuses more often than one that watches its own output,
and every refusal is a menu rebuilt at full cost. That is the price, and it is
the right way round: the failure it avoids is silent, and the failure it causes
is a page that is merely as slow as before.

**A blunt check must stay blunt.** The temptation is to narrow it — parse the
bracket, confirm the shortcode is registered, decide it is harmless. Every such
narrowing reintroduces a claim about behaviour that only a render could support,
which is the rule this ADR exists to state.

**Absence of a hook is not proof; presence of a value is not proof either.** Both
were tried. A new dynamic surface must be added to the table above with its own
input-side check, not left to a value inspection that happens to catch it today.

**A gate that cannot be shown to fail is not a gate.** Each is verified by
removing it and confirming exactly its own tests break; a stub general enough to
satisfy two different implementations has proven nothing. The field-config
version shipped for one commit reading the wrong ACF screen — it returned a
constant on every menu, versioning nothing — and the tests passed either way,
because the stub returned the same group for any screen. It was caught by
reading a Redis key, not by CI.

**What this does not cover.** A trusted callback that varies on something the
cache key does not carry is undetectable by any of this; trusting a root is a
judgement, and today ACF and WPML/ACFML are trusted because what they vary on —
mechanism and language — is already in the key. A site registering its own ACF
location type that narrows field groups to specific menu items is also outside
the config-version hash.

Guards: `tests/Unit/Helpers/FormatMenuCacheTest.php` holds one test per gate,
each written to fail against the implementation that lacked it, and
`Helpers::menuCacheDecisions()` reports which gate refused so a refusal is a
question that can be answered rather than one that has to be read out of the
source.
