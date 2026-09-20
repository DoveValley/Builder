# Pic Drop — Per-Domain / Per-Template Image Management

**Read this before touching `includes/picdrop.php`, `admin/picdrop_api.php`, or
`admin/tabs/picdrop.php`.** Pic Drop is the admin tab for managing a site's real (uploaded) and AI
photos slot-by-slot — for a solo single-tenant site, per PAGE; for a multisite master, per
TEMPLATE, because a real fleet's city-page count makes per-page rows unworkable (100 cities would
mean 100 rows for the same logical photo).

## Slot addressing

Every manageable photo field is addressed by one string key: `"scope:id:blockIndex:fieldPath"`.
Scopes: `global` (logo/favicon), `home`, `core` (a non-landing page like About Us), `landing` (a
built city page), and `template` (a multisite master's landing-page template, added for
template-level management). `picdrop_parse_key()`/`picdrop_find_slot()` (`includes/picdrop.php`)
resolve a key to its actual field location; `template` reads from `TEMPLATES_FILE`, everything else
from the relevant `site.json`/page file.

## Real vs. AI, side by side

`picdrop_pairs.json` (per-site) holds a Real/AI pair for each slot key — whichever one is active is
what actually renders; switching between them (or regenerating the AI side) doesn't touch the
other. This mechanic is identical for a `template` row and a `landing`/`home` row — Pic Drop's
redesign to add template-level rows reused every bit of the existing drop/library/AI-generate
widget rather than inventing a second storage or generation path. `picdrop_apply_edits()` is the
one function both a template edit and a page edit ultimately funnel through.

## "Also replace elsewhere" — matches differently for a template row

For a `landing`/`home` row, propagating an edit to other pages that share the same photo works by
**exact file match** (`picdrop_matching_slots()`) — those pages currently hold the literal same
filename, so finding them is a value comparison.

That doesn't work for a `template` row, because by the time a template's cities have been built,
per-city differentiation has already made every page's photo its own distinct file (deliberate —
keeps every domain's images unique for search engines). Matching a template edit to its built pages
by file-value would find zero matches even though the pages are genuinely "built from this
template." `picdrop_pages_for_template($templateId, $templateBlockIndex, $field)`
(`includes/picdrop.php`) solves this differently: it matches by **block TYPE + ordinal position**
within the template (the Nth block of type X), then finds the same Nth occurrence of that type on
each real page built from that template — structural identity, not byte identity.
`picdrop_template_matches()`/`picdrop_matching_slots()` route to this function specifically when the
edit's scope is `template`.

## What Pic Drop does NOT touch

- **Batch (cloning brand-new domains)** is unaffected — it picks up whatever photo is sitting on
  the template at clone time; Pic Drop only changes what that photo currently is.
- **Home-page AI photos in a real batch run** are a separate system (`includes/multisite/
  image_ai.php`, see the AI Content Cache/batch docs) — Pic Drop is the admin-side approval flow
  that decides what an AI photo LOOKS like; batch-time generation increasingly edits FROM that
  approved reference rather than generating fresh, specifically so batch results stay visually
  close to what was approved here.
- **AI-written page text** — completely separate from Pic Drop, handled by `generate.py`/the
  archetype system (`docs/niche-brief-and-research.md`).

## Known sharp edges

- **A filename outlives its file.** Deleting a photo from the Media Library leaves live references
  in `templates.json`, `media.json`, AND `picdrop_pairs.json`'s cached AI pair — only a real BUILD
  surfaces a dangling reference as a 404, because Pic Drop and the media library don't cross-check
  each other's storage. A cleanup scan for this must print how many slots it actually checked, not
  just how many it found broken — a scan that silently checked zero is indistinguishable from a
  scan that checked everything and found nothing clean.
- **A reference-mode AI edit's temp file must not be deleted before every use of it is done.** A
  past bug in `admin/picdrop_api.php`'s AI-generate action cleaned up the downloaded reference
  image before `img_optimize()`'s own backup-copy step tried to read it — silently breaking "Adjust
  view" zoom-out for every AI-generated photo, not just failing loudly.
