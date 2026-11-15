# Landing Page Clients — New Client Checklist

**Version:** V1 · 2026-11-15

The steps to go through once a new Landing Page Clients customer is identified, from nothing to a
live, client-connected set of pages. Verified against the actual built code (`admin/client_wizard.php`,
`admin/client.php`), not just the original design conversation — some of that design drifted once built.

Content Profile is deliberately **not** a wizard step (see step 9's note on why) — everything else maps
1:1 to an existing screen.

## 1. Set up the client — the wizard (`client_wizard.php`, "+ New Client")

1. Business Name + Domain, then click Auto-detect if they already have a live site (pulls logo/colors/phone/hub city from the real site — always double-check, it's a best-effort guess).
2. Fill Industry Category + Contact Name/Email/Phone (industry category drives automatic niche-profile matching).
3. Branding tab — logo/colors/font (cosmetic, admin-only; the live pages inherit the client's real WordPress theme regardless).
4. Add every service the business offers (defaults onto every target city, per-city override happens later).
5. Add each real hub office — address, phone, service radius (feeds the `LocalBusiness` schema and the city-suggestion radius).
6. Per hub, optionally click "Fetch Keyword Data" before picking cities (real DataForSEO cost — select by real demand, not just population, when it's available).
7. Check the target cities to add, "Add Selected as Target Cities."
8. Review & Create (this immediately spends real AI cost generating a draft for every confirmed city×service combo).

## 2. Before publishing anything (client workspace, post-wizard)

9. Content Profile tab — set tone, excluded services, always/never-mention **now, before reviewing drafts** (there's no bulk regenerate — skipping this means re-touching every page one at a time later if something needs to change).
10. Locations & Services tab — review every drafted page, Edit/Regenerate individual pages as needed, Lock any page that's final and shouldn't be touched by a future Regenerate.
11. *(Once the image-sourcing backlog item ships: assign each page's hero/OG image here too — manual URL paste only today.)*
12. Publish the pages you're satisfied with — checkbox + "Publish Checked" in the matrix, or one at a time from the page editor.

## 3. Hand off to the client (Delivery tab — already its own 3-step mini-flow)

13. Download the plugin zip, grab the API key shown on this tab.
14. Email it to them (built-in compose form, pre-filled install instructions and message).
15. Confirm the status badge flips Connected once they've pasted the key into their WordPress settings.

## Ongoing (not onboarding — revisit any time after)

- Add a City / Add a Service later — new cities default to the full service list; a new service applies to every existing city.
- Delivery tab "steps 4+" — Support Message, Disable/Re-enable, Regenerate Key — occasional controls, not part of onboarding.
- Reporting tab — rank position + GSC clicks, once pages are published and (optionally) Search Console access is granted.

## Not real yet — steps this checklist will gain once the Phase 3 backlog ships

See `project_landing_page_clients_feature_design` memory, "Phase 3 backlog," for full detail on each:
- A per-service, per-city keyword/demand check before deciding which services get a page at which city (today: every service, every city, no demand check).
- Primary/secondary keyword review per page (no such field exists on a page yet).
- Per-city "Researched" status is hand-toggled only today — will auto-populate once the real research pipeline is wired in.
