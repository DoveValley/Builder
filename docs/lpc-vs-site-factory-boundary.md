# LPC vs. the Site Factory — what's separate, what's shared

**Version:** V1 · 2026-11-18
**Why this doc exists:** Landing Page Clients (LPC, working product name "MoreTowns") and the rank-and-
rent fleet (the "Site Factory" — water/mold/pest/appliance, etc.) are two different businesses living
in overlapping parts of this repo. Scott asked for a clear, explicit line between them before deciding
anything about splitting LPC onto its own server. This is that line, verified against the actual code
on 2026-11-17/18 — not the original design intent, which drifted in places once built.

**The short version:** the two systems are more separate than they look, with one real exception — they
currently share a live database file and a set of paid API credentials. That's the one thing worth
fixing. Everything else below is either already clean or a deliberate, correct difference that should
stay a difference.

---

## 1. Completely separate — different code, different data, by design

| Piece | Fleet (Site Factory) | LPC |
|---|---|---|
| **Client-facing runtime** | Static-ish PHP pages served from this app/host | Its own standalone delivery server, `31.97.133.251` (`/opt/standalone-lpc-delivery/`) — a different physical box, already isolated |
| **Page rendering** | `includes/blocks.php` / `site-template.php` — 39 block types, deep theme/color integration | `wp-plugin/landing-page-client/includes/renderer.php` — 14 section types, bare semantic HTML, zero inline styling |
| **Where it renders** | This server, under Scott's own theme system | Inside each client's own WordPress install — different server, different theme, different PHP version per client |
| **Billing** | None — the fleet doesn't bill anyone | `includes/stripe_client.php` + `admin/stripe_webhook.php` — LPC-only |
| **Niche content files** | `sites/{master}/multisite/niche_brief.json` — referral-network framing ("never say *our team* did the repair") | `client_niche_profiles/*.json` — real-operator framing | 
| **"Page catalog" for a vertical** | The fleet's Page Pool + `keyword_map.json` (per-service primary/secondary keywords, real volume/CPC/KD data once researched) | `default_services[]` on the niche profile (label + simple keyword pattern) |
| **Admin auth UI** | `admin/login.php` | `admin/agency_login.php`, `admin/client_login.php` |

**Why the niche content files stay separate even after the niche library (§4) exists:** the underlying
facts (what services exist, what's climate/seasonally relevant) are the same regardless of business
model. The *guardrails and framing* are not — a referral network legally cannot claim to perform the
repair itself; a real LPC customer usually does the work. Merging these two would produce content that's
not just unpolished but factually wrong about who's doing the work. The library shares the facts; each
side keeps its own framing layer on top.

**Why the block renderers stay separate:** fleet blocks assume full control of the page's CSS and theme.
LPC's renderer assumes *none* — it must not fight an arbitrary third-party WordPress theme, which is why
it ships bare HTML with fixed `lpc-*` classes instead of the fleet's heavily-styled, color-resolving
markup. Every block type that exists on both sides (hero, FAQ, trust bar, comparison, steps, related
links, CTA, service cards) was checked line-by-line on 2026-11-17 and found to have already diverged for
this real reason, not from neglect. Do not try to unify these into one renderer.

---

## 2. Currently shared, and shouldn't be — the real finding

This is the one thing that actually violates "zero shared footprint" between the two businesses. It's
not a code-reuse question, it's that LPC's admin pages read and write the fleet's own private state.

**The database:** `admin/infra/lib/state.php`'s `infra_state_db()` returns a PDO connection to
`admin/infra/state/fleet.db` — the same SQLite file holding Hestia server credentials, Cloudflare zone
data, the full domain inventory, and rank-sweep history. `admin/infra/lib/cities.php`'s
`infra_cities_init()` and `admin/infra/lib/census.php` both create their tables (`cities`, `city_data`)
**inside that same file**, and the following LPC files call them directly:

- `admin/client.php`, `admin/client_wizard.php`, `admin/client_save.php`
- `includes/landing_page_clients.php`, `_ai.php`, `_area_charts.php`, `_area_images.php`, `_reporting.php`

**The credentials:** the same files also reuse the fleet's own paid API clients, with the fleet's own
keys, not a copy with LPC's own:

- `admin/infra/lib/keywords.php` → DataForSEO, via the fleet's `config/keywords.json` — used by LPC's
  rank-check feature (`landing_page_clients_reporting.php`, `landing_page_clients.php`) and the fleet's
  own rank sweep, same account, same quota.
- `includes/anthropic.php` → used by the fleet's AI content pipeline AND `landing_page_clients_ai.php`,
  same API key.
- `includes/openai_images.php` → used by the fleet's image generation AND `landing_page_clients_media.php`,
  same API key.

**The session:** `admin/agency_login.php` sets `$_SESSION['admin_logged_in'] = true` on a successful
agency login — the exact same flag that means "this is Scott, running the whole fleet console" anywhere
else in the app. An agency account and the platform operator are not currently distinguishable by
session state alone.

**The fix (scoped, not a rebuild):**
1. Give LPC its own copy of just the `cities` and `city_data` tables, in their own SQLite file, with a
   fork of `infra_cities_init()` pointed at it instead of `infra_state_db()`. Pure reference data, no
   secrets — safe and cheap to duplicate.
2. Get LPC its own DataForSEO account and its own Anthropic/OpenAI API keys. A real decision (someone
   has to create and fund the accounts), mechanically trivial once decided — same client code, different
   `config/keywords.json` / key.
3. Give agencies their own session flag, separate from `admin_logged_in`, when auth is next touched.

None of this requires moving anything to a different server — it's the same fix whether the admin app
stays here or moves later.

---

## 3. Genuinely shared, and that's fine — no fix needed

Small, generic, or already-portable code that both sides use with no real coupling:

- `includes/helpers.php`-style utilities — `h()`, `sanitize_url()`, `slugify()`, the CSRF pattern. A few
  lines each, zero fleet-specific logic.
- `admin/infra/lib/http.php` — the generic HTTP client wrapper `stripe_client.php` and others sit on.
- `plugins/image-data-chart/*` and `plugins/image-area-map/*` (charts.php/render.php/draw.php) — pure
  data-in/image-out rendering engines, zero dependency on `fleet.db` or any fleet session state, despite
  living in the fleet's own `plugins/` directory. LPC's `landing_page_clients_area_charts.php` and
  `_area_images.php` already reuse these directly, safely.
- `includes/functions.php` — the shared bootstrap loader. This is *why* the admin-side separation isn't
  clean yet (every LPC admin page transitively loads the entire fleet's rendering/editor machinery on
  every request, unused), but it's not urgent to fix — see §5.

---

## 4. Should become one shared asset — planned, not yet built

Agreed direction from the 2026-11-17/18 conversation, not yet implemented:

- **A niche library** — one file per niche holding the business-model-agnostic facts: the service/page
  catalog, each service's natural keyword phrase, the local/seasonal angle, trust-bar phrases, blog topic
  ideas, and a `custom_research_fields` list (see below). The fleet's `niche_brief.json` + `keyword_map.json`
  and LPC's `client_niche_profiles/*.json` each get generated/derived from it, with their own framing
  layered on top. Goal: write a new niche's facts once, not twice.
- **The `custom_research_fields` extensibility pattern** (currently fleet-only, in `generate.py` —
  `niche_research_fields()` / `chart_research_fields()` / `plugin_research_fields()`) — a niche declares
  extra per-city facts to research as `{key, ask}` pairs, no code change required. This is the answer to
  "what if a niche needs special research" and "what if I need a new kind of city data" — the mechanism
  already exists and should be adopted by the niche library wholesale rather than reinvented.
- **The cities/census reference data itself** — once split out per §2's fix, both sides should read the
  *same* underlying facts (even if each keeps its own copy), not independently-researched, independently-
  drifting versions of "what's true about this city."

A drafting tool (AI-assisted, same "AI drafts, Scott approves" pattern as the existing Schema AI / About
Us AI / map_info AI tools) is planned to sit on top of the niche library so a new niche is described in
plain English once, rather than hand-authored as JSON on both sides.

---

## 5. Decided, for now: don't move LPC to a separate server

Raised and settled 2026-11-18. The part that actually needed physical isolation — serving live client
traffic — is already done (§1, the delivery server). What's left sharing a host is admin/wizard/billing
code, and its real problem is §2 (a shared database and shared credentials), not which box it runs on.
Moving the code now, before the niche library exists, would mean either duplicating that library across
two servers or building a sync mechanism for something that's trivial to share while it's one box.
Revisit a physical move if/when LPC grows enough to need its own scaling, uptime, or team-access boundary
— none of which is true yet.
