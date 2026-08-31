# 0008. Scope the resizer cache key by the source's upload path

## Context

`Resizer` writes every derivative to `wp-content/cache/image/<W>x<H>-<style>/<name>.<fmt>`,
where `<name>` is `pathinfo( basename( $src ), PATHINFO_FILENAME )` run through
`sanitize_file_name()` — the source file's name with its directory *and* its
own extension thrown away. Uploads keep their directory: WordPress files them
under `uploads/<year>/<month>/`.

So the cache namespace is flatter than the namespace it caches — flatter twice
over, because the derivative is named for the source *without its extension*, so
`11.png` and `11.jpg` land on one path as surely as two `11.png` in different
months do. Measured on a five-language production site: **496 names map to more
than one source file, and 152 of those collide inside a single directory** —
same name, different extension. The two axes are independent, and a fix that
addresses only one leaves the other exactly as it was.

```
900x0-center/11.avif   <-  2022/03/11.png
                       <-  2022/04/11.png
                       <-  2022/08/11.png
                       <-  2022/10/11.png
```

Whichever renders first writes the file; the rest read it and get a picture of
something else. Nothing errors, and the derivative is a plausible image, so the
failure is invisible until somebody recognises the wrong photograph on a page.

The same flatness reaches the delete path. `StarterBase::cleanup_cached_images()`
cannot address a derivative, so it scans the tree and matches basenames — which
is why deleting one attachment could remove another's images.

Three shapes were considered. Putting the segment **above** the size directory
(`<year>/<month>/<size>/<name>`) groups a month together and makes "purge March
2022" one delete, but turns "drop a discontinued breakpoint" into a tree scan;
today the trade runs the other way, and nothing here purges by month. Folding
the path **into the filename** (`2026-08-hero.avif`) adds no directories but lets
a derivative's name collide with a genuinely uploaded `2026-08-hero.png` —
trading one silent collision for a subtler one.

## Decision

Key the derivative by the source's **whole identity** — its directory below the
uploads root, and its full filename including the source's own extension:

```
<W>x<H>-<style>[-q<N>]/<source-relative-dir>/<source-filename>.<fmt>
```

So `uploads/2026/08/hero.webp` at 900px wide becomes
`900x0-center/2026/08/hero.webp.avif`.

Both halves are load-bearing and neither substitutes for the other. The
directory separates `2022/03/11.png` from `2022/10/11.png`; the retained source
extension separates `hero.jpg` from `hero.png` in one directory. Dropping either
half leaves one of the two collision axes untouched — and the 152 same-directory
collisions measured above are the half that is easy to forget, because adding a
directory *feels* like it has separated everything.

It is derived from the source's own URL, not `$source_path`: the directory half
must be resolvable before `resize()` knows whether the local file exists (a
missing source is served by a filter that addresses the same cache path on
another host), and the filename half must agree with what
`StarterBase::cached_derivative_paths_by_source_path()` and the migration
command compute from `_wp_attached_file`. Both halves parse the URL the same
way: strip the query string and decode each `%`-encoded component — a URL is
encoded where the database value is not, so skipping the decode step would make
the writer and the deleter/migrator name the same source differently. The
decoded component is then used exactly as it stands. (This paragraph originally
said the decoded component is passed through `sanitize_file_name()`. The
amendment below retracts that; the amendment is what binds.) `Resizer` maps a URL to a path only through
the uploads base pair, so every source it caches lies below the uploads root
and the directory segment is always well defined — possibly empty, never
absent. A theme-directory image does not resolve and never reaches the cache.

The behaviour ships behind `StarterBase::$resizer_source_path_in_cache_key`
(filter `timber_kit_resizer_source_path_in_cache_key`), default `false`,
alongside the `$resizer_quality_in_cache_key` flag it mirrors.

The cache root stays `wp-content/cache/image`. A new
`wp timber-kit migrate-image-cache` moves existing derivatives into the new
shape in place, dry-run by default.

## Consequences

**Enabling the flag invalidates every cached derivative**, which is why it
defaults off and why the change is not simply applied. On a site with rewritten
uploads this is thousands of files; regenerating them costs encoder time, and on
a host whose encoder is defective it costs correctness. The decision is the
project's to take on its own schedule, not a consequence of `composer update`.

Where a site has year/month folders switched off (a root upload), the relative
directory is empty, but the path is *not* generally byte-identical to the
current one: the target name still gains the source's own extension
(`hero.avif` becomes `hero.png.avif`), so a root upload needs migrating too.
The one exception is a root upload whose own filename already carries no
extension — there the target and the current flat name coincide, and the test
suite pins exactly that case, not the broader one.

`<source-relative-dir>/<source-filename>` is `_wp_attached_file` in full,
extension included — not the extension-stripped form the flat layout used.
A derivative's path therefore becomes **derivable from the database** rather than
discoverable by scanning, which lets `cleanup_cached_images()` address the files
it means to delete instead of matching names. The sibling-attachment guard stays:
one file can carry several attachment rows under WPML, and that is independent
of how the path is spelled.

### Amendment: the key is the stored name, not a sanitized rewrite of it

The first version of this decision built the key from `sanitize_file_name()`'s
output and argued the resulting collisions were theoretical: zero occurrences
of `[` or `]` in `_wp_attached_file` on the site surveyed, and WordPress
sanitizes at upload anyway, so a stored value is already a fixpoint.

Both halves of that argument are wrong, and a deployment showed it.

`sanitize_file_name()` is **filterable**, and this package is one of the things
filtering it. `StarterBase::clean_uploaded_filename()` — registered whenever
`$clean_image_filenames` is true, which is the **default** — removes accents,
lowercases, maps whitespace and underscores to hyphens, and strips everything
that is not alphanumeric or a hyphen.

That is correct behaviour at *upload*, where it is a deliberate normalisation.
Reusing it at *generation* made the key neither *stable over time* nor
*injective*:

- Not stable. A derivative written before the filter existed is addressed
  afterwards under a different spelling. Every future lookup — including the
  delete — points away from what is on disk.
- Not injective. `usp_1.webp` and `usp-1.webp` sanitize to one name, as do
  `Ebook.svg` and `ebook.svg`. **Eight such pairs** were measured on one site;
  six are visibly different images, two are duplicate uploads. Six is the
  number that matters — those are the ones where the reader is shown the wrong
  picture.

So this is not one site's quirk. Any consumer leaving `$clean_image_filenames`
at its default — the whole fleet the package was written for — keys its cache
through a deliberately lossy normalisation. The first version of this ADR
argued the collisions were theoretical because it was looking for a *third
party* doing something unusual; the collapsing function ships in this
repository.

The second failure is the worse one, because it does not merely leave a
collision unfixed. `MigrateImageCacheCommand` deduped its candidate list by
the sanitized path, so a collapsing pair became *one* candidate: the name then
looked unambiguous and the planner moved a derivative into a path two
different uploads claim — a confident wrong placement in the one bucket this
design promises never to guess in.

The premise underneath all of it was never examined: **a name is sanitized at
upload; there is no reason to sanitize it again at generation.** The stored
value is already what WordPress decided to call the file. Re-deriving a
spelling from it, with today's filter stack, discards information the database
had and invents an identity nothing else shares.

So the key is now the stored name **verbatim** — decoded, since the writer
reads a URL and the deleter reads the database, but never rewritten. The guard
becomes a refusal rather than a repair: a component is used exactly as stored
unless it could leave the directory it is written into (empty, `.`, `..`, or
carrying a separator or a NUL). That rule is the same for the directory half
and the filename half, where previously only the directory half had it.

One thing had to change for this to be possible. The delete looked its files
up with `glob( "$name.*" )`, and `glob()` has no way to quote a
metacharacter — which is the *actual* reason the name was being rewritten. It
now reads the directory and compares the name as a string, so `img[0-9].png`
is matched literally. Removing the pattern removed the only argument for the
rewrite.

Two costs, accepted knowingly. The derivative's public URL must now
percent-encode each path segment, because a stored name may legitimately carry
a space, `#` or `%`. And on a **case-insensitive filesystem** `Ebook.svg.avif`
and `ebook.svg.avif` are still one file — that is the filesystem, not the key,
and no spelling rule inside this package can change it.

The migration keeps *both* spellings on purpose: the legacy flat names it
reads were written by the old writer, so it reproduces the sanitized spelling
to find them, while recording the new identity verbatim. Reading a historical
artefact means spelling it the historical way.

A note on how this decision was reached, because it is the point of the
practice rather than a detail of it: the first version of this ADR added only
the directory, and seven review rounds passed it. The same-directory half was
found by an independent reviewer on a different model, reading the branch
without sight of those rounds — the one thing the repo's own two-reviewer rule
asks for and the one thing that run had skipped.

The migration ignores a source the resizer could never have decoded. An SVG or
a PDF sharing a stem with a real image made that image's derivative look
contested and left it unmigrated — 30 of 329 ambiguous entries on the site
measured. The set it tests against is the static format list *widened* by
`timber_kit_resizer_allowed_types` and never narrowed by the live backend:
narrowing would drop a source that was decodable when the file was written,
make a surviving candidate look unique, and move another attachment's
derivative into it.

The migration cannot place a derivative whose name maps to more than one
source — recovering that association is exactly what the old layout destroyed.
Those files are reported and left alone; the new path is empty for them, so they
are re-encoded on first view. The command moves rather than copies (no transient
doubling of the cache), and is idempotent, so an interrupted run is resumed by
running it again — including for a root upload, whose migrated derivative
lands at the same depth in the size directory as an unmigrated legacy one, and
so is told apart from it by matching against the source's own full name rather
than by where it sits in the tree.

The added segment is derived from a URL, so it carries a path-traversal duty.
Both halves of the key now discharge it the same way — by refusing a component
that could escape its directory, never by repairing one.

`Helpers::resizeImage()` — the legacy path predating `Resizer` — keeps the flat
layout. It is not migrated and not flagged.
