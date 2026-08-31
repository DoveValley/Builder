/**
 * Domain Workbench — bulk domain-name acquisition across service niches.
 *
 * SOURCE FILE. Edit this, then rebuild:
 *
 *     npx --yes esbuild@0.24.0 assets/admin/src/domain-workbench.jsx \
 *       --loader:.jsx=jsx --jsx=transform --bundle=false \
 *       --outfile=assets/admin/domain-workbench.js
 *
 * The built file is committed; nothing transpiles at request time. Editing the
 * built .js directly will be overwritten by the next build — this is the file.
 *
 * Ported from a Claude.ai artifact, per uploads/convo PORTING-BRIEF.md. Three
 * things changed and nothing else:
 *
 *   1. React comes from a vendored UMD global instead of an import — there is no
 *      bundler here and the rest of the factory has no build step either.
 *   2. loadState/saveState talk to actions/dfinder_state.php (window.storage is
 *      an artifact-host thing and does not exist outside it).
 *   3. The two model calls go to actions/dfinder_ai.php, which holds the API key
 *      server-side. The artifact host injected credentials into a keyless fetch;
 *      nothing outside it can, and a key in client code is readable by anyone
 *      who opens devtools.
 *
 * Behaviour that looks redundant and is NOT — do not "clean up":
 *
 *   - The client-side blockedSet filter in generate() duplicates the exclusion
 *     list already given to the model, because the model does not reliably
 *     honour it.
 *   - The keyword-stripping loop in generate() is what stops
 *     adamsrestorationrestoration.com when the model returns "Adams Restoration"
 *     as the name. DOUBLED_RE in normalize() repairs ones already saved.
 *   - applyPaste() reads each domain match PLUS the text up to the next match,
 *     not line by line. Registrars put the status on a following line, and
 *     line-by-line parsing marked taken domains as available. That was a real bug.
 *   - Price parsing takes the MINIMUM dollar figure in a block, because
 *     registrars show a promo first-year price beside a higher renewal.
 */
const { useState, useEffect, useRef, useMemo } = React;

/* Endpoints and the CSRF token, set by views/dfinder.php before this file loads. */
const DW = window.DW_CONFIG || {};

const KEY = "domain-workbench-v1";

const STATUSES = {
  idea: { label: "Unchecked", tone: "neutral" },
  available: { label: "Available", tone: "good" },
  shortlist: { label: "Shortlist", tone: "warn" },
  purchased: { label: "Purchased", tone: "claim" },
  taken: { label: "Not available", tone: "dead" },
  pricey: { label: "Too pricey", tone: "dead" },
  rejected: { label: "Didn't like", tone: "dead" },
};
const STATUS_ORDER = ["idea", "available", "shortlist", "purchased", "taken", "pricey", "rejected"];
const NOT_SPENT = ["taken", "pricey"];

const NAME_TYPES = {
  any: { label: "Any name", prompt: "mix surnames, first names and familiar short forms" },
  surname: { label: "Surnames only", prompt: "family surnames only" },
  first: { label: "First names only", prompt: "first names only, including familiar short forms like dave or gus" },
};

const SEED = [
  { name: "Appliance repair", patterns: ["appliancerepair", "appliance", "appliances"] },
  { name: "Pest control", patterns: ["pestcontrol", "pest", "exterminating"] },
  { name: "Water restoration", patterns: ["waterrestoration", "restoration", "waterdamage"] },
  { name: "Mold remediation", patterns: ["moldremediation", "moldremoval", "mold"] },
];

const DEFAULT_RULES = [
  "Use .com only — never suggest another extension",
  "Easy to say out loud, and easy to spell correctly after hearing it once",
  "Shorter is better — fewer syllables and fewer characters win",
  "Sounds like a real local business people would trust and buy from",
  "No awkward letter collisions where the name meets the keyword",
  "No initials, numbers, hyphens, or invented words",
];

const uid = () => Math.random().toString(36).slice(2, 10);
const mkPattern = (kw) => ({ id: uid(), kw, on: true });
const mkRule = (text) => ({ id: uid(), text, on: true });
const cleanKw = (s) => String(s || "").toLowerCase().replace(/[^a-z]/g, "");

const DOMAIN_RE = /([a-z0-9][a-z0-9-]*\.com)/g;
const TAKEN_RE =
  /(unavailable|not available|is taken|\btaken\b|registered|make ?offer|backorder|transfer to|\bwhois\b|\bsold\b|already)/;
const FREE_RE = /(\bavailable\b|add to cart|\bcart\b|\$\s?\d|\bfree\b)/;

const PRICE_RE = /\$\s?([\d,]+(?:\.\d{1,2})?)/g;

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    try {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand("copy");
      document.body.removeChild(ta);
      return ok;
    } catch {
      return false;
    }
  }
}

function ruleLines(rules = []) {
  return (
    rules
      .filter((r) => r.on && r.text.trim())
      .map((r, i) => `${i + 1}. ${r.text.trim()}`)
      .join("\n") || "(none set)"
  );
}

function buildBrief(niche, batch, rules = []) {
  const live = (niche.patterns || []).filter((p) => p.on && p.kw);
  const lines = live.map((p, i) => `${i + 1}. {name}${p.kw}.com`).join("\n") || "(none set)";
  const ruleList = ruleLines(rules);
  return `Niche: ${niche.name}
Name type: ${NAME_TYPES[niche.nameType || "any"].prompt}
Maximum length before ".com": ${niche.maxLength} characters
Notes for this niche: ${niche.notes || "none"}

Rules, most important first:
${ruleList}

Domain patterns, highest priority first:
${lines}

Return exactly ${batch} candidates. Work down the priority list: fill priority 1 as far as good names allow, then priority 2, and so on. Never fall back to a keyword that is not listed above.`;
}

function freshState() {
  const niches = SEED.map((s) => ({
    id: uid(),
    name: s.name,
    patterns: s.patterns.map(mkPattern),
    nameType: "any",
    maxLength: 22,
    notes: "Short, easy to spell over the phone. Sounds like a family business.",
    candidates: [],
  }));
  return { niches, activeId: niches[0].id, registry: {}, rules: DEFAULT_RULES.map(mkRule), maxPrice: 15 };
}

const DOUBLED_RE = /^([a-z]*?)([a-z]{4,})\2\.com$/;

function normalize(s) {
  if (!s || !Array.isArray(s.niches) || !s.niches.length) return null;
  const renames = {};
  const niches = s.niches.map((n) => ({
    ...n,
    patterns:
      n.patterns && n.patterns.length
        ? n.patterns.map((p) => ({ id: p.id || uid(), kw: cleanKw(p.kw), on: p.on !== false }))
        : String(n.keywords || "")
            .split(",")
            .map((k) => cleanKw(k))
            .filter(Boolean)
            .map(mkPattern),
    nameType: n.nameType || "any",
    maxLength: n.maxLength || 22,
    candidates: (n.candidates || []).map((c) => {
      const m = (c.domain || "").match(DOUBLED_RE);
      if (m && m[1]) {
        const person = m[1];
        if (c.person && c.person !== person) renames[c.person] = person;
        return { ...c, tier: c.tier || 1, domain: m[1] + m[2] + ".com", person };
      }
      return { ...c, tier: c.tier || 1 };
    }),
  }));
  const registry = {};
  Object.entries(s.registry || {}).forEach(([k, v]) => {
    const key = renames[k] || k;
    const m = (v.domain || "").match(DOUBLED_RE);
    registry[key] = m && m[1] ? { ...v, domain: m[1] + m[2] + ".com" } : v;
  });

  return {
    niches,
    activeId: niches.some((n) => n.id === s.activeId) ? s.activeId : niches[0].id,
    registry,
    rules:
      s.rules && s.rules.length
        ? s.rules.map((r) => ({ id: r.id || uid(), text: String(r.text || ""), on: r.on !== false }))
        : DEFAULT_RULES.map(mkRule),
    maxPrice: s.maxPrice || 15,
  };
}

/* PORTED: was window.storage, which only exists inside the artifact host.
   Same contract as before — async, never throws, null means "nothing saved yet"
   so the caller falls back to freshState(). The component is unchanged: it calls
   loadState() once on mount and debounces saveState(state) 500ms after a change. */
async function loadState() {
  try {
    const r = await fetch(DW.stateUrl, { credentials: "same-origin" });
    if (!r.ok) return null;
    const j = await r.json();
    return j && j.state ? j.state : null;
  } catch {
    return null;
  }
}

async function saveState(state) {
  try {
    const body = new FormData();
    body.append("csrf", DW.csrf);
    body.append("state", JSON.stringify(state));
    await fetch(DW.stateUrl, { method: "POST", body, credentials: "same-origin" });
  } catch {
    /* storage unavailable — session still works in memory */
  }
}

/**
 * PORTED: both model calls used to POST straight to api.anthropic.com with no
 * x-api-key, because the artifact host injected one. Outside it that fails, and
 * the key cannot move into this file — anyone can read it.
 *
 * actions/dfinder_ai.php holds the key and answers in the Messages API's shape,
 * so every caller below parses the reply exactly as it did before: content[],
 * text blocks, markdown fences stripped, JSON extracted between brackets. That
 * error handling is load-bearing — the model occasionally wraps JSON in prose.
 *
 * The model is NOT named here on purpose. The server asks includes/anthropic.php
 * for a tier, so when a model is superseded one file changes and every caller
 * moves with it. This file used to pin claude-sonnet-4-6.
 */
/**
 * Pull the JSON array out of a model reply, and say what went wrong when there
 * isn't one.
 *
 * The old form was JSON.parse(raw.slice(raw.indexOf("["), raw.lastIndexOf("]") + 1)).
 * When the reply had no "[" or no closing "]" both indexOf calls returned -1, the
 * slice produced an EMPTY STRING, and the user got "Unexpected end of JSON input"
 * — a message about our parser, not about what happened. A reply cut off at the
 * token limit and a reply that was prose both landed there identically, and only
 * one of them is fixed by asking for fewer names.
 */
function parseModelArray(data, what) {
  const raw = (data.content || [])
    .filter((b) => b.type === "text")
    .map((b) => b.text)
    .join("\n")
    .replace(/```json|```/g, "")
    .trim();

  const open = raw.indexOf("[");
  const close = raw.lastIndexOf("]");

  if (data.stop_reason === "max_tokens" || (open !== -1 && close < open)) {
    throw new Error(
      `The model ran out of room before it finished the list of ${what}. Ask for a smaller batch, or shorten the rules and blocked-name list.`
    );
  }
  if (open === -1) {
    const gist = raw.slice(0, 140).replace(/\s+/g, " ");
    throw new Error(
      gist
        ? `The model replied with prose instead of a list: “${gist}${raw.length > 140 ? "…" : ""}”`
        : "The model returned an empty reply."
    );
  }
  try {
    return JSON.parse(raw.slice(open, close + 1));
  } catch (e) {
    throw new Error(`The model's list was not valid JSON (${e.message}).`);
  }
}

async function askModel(system, prompt, maxTokens) {
  const body = new FormData();
  body.append("csrf", DW.csrf);
  body.append("system", system);
  body.append("prompt", prompt);
  body.append("max_tokens", String(maxTokens));
  const res = await fetch(DW.aiUrl, { method: "POST", body, credentials: "same-origin" });
  if (!res.ok) {
    // Surface what the server said rather than a bare status: "no API key
    // configured" and "rate limited" need different things done about them.
    let why = `Request failed (${res.status})`;
    try {
      const j = await res.json();
      if (j && j.error) why = j.error;
    } catch {
      /* non-JSON error body — keep the status */
    }
    throw new Error(why);
  }
  return res.json();
}

const CSS = `
@import url('https://fonts.googleapis.com/css2?family=Archivo:wght@600;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;700&display=swap');

.dw {
  --paper:#E7EBE4; --card:#FBFCFA; --ink:#15211E; --soft:#5D6B64;
  --rule:#C6CDC3; --oxide:#A93B2B; --green:#2C6A4C; --amber:#9C7318;
  --display:'Archivo', system-ui, sans-serif;
  --body:'Inter', system-ui, sans-serif;
  --mono:'JetBrains Mono', ui-monospace, monospace;
  background:var(--paper); color:var(--ink); font-family:var(--body);
  min-height:100vh; font-size:14px; line-height:1.5;
}
.dw *, .dw *::before, .dw *::after { box-sizing:border-box; }
.dw button { font-family:inherit; font-size:inherit; cursor:pointer; }
.dw input, .dw textarea, .dw select { font-family:inherit; font-size:inherit; color:var(--ink); }
.dw :focus-visible { outline:2px solid var(--oxide); outline-offset:2px; }

.dw-bar {
  display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;
  padding:18px 22px; border-bottom:1px solid var(--rule); background:var(--card);
}
.dw-logo { font-family:var(--display); font-weight:800; font-size:19px; letter-spacing:-0.02em; text-transform:uppercase; }
.dw-sub { font-family:var(--mono); font-size:11px; color:var(--soft); letter-spacing:0.02em; }
.dw-bar-end { margin-left:auto; display:flex; gap:8px; align-items:center; }

/* Two columns, not three: the niche switcher moved into the top of the right
   column, so the 200px rail that used to hold four buttons is gone and the work
   itself gets the width back. */
.dw-grid { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:0; align-items:start; }
.dw-col { padding:20px; }
.dw-side { border-left:1px solid var(--rule); }
.dw-sep { border:none; border-top:1px solid var(--rule); margin:20px 0 18px; }

.dw-eyebrow {
  font-family:var(--display); font-weight:600; font-size:10px; text-transform:uppercase;
  letter-spacing:0.14em; color:var(--soft); margin:0 0 10px;
}
.dw-h { font-family:var(--display); font-weight:800; font-size:24px; letter-spacing:-0.02em; margin:0; }

.dw-niche {
  display:flex; align-items:center; gap:8px; width:100%; text-align:left; background:none;
  border:none; border-bottom:1px solid var(--rule); padding:9px 2px; color:var(--soft);
}
.dw-niche:hover { color:var(--ink); }
.dw-niche[data-on="1"] { color:var(--ink); font-weight:600; }
.dw-niche[data-on="1"]::before { content:""; width:5px; height:5px; background:var(--oxide); border-radius:50%; flex:none; }
.dw-count { margin-left:auto; font-family:var(--mono); font-size:11px; color:var(--soft); }

.dw-btn {
  border:1px solid var(--ink); background:var(--ink); color:var(--card); padding:8px 13px;
  font-weight:600; font-size:13px; border-radius:2px;
}
.dw-btn:hover { background:#243532; }
.dw-btn:disabled { opacity:.45; cursor:not-allowed; }
.dw-btn.ghost { background:none; color:var(--ink); border-color:var(--rule); font-weight:500; }
.dw-btn.ghost:hover { border-color:var(--ink); background:rgba(0,0,0,.03); }
.dw-btn.wide { width:100%; }
.dw-btn.tiny { padding:4px 8px; font-size:11px; }

.dw-field { display:block; margin-bottom:14px; }
.dw-label {
  display:block; font-family:var(--display); font-weight:600; font-size:10px; text-transform:uppercase;
  letter-spacing:0.1em; color:var(--soft); margin-bottom:5px;
}
.dw-in, .dw-ta {
  width:100%; background:var(--card); border:1px solid var(--rule); border-radius:2px;
  padding:8px 9px; font-size:13px;
}
.dw-ta { resize:vertical; min-height:62px; font-family:var(--mono); font-size:12px; line-height:1.55; }
.dw-hint { font-size:11px; color:var(--soft); margin:5px 0 0; }

.dw-pat { border-bottom:1px solid var(--rule); padding:8px 0; }
.dw-pat[data-off="1"] { opacity:.45; }
.dw-pat-top { display:flex; align-items:center; gap:7px; }
.dw-pat-bot { display:flex; align-items:center; gap:4px; margin-top:4px; padding-left:22px; }
.dw-pnum {
  font-family:var(--display); font-weight:800; font-size:10px; letter-spacing:0.08em;
  color:var(--oxide); flex:none; width:18px;
}
.dw-prev { font-family:var(--mono); font-size:11px; color:var(--soft); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.dw-mini {
  border:1px solid var(--rule); background:var(--card); color:var(--soft); border-radius:2px;
  width:20px; height:20px; line-height:1; padding:0; font-size:12px; flex:none;
}
.dw-mini:hover:enabled { border-color:var(--ink); color:var(--ink); }
.dw-mini:disabled { opacity:.3; cursor:not-allowed; }
.dw-pri { font-family:var(--mono); font-size:10px; font-weight:700; color:var(--oxide); }
.dw-doc { margin-top:22px; border-top:1px solid var(--rule); padding-top:12px; }
.dw-doc summary {
  font-family:var(--display); font-weight:600; font-size:10px; text-transform:uppercase;
  letter-spacing:0.14em; color:var(--soft); cursor:pointer;
}
.dw-doc summary:hover { color:var(--ink); }
.dw-pre {
  font-family:var(--mono); font-size:11px; line-height:1.6; white-space:pre-wrap; margin:10px 0 0;
  background:var(--card); border:1px solid var(--rule); border-left:3px solid var(--oxide);
  border-radius:2px; padding:10px; color:var(--soft); max-height:260px; overflow:auto;
}

.dw-ready {
  border:1px solid var(--rule); border-left:3px solid var(--green); background:var(--card);
  border-radius:2px; padding:11px 13px; margin-bottom:14px; font-size:13px; color:var(--soft);
}
.dw-ready-n { font-family:var(--display); font-weight:800; font-size:18px; color:var(--ink); }

.dw-rules { display:grid; grid-template-columns:repeat(auto-fill, minmax(330px, 1fr)); gap:0 22px; }
.dw-rule { display:flex; align-items:flex-start; gap:7px; padding:8px 0; border-bottom:1px solid var(--rule); }
.dw-rule[data-off="1"] { opacity:.4; }
.dw-rule input[type="checkbox"] { margin-top:6px; flex:none; }
.dw-rnum {
  font-family:var(--mono); font-size:11px; font-weight:700; color:var(--oxide);
  flex:none; padding-top:5px;
}
.dw-rtext {
  flex:1; min-width:0; background:var(--card); border:1px solid var(--rule); border-radius:2px;
  padding:5px 7px; font-size:12.5px; line-height:1.45; resize:vertical;
}
.dw-rule .dw-mini { margin-top:3px; }

.dw-sugg-box {
  border:1px solid var(--rule); border-left:3px solid var(--amber); background:var(--card);
  border-radius:2px; padding:12px 14px; margin-bottom:14px;
}
.dw-sugg {
  display:flex; align-items:baseline; gap:9px; padding:6px 0;
  border-bottom:1px solid var(--rule); cursor:pointer;
}
.dw-sugg:last-of-type { border-bottom:none; }
.dw-sugg[data-off="1"] { opacity:.4; }
.dw-sugg-dom { font-family:var(--mono); font-size:12.5px; font-weight:500; flex:none; }
.dw-sugg-why { font-size:12px; color:var(--soft); }

.dw-tabs { display:flex; flex-wrap:wrap; gap:2px; border-bottom:1px solid var(--rule); margin-bottom:14px; }
.dw-tab {
  background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-1px;
  padding:7px 11px; color:var(--soft); font-weight:500; white-space:nowrap;
}
.dw-tab:hover { color:var(--ink); }
.dw-tab[data-on="1"] { color:var(--ink); font-weight:600; border-bottom-color:var(--oxide); }
.dw-tab[data-empty="1"]:not([data-on="1"]) { opacity:.45; }
.dw-tab-n { font-family:var(--mono); font-size:11px; color:var(--soft); }
.dw-bulk {
  display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:var(--ink); color:var(--card);
  border-radius:2px; padding:8px 11px; margin-bottom:12px; font-size:12.5px;
}
.dw-bulk .dw-btn.ghost { color:var(--card); border-color:rgba(255,255,255,.35); }
.dw-bulk .dw-btn.ghost:hover { background:rgba(255,255,255,.12); border-color:var(--card); }
.dw-table tr[data-sel="1"] { background:rgba(169,59,43,.06); }

.dw-help { border-bottom:1px solid var(--rule); background:var(--card); }
.dw-help-title {
  font-family:var(--display); font-weight:800; font-size:22px; letter-spacing:-0.02em; margin:0 0 6px;
}
.dw-help-lead { max-width:62ch; margin:0 0 22px; color:var(--soft); }
.dw-help-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:6px 32px; }
.dw-help-grid > div { break-inside:avoid; padding-bottom:14px; }
.dw-help-h {
  font-family:var(--display); font-weight:800; font-size:11px; text-transform:uppercase;
  letter-spacing:0.12em; color:var(--oxide); margin:12px 0 7px;
  padding-bottom:5px; border-bottom:1px solid var(--rule);
}
.dw-help p { margin:0 0 9px; font-size:13px; }
.dw-help code { font-family:var(--mono); font-size:11.5px; }
.dw-help-list { margin:0; padding-left:18px; font-size:13px; }
.dw-help-list li { margin-bottom:7px; }
.dw-help-dl { margin:0; font-size:13px; }
.dw-help-dl dt {
  font-family:var(--mono); font-size:11.5px; font-weight:700; margin-top:8px;
}
.dw-help-dl dd { margin:1px 0 0; padding-left:0; color:var(--soft); }

.dw-btn.danger { background:var(--oxide); border-color:var(--oxide); color:var(--card); }
.dw-btn.danger:hover { background:#8E2E20; border-color:#8E2E20; }

.dw-ask {
  display:flex; align-items:center; gap:7px; flex-wrap:wrap;
  background:var(--card); border:1px solid var(--oxide); border-left-width:3px;
  border-radius:2px; padding:9px 11px; margin:8px 0; font-size:12.5px;
}
.dw-ask-t { flex:1; min-width:160px; }
.dw-btn.danger { background:var(--oxide); border-color:var(--oxide); color:var(--card); }
.dw-btn.danger:hover { background:#8E2E20; border-color:#8E2E20; }

.dw-reg { max-height:280px; overflow:auto; border:1px solid var(--rule); border-radius:2px; background:var(--card); }
.dw-reg-row {
  display:grid; grid-template-columns:120px 110px minmax(160px,1fr) 130px minmax(0,1fr) 24px;
  gap:10px; align-items:center; padding:6px 10px; border-bottom:1px solid var(--rule); font-size:12px;
}
.dw-reg-row:last-child { border-bottom:none; }
.dw-reg-name {
  font-family:var(--mono); font-size:12px; font-weight:500;
  text-decoration:line-through; text-decoration-color:var(--oxide);
}
.dw-reg-dom { font-family:var(--mono); font-size:11px; color:var(--soft); overflow:hidden; text-overflow:ellipsis; }
.dw-reg-niche, .dw-reg-note { color:var(--soft); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
@media (max-width:760px) {
  .dw-reg-row { grid-template-columns:1fr 1fr 24px; }
  .dw-reg-niche, .dw-reg-note { display:none; }
}

.dw-reg-name[data-free="1"] { text-decoration:none; color:var(--green); }
.dw-reg-row[data-free="1"] { background:rgba(44,106,76,.05); }

.dw-table { width:100%; border-collapse:collapse; }
.dw-table th {
  font-family:var(--display); font-weight:600; font-size:10px; text-transform:uppercase;
  letter-spacing:0.1em; color:var(--soft); text-align:left; padding:0 8px 7px; border-bottom:1px solid var(--rule);
}
.dw-table td { padding:9px 8px; border-bottom:1px solid var(--rule); vertical-align:middle; }
.dw-table tr[data-dead="1"] .dw-dom { color:var(--soft); text-decoration:line-through; }
.dw-dom { font-family:var(--mono); font-size:13px; font-weight:500; }
.dw-note {
  width:100%; background:none; border:none; border-bottom:1px dotted var(--rule);
  font-size:12px; padding:2px 0; color:var(--soft);
}
.dw-note:focus { color:var(--ink); }
.dw-sel { background:var(--card); border:1px solid var(--rule); border-radius:2px; padding:4px 5px; font-size:12px; }

.dw-stamp {
  display:inline-block; font-family:var(--display); font-weight:800; font-size:9px; letter-spacing:0.12em;
  text-transform:uppercase; color:var(--oxide); border:1.5px solid var(--oxide); border-radius:2px;
  padding:2px 5px; transform:rotate(-4deg);
}
.dw-tag { font-family:var(--mono); font-size:11px; }
.dw-tag[data-tone="good"] { color:var(--green); }
.dw-tag[data-tone="warn"] { color:var(--amber); }
.dw-tag[data-tone="dead"] { color:var(--soft); }

.dw-strip {
  display:flex; flex-wrap:wrap; gap:5px; max-height:120px; overflow:auto;
  padding:9px; background:var(--card); border:1px solid var(--rule); border-radius:2px;
}
.dw-name {
  font-family:var(--mono); font-size:11px; color:var(--soft); text-decoration:line-through;
  text-decoration-color:var(--oxide); padding:1px 4px; border:1px solid var(--rule); border-radius:2px;
}
.dw-name button { background:none; border:none; color:var(--oxide); padding:0 0 0 4px; font-size:11px; }

.dw-empty {
  border:1px dashed var(--rule); border-radius:2px; padding:26px 18px; text-align:center; color:var(--soft);
}
.dw-err { border:1px solid var(--oxide); border-left-width:3px; padding:9px 11px; margin-bottom:14px; font-size:12px; color:var(--oxide); background:var(--card); }
.dw-toast {
  position:fixed; left:50%; bottom:22px; transform:translateX(-50%); background:var(--ink); color:var(--card);
  padding:9px 16px; border-radius:2px; font-size:13px; z-index:50;
}
.dw-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

@media (max-width:900px) {
  .dw-grid { grid-template-columns:1fr; }
  .dw-side { border-left:none; border-top:1px solid var(--rule); }
}
@media (prefers-reduced-motion:reduce) { .dw * { animation:none !important; transition:none !important; } }
`;

/* Was `export default` — there is no module system on this page, so it is a
   plain function and the mount at the bottom of this file renders it. */
function DomainWorkbench() {
  const [state, setState] = useState(null);
  const [busy, setBusy] = useState(false);
  const [sendingToDbuy, setSendingToDbuy] = useState(false);
  const [err, setErr] = useState("");
  const [toast, setToast] = useState("");
  const [paste, setPaste] = useState("");
  const [fallback, setFallback] = useState("skip");
  const [undo, setUndo] = useState(null);
  const [sel, setSel] = useState([]);
  const [ask, setAsk] = useState(null);
  const [askText, setAskText] = useState("");
  const [csv, setCsv] = useState(null);
  const [batch, setBatch] = useState(12);
  const [filter, setFilter] = useState("all");
  const [showReg, setShowReg] = useState(false);
  const [showRules, setShowRules] = useState(true);
  const [showHelp, setShowHelp] = useState(false);
  const [showRegs, setShowRegs] = useState(false);
  const [suggs, setSuggs] = useState(null);
  const [suggBusy, setSuggBusy] = useState(false);
  const [showList, setShowList] = useState(false);
  const [showImport, setShowImport] = useState(false);   // added in the port — see importBackup()
  const [importText, setImportText] = useState("");
  const [checking, setChecking] = useState(false);       // added in the port — see checkAvailability()
  const [checker, setChecker] = useState("");
  const [checkers, setCheckers] = useState(null);        // null = not asked yet, {} = none configured
  const first = useRef(true);
  const suggRef = useRef(null);
  const csvRef = useRef(null);

  useEffect(() => {
    if (csv && csvRef.current) csvRef.current.scrollIntoView({ behavior: "smooth", block: "center" });
  }, [csv]);

  useEffect(() => {
    if (suggs && suggRef.current) {
      suggRef.current.scrollIntoView({ behavior: "smooth", block: "center" });
    }
  }, [suggs]);

  useEffect(() => {
    loadState().then((s) => setState(normalize(s) || freshState()));
  }, []);

  /* Added in the port: which registrars can answer an availability question. Asked
     once, and failure is silent on purpose — the paste box below still works, so a
     panel with no registrar configured loses a shortcut, not the feature. */
  useEffect(() => {
    fetch(DW.checkUrl, { credentials: "same-origin" })
      .then((r) => r.json())
      .then((d) => {
        setCheckers(d.checkers || {});
        setChecker(d.default || "");
      })
      .catch(() => setCheckers({}));
  }, []);

  useEffect(() => {
    if (!state) return;
    if (first.current) {
      first.current = false;
      return;
    }
    const t = setTimeout(() => saveState(state), 500);
    return () => clearTimeout(t);
  }, [state]);

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(""), 4000);
    return () => clearTimeout(t);
  }, [toast]);

  const niche = useMemo(
    () => state?.niches.find((n) => n.id === state.activeId) || state?.niches[0],
    [state]
  );

  if (!state) {
    return (
      <div className="dw">
        <style>{CSS}</style>
        <div style={{ padding: 40, fontFamily: "monospace", fontSize: 13 }}>Opening workbench…</div>
      </div>
    );
  }

  const patchNiche = (patch) =>
    setState((s) => ({
      ...s,
      niches: s.niches.map((n) => (n.id === s.activeId ? { ...n, ...patch } : n)),
    }));

  const setCandidates = (fn) =>
    setState((s) => ({
      ...s,
      niches: s.niches.map((n) => (n.id === s.activeId ? { ...n, candidates: fn(n.candidates) } : n)),
    }));

  const patchRule = (rid, patch) =>
    setState((s) => ({ ...s, rules: s.rules.map((r) => (r.id === rid ? { ...r, ...patch } : r)) }));

  const removeRule = (rid) => {
    const r = state.rules.find((x) => x.id === rid);
    setAsk({
      key: "rule",
      title: `Delete rule "${(r?.text || "").trim().slice(0, 48) || "(empty)"}"? Untick it instead to keep it.`,
      actions: [
        {
          label: "Delete",
          danger: true,
          run: () => setState((s) => ({ ...s, rules: s.rules.filter((x) => x.id !== rid) })),
        },
      ],
    });
  };

  const moveRule = (i, dir) =>
    setState((s) => {
      const next = [...s.rules];
      const j = i + dir;
      if (j < 0 || j >= next.length) return s;
      [next[i], next[j]] = [next[j], next[i]];
      return { ...s, rules: next };
    });

  const patchPattern = (pid, patch) =>
    patchNiche({ patterns: niche.patterns.map((p) => (p.id === pid ? { ...p, ...patch } : p)) });

  const removePattern = (pid) => {
    const p = niche.patterns.find((x) => x.id === pid);
    setAsk({
      key: "pat",
      title: `Delete pattern {name}${p?.kw || ""}.com?`,
      actions: [
        {
          label: "Delete",
          danger: true,
          run: () => patchNiche({ patterns: niche.patterns.filter((x) => x.id !== pid) }),
        },
      ],
    });
  };

  const movePattern = (i, dir) => {
    const next = [...niche.patterns];
    const j = i + dir;
    if (j < 0 || j >= next.length) return;
    [next[i], next[j]] = [next[j], next[i]];
    patchNiche({ patterns: next });
  };

  const addNiche = () => {
    setAskText("");
    setAsk({
      key: "add-niche",
      title: "Name it:",
      input: "e.g. Gutter cleaning",
      actions: [
        {
          label: "Add",
          primary: true,
          run: (text) => {
            const name = (text || "").trim();
            if (!name) return setToast("Give the niche a name");
            const n = {
              id: uid(),
              name,
              patterns: [mkPattern("")],
              nameType: "any",
              maxLength: 22,
              notes: "",
              candidates: [],
            };
            setState((s) => ({ ...s, niches: [...s.niches, n], activeId: n.id }));
            setToast(`${name} added`);
          },
        },
      ],
    });
  };

  const deleteNiche = () => {
    if (state.niches.length < 2) return setToast("Keep at least one niche");
    setAsk({
      key: "del-niche",
      title: `Delete "${niche.name}" and all ${niche.candidates.length} of its domains? Names stay retired. No undo.`,
      actions: [
        {
          label: "Delete niche",
          danger: true,
          run: () =>
            setState((s) => {
              const niches = s.niches.filter((n) => n.id !== s.activeId);
              return { ...s, niches, activeId: niches[0].id };
            }),
        },
      ],
    });
  };

  async function generate() {
    setErr("");
    const live = (niche.patterns || []).filter((p) => p.on && p.kw);
    if (!live.length) return setErr("Turn on at least one domain pattern before searching.");
    setBusy(true);
    try {
      const block = blockedNames.slice(-400).join(", ") || "(none yet)";
      const data = await askModel(
        'You generate .com domain candidates for local home-service brands. Each candidate joins a personal name directly to one of the supplied keywords: lowercase letters only, no spaces. The "name" field must contain ONLY the personal name — never the keyword, never a full business name. Follow the user\'s numbered rules closely — they are ranked, so when two rules pull against each other the lower number wins. Never reuse a name from the blocked list, and never repeat a name within your own answer. Reply with ONLY a JSON array, no markdown fences and no commentary. Each element looks like {"name":"Carver","keyword":"appliancerepair","domain":"carverappliancerepair.com"}',
        `${buildBrief(niche, batch, state.rules)}

Blocked names (already claimed, never reuse): ${block}`,
        // Scale with the batch instead of a fixed 1000. Each candidate is ~30
        // tokens of JSON, and the budget is shared with the model's own thinking,
        // so a batch of 20 could not fit and came back cut off mid-object.
        // Unused headroom costs nothing.
        2000 + batch * 200
      );
      const arr = parseModelArray(data, "domains");

      const fresh = [];
      const claimed = { ...state.registry };
      const blockedSet = new Set(blockedNames);
      const haveDomain = new Set(niche.candidates.map((c) => c.domain));
      for (const c of arr) {
        let person = cleanKw(c.name || c.surname);
        const kw = cleanKw(c.keyword);
        if (!person || !kw) continue;
        // The model sometimes returns "adams restoration" as the name. Don't glue the keyword on twice.
        for (const p of live) {
          if (p.kw && person.endsWith(p.kw) && person.length > p.kw.length) {
            person = person.slice(0, -p.kw.length);
            break;
          }
        }
        if (!person || blockedSet.has(person)) continue;
        const rank = live.findIndex((p) => p.kw === kw);
        if (rank === -1) continue;
        const label = (person + kw).slice(0, 63);
        if (label.length > Number(niche.maxLength)) continue;
        if (haveDomain.has(label + ".com")) continue;
        blockedSet.add(person);
        haveDomain.add(label + ".com");
        claimed[person] = { niche: niche.name, domain: label + ".com", at: Date.now() };
        fresh.push({
          id: uid(),
          person,
          domain: label + ".com",
          tier: rank + 1,
          status: "idea",
          note: "",
          at: Date.now(),
        });
      }
      if (!fresh.length)
        throw new Error("Nothing usable came back — names collided or fell outside your patterns. Try again.");
      setState((s) => ({
        ...s,
        registry: claimed,
        niches: s.niches.map((n) =>
          n.id === s.activeId ? { ...n, candidates: [...fresh, ...n.candidates] } : n
        ),
      }));
      setToast(`${fresh.length} added · ${fresh.length} names retired`);
    } catch (e) {
      const msg = e.message || "Search failed. Try again.";
      setErr(msg);
      setToast(msg);
    } finally {
      setBusy(false);
    }
  }

  async function suggestPatterns() {
    setErr("");
    setSuggBusy(true);
    try {
      const existing = (niche.patterns || []).map((p) => p.kw).filter(Boolean).join(", ") || "(none)";
      const data = await askModel(
        'You advise on keyword choice for local home-service domain names. The keyword is the part that follows a personal name in a .com domain, so {name}KEYWORD.com. Keywords are lowercase letters only, no spaces or punctuation. Reply with ONLY a JSON array, no markdown fences and no commentary. Each element looks like {"keyword":"appliancerepair","why":"what people say when the fridge dies"}.',
        `Niche: ${niche.name}

Suggest 8 keywords, best first. Rank them by how likely a homeowner is to call the number and trust who answers. Weigh, in this order: the words customers actually use when they need this service urgently, how plainly it reads on a truck door or a voicemail greeting, and how well it matches what someone would type into a search box. Prefer everyday speech over trade jargon, and note that a longer exact-service keyword often beats a short vague one because the caller knows immediately what they reached.

House rules, most important first:
${ruleLines(state.rules)}

Already in this niche's list, do not repeat: ${existing}

Each "why" must be under 12 words and say something about the caller, not about the word.`,
        4000
      );
      const arr = parseModelArray(data, "keywords");
      const have = new Set((niche.patterns || []).map((p) => p.kw));
      const clean = [];
      for (const s of arr) {
        const kw = cleanKw(s.keyword);
        if (!kw || have.has(kw) || clean.some((c) => c.kw === kw)) continue;
        clean.push({ kw, why: String(s.why || "").slice(0, 90), pick: true });
      }
      if (!clean.length) throw new Error("Nothing new came back — your list already covers it.");
      setSuggs(clean);
      setToast(`${clean.length} patterns suggested — review them above the table`);
    } catch (e) {
      const msg = e.message || "Couldn't get suggestions. Try again.";
      setErr(msg);
      setToast(msg);
    } finally {
      setSuggBusy(false);
    }
  }

  const takeSuggs = (mode) => {
    const picked = suggs.filter((s) => s.pick).map((s) => mkPattern(s.kw));
    if (!picked.length) return setToast("Tick at least one to add");
    patchNiche({ patterns: mode === "replace" ? picked : [...(niche.patterns || []), ...picked] });
    setSuggs(null);
    setToast(mode === "replace" ? `Replaced with ${picked.length} patterns` : `${picked.length} patterns added`);
  };

  const unchecked = niche.candidates.filter((c) => c.status === "idea");

  const uncheckedText = unchecked.map((c) => c.domain).join("\n");

  const copyUnchecked = async () => {
    if (!unchecked.length) return setToast("Nothing unchecked to copy");
    const ok = await copyText(uncheckedText);
    if (ok) setToast(`${unchecked.length} domains copied — paste into Namecheap bulk search`);
    else {
      setShowList(true);
      setToast("Clipboard blocked — select the list below and copy");
    }
  };

  const applyPaste = () => {
    const low = paste.toLowerCase();
    const hits = [...low.matchAll(DOMAIN_RE)];
    if (!hits.length) return setToast("No .com domains found in that text");

    const verdicts = {};
    const notes = {};
    let unclear = 0;
    let overPriced = 0;
    const cap = Number(state.maxPrice) || 0;
    hits.forEach((h, i) => {
      const from = h.index;
      const to = i + 1 < hits.length ? hits[i + 1].index : low.length;
      const chunk = low.slice(from, to);
      let v;
      if (TAKEN_RE.test(chunk)) v = "taken";
      else if (FREE_RE.test(chunk)) v = "available";
      else {
        unclear++;
        v = fallback === "skip" ? null : fallback;
      }
      if (!v) return;

      const prices = [...chunk.matchAll(PRICE_RE)].map((m) => parseFloat(m[1].replace(/,/g, "")));
      const price = prices.length ? Math.min(...prices) : null;
      if (v === "available" && price !== null) {
        if (cap && price > cap) {
          v = "pricey";
          notes[h[1]] = `Over $${cap} — priced $${price.toLocaleString()}`;
          overPriced++;
        } else {
          notes[h[1]] = `$${price.toFixed(2)}`;
        }
      }
      verdicts[h[1]] = v;
    });

    const known = new Set(niche.candidates.map((c) => c.domain));
    const changed = Object.keys(verdicts).filter((d) => known.has(d));
    if (!changed.length)
      return setToast(unclear ? "Couldn't read a status for any of those" : "None of those are in this niche");

    setUndo(niche.candidates);
    setCandidates((cs) =>
      cs.map((c) =>
        verdicts[c.domain]
          ? { ...c, prev: c.status, status: verdicts[c.domain], note: notes[c.domain] || c.note }
          : c
      )
    );
    setPaste("");
    const nTaken = changed.filter((d) => verdicts[d] === "taken").length;
    const nFree = changed.filter((d) => verdicts[d] === "available").length;
    setToast(
      `${nFree} available · ${nTaken} not available${overPriced ? ` · ${overPriced} over $${cap}` : ""}${
        unclear ? ` · ${unclear} unreadable` : ""
      }`
    );
  };

  /* ADDED IN THE PORT. Ask the registrar directly instead of the copy-paste round
     trip below, which only ever existed because an artifact cannot hold an API key.

     Deliberately maps verdicts through the SAME rules as applyPaste(): the price
     ceiling turns an available name into "pricey", an unreadable verdict follows
     the fallback setting, and the previous status is kept on each row so one Undo
     works for both paths. Two ways of reaching the same statuses must not disagree
     about what a status means.

     Checks whatever the tab is currently showing, so "Unchecked" checks the new
     ones and "All" re-checks everything — the visible list is the promise. */
  async function checkAvailability() {
    if (!shown.length) return setToast("Nothing in this view to check");
    setChecking(true);
    setErr("");
    try {
      const body = new FormData();
      body.append("csrf", DW.csrf);
      body.append("registrar", checker);
      body.append("domains", JSON.stringify(shown.map((c) => c.domain)));
      const res = await fetch(DW.checkUrl, { method: "POST", body, credentials: "same-origin" });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `Check failed (${res.status})`);

      const verdicts = {};
      const notes = {};
      let unclear = 0;
      let overPriced = 0;
      const cap = Number(state.maxPrice) || 0;
      Object.entries(data.results || {}).forEach(([domain, r]) => {
        let v;
        if (r.available === true) v = "available";
        else if (r.available === false) v = "taken";
        else {
          // null means the registrar would not say — not the same as "taken", and
          // guessing here is what the fallback setting exists to decide.
          unclear++;
          v = fallback === "skip" ? null : fallback;
        }
        if (!v) return;
        const price = r.price !== "" && r.price != null ? parseFloat(String(r.price).replace(/,/g, "")) : null;
        if (v === "available" && price !== null && !isNaN(price)) {
          if (cap && price > cap) {
            v = "pricey";
            notes[domain] = `Over $${cap} — priced $${price.toLocaleString()}`;
            overPriced++;
          } else {
            notes[domain] = `$${price.toFixed(2)}`;
          }
        } else if (r.note) {
          notes[domain] = r.note;
        }
        verdicts[domain] = v;
      });

      const known = new Set(niche.candidates.map((c) => c.domain));
      const changed = Object.keys(verdicts).filter((d) => known.has(d));
      if (!changed.length) return setToast("Nothing came back that is in this niche");

      setUndo(niche.candidates);
      setCandidates((cs) =>
        cs.map((c) =>
          verdicts[c.domain]
            ? { ...c, prev: c.status, status: verdicts[c.domain], note: notes[c.domain] || c.note }
            : c
        )
      );
      const nTaken = changed.filter((d) => verdicts[d] === "taken").length;
      const nFree = changed.filter((d) => verdicts[d] === "available").length;
      setToast(
        `${data.label}: ${nFree} available · ${nTaken} not available` +
          (overPriced ? ` · ${overPriced} over $${cap}` : "") +
          (unclear ? ` · ${unclear} no answer` : "") +
          (data.skipped ? ` · ${data.skipped} skipped (over the ${data.checked} cap)` : "")
      );
    } catch (e) {
      const msg = e.message || "Check failed. Try again.";
      setErr(msg);
      setToast(msg);
    } finally {
      setChecking(false);
    }
  }

  const clearRegistry = () => {
    const inUse = new Set();
    state.niches.forEach((n) => n.candidates.forEach((c) => c.person && inUse.add(c.person)));
    const total = Object.keys(state.registry).length;
    const unused = Object.keys(state.registry).filter((k) => !inUse.has(k));
    setAsk({
      key: "clear-reg",
      title: `${total} names retired, ${unused.length} of them not attached to any domain in your lists.`,
      actions: [
        {
          label: `Free the ${unused.length} unused`,
          run: () => {
            setState((s) => {
              const r = { ...s.registry };
              unused.forEach((k) => delete r[k]);
              return { ...s, registry: r };
            });
            setToast(`${unused.length} names back in circulation`);
          },
        },
        {
          label: "Clear all",
          danger: true,
          run: () => {
            setState((s) => ({ ...s, registry: {} }));
            setToast("Registry emptied — every name is available again");
          },
        },
      ],
    });
  };

  const buildCsv = (onlyShortlist) => {
    const esc = (v) => `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
    const rows = [["Niche", "Priority", "Domain", "Name", "Status", "Note"]];
    state.niches.forEach((n) =>
      n.candidates
        .filter((c) => (onlyShortlist ? c.status === "shortlist" : true))
        .slice()
        .sort((a, b) => (a.tier || 1) - (b.tier || 1) || a.domain.localeCompare(b.domain))
        .forEach((c) =>
          rows.push([n.name, `P${c.tier || 1}`, c.domain, c.person || "", STATUSES[c.status].label, c.note || ""])
        )
    );
    return rows.map((r) => r.map(esc).join(",")).join("\r\n");
  };

  const openExport = (onlyShortlist) => {
    const text = buildCsv(onlyShortlist);
    const lines = text.split("\r\n").length - 1;
    if (!lines) return setToast(onlyShortlist ? "Nothing shortlisted yet" : "No domains yet");
    setCsv({ text, lines, scope: onlyShortlist ? "shortlist" : "everything" });
    setToast(`${lines} rows ready`);
  };

  /* Sends every shortlisted candidate across ALL niches (same scope as "Export
     shortlist" above) into D.Buy's own domains table via the server route,
     which uses the exact same additive primitive the paste-box loader does —
     a domain already tracked there is left completely untouched, never reset
     or reordered. Once the server confirms each domain is actually present in
     D.Buy (newly added OR already there from before), it moves OUT of the
     Shortlist tab here too — into the "purchased" status this app already
     defines (STATUSES.purchased, the "Claimed" stamp) but never had a real
     trigger for until now. That is a genuine move, just split into two steps
     so a failed request never claims a shortlist entry that never actually
     reached D.Buy. */
  async function sendShortlistToDbuy() {
    const items = [];
    state.niches.forEach((n) =>
      n.candidates
        .filter((c) => c.status === "shortlist")
        .forEach((c) => items.push({ domain: c.domain, niche: n.name }))
    );
    if (!items.length) return setToast("Nothing shortlisted yet");

    setSendingToDbuy(true);
    setErr("");
    try {
      const body = new FormData();
      body.append("csrf", DW.csrf);
      body.append("items", JSON.stringify(items));
      const res = await fetch(DW.dbuyUrl, { method: "POST", body, credentials: "same-origin" });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `Send failed (${res.status})`);

      const confirmed = new Set([...(data.added || []), ...(data.duplicates || [])]);
      if (confirmed.size) {
        setState((s) => ({
          ...s,
          niches: s.niches.map((n) => ({
            ...n,
            candidates: n.candidates.map((c) =>
              c.status === "shortlist" && confirmed.has(c.domain) ? { ...c, status: "purchased" } : c
            ),
          })),
        }));
      }

      setToast(
        `${data.added_count} added to D.Buy` +
          (data.duplicate_count ? ` · ${data.duplicate_count} already there` : "") +
          (confirmed.size ? ` · ${confirmed.size} moved out of Shortlist` : "") +
          (data.invalid_count ? ` · ${data.invalid_count} skipped (bad domain)` : "")
      );
    } catch (e) {
      const msg = e.message || "Send failed. Try again.";
      setErr(msg);
      setToast(msg);
    } finally {
      setSendingToDbuy(false);
    }
  }

  const askStrip = (key) => {
    if (!ask || ask.key !== key) return null;
    return (
      <div className="dw-ask">
        <span className="dw-ask-t">{ask.title}</span>
        {ask.input !== undefined && (
          <input
            className="dw-in"
            autoFocus
            value={askText}
            placeholder={ask.input}
            onChange={(e) => setAskText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                ask.actions[0].run(askText);
                setAsk(null);
              }
            }}
            style={{ flex: 1, minWidth: 140 }}
          />
        )}
        {ask.actions.map((a) => (
          <button
            key={a.label}
            className={a.danger ? "dw-btn danger tiny" : "dw-btn tiny"}
            onClick={() => {
              a.run(askText);
              setAsk(null);
            }}
          >
            {a.label}
          </button>
        ))}
        <button className="dw-btn ghost tiny" onClick={() => setAsk(null)}>
          Cancel
        </button>
      </div>
    );
  };

  const toggleSel = (id) =>
    setSel((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

  const moveSelected = (status) => {
    setUndo(niche.candidates);
    setCandidates((cs) =>
      cs.map((c) => (sel.includes(c.id) ? { ...c, prev: c.status, status } : c))
    );
    setToast(`${sel.length} moved to ${STATUSES[status].label.toLowerCase()}`);
    setSel([]);
  };

  const restoreSelected = () => {
    setUndo(niche.candidates);
    setCandidates((cs) =>
      cs.map((c) => (sel.includes(c.id) ? { ...c, status: c.prev || "idea", prev: c.status } : c))
    );
    setToast(`${sel.length} put back`);
    setSel([]);
  };

  const undoPaste = () => {
    if (!undo) return;
    setCandidates(() => undo);
    setUndo(null);
    setToast("Last update reversed");
  };

  const releaseName = (person) => {
    setState((s) => {
      const r = { ...s.registry };
      delete r[person];
      return { ...s, registry: r };
    });
    setToast(`${person} back in circulation`);
  };

  const exportBackup = async () => {
    const text = JSON.stringify(state, null, 2);
    const ok = await copyText(text);
    setToast(ok ? "Backup copied to clipboard" : "Clipboard blocked — try again from a wider window");
  };

  /* ADDED IN THE PORT. exportBackup() produced JSON that nothing could read back,
     which made it a copy rather than a backup — and the artifact's storage does
     not migrate itself, so without this the existing work had no way across.

     Runs the paste through the same normalize() every load uses, so an older
     shape is repaired on the way in rather than being trusted because it arrived
     by a different door. Confirmation is an inline strip, like every other
     destructive action here: the artifact sandbox blocked window.confirm, and
     the pattern works everywhere so there is no reason to change it. */
  const importBackup = () => {
    let parsed;
    try {
      parsed = JSON.parse(importText);
    } catch {
      return setToast("That is not valid JSON — paste the whole backup, braces included");
    }
    const clean = normalize(parsed);
    if (!clean) return setToast("No niches in that backup — nothing to restore");
    const nCand = clean.niches.reduce((t, n) => t + (n.candidates || []).length, 0);
    const nHere = state.niches.reduce((t, n) => t + (n.candidates || []).length, 0);
    setAsk({
      key: "import",
      title:
        `Replace everything with this backup? ${clean.niches.length} niches, ${nCand} candidates, ` +
        `${Object.keys(clean.registry).length} retired names — over the ${state.niches.length} niches ` +
        `and ${nHere} candidates here now. This cannot be undone.`,
      actions: [
        {
          label: "Replace",
          danger: true,
          run: () => {
            setState(clean);
            setImportText("");
            setShowImport(false);
            setToast(`Restored ${clean.niches.length} niches · ${nCand} candidates`);
          },
        },
      ],
    });
  };

  const shown = (
    filter === "all" ? niche.candidates : niche.candidates.filter((c) => c.status === filter)
  )
    .slice()
    .sort((a, b) => (a.tier || 1) - (b.tier || 1) || b.at - a.at);
  const tallyMap = Object.fromEntries(
    STATUS_ORDER.map((k) => [k, niche.candidates.filter((c) => c.status === k).length])
  );
  const nameUses = {};
  state.niches.forEach((n) =>
    n.candidates.forEach((c) => {
      if (!c.person) return;
      (nameUses[c.person] = nameUses[c.person] || []).push({
        status: c.status,
        domain: c.domain,
        niche: n.name,
        note: c.note,
      });
    })
  );

  // A name is spent if it's still in play anywhere, or you judged the name itself.
  // If every domain built on it came back taken, the name was never really used — reuse it.
  const isBlocked = (p) => {
    const u = nameUses[p];
    if (!u || !u.length) return true;
    return u.some((x) => !NOT_SPENT.includes(x.status));
  };
  const blockedNames = Object.keys(state.registry).filter(isBlocked);

  const bestUse = (p) => {
    const u = nameUses[p] || [];
    if (!u.length) return null;
    const rank = ["purchased", "shortlist", "available", "idea", "rejected", "taken"];
    return u.slice().sort((a, b) => rank.indexOf(a.status) - rank.indexOf(b.status))[0];
  };

  const registryList = Object.entries(state.registry).sort((a, b) => b[1].at - a[1].at);

  return (
    <div className="dw">
      <style>{CSS}</style>

      <header className="dw-bar">
        <span className="dw-logo">Name Registry</span>
        <span className="dw-sub">
          {blockedNames.length} names spent · {registryList.length - blockedNames.length} reusable ·{" "}
          {state.niches.length} niches · .com only
        </span>
        <span className="dw-bar-end">
          <button className="dw-btn ghost tiny" onClick={() => setShowHelp((v) => !v)}>
            {showHelp ? "Close" : "How this works"}
          </button>
          <button className="dw-btn ghost tiny" onClick={() => setShowRules((v) => !v)}>
            {showRules ? "Hide rules" : "Rules"}
          </button>
          <button className="dw-btn ghost tiny" onClick={() => setShowReg((v) => !v)}>
            {showReg ? "Hide registry" : "View registry"}
          </button>
          <button className="dw-btn ghost tiny" onClick={() => setShowRegs((v) => !v)}>
            {showRegs ? "Close" : "Reg $/APIs"}
          </button>
          <button className="dw-btn ghost tiny" onClick={() => openExport(true)}>
            Export shortlist
          </button>
          <button className="dw-btn ghost tiny" onClick={() => openExport(false)}>
            Export all
          </button>
          <button className="dw-btn ghost tiny" onClick={exportBackup}>
            Copy backup
          </button>
          {/* Added in the port — sits next to Copy backup because the two are one
              round trip, and an export with no way back in is not a backup. */}
          <button className="dw-btn ghost tiny" onClick={() => setShowImport((v) => !v)}>
            {showImport ? "Close" : "Restore backup"}
          </button>
        </span>
      </header>

      {showImport && (
        <section className="dw-col" style={{ borderBottom: "1px solid var(--rule)" }}>
          <p className="dw-eyebrow">Restore from a backup</p>
          <p style={{ margin: "0 0 10px", maxWidth: 640, color: "var(--soft)" }}>
            Paste a backup JSON — from <strong>Copy backup</strong> here, or from the artifact this
            workbench came from. It replaces everything: niches, candidates, the retired-name registry
            and your rules. You'll be asked to confirm first.
          </p>
          <textarea
            className="dw-ta"
            value={importText}
            onChange={(e) => setImportText(e.target.value)}
            placeholder={'{\n  "niches": [ … ],\n  "registry": { … }\n}'}
            rows={8}
            style={{ maxWidth: 640 }}
          />
          <div style={{ marginTop: 10, display: "flex", gap: 8 }}>
            <button className="dw-btn" onClick={importBackup} disabled={!importText.trim()}>
              Restore
            </button>
            <button className="dw-btn ghost" onClick={() => { setImportText(""); setShowImport(false); }}>
              Cancel
            </button>
          </div>
          {/* Without this the confirmation has nowhere to appear: askStrip only renders
              where its key is placed, so setAsk({key:"import"}) put the strip in state
              and Restore silently did nothing. Found by clicking the button, not by
              reading the code — every other confirm in this file has its own strip. */}
          {askStrip("import")}
        </section>
      )}

      {showHelp && (
        <section className="dw-col dw-help">
          <h2 className="dw-help-title">How this works</h2>
          <p className="dw-help-lead">
            You're building brands shaped like a person's name joined to a service — Carver Appliance
            Repair, Gus Pest Control. This tool generates those candidates, keeps track of which ones
            are free, and remembers every name you've already spent so you never go round in circles.
          </p>

          <div className="dw-help-grid">
            <div>
              <h4 className="dw-help-h">The loop</h4>
              <ol className="dw-help-list">
                <li>
                  Pick a niche on the left. Each one keeps its own patterns and its own list of
                  domains.
                </li>
                <li>
                  Press <b>Find domains</b>. Candidates appear in the table as Unchecked. Nothing has
                  been looked up yet — these are just ideas.
                </li>
                <li>
                  Press <b>Copy list</b>, paste into Namecheap's bulk domain search, and run it.
                </li>
                <li>
                  Copy Namecheap's results, paste them into the box on the right, and press{" "}
                  <b>Update from results</b>. Rows sort themselves into Available and Not available.
                </li>
                <li>
                  Work the Available tab: keep the good ones, park the rest. Buy from your shortlist,
                  then mark those rows Purchased by hand.
                </li>
              </ol>
            </div>

            <div>
              <h4 className="dw-help-h">The one rule that shapes everything</h4>
              <p>
                A name can only be spent once. If it's in play anywhere — unchecked, available,
                shortlisted, purchased — or you marked it "didn't like", it won't be suggested again
                in any niche.
              </p>
              <p>
                The exception: if every domain built on a name came back <b>not available</b> or{" "}
                <b>too pricey</b>, you never actually got it, so the name returns to the pool for
                other niches. Those show as <b>Free to reuse</b> in the registry.
              </p>
              <p>
                <b>View registry</b> lists every name with the reason it's held. The × removes it
                from the list entirely, freeing it regardless of status.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Patterns and priority</h4>
              <p>
                A pattern is the word that follows the name: <code>{"{name}appliancerepair.com"}</code>.
                They're ranked P1, P2, P3, and searches fill P1 as far as good names allow before
                dropping to P2. Reorder with the arrows, untick to mute one without deleting it.
              </p>
              <p>
                <b>Suggest patterns</b> proposes eight, ranked by how a homeowner is likely to talk
                about the service and who they'd trust to call. Nothing is applied until you choose
                Add or Replace.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Rules</h4>
              <p>
                The <b>Rules</b> panel is your judgement, written down — short, easy to say, sounds
                trustworthy. They apply to every niche and are ranked: when two rules pull against
                each other, the higher one wins. Untick to mute a rule, delete to lose it.
              </p>
              <p>
                <b>Search brief</b> at the bottom of the right panel shows the exact instructions
                being sent, built from your rules and patterns. Open it if a search isn't returning
                what you expected.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">The columns</h4>
              <dl className="dw-help-dl">
                <dt>Checkbox</dt>
                <dd>Selects rows for a bulk move. The one in the header selects everything in view.</dd>
                <dt>Pri</dt>
                <dd>Which pattern the domain came from. P1 is your best pattern.</dd>
                <dt>Domain</dt>
                <dd>
                  The candidate itself. Struck through means dead; a red <b>Claimed</b> stamp means
                  you own it.
                </dd>
                <dt>Status</dt>
                <dd>Change one row at a time here. For many rows, use the checkboxes instead.</dd>
                <dt>Note</dt>
                <dd>Free text — price paid, why you passed, anything. It survives every move.</dd>
                <dt>×</dt>
                <dd>
                  Removes the row, then asks whether the name should go back into circulation.
                  Reserve it for mistakes; parking a domain in a tab is almost always better.
                </dd>
              </dl>
            </div>

            <div>
              <h4 className="dw-help-h">The tabs</h4>
              <dl className="dw-help-dl">
                <dt>Unchecked</dt>
                <dd>Generated but never looked up. This is what Copy list gives you.</dd>
                <dt>Available</dt>
                <dd>Namecheap says it's free. Your decisions get made here.</dd>
                <dt>Shortlist</dt>
                <dd>Worth buying. Your queue when you next open Namecheap with a card.</dd>
                <dt>Purchased</dt>
                <dd>Owned. Set this yourself once the money is actually spent.</dd>
                <dt>Not available</dt>
                <dd>Taken by someone else.</dd>
                <dt>Too pricey</dt>
                <dd>
                  Free, but above your price ceiling. Like Not available, this doesn't spend the
                  name — it stays open for your other niches, where the same name may cost normal
                  money.
                </dd>
                <dt>Didn't like</dt>
                <dd>
                  Your parking lot. Rejected but kept, so you remember passing on it. Select rows
                  here and press <b>↩ Put back</b> to return them to wherever they came from.
                </dd>
              </dl>
            </div>

            <div>
              <h4 className="dw-help-h">If something goes wrong</h4>
              <p>
                <b>Undo last update</b> appears under the paste box after any bulk move or pasted
                result, and reverses it in one click.
              </p>
              <p>
                When pasting results, anything the parser can't read a status for is left Unchecked
                rather than guessed at, and the message at the bottom of the screen tells you the
                counts. If those don't match what Namecheap showed you, undo and check the text you
                pasted.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Saving</h4>
              <p>
                Everything saves automatically about half a second after you stop typing, tied to
                your Claude account. Close the tab and come back whenever — reopen this conversation
                and it's as you left it.
              </p>
              <p>
                <b>Copy backup</b> puts all your data on the clipboard as text. Paste it somewhere
                safe now and then. It holds your work, not the app itself, and deleting this
                conversation deletes the workbench with it.
              </p>
            </div>
          </div>
        </section>
      )}

      {csv && (
        <section className="dw-col" ref={csvRef} style={{ borderBottom: "1px solid var(--rule)" }}>
          <div className="dw-row" style={{ justifyContent: "space-between", marginBottom: 8 }}>
            <p className="dw-eyebrow" style={{ margin: 0 }}>
              {csv.lines} rows — {csv.scope}
            </p>
            <div className="dw-row">
              <button
                className="dw-btn"
                onClick={async () => {
                  const ok = await copyText(csv.text);
                  setToast(ok ? "Copied — paste into a blank sheet" : "Select the text below and copy");
                }}
              >
                Copy for spreadsheet
              </button>
              <a
                className="dw-btn ghost"
                href={`data:text/csv;charset=utf-8,${encodeURIComponent(csv.text)}`}
                download={`domains-${csv.scope}.csv`}
                target="_blank"
                rel="noreferrer"
              >
                Try .csv download
              </a>
              <button className="dw-btn ghost" onClick={() => setCsv(null)}>
                Close
              </button>
            </div>
          </div>
          <textarea
            className="dw-ta"
            readOnly
            rows={8}
            value={csv.text}
            onFocus={(e) => e.target.select()}
          />
          <p className="dw-hint">
            Downloads are often blocked inside this panel. The reliable route: press{" "}
            <b>Copy for spreadsheet</b>, open a blank Excel or Google Sheets file, click cell A1 and
            paste — it splits into columns automatically.
          </p>
        </section>
      )}

      {showRegs && (
        <section className="dw-col dw-help">
          <h2 className="dw-help-title">Registrars — funding &amp; API keys</h2>
          <p className="dw-help-lead">
            Menu paths change; if one doesn't match, search the registrar's help centre for the
            wording in bold. Checked August 2026.
          </p>

          <div className="dw-help-grid">
            <div>
              <h4 className="dw-help-h">Namecheap</h4>
              <p>
                <b>Adding money.</b> Click <b>Top-up</b> on the Dashboard, or go{" "}
                <b>Profile → Billing → Balance → Top-up</b>. Enter an amount, add card details,
                confirm with <b>Charge and Proceed</b>. Minimum $5, maximum $100,000 by card or
                PayPal; $1 minimum by Bitcoin, which can take up to 24 hours to land. Balance applies
                automatically at checkout. Funds can't be transferred between accounts.
              </p>
              <p>
                <b>API key.</b> <b>Profile → Tools</b>, scroll to <b>Business &amp; Dev Tools</b>,
                click <b>Manage</b> beside <b>Namecheap API Access</b>, toggle on, accept terms,
                re-enter your password. The key appears immediately; your account username doubles as
                the API username.
              </p>
              <p>
                Two catches. Access needs 20+ domains, a $50 balance, or $50 spent in the last two
                years. And you must whitelist at least one IPv4 address in the same panel or every
                call fails with "Invalid API Key" even when the key is correct. Free sandbox at
                sandbox.namecheap.com with separate credentials.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Porkbun</h4>
              <p>
                <b>Adding money.</b> <b>Account → Settings / Billing</b>, find{" "}
                <b>Billing Information</b>, then under <b>Pre-fund Your Account</b> click{" "}
                <b>buy account credit</b>. The cart defaults to $100; adjust it (minimum $10) and
                press apply. Credit applies automatically at checkout and to auto-renewals. Balance
                also visible at porkbun.com/account/credit.
              </p>
              <p>
                ACH bank transfer earns extra discounts but takes up to 7 days to clear, so pre-fund
                ahead of renewals. Going ACH-only disables cards and PayPal on that account — the
                usual workaround is a subaccount designated ACH-only.
              </p>
              <p>
                <b>API key.</b> <b>Account → API Access</b> (porkbun.com/account/api). Name the key,
                click <b>Create API Key</b>, copy both the key and the secret — the secret is shown
                once and can't be retrieved later.
              </p>
              <p>
                Easy to miss: a key alone isn't enough. Each domain needs <b>API Access</b> switched
                on individually under <b>Domain Management → Details</b>. One key pair covers every
                domain you've enabled.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Cloudflare</h4>
              <p>
                <b>Adding money.</b> You can't. There's no account balance to prepay into — a valid
                payment method on file is required instead, set at{" "}
                <b>Manage Account → Billing → Payment</b>. Registrar orders are processed as new
                orders charged to that method and won't draw on any credits sitting in the account.
              </p>
              <p>
                <b>API token.</b> <b>My Profile → API Tokens</b>
                (dash.cloudflare.com/profile/api-tokens). Use <b>Create Token</b> and scope it to
                what you need; the legacy Global API Key is all-or-nothing and best avoided. Shown
                once at creation.
              </p>
              <p>
                Worth knowing: Cloudflare sells domains at wholesale cost with no markup, so renewals
                roughly match registration. But it only accepts <b>transfers</b> — you can't register
                a new domain there. Buy elsewhere, move it after the 60-day ICANN lock.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Dynadot</h4>
              <p>
                <b>Adding money.</b> Fund the account balance from your account panel — the API
                exposes it as <code>get_account_balance</code>, and registrations fail with{" "}
                <b>insufficient_funds</b> if the balance won't cover the order. Prepaying is
                effectively required for API buying rather than optional.
              </p>
              <p>
                <b>API key.</b> Left menu <b>Tools → API</b>. Unlock the account using the link on
                that page, and the keys appear: a <b>Production Key</b> and <b>Sandbox Key</b> for
                the legacy and RESTful APIs, plus <b>Secret Keys</b> used to generate the x-signature
                for the RESTful API.
              </p>
              <p>
                Set authorised IPs in the same panel (single addresses, or CIDR ranges for servers).
                Allow about 10 minutes for changes to reach the API servers before testing. API
                access is open to all accounts, but your spending level determines how many
                concurrent threads you get.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">NameSilo</h4>
              <p>
                <b>Adding money.</b> Account page → <b>Account Options</b> → <b>Account Funds
                Manager</b> → <b>Add Funds</b>. No fees on verified credit/debit card, verified
                PayPal, Bitcoin, or verified Skrill. A prepaid balance also unlocks better per-domain
                pricing.
              </p>
              <p>
                Useful for a portfolio: set a minimum threshold and a replenishment amount, and the
                balance tops itself up whenever it drops below the threshold — renewals can't fail
                for lack of funds.
              </p>
              <p>
                <b>API key.</b> <b>API Manager</b> from the account menu
                (namesilo.com/account/api-manager) — generate the key there and store it somewhere
                safe.
              </p>
              <p>
                You can restrict access to up to 5 originating IP addresses; leaving the fields blank
                allows connections from anywhere, which isn't what you want for a key that can spend
                money. NameSilo's API can also add account funds programmatically via{" "}
                <code>addAccountFunds</code> against a verified card.
              </p>
            </div>

            <div>
              <h4 className="dw-help-h">Which to use for what</h4>
              <p>
                For buying: <b>Namecheap</b>, <b>Porkbun</b>, <b>Dynadot</b>, and <b>NameSilo</b> all
                hold a prepaid balance. That caps what a session can spend and stops a renewal
                failing on an expired card. NameSilo's auto-replenish and Porkbun's ACH discount are
                the standouts for a portfolio.
              </p>
              <p>
                Hold long term at <b>Cloudflare</b> if the portfolio grows — wholesale renewal
                pricing compounds across dozens of domains held for years. Transfer them in after the
                60-day lock; it can't register new ones.
              </p>
              <p>
                Every registrar here except Cloudflare restricts API access by IP address. Set that
                before your first call — a wrong or missing IP usually surfaces as an authentication
                error rather than anything mentioning IPs, which sends people hunting the wrong
                problem.
              </p>
              <p>
                An API key would let a future version of this tool check availability directly
                instead of the copy-paste round trip. Namecheap's{" "}
                <code>namecheap.domains.check</code> takes up to 50 domains per call. That needs a
                server to hold the key — never put one in browser code.
              </p>
            </div>
          </div>
        </section>
      )}

      {showRules && (
        <section className="dw-col" style={{ borderBottom: "1px solid var(--rule)" }}>
          <div className="dw-row" style={{ justifyContent: "space-between", marginBottom: 4 }}>
            <p className="dw-eyebrow" style={{ margin: 0 }}>
              Search rules — most important first, applied to every niche
            </p>
            <button
              className="dw-btn ghost tiny"
              onClick={() =>
                setAsk({
                  key: "restore-rules",
                  title: `Throw away all ${state.rules.length} of your rules and put back the ${DEFAULT_RULES.length} originals? No undo.`,
                  actions: [
                    {
                      label: "Restore defaults",
                      danger: true,
                      run: () => setState((s) => ({ ...s, rules: DEFAULT_RULES.map(mkRule) })),
                    },
                  ],
                })
              }
            >
              Restore defaults
            </button>
          </div>
          <p className="dw-hint" style={{ margin: "0 0 12px" }}>
            Rank matters: when two rules conflict, the higher one wins. Uncheck to mute a rule
            without losing it.
          </p>
          {askStrip("restore-rules")}
          {askStrip("rule")}
          <div className="dw-rules">
            {state.rules.map((r, i) => (
              <div className="dw-rule" key={r.id} data-off={r.on ? "0" : "1"}>
                <input type="checkbox" checked={r.on} onChange={() => patchRule(r.id, { on: !r.on })} />
                <span className="dw-rnum">{String(i + 1).padStart(2, "0")}</span>
                <textarea
                  className="dw-rtext"
                  rows={2}
                  value={r.text}
                  placeholder="Describe what makes a name good or bad"
                  onChange={(e) => patchRule(r.id, { text: e.target.value })}
                />
                <button className="dw-mini" title="Move up" disabled={i === 0} onClick={() => moveRule(i, -1)}>
                  ↑
                </button>
                <button
                  className="dw-mini"
                  title="Move down"
                  disabled={i === state.rules.length - 1}
                  onClick={() => moveRule(i, 1)}
                >
                  ↓
                </button>
                <button className="dw-mini" title="Delete rule" onClick={() => removeRule(r.id)}>
                  ×
                </button>
              </div>
            ))}
          </div>
          <button
            className="dw-btn ghost"
            style={{ marginTop: 12 }}
            onClick={() => setState((s) => ({ ...s, rules: [...s.rules, mkRule("")] }))}
          >
            + Add rule
          </button>
          <p className="dw-hint">
            The .com ending, your length limit, and the retired-name check are enforced in code too,
            so they hold even if a rule here is muted.
          </p>
        </section>
      )}

      {showReg && (
        <div className="dw-col" style={{ borderBottom: "1px solid var(--rule)" }}>
          <div className="dw-row" style={{ justifyContent: "space-between", marginBottom: 4 }}>
            <p className="dw-eyebrow" style={{ margin: 0 }}>
              Names used — only the spent ones are blocked from future searches
            </p>
            <button className="dw-btn ghost tiny" onClick={clearRegistry}>
              Clear registry
            </button>
          </div>
          {askStrip("clear-reg")}
          {registryList.length ? (
            <div className="dw-reg">
              {registryList.map(([sn, meta]) => {
                const use = bestUse(sn);
                const free = !isBlocked(sn);
                const reason = use ? STATUSES[use.status].label : meta.outcome || "Row removed";
                const tone = use ? STATUSES[use.status].tone : "dead";
                const count = (nameUses[sn] || []).length;
                return (
                  <div className="dw-reg-row" key={sn} data-free={free ? "1" : "0"}>
                    <span className="dw-reg-name" data-free={free ? "1" : "0"}>
                      {sn}
                    </span>
                    <span className="dw-tag" data-tone={free ? "good" : tone}>
                      {free ? "Free to reuse" : reason}
                    </span>
                    <code className="dw-reg-dom">
                      {use ? use.domain : meta.domain}
                      {count > 1 ? ` +${count - 1}` : ""}
                    </code>
                    <span className="dw-reg-niche">{use ? use.niche : meta.niche}</span>
                    <span className="dw-reg-note">{use?.note || ""}</span>
                    <button className="dw-mini" onClick={() => releaseName(sn)} title="Remove from this list">
                      ×
                    </button>
                  </div>
                );
              })}
            </div>
          ) : (
            <p className="dw-hint">Empty. Every name is still in circulation.</p>
          )}
        </div>
      )}

      <div className="dw-grid">
        <main className="dw-col">
          <div className="dw-row" style={{ justifyContent: "space-between", marginBottom: 4 }}>
            <h1 className="dw-h">{niche.name}</h1>
            <button className="dw-btn ghost tiny" onClick={deleteNiche}>
              Delete niche
            </button>
          </div>
          {askStrip("del-niche")}
          {err && <div className="dw-err" style={{ marginTop: 12 }}>{err}</div>}

          <div className="dw-row" style={{ margin: "12px 0 16px" }}>
            <button className="dw-btn" onClick={generate} disabled={busy}>
              {busy ? "Searching…" : "Find domains"}
            </button>
            <select className="dw-sel" value={batch} onChange={(e) => setBatch(Number(e.target.value))}>
              <option value={8}>8 at a time</option>
              <option value={12}>12 at a time</option>
              <option value={20}>20 at a time</option>
            </select>
            <button className="dw-btn ghost" onClick={sendShortlistToDbuy} disabled={sendingToDbuy}>
              {sendingToDbuy ? "Sending…" : "Move items in Shortlist to D.Buy"}
            </button>
          </div>

          <div className="dw-tabs">
            {[["all", "All"], ...STATUS_ORDER.map((k) => [k, STATUSES[k].label])].map(([k, label]) => {
              const n = k === "all" ? niche.candidates.length : tallyMap[k];
              return (
                <button
                  key={k}
                  className="dw-tab"
                  data-on={filter === k ? "1" : "0"}
                  data-empty={n ? "0" : "1"}
                  onClick={() => {
                    setFilter(k);
                    setSel([]);
                  }}
                >
                  {label} <span className="dw-tab-n">{n}</span>
                </button>
              );
            })}
          </div>

          {suggs && (
            <div className="dw-sugg-box" ref={suggRef}>
              <p className="dw-eyebrow" style={{ margin: "0 0 2px" }}>
                Suggested patterns — ranked by how customers ask for it
              </p>
              <p className="dw-hint" style={{ margin: "0 0 10px" }}>
                Untick anything you don't want. Order is the recommendation.
              </p>
              {suggs.map((s, i) => (
                <label className="dw-sugg" key={s.kw} data-off={s.pick ? "0" : "1"}>
                  <input
                    type="checkbox"
                    checked={s.pick}
                    onChange={() =>
                      setSuggs((list) => list.map((x, j) => (j === i ? { ...x, pick: !x.pick } : x)))
                    }
                  />
                  <span className="dw-rnum">{String(i + 1).padStart(2, "0")}</span>
                  <code className="dw-sugg-dom">{`{name}${s.kw}.com`}</code>
                  <span className="dw-sugg-why">{s.why}</span>
                </label>
              ))}
              <div className="dw-row" style={{ marginTop: 12 }}>
                <button className="dw-btn" onClick={() => takeSuggs("append")}>
                  Add to list
                </button>
                <button className="dw-btn ghost" onClick={() => takeSuggs("replace")}>
                  Replace my list
                </button>
                <button className="dw-btn ghost" onClick={() => setSuggs(null)}>
                  Dismiss
                </button>
              </div>
            </div>
          )}

          {unchecked.length > 0 && (
            <div className="dw-ready">
              <div className="dw-row" style={{ width: "100%" }}>
                <span className="dw-ready-n">{unchecked.length}</span>
                <span>
                  unchecked {unchecked.length === 1 ? "domain" : "domains"} ready for Namecheap
                </span>
                <span style={{ marginLeft: "auto" }} className="dw-row">
                  <button className="dw-btn" onClick={copyUnchecked}>
                    Copy list
                  </button>
                  <button className="dw-btn ghost" onClick={() => setShowList((v) => !v)}>
                    {showList ? "Hide list" : "Show list"}
                  </button>
                </span>
              </div>
              {showList && (
                <textarea
                  className="dw-ta"
                  readOnly
                  value={uncheckedText}
                  onFocus={(e) => e.target.select()}
                  rows={Math.min(unchecked.length, 10)}
                  style={{ marginTop: 10 }}
                />
              )}
            </div>
          )}

          {sel.length > 0 && (
            <div className="dw-bulk">
              <span>
                <strong>{sel.length}</strong> selected — move to
              </span>
              {["taken", "pricey", "rejected"].includes(filter) && (
                <button className="dw-btn ghost tiny" onClick={restoreSelected}>
                  ↩ Put back
                </button>
              )}
              {STATUS_ORDER.filter((k) => k !== filter).map((k) => (
                <button key={k} className="dw-btn ghost tiny" onClick={() => moveSelected(k)}>
                  {STATUSES[k].label}
                </button>
              ))}
              <button className="dw-btn ghost tiny" style={{ marginLeft: "auto" }} onClick={() => setSel([])}>
                Clear
              </button>
            </div>
          )}

          {shown.length ? (
            <table className="dw-table">
              <thead>
                <tr>
                  <th style={{ width: 26 }}>
                    <input
                      type="checkbox"
                      title="Select all shown"
                      checked={sel.length > 0 && sel.length === shown.length}
                      onChange={(e) => setSel(e.target.checked ? shown.map((c) => c.id) : [])}
                    />
                  </th>
                  <th style={{ width: 38 }}>Pri</th>
                  <th style={{ width: "38%" }}>Domain</th>
                  <th style={{ width: 140 }}>Status</th>
                  <th>Note</th>
                  <th style={{ width: 28 }} />
                </tr>
              </thead>
              <tbody>
                {shown.map((c) => (
                  <React.Fragment key={c.id}>
                  <tr
                    data-dead={["taken", "pricey", "rejected"].includes(c.status) ? "1" : "0"}
                    data-sel={sel.includes(c.id) ? "1" : "0"}
                  >
                    <td>
                      <input type="checkbox" checked={sel.includes(c.id)} onChange={() => toggleSel(c.id)} />
                    </td>
                    <td>
                      <span className="dw-pri">P{c.tier || 1}</span>
                    </td>
                    <td>
                      <span className="dw-dom">{c.domain}</span>{" "}
                      {c.status === "purchased" && <span className="dw-stamp">Claimed</span>}
                    </td>
                    <td>
                      <select
                        className="dw-sel"
                        value={c.status}
                        onChange={(e) =>
                          setCandidates((cs) =>
                            cs.map((x) =>
                              x.id === c.id ? { ...x, prev: x.status, status: e.target.value } : x
                            )
                          )
                        }
                      >
                        {STATUS_ORDER.map((k) => (
                          <option key={k} value={k}>
                            {STATUSES[k].label}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>
                      <input
                        className="dw-note"
                        value={c.note}
                        placeholder="—"
                        onChange={(e) =>
                          setCandidates((cs) =>
                            cs.map((x) => (x.id === c.id ? { ...x, note: e.target.value } : x))
                          )
                        }
                      />
                    </td>
                    <td>
                      <button
                        className="dw-btn ghost tiny"
                        title="Remove row (name stays retired)"
                        onClick={() =>
                          setAsk({
                            key: `row:${c.id}`,
                            title: `Remove ${c.domain}. Keep the name "${c.person}" retired, or free it for future searches?`,
                            actions: [
                              {
                                label: "Remove, keep retired",
                                danger: true,
                                run: () => {
                                  setCandidates((cs) => cs.filter((x) => x.id !== c.id));
                                  setState((s) => ({
                                    ...s,
                                    registry: s.registry[c.person]
                                      ? {
                                          ...s.registry,
                                          [c.person]: {
                                            ...s.registry[c.person],
                                            outcome: `Row removed${c.note ? ` — ${c.note}` : ""}`,
                                          },
                                        }
                                      : s.registry,
                                  }));
                                },
                              },
                              {
                                label: "Remove, free the name",
                                run: () => {
                                  setCandidates((cs) => cs.filter((x) => x.id !== c.id));
                                  if (c.person) releaseName(c.person);
                                },
                              },
                            ],
                          })
                        }
                      >
                        ×
                      </button>
                    </td>
                  </tr>
                    {ask && ask.key === `row:${c.id}` && (
                      <tr>
                        <td colSpan={6} style={{ padding: "0 0 8px" }}>
                          {askStrip(`row:${c.id}`)}
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="dw-empty">
              {niche.candidates.length
                ? "Nothing matches that filter."
                : "No domains yet. Set your criteria, then hit Find domains."}
            </div>
          )}
        </main>

        <aside className="dw-col dw-side">
          {/* Niches sit at the top of this column rather than in a rail of their
              own on the left: the switcher and the settings for whatever it
              switches to are the same job, and splitting them put a 200px column
              on screen holding four buttons. */}
          <p className="dw-eyebrow">Niches</p>
          {state.niches.map((n) => (
            <button
              key={n.id}
              className="dw-niche"
              data-on={n.id === niche.id ? "1" : "0"}
              onClick={() => {
                setUndo(null);
                setSuggs(null);
                setState((s) => ({ ...s, activeId: n.id }));
              }}
            >
              {n.name}
              <span className="dw-count">{n.candidates.length}</span>
            </button>
          ))}
          <button className="dw-btn ghost wide" style={{ marginTop: 12 }} onClick={addNiche}>
            + Add niche
          </button>
          {askStrip("add-niche")}

          <hr className="dw-sep" />

          <p className="dw-eyebrow">Domain patterns — priority order</p>
          <p className="dw-hint" style={{ margin: "-4px 0 10px" }}>
            Searches fill P1 first, then work down.
          </p>
          {(niche.patterns || []).map((p, i) => (
            <div className="dw-pat" key={p.id} data-off={p.on ? "0" : "1"}>
              <div className="dw-pat-top">
                <input
                  type="checkbox"
                  checked={p.on}
                  title={p.on ? "In use" : "Skipped"}
                  onChange={() => patchPattern(p.id, { on: !p.on })}
                />
                <span className="dw-pnum">P{i + 1}</span>
                <input
                  className="dw-in"
                  value={p.kw}
                  placeholder="appliancerepair"
                  onChange={(e) => patchPattern(p.id, { kw: cleanKw(e.target.value) })}
                />
              </div>
              <div className="dw-pat-bot">
                <code className="dw-prev">{`{name}${p.kw || "…"}.com`}</code>
                <button className="dw-mini" title="Move up" disabled={i === 0} onClick={() => movePattern(i, -1)}>
                  ↑
                </button>
                <button
                  className="dw-mini"
                  title="Move down"
                  disabled={i === niche.patterns.length - 1}
                  onClick={() => movePattern(i, 1)}
                >
                  ↓
                </button>
                <button className="dw-mini" title="Remove pattern" onClick={() => removePattern(p.id)}>
                  ×
                </button>
              </div>
            </div>
          ))}
          {askStrip("pat")}
          <div className="dw-row" style={{ margin: "4px 0 18px" }}>
            <button
              className="dw-btn ghost"
              style={{ flex: 1 }}
              onClick={() => patchNiche({ patterns: [...(niche.patterns || []), mkPattern("")] })}
            >
              + Add pattern
            </button>
            <button className="dw-btn" style={{ flex: 1 }} onClick={suggestPatterns} disabled={suggBusy}>
              {suggBusy ? "Thinking…" : "Suggest patterns"}
            </button>
          </div>

          <p className="dw-eyebrow">Criteria</p>
          <label className="dw-field">
            <span className="dw-label">Name type</span>
            <select
              className="dw-in"
              value={niche.nameType || "any"}
              onChange={(e) => patchNiche({ nameType: e.target.value })}
            >
              {Object.entries(NAME_TYPES).map(([k, v]) => (
                <option key={k} value={k}>
                  {v.label}
                </option>
              ))}
            </select>
          </label>
          <label className="dw-field">
            <span className="dw-label">Max characters before .com</span>
            <input
              className="dw-in"
              type="number"
              min={8}
              max={40}
              value={niche.maxLength}
              onChange={(e) => patchNiche({ maxLength: e.target.value })}
            />
          </label>
          <label className="dw-field">
            <span className="dw-label">Notes for this niche only</span>
            <textarea
              className="dw-ta"
              value={niche.notes}
              onChange={(e) => patchNiche({ notes: e.target.value })}
              placeholder="Anything true of this niche but not the others."
            />
          </label>

          <p className="dw-eyebrow" style={{ marginTop: 26 }}>
            Availability check
          </p>

          {/* Added in the port. The paste box below is kept, not replaced: it is the
              way through when no registrar is configured, when one is down, and when
              you already have results from somewhere else. */}
          {checkers && Object.keys(checkers).length > 0 && (
            <div style={{ marginBottom: 14 }}>
              <button
                className="dw-btn wide"
                onClick={checkAvailability}
                disabled={checking || !shown.length}
              >
                {checking
                  ? "Asking the registrar…"
                  : `Check ${shown.length} ${shown.length === 1 ? "domain" : "domains"} now`}
              </button>
              <div className="dw-row" style={{ marginTop: 8 }}>
                <span className="dw-hint" style={{ margin: 0 }}>
                  Ask
                </span>
                <select
                  className="dw-sel"
                  value={checker}
                  onChange={(e) => setChecker(e.target.value)}
                  style={{ flex: 1 }}
                >
                  {Object.entries(checkers).map(([k, c]) => (
                    <option key={k} value={k}>
                      {c.label} — {c.speed}
                    </option>
                  ))}
                </select>
              </div>
              <p className="dw-hint" style={{ margin: "6px 0 0" }}>
                Checks whatever this tab is showing — {filter === "all" ? "all of them" : `the ${STATUSES[filter].label.toLowerCase()} ones`}.
                {checker === "namecheap" && (
                  <>
                    {" "}
                    <strong>Namecheap returns no price</strong> for ordinary names, so your ${state.maxPrice}{" "}
                    ceiling can't apply — NameSilo prices them.
                  </>
                )}
              </p>
            </div>
          )}

          <p className="dw-hint" style={{ margin: "0 0 10px" }}>
            Or copy the list above the results, run it through a registrar's bulk search, and paste
            what comes back here.
          </p>
          <textarea
            className="dw-ta"
            value={paste}
            onChange={(e) => setPaste(e.target.value)}
            placeholder="carverappliance.com  Available&#10;bexleyappliance.com  Unavailable"
          />
          <div className="dw-row" style={{ margin: "10px 0" }}>
            <span className="dw-hint" style={{ margin: 0 }}>
              Reject over $
            </span>
            <input
              className="dw-in"
              type="number"
              min={0}
              step={1}
              style={{ width: 72 }}
              value={state.maxPrice}
              onChange={(e) => setState((s) => ({ ...s, maxPrice: e.target.value }))}
            />
            <span className="dw-hint" style={{ margin: 0 }}>
              0 = no limit
            </span>
          </div>
          <div className="dw-row" style={{ margin: "10px 0" }}>
            <span className="dw-hint" style={{ margin: 0 }}>
              If a status can't be read →
            </span>
            <select className="dw-sel" value={fallback} onChange={(e) => setFallback(e.target.value)}>
              <option value="skip">Leave unchecked</option>
              <option value="available">Available</option>
              <option value="taken">Not available</option>
            </select>
          </div>
          <button className="dw-btn wide" onClick={applyPaste}>
            Update from results
          </button>
          {undo && (
            <button className="dw-btn ghost wide" style={{ marginTop: 8 }} onClick={undoPaste}>
              Undo last update
            </button>
          )}

          <details className="dw-doc">
            <summary>Search brief</summary>
            <pre className="dw-pre">{buildBrief(niche, batch, state.rules)}</pre>
            <p className="dw-hint">
              Sent verbatim on every search, plus the {registryList.length} retired names.
            </p>
          </details>
        </aside>
      </div>

      {toast && <div className="dw-toast">{toast}</div>}
    </div>
  );
}

/* ---------------------------------------------------------------------------
   Mount. The artifact host rendered the default export for us; here the page
   says where it goes. Guarded on the node existing so loading this file
   anywhere else is inert rather than a console error.
   --------------------------------------------------------------------------- */
const dwRoot = document.getElementById("dw-root");
if (dwRoot) ReactDOM.createRoot(dwRoot).render(React.createElement(DomainWorkbench));
