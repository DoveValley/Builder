# Recovery Insurance plugin

Owns the recovery-insurance site's programmatic **directory matrix** so the shared
site factory is never modified for one niche.

## Why a plugin
The recovery site's URLs are a nested matrix (states × cities × carriers), unlike the
factory's flat single-segment landing pages. Rather than teach core routing +
generation about this one site, all of it is quarantined here and activated **only**
for the recovery site (`ACTIVE_SITE_ID === 'recovery-site'`).

## URL structure (frozen)
| Type | URL | Example |
|---|---|---|
| hub | `/insurance/` | `/insurance/` |
| company_national | `/insurance/{company}` | `/insurance/aetna` |
| state | `/{state}` | `/texas` |
| city | `/{state}/{city}` | `/texas/houston` |
| state_company | `/{state}/{company}` | `/texas/aetna` |
| city_company | `/{state}/{city}/{company}` | `/texas/houston/aetna` |

`{company}` = insurance carrier (~25, same set at every level). A bare 2nd/3rd segment
is disambiguated **city-vs-company by known-carrier lookup** — safe because all slugs
are closed sets we own.

## How it hooks in (one generic core seam — dormant for other sites)
- `includes/hooks.php` gains `route_hook()` — fire listeners in priority order, **first
  non-null result wins**.
- `page.php` fires `route_request` on the **raw path before `slugify()`**; if a plugin
  returns a payload, core renders it via `site-template.php` and exits. No-op when no
  plugin claims the path.
- `.htaccess` routes multi-segment paths to `page.php?path=a/b/c` (single-segment slugs
  still route to `?slug=` unchanged).

None of these are recovery-specific.

## Files
- `plugin.php` — registration + per-site-guarded `route_request` hook
- `router.php` — `recovery_match_route($path)` → matched type, or null
- `pages.php`  — `recovery_render_route($match,$data)` → render payload *(SCAFFOLD: one placeholder `text` block per type)*
- `data.php`   — matrix loaders (carriers/states/cities) from `sites/{site}/data/recovery/`
- `build.php`  — `recovery_enumerate_urls()` for static deploy + the phasing gate

## Matrix data
`sites/recovery-site/data/recovery/{carriers,states,cities}.json` — currently **SEED
placeholders** (a few carriers, Texas, 3 cities) so routing is testable. Replace with
the real Phase-1 state + cities + the ~25-carrier list.

## TODO (post-scaffold)
- Real block composition per page type (reuse hero / cards / faq / cta / map_info / …)
- Real SEO (title / meta / canonical) + breadcrumbs (hubs → entities) per type
- Admin `panel.php` to manage the matrix
- Wire `build.php` into the static deploy step and apply the phasing gate
