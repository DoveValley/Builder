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

## "Also replace elsewhere" — page/home/core rows only; a template row never propagates

For a `landing`/`home`/`core` row, propagating an edit to other pages that share the same photo works
by **exact file match**: `picdrop_matching_slots()` (any slot, any scope) and
`picdrop_template_matches()` (other templates) both find anything currently holding the literal same
filename, and `pd_commit()` (`admin/picdrop_api.php`) writes the new value into all of them too when
`propagate=1`.

**A `template`-scope edit never propagates, full stop** — `pd_commit()` skips this entire block when
`$parts['scope'] === 'template']`, regardless of whether `propagate` was sent. One Pic Drop action
(upload/generate/adjust) on a template touches exactly the one field it was run on. The "Also replace
elsewhere" checkbox is never even rendered for a template row (`$propShow` is hardcoded `false` there
in `admin/tabs/picdrop.php`).

This used to be more clever and much more dangerous. A template row once got the EXACT SAME
byte-identical matchers page rows use — meaning editing one template could silently reach into ANY
OTHER template that happened to share the same current photo. A real incident, not a theoretical one:
templates commonly share a generic starting photo before each is individually customized, so checking
the then-default-ON "also replace elsewhere" box on ONE template — normal, expected use — silently
overwrote seven *unrelated* mold-removal templates (crawl space, basement, bathroom, kitchen, carpet,
garage, drywall) that still happened to share that starting photo, with the checkbox's own label
("Also replace on this template's N existing pages") giving no hint that anything beyond that one
template was about to change. An intermediate fix scoped the byte-identical matchers to non-template
edits while keeping a narrower, structurally-matched mechanism (`picdrop_pages_for_template()` — "find
this template's own already-built pages by block type + ordinal position, not by file value") for
pushing a template edit down to its built pages. That mechanism was itself removed on request —
template edits are now single-slot across the board, with no propagation of any kind, by design.

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
- **"Adjust view" must write back into `picdrop_pairs.json`, not just the page field.** The `adjust`
  action re-crops into a brand-new file, but it used to apply the edit on its own instead of routing
  through `pd_commit()` like every other write — so it never updated the Real/AI pair sidecar,
  never offered "also replace elsewhere" propagate, and never followed `seo.og_image`. `set_active`
  and every page reload redraw purely from the sidecar (`picdrop_pairs_get()`), so a crop looked fine
  until the next Real/AI switch, or just a reload, silently put the uncropped picture straight back —
  worse, on a shared photo, every OTHER page that shared it kept the old framing with no way to tell.
  `adjust` now goes through `pd_commit()` like everything else, syncs whichever pair side held the
  old value in its success callback, and cleans up the file it replaced via `picdrop_delete_if_unused()`
  (see below) instead of a narrower one-off "is this still used" check that only looked at this
  site's live page fields and missed the pairs sidecar entirely — which is exactly how a crop's own
  replaced file used to get deleted while its own pair entry still pointed at it.
- **`picdrop_delete_if_unused()`'s safety scan must include the ACTIVE site, not just `sites/*`.**
  `picdrop_file_references()` globs `BASE_DIR/sites/*` for the fleet-wide safety net, but a legacy
  single-site install (or a CLI multisite worker's ephemeral clone) lives outside `sites/` entirely —
  see config.php's 3-tier routing. Without explicitly including `ACTIVE_SITE_DIR` (or `BASE_DIR` for
  legacy), the scan found zero references for anything a non-multisite-session site owned, so
  *every* file looked unused no matter how many pages still pointed at it. Only had one caller
  (`remove_side`'s Delete button) before `adjust` and the plain upload/generate/library-pick path
  started calling it too (previously those two never cleaned up the file they replaced at all,
  leaving it orphaned in `uploads/media/` + `media.json` forever) — which is what surfaced it.
