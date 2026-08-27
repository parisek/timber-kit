# 0007. Scope the resizer cache key by the source's upload path

## Context

`Resizer` writes every derivative to `wp-content/cache/image/<W>x<H>-<style>/<name>.<fmt>`,
where `<name>` is `sanitize_file_name( basename( $src ) )` — the source file's
name with its directory thrown away. Uploads keep their directory: WordPress
files them under `uploads/<year>/<month>/`.

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

It is derived from `$source_path`, which `resize()` already computes; no new
input and no filename parsing. `Resizer` maps a URL to a path only through the
uploads base pair, so every source it caches lies below the uploads root and the
segment is always well defined — possibly empty, never absent. A theme-directory
image does not resolve and never reaches the cache.

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

Where a site has year/month folders switched off, the relative directory is
empty and the path is byte-identical to the current one. Those sites enable the
flag with no regeneration at all. That is a property of the scheme, not a goal
of it, and the test suite pins it.

`<source-relative-dir>/<name>` is `_wp_attached_file` without its extension.
A derivative's path therefore becomes **derivable from the database** rather than
discoverable by scanning, which lets `cleanup_cached_images()` address the files
it means to delete instead of matching names. The sibling-attachment guard stays:
one file can carry several attachment rows under WPML, and that is independent
of how the path is spelled.

A note on how this decision was reached, because it is the point of the
practice rather than a detail of it: the first version of this ADR added only
the directory, and seven review rounds passed it. The same-directory half was
found by an independent reviewer on a different model, reading the branch
without sight of those rounds — the one thing the repo's own two-reviewer rule
asks for and the one thing that run had skipped.

The migration cannot place a derivative whose name maps to more than one
source — recovering that association is exactly what the old layout destroyed.
Those files are reported and left alone; the new path is empty for them, so they
are re-encoded on first view. The command moves rather than copies (no transient
doubling of the cache), and is idempotent, so an interrupted run is resumed by
running it again.

The added segment is derived from a URL, so it inherits the path-traversal
duty that `sanitize_file_name()` already discharges for the filename. That
protection has to widen to cover it.

`Helpers::resizeImage()` — the legacy path predating `Resizer` — keeps the flat
layout. It is not migrated and not flagged.
