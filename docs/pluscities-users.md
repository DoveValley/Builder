# PlusCities — Users & Roles

Who can do what, today — verified against the real code (`lpc_current_actor_role()`,
`admin/impersonate.php`, every `pluscities_*.php` role gate), not the original design spec's aspirational
5-role model. **4 roles are real and reachable today; 2 more are spec'd but not built.** See
`docs/pluscities-system-reference.md` §5 for the full prose version this doc summarizes.

---

## The 4 real roles

### Platform Admin (`super_admin`)

**How they get this role:** the default — `lpc_current_actor_role()` returns `super_admin` whenever
`$_SESSION['actor_role']` is unset, which is every session started via the platform operator's own
`login.php`. Nobody has to be explicitly promoted to this; it's what you get by logging in at the root.

**Scope:** everything — every agency, every client, every page, every niche.

**Can do:**
- Everything every other role can do, for any agency/client, plus:
- The **only** role that can impersonate (both "become an agency" and "become a client").
- Manage the entire Niche Library — families, niches, compliance presets, including the real
  type-to-confirm **delete** action for niches/presets (added 2026-11-26).
- **Suspend a client's whole site** (the platform's kill switch — unpublishes every live page
  immediately, no grace period, no agency override).
- Permanently **delete a client** (Danger Zone, type-to-confirm, soft-delete to a recoverable
  quarantine — added 2026-11-26).
- Manage Agencies, Settings, and the platform-only Build Tools (WP Builder, Site Factory) — screens no
  other role can even reach.
- **Cannot** open `pluscities_team.php` (Agency Editor account management) directly — that screen is
  `agency_admin`-only, even for the Platform Admin, except by impersonating that agency first.

### Agency Admin (`agency_admin`)

**How they get this role:** logs in at `agency_login.php` with the agency's own shared admin credential.

**Scope:** their own `agency_id` only.

**Can do:**
- Create and manage their agency's own clients, pages, photos, hub/listing-page text, and branding.
- Edit a client's **Business Info** (licenses, hours, banned phrases, price ranges, reviews-by-city —
  added 2026-11-26) and a page's **SEO title/meta description** (added 2026-11-26).
- Manage their own **team roster** (`pluscities_team.php`) — add/remove Agency Editor accounts, assign
  them to specific clients, and toggle whether editors can publish at all.
- **Cancel a client** (reversible, 301-redirects to the client's own homepage after a grace period) and
  toggle the **per-client approval gate** (requires the client's own sign-off before a page can publish).
- Toggle their agency's own **approval gate** (whether an Agency Editor can approve a page at all, or
  only an Agency Admin can).
- Sees their **own agency's branding** (name/logo) in the shared sidebar, not "PlusCities."
- **Blocked** from every platform-only screen (Agencies, Niche Library, Settings, WP Builder, Site
  Factory) and from permanently deleting a client (that's Platform Admin only).

### Agency Editor (`agency_editor`)

**How they get this role:** a real, individually-created account (`includes/pluscities_users.php`),
added by an Agency Admin via `pluscities_team.php`, logging in at the same `agency_login.php`.

**Scope:** the same agency as their Agency Admin, further narrowed by `assigned_client_ids` — empty
means they see every client in the agency, a real list means only those specific clients.

**Can do:**
- Edit/lock/regenerate/swap blocks freely, same as an Agency Admin, on whichever clients they're
  assigned to.
- Edit Business Info and per-page SEO fields, same as an Agency Admin.
- **Approve is conditionally blocked**: if the agency's approval gate is on (the default), only an
  Agency Admin or Platform Admin can approve a page — enforced inside the approve function itself, not
  just hidden in the UI.
- **Publish is conditionally blocked**: blocked by default unless the agency's "editors can publish"
  flag is explicitly turned on — same enforcement discipline, inside the publish function itself.
- **Cannot** manage the team roster, cancel/suspend clients, or touch anything platform-only.

### End Client (`end_client`)

**How they get this role:** **no self-service login exists.** The only way to reach this role is a
Platform Admin or Agency Admin clicking "Log in as" on that client's row — impersonation, not a real
credential a business owner holds themselves. This is a known, documented gap (see
`docs/pluscities-system-reference.md` §5.5), not an oversight in this doc.

**Scope:** their own business only.

**Can do:**
- View every one of their own pages, in any status (draft/approved/live/unpublished) — a real,
  honest status pill per row, not just the live ones.
- View one page's actual content, block by block — strictly read-only.
- **The one write action anywhere on this role's screens**: Approve a specific page, but only when
  that page is sitting in `pending_client_review` — i.e., only when their agency has turned on the
  per-client approval gate AND an agency user has already approved it on their side first.
- **Cannot** reach any other `pluscities_*.php` screen at all — excluded the same way it always has
  been.

---

## Spec'd, not built: Client Approver / Client Viewer

The original design spec describes a 5-role model where **End Client** splits into **Client Approver**
(can approve, like today's real approve button) and **Client Viewer** (read-only, no approve button at
all). Today there's only one `end_client` role carrying both capabilities at once, gated by the
per-client approval-gate setting rather than by a second distinct role. Building a real split — and a
real self-service login for either of them — remains unbuilt.

---

## How the roles relate — hierarchy + impersonation

```
┌───────────────────────────────────────────────────────────────────────┐
│  PLATFORM ADMIN (super_admin)                                          │
│  Default role whenever no session role is set — every login.php login. │
│  Sees every agency, every client, every page. Only role that can        │
│  impersonate. Only role with Niche Library + client-delete + site-      │
│  suspend + Agencies/Settings/Build Tools.                               │
└──────────────┬───────────────────────────────────────┬──────────────────┘
               │ "Log in as" → becomes that agency's     │ manages directly
               │ own Agency Admin, one hop, no stacking   ▼
               ▼                                  ┌─────────────────────────┐
┌───────────────────────────────┐                 │ Platform-only screens:   │
│  AGENCY ADMIN                   │                │ Agencies · Niche Library │
│  agency_login.php, own agency_id│                │ · Settings · WP Builder  │
│                                  │                │ · Site Factory           │
│  Manages: clients, pages,       │                └─────────────────────────┘
│  Business Info, SEO fields,     │
│  branding, cancel/approval-gate │
└──────────────┬───────────────────┘
               │ adds/assigns via Team roster
               ▼
┌────────────────────────────────┐
│  AGENCY EDITOR                   │
│  agency_login.php, real per-     │
│  person account, same agency,    │
│  narrowed by assigned_client_ids │
│                                   │
│  Edit/regenerate/swap freely.     │
│  Approve/Publish conditionally    │
│  blocked by 2 agency-level gates  │
│  (requires_approval_gate /        │
│  editor_can_publish), enforced    │
│  server-side either way.          │
└───────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  END CLIENT                                                           │
│  NO self-service login — reachable ONLY via "Log in as" from a        │
│  Platform Admin or Agency Admin (one hop, no stacking — an already-    │
│  impersonating session must return to its own account first).         │
│                                                                         │
│  Sees: every one of their own pages, any status, read-only content.    │
│  Can: Approve ONE specific page, only while it's pending_client_review │
│  — the single write action anywhere on this role's 2 screens.          │
│                                                                         │
│  Spec'd, not built: splitting this into Client Approver (can approve)  │
│  vs. Client Viewer (read-only, no approve button at all).              │
└─────────────────────────────────────────────────────────────────────┘
```

**Impersonation, precisely**: one hop only, never stacked. `super_admin` can become any agency's own
Agency Admin, or any client's End Client, directly. `agency_admin` can only become one of its own
clients' End Client — never another agency. An already-impersonating session attempting to impersonate
again is bounced back to its own account first, not allowed to chain.
