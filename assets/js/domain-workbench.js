const { useState, useEffect, useRef, useMemo } = React;
const DW = window.DW_CONFIG || {};
const KEY = "domain-workbench-v1";
const STATUSES = {
  idea: { label: "Unchecked", tone: "neutral" },
  available: { label: "Available", tone: "good" },
  shortlist: { label: "Shortlist", tone: "warn" },
  purchased: { label: "Purchased", tone: "claim" },
  taken: { label: "Not available", tone: "dead" },
  pricey: { label: "Too pricey", tone: "dead" },
  rejected: { label: "Didn't like", tone: "dead" }
};
const STATUS_ORDER = ["idea", "available", "shortlist", "purchased", "taken", "pricey", "rejected"];
const NOT_SPENT = ["taken", "pricey"];
const NAME_TYPES = {
  any: { label: "Any name", prompt: "mix surnames, first names and familiar short forms" },
  surname: { label: "Surnames only", prompt: "family surnames only" },
  first: { label: "First names only", prompt: "first names only, including familiar short forms like dave or gus" }
};
const SEED = [
  { name: "Appliance repair", patterns: ["appliancerepair", "appliance", "appliances"] },
  { name: "Pest control", patterns: ["pestcontrol", "pest", "exterminating"] },
  { name: "Water restoration", patterns: ["waterrestoration", "restoration", "waterdamage"] },
  { name: "Mold remediation", patterns: ["moldremediation", "moldremoval", "mold"] }
];
const DEFAULT_RULES = [
  "Use .com only \u2014 never suggest another extension",
  "Easy to say out loud, and easy to spell correctly after hearing it once",
  "Shorter is better \u2014 fewer syllables and fewer characters win",
  "Sounds like a real local business people would trust and buy from",
  "No awkward letter collisions where the name meets the keyword",
  "No initials, numbers, hyphens, or invented words"
];
const uid = () => Math.random().toString(36).slice(2, 10);
const mkPattern = (kw) => ({ id: uid(), kw, on: true });
const mkRule = (text) => ({ id: uid(), text, on: true });
const cleanKw = (s) => String(s || "").toLowerCase().replace(/[^a-z]/g, "");
const DOMAIN_RE = /([a-z0-9][a-z0-9-]*\.com)/g;
const TAKEN_RE = /(unavailable|not available|is taken|\btaken\b|registered|make ?offer|backorder|transfer to|\bwhois\b|\bsold\b|already)/;
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
  return rules.filter((r) => r.on && r.text.trim()).map((r, i) => `${i + 1}. ${r.text.trim()}`).join("\n") || "(none set)";
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
    candidates: []
  }));
  return { niches, activeId: niches[0].id, registry: {}, rules: DEFAULT_RULES.map(mkRule), maxPrice: 15 };
}
const DOUBLED_RE = /^([a-z]*?)([a-z]{4,})\2\.com$/;
function normalize(s) {
  if (!s || !Array.isArray(s.niches) || !s.niches.length) return null;
  const renames = {};
  const niches = s.niches.map((n) => ({
    ...n,
    patterns: n.patterns && n.patterns.length ? n.patterns.map((p) => ({ id: p.id || uid(), kw: cleanKw(p.kw), on: p.on !== false })) : String(n.keywords || "").split(",").map((k) => cleanKw(k)).filter(Boolean).map(mkPattern),
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
    })
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
    rules: s.rules && s.rules.length ? s.rules.map((r) => ({ id: r.id || uid(), text: String(r.text || ""), on: r.on !== false })) : DEFAULT_RULES.map(mkRule),
    maxPrice: s.maxPrice || 15
  };
}
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
    let why = `Request failed (${res.status})`;
    try {
      const j = await res.json();
      if (j && j.error) why = j.error;
    } catch {
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

.dw-grid { display:grid; grid-template-columns:200px minmax(0,1fr) 320px; gap:0; align-items:start; }
.dw-col { padding:20px; }
.dw-rail { border-right:1px solid var(--rule); }
.dw-side { border-left:1px solid var(--rule); }

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
  .dw-rail, .dw-side { border:none; border-bottom:1px solid var(--rule); }
  .dw-side { border-bottom:none; border-top:1px solid var(--rule); }
}
@media (prefers-reduced-motion:reduce) { .dw * { animation:none !important; transition:none !important; } }
`;
function DomainWorkbench() {
  const [state, setState] = useState(null);
  const [busy, setBusy] = useState(false);
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
  const [showImport, setShowImport] = useState(false);
  const [importText, setImportText] = useState("");
  const [checking, setChecking] = useState(false);
  const [checker, setChecker] = useState("");
  const [checkers, setCheckers] = useState(null);
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
  useEffect(() => {
    fetch(DW.checkUrl, { credentials: "same-origin" }).then((r) => r.json()).then((d) => {
      setCheckers(d.checkers || {});
      setChecker(d.default || "");
    }).catch(() => setCheckers({}));
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
    const t = setTimeout(() => setToast(""), 4e3);
    return () => clearTimeout(t);
  }, [toast]);
  const niche = useMemo(
    () => state?.niches.find((n) => n.id === state.activeId) || state?.niches[0],
    [state]
  );
  if (!state) {
    return /* @__PURE__ */ React.createElement("div", { className: "dw" }, /* @__PURE__ */ React.createElement("style", null, CSS), /* @__PURE__ */ React.createElement("div", { style: { padding: 40, fontFamily: "monospace", fontSize: 13 } }, "Opening workbench\u2026"));
  }
  const patchNiche = (patch) => setState((s) => ({
    ...s,
    niches: s.niches.map((n) => n.id === s.activeId ? { ...n, ...patch } : n)
  }));
  const setCandidates = (fn) => setState((s) => ({
    ...s,
    niches: s.niches.map((n) => n.id === s.activeId ? { ...n, candidates: fn(n.candidates) } : n)
  }));
  const patchRule = (rid, patch) => setState((s) => ({ ...s, rules: s.rules.map((r) => r.id === rid ? { ...r, ...patch } : r) }));
  const removeRule = (rid) => {
    const r = state.rules.find((x) => x.id === rid);
    setAsk({
      key: "rule",
      title: `Delete rule "${(r?.text || "").trim().slice(0, 48) || "(empty)"}"? Untick it instead to keep it.`,
      actions: [
        {
          label: "Delete",
          danger: true,
          run: () => setState((s) => ({ ...s, rules: s.rules.filter((x) => x.id !== rid) }))
        }
      ]
    });
  };
  const moveRule = (i, dir) => setState((s) => {
    const next = [...s.rules];
    const j = i + dir;
    if (j < 0 || j >= next.length) return s;
    [next[i], next[j]] = [next[j], next[i]];
    return { ...s, rules: next };
  });
  const patchPattern = (pid, patch) => patchNiche({ patterns: niche.patterns.map((p) => p.id === pid ? { ...p, ...patch } : p) });
  const removePattern = (pid) => {
    const p = niche.patterns.find((x) => x.id === pid);
    setAsk({
      key: "pat",
      title: `Delete pattern {name}${p?.kw || ""}.com?`,
      actions: [
        {
          label: "Delete",
          danger: true,
          run: () => patchNiche({ patterns: niche.patterns.filter((x) => x.id !== pid) })
        }
      ]
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
              candidates: []
            };
            setState((s) => ({ ...s, niches: [...s.niches, n], activeId: n.id }));
            setToast(`${name} added`);
          }
        }
      ]
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
          run: () => setState((s) => {
            const niches = s.niches.filter((n) => n.id !== s.activeId);
            return { ...s, niches, activeId: niches[0].id };
          })
        }
      ]
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
        `You generate .com domain candidates for local home-service brands. Each candidate joins a personal name directly to one of the supplied keywords: lowercase letters only, no spaces. The "name" field must contain ONLY the personal name \u2014 never the keyword, never a full business name. Follow the user's numbered rules closely \u2014 they are ranked, so when two rules pull against each other the lower number wins. Never reuse a name from the blocked list, and never repeat a name within your own answer. Reply with ONLY a JSON array, no markdown fences and no commentary. Each element looks like {"name":"Carver","keyword":"appliancerepair","domain":"carverappliancerepair.com"}`,
        `${buildBrief(niche, batch, state.rules)}

Blocked names (already claimed, never reuse): ${block}`,
        1e3
      );
      const raw = data.content.filter((b) => b.type === "text").map((b) => b.text).join("\n").replace(/```json|```/g, "").trim();
      const arr = JSON.parse(raw.slice(raw.indexOf("["), raw.lastIndexOf("]") + 1));
      const fresh = [];
      const claimed = { ...state.registry };
      const blockedSet = new Set(blockedNames);
      const haveDomain = new Set(niche.candidates.map((c) => c.domain));
      for (const c of arr) {
        let person = cleanKw(c.name || c.surname);
        const kw = cleanKw(c.keyword);
        if (!person || !kw) continue;
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
          at: Date.now()
        });
      }
      if (!fresh.length)
        throw new Error("Nothing usable came back \u2014 names collided or fell outside your patterns. Try again.");
      setState((s) => ({
        ...s,
        registry: claimed,
        niches: s.niches.map(
          (n) => n.id === s.activeId ? { ...n, candidates: [...fresh, ...n.candidates] } : n
        )
      }));
      setToast(`${fresh.length} added \xB7 ${fresh.length} names retired`);
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
        1e3
      );
      const raw = data.content.filter((b) => b.type === "text").map((b) => b.text).join("\n").replace(/```json|```/g, "").trim();
      const arr = JSON.parse(raw.slice(raw.indexOf("["), raw.lastIndexOf("]") + 1));
      const have = new Set((niche.patterns || []).map((p) => p.kw));
      const clean = [];
      for (const s of arr) {
        const kw = cleanKw(s.keyword);
        if (!kw || have.has(kw) || clean.some((c) => c.kw === kw)) continue;
        clean.push({ kw, why: String(s.why || "").slice(0, 90), pick: true });
      }
      if (!clean.length) throw new Error("Nothing new came back \u2014 your list already covers it.");
      setSuggs(clean);
      setToast(`${clean.length} patterns suggested \u2014 review them above the table`);
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
    patchNiche({ patterns: mode === "replace" ? picked : [...niche.patterns || [], ...picked] });
    setSuggs(null);
    setToast(mode === "replace" ? `Replaced with ${picked.length} patterns` : `${picked.length} patterns added`);
  };
  const unchecked = niche.candidates.filter((c) => c.status === "idea");
  const uncheckedText = unchecked.map((c) => c.domain).join("\n");
  const copyUnchecked = async () => {
    if (!unchecked.length) return setToast("Nothing unchecked to copy");
    const ok = await copyText(uncheckedText);
    if (ok) setToast(`${unchecked.length} domains copied \u2014 paste into Namecheap bulk search`);
    else {
      setShowList(true);
      setToast("Clipboard blocked \u2014 select the list below and copy");
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
          notes[h[1]] = `Over $${cap} \u2014 priced $${price.toLocaleString()}`;
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
    setCandidates(
      (cs) => cs.map(
        (c) => verdicts[c.domain] ? { ...c, prev: c.status, status: verdicts[c.domain], note: notes[c.domain] || c.note } : c
      )
    );
    setPaste("");
    const nTaken = changed.filter((d) => verdicts[d] === "taken").length;
    const nFree = changed.filter((d) => verdicts[d] === "available").length;
    setToast(
      `${nFree} available \xB7 ${nTaken} not available${overPriced ? ` \xB7 ${overPriced} over $${cap}` : ""}${unclear ? ` \xB7 ${unclear} unreadable` : ""}`
    );
  };
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
          unclear++;
          v = fallback === "skip" ? null : fallback;
        }
        if (!v) return;
        const price = r.price !== "" && r.price != null ? parseFloat(String(r.price).replace(/,/g, "")) : null;
        if (v === "available" && price !== null && !isNaN(price)) {
          if (cap && price > cap) {
            v = "pricey";
            notes[domain] = `Over $${cap} \u2014 priced $${price.toLocaleString()}`;
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
      setCandidates(
        (cs) => cs.map(
          (c) => verdicts[c.domain] ? { ...c, prev: c.status, status: verdicts[c.domain], note: notes[c.domain] || c.note } : c
        )
      );
      const nTaken = changed.filter((d) => verdicts[d] === "taken").length;
      const nFree = changed.filter((d) => verdicts[d] === "available").length;
      setToast(
        `${data.label}: ${nFree} available \xB7 ${nTaken} not available` + (overPriced ? ` \xB7 ${overPriced} over $${cap}` : "") + (unclear ? ` \xB7 ${unclear} no answer` : "") + (data.skipped ? ` \xB7 ${data.skipped} skipped (over the ${data.checked} cap)` : "")
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
    const inUse = /* @__PURE__ */ new Set();
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
          }
        },
        {
          label: "Clear all",
          danger: true,
          run: () => {
            setState((s) => ({ ...s, registry: {} }));
            setToast("Registry emptied \u2014 every name is available again");
          }
        }
      ]
    });
  };
  const buildCsv = (onlyShortlist) => {
    const esc = (v) => `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
    const rows = [["Niche", "Priority", "Domain", "Name", "Status", "Note"]];
    state.niches.forEach(
      (n) => n.candidates.filter((c) => onlyShortlist ? c.status === "shortlist" : true).slice().sort((a, b) => (a.tier || 1) - (b.tier || 1) || a.domain.localeCompare(b.domain)).forEach(
        (c) => rows.push([n.name, `P${c.tier || 1}`, c.domain, c.person || "", STATUSES[c.status].label, c.note || ""])
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
  const askStrip = (key) => {
    if (!ask || ask.key !== key) return null;
    return /* @__PURE__ */ React.createElement("div", { className: "dw-ask" }, /* @__PURE__ */ React.createElement("span", { className: "dw-ask-t" }, ask.title), ask.input !== void 0 && /* @__PURE__ */ React.createElement(
      "input",
      {
        className: "dw-in",
        autoFocus: true,
        value: askText,
        placeholder: ask.input,
        onChange: (e) => setAskText(e.target.value),
        onKeyDown: (e) => {
          if (e.key === "Enter") {
            ask.actions[0].run(askText);
            setAsk(null);
          }
        },
        style: { flex: 1, minWidth: 140 }
      }
    ), ask.actions.map((a) => /* @__PURE__ */ React.createElement(
      "button",
      {
        key: a.label,
        className: a.danger ? "dw-btn danger tiny" : "dw-btn tiny",
        onClick: () => {
          a.run(askText);
          setAsk(null);
        }
      },
      a.label
    )), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => setAsk(null) }, "Cancel"));
  };
  const toggleSel = (id) => setSel((s) => s.includes(id) ? s.filter((x) => x !== id) : [...s, id]);
  const moveSelected = (status) => {
    setUndo(niche.candidates);
    setCandidates(
      (cs) => cs.map((c) => sel.includes(c.id) ? { ...c, prev: c.status, status } : c)
    );
    setToast(`${sel.length} moved to ${STATUSES[status].label.toLowerCase()}`);
    setSel([]);
  };
  const restoreSelected = () => {
    setUndo(niche.candidates);
    setCandidates(
      (cs) => cs.map((c) => sel.includes(c.id) ? { ...c, status: c.prev || "idea", prev: c.status } : c)
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
    setToast(ok ? "Backup copied to clipboard" : "Clipboard blocked \u2014 try again from a wider window");
  };
  const importBackup = () => {
    let parsed;
    try {
      parsed = JSON.parse(importText);
    } catch {
      return setToast("That is not valid JSON \u2014 paste the whole backup, braces included");
    }
    const clean = normalize(parsed);
    if (!clean) return setToast("No niches in that backup \u2014 nothing to restore");
    const nCand = clean.niches.reduce((t, n) => t + (n.candidates || []).length, 0);
    const nHere = state.niches.reduce((t, n) => t + (n.candidates || []).length, 0);
    setAsk({
      key: "import",
      title: `Replace everything with this backup? ${clean.niches.length} niches, ${nCand} candidates, ${Object.keys(clean.registry).length} retired names \u2014 over the ${state.niches.length} niches and ${nHere} candidates here now. This cannot be undone.`,
      actions: [
        {
          label: "Replace",
          danger: true,
          run: () => {
            setState(clean);
            setImportText("");
            setShowImport(false);
            setToast(`Restored ${clean.niches.length} niches \xB7 ${nCand} candidates`);
          }
        }
      ]
    });
  };
  const shown = (filter === "all" ? niche.candidates : niche.candidates.filter((c) => c.status === filter)).slice().sort((a, b) => (a.tier || 1) - (b.tier || 1) || b.at - a.at);
  const tallyMap = Object.fromEntries(
    STATUS_ORDER.map((k) => [k, niche.candidates.filter((c) => c.status === k).length])
  );
  const nameUses = {};
  state.niches.forEach(
    (n) => n.candidates.forEach((c) => {
      if (!c.person) return;
      (nameUses[c.person] = nameUses[c.person] || []).push({
        status: c.status,
        domain: c.domain,
        niche: n.name,
        note: c.note
      });
    })
  );
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
  return /* @__PURE__ */ React.createElement("div", { className: "dw" }, /* @__PURE__ */ React.createElement("style", null, CSS), /* @__PURE__ */ React.createElement("header", { className: "dw-bar" }, /* @__PURE__ */ React.createElement("span", { className: "dw-logo" }, "Name Registry"), /* @__PURE__ */ React.createElement("span", { className: "dw-sub" }, blockedNames.length, " names spent \xB7 ", registryList.length - blockedNames.length, " reusable \xB7", " ", state.niches.length, " niches \xB7 .com only"), /* @__PURE__ */ React.createElement("span", { className: "dw-bar-end" }, /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => setShowHelp((v) => !v) }, showHelp ? "Close" : "How this works"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => setShowRules((v) => !v) }, showRules ? "Hide rules" : "Rules"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => setShowReg((v) => !v) }, showReg ? "Hide registry" : "View registry"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => setShowRegs((v) => !v) }, showRegs ? "Close" : "Reg $/APIs"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => openExport(true) }, "Export shortlist"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => openExport(false) }, "Export all"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: exportBackup }, "Copy backup"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: () => setShowImport((v) => !v) }, showImport ? "Close" : "Restore backup"))), showImport && /* @__PURE__ */ React.createElement("section", { className: "dw-col", style: { borderBottom: "1px solid var(--rule)" } }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow" }, "Restore from a backup"), /* @__PURE__ */ React.createElement("p", { style: { margin: "0 0 10px", maxWidth: 640, color: "var(--soft)" } }, "Paste a backup JSON \u2014 from ", /* @__PURE__ */ React.createElement("strong", null, "Copy backup"), " here, or from the artifact this workbench came from. It replaces everything: niches, candidates, the retired-name registry and your rules. You'll be asked to confirm first."), /* @__PURE__ */ React.createElement(
    "textarea",
    {
      className: "dw-ta",
      value: importText,
      onChange: (e) => setImportText(e.target.value),
      placeholder: '{\n  "niches": [ \u2026 ],\n  "registry": { \u2026 }\n}',
      rows: 8,
      style: { maxWidth: 640 }
    }
  ), /* @__PURE__ */ React.createElement("div", { style: { marginTop: 10, display: "flex", gap: 8 } }, /* @__PURE__ */ React.createElement("button", { className: "dw-btn", onClick: importBackup, disabled: !importText.trim() }, "Restore"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost", onClick: () => {
    setImportText("");
    setShowImport(false);
  } }, "Cancel"))), showHelp && /* @__PURE__ */ React.createElement("section", { className: "dw-col dw-help" }, /* @__PURE__ */ React.createElement("h2", { className: "dw-help-title" }, "How this works"), /* @__PURE__ */ React.createElement("p", { className: "dw-help-lead" }, "You're building brands shaped like a person's name joined to a service \u2014 Carver Appliance Repair, Gus Pest Control. This tool generates those candidates, keeps track of which ones are free, and remembers every name you've already spent so you never go round in circles."), /* @__PURE__ */ React.createElement("div", { className: "dw-help-grid" }, /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "The loop"), /* @__PURE__ */ React.createElement("ol", { className: "dw-help-list" }, /* @__PURE__ */ React.createElement("li", null, "Pick a niche on the left. Each one keeps its own patterns and its own list of domains."), /* @__PURE__ */ React.createElement("li", null, "Press ", /* @__PURE__ */ React.createElement("b", null, "Find domains"), ". Candidates appear in the table as Unchecked. Nothing has been looked up yet \u2014 these are just ideas."), /* @__PURE__ */ React.createElement("li", null, "Press ", /* @__PURE__ */ React.createElement("b", null, "Copy list"), ", paste into Namecheap's bulk domain search, and run it."), /* @__PURE__ */ React.createElement("li", null, "Copy Namecheap's results, paste them into the box on the right, and press", " ", /* @__PURE__ */ React.createElement("b", null, "Update from results"), ". Rows sort themselves into Available and Not available."), /* @__PURE__ */ React.createElement("li", null, "Work the Available tab: keep the good ones, park the rest. Buy from your shortlist, then mark those rows Purchased by hand."))), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "The one rule that shapes everything"), /* @__PURE__ */ React.createElement("p", null, `A name can only be spent once. If it's in play anywhere \u2014 unchecked, available, shortlisted, purchased \u2014 or you marked it "didn't like", it won't be suggested again in any niche.`), /* @__PURE__ */ React.createElement("p", null, "The exception: if every domain built on a name came back ", /* @__PURE__ */ React.createElement("b", null, "not available"), " or", " ", /* @__PURE__ */ React.createElement("b", null, "too pricey"), ", you never actually got it, so the name returns to the pool for other niches. Those show as ", /* @__PURE__ */ React.createElement("b", null, "Free to reuse"), " in the registry."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "View registry"), " lists every name with the reason it's held. The \xD7 removes it from the list entirely, freeing it regardless of status.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Patterns and priority"), /* @__PURE__ */ React.createElement("p", null, "A pattern is the word that follows the name: ", /* @__PURE__ */ React.createElement("code", null, "{name}appliancerepair.com"), ". They're ranked P1, P2, P3, and searches fill P1 as far as good names allow before dropping to P2. Reorder with the arrows, untick to mute one without deleting it."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Suggest patterns"), " proposes eight, ranked by how a homeowner is likely to talk about the service and who they'd trust to call. Nothing is applied until you choose Add or Replace.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Rules"), /* @__PURE__ */ React.createElement("p", null, "The ", /* @__PURE__ */ React.createElement("b", null, "Rules"), " panel is your judgement, written down \u2014 short, easy to say, sounds trustworthy. They apply to every niche and are ranked: when two rules pull against each other, the higher one wins. Untick to mute a rule, delete to lose it."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Search brief"), " at the bottom of the right panel shows the exact instructions being sent, built from your rules and patterns. Open it if a search isn't returning what you expected.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "The columns"), /* @__PURE__ */ React.createElement("dl", { className: "dw-help-dl" }, /* @__PURE__ */ React.createElement("dt", null, "Checkbox"), /* @__PURE__ */ React.createElement("dd", null, "Selects rows for a bulk move. The one in the header selects everything in view."), /* @__PURE__ */ React.createElement("dt", null, "Pri"), /* @__PURE__ */ React.createElement("dd", null, "Which pattern the domain came from. P1 is your best pattern."), /* @__PURE__ */ React.createElement("dt", null, "Domain"), /* @__PURE__ */ React.createElement("dd", null, "The candidate itself. Struck through means dead; a red ", /* @__PURE__ */ React.createElement("b", null, "Claimed"), " stamp means you own it."), /* @__PURE__ */ React.createElement("dt", null, "Status"), /* @__PURE__ */ React.createElement("dd", null, "Change one row at a time here. For many rows, use the checkboxes instead."), /* @__PURE__ */ React.createElement("dt", null, "Note"), /* @__PURE__ */ React.createElement("dd", null, "Free text \u2014 price paid, why you passed, anything. It survives every move."), /* @__PURE__ */ React.createElement("dt", null, "\xD7"), /* @__PURE__ */ React.createElement("dd", null, "Removes the row, then asks whether the name should go back into circulation. Reserve it for mistakes; parking a domain in a tab is almost always better."))), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "The tabs"), /* @__PURE__ */ React.createElement("dl", { className: "dw-help-dl" }, /* @__PURE__ */ React.createElement("dt", null, "Unchecked"), /* @__PURE__ */ React.createElement("dd", null, "Generated but never looked up. This is what Copy list gives you."), /* @__PURE__ */ React.createElement("dt", null, "Available"), /* @__PURE__ */ React.createElement("dd", null, "Namecheap says it's free. Your decisions get made here."), /* @__PURE__ */ React.createElement("dt", null, "Shortlist"), /* @__PURE__ */ React.createElement("dd", null, "Worth buying. Your queue when you next open Namecheap with a card."), /* @__PURE__ */ React.createElement("dt", null, "Purchased"), /* @__PURE__ */ React.createElement("dd", null, "Owned. Set this yourself once the money is actually spent."), /* @__PURE__ */ React.createElement("dt", null, "Not available"), /* @__PURE__ */ React.createElement("dd", null, "Taken by someone else."), /* @__PURE__ */ React.createElement("dt", null, "Too pricey"), /* @__PURE__ */ React.createElement("dd", null, "Free, but above your price ceiling. Like Not available, this doesn't spend the name \u2014 it stays open for your other niches, where the same name may cost normal money."), /* @__PURE__ */ React.createElement("dt", null, "Didn't like"), /* @__PURE__ */ React.createElement("dd", null, "Your parking lot. Rejected but kept, so you remember passing on it. Select rows here and press ", /* @__PURE__ */ React.createElement("b", null, "\u21A9 Put back"), " to return them to wherever they came from."))), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "If something goes wrong"), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Undo last update"), " appears under the paste box after any bulk move or pasted result, and reverses it in one click."), /* @__PURE__ */ React.createElement("p", null, "When pasting results, anything the parser can't read a status for is left Unchecked rather than guessed at, and the message at the bottom of the screen tells you the counts. If those don't match what Namecheap showed you, undo and check the text you pasted.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Saving"), /* @__PURE__ */ React.createElement("p", null, "Everything saves automatically about half a second after you stop typing, tied to your Claude account. Close the tab and come back whenever \u2014 reopen this conversation and it's as you left it."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Copy backup"), " puts all your data on the clipboard as text. Paste it somewhere safe now and then. It holds your work, not the app itself, and deleting this conversation deletes the workbench with it.")))), csv && /* @__PURE__ */ React.createElement("section", { className: "dw-col", ref: csvRef, style: { borderBottom: "1px solid var(--rule)" } }, /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { justifyContent: "space-between", marginBottom: 8 } }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow", style: { margin: 0 } }, csv.lines, " rows \u2014 ", csv.scope), /* @__PURE__ */ React.createElement("div", { className: "dw-row" }, /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-btn",
      onClick: async () => {
        const ok = await copyText(csv.text);
        setToast(ok ? "Copied \u2014 paste into a blank sheet" : "Select the text below and copy");
      }
    },
    "Copy for spreadsheet"
  ), /* @__PURE__ */ React.createElement(
    "a",
    {
      className: "dw-btn ghost",
      href: `data:text/csv;charset=utf-8,${encodeURIComponent(csv.text)}`,
      download: `domains-${csv.scope}.csv`,
      target: "_blank",
      rel: "noreferrer"
    },
    "Try .csv download"
  ), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost", onClick: () => setCsv(null) }, "Close"))), /* @__PURE__ */ React.createElement(
    "textarea",
    {
      className: "dw-ta",
      readOnly: true,
      rows: 8,
      value: csv.text,
      onFocus: (e) => e.target.select()
    }
  ), /* @__PURE__ */ React.createElement("p", { className: "dw-hint" }, "Downloads are often blocked inside this panel. The reliable route: press", " ", /* @__PURE__ */ React.createElement("b", null, "Copy for spreadsheet"), ", open a blank Excel or Google Sheets file, click cell A1 and paste \u2014 it splits into columns automatically.")), showRegs && /* @__PURE__ */ React.createElement("section", { className: "dw-col dw-help" }, /* @__PURE__ */ React.createElement("h2", { className: "dw-help-title" }, "Registrars \u2014 funding & API keys"), /* @__PURE__ */ React.createElement("p", { className: "dw-help-lead" }, "Menu paths change; if one doesn't match, search the registrar's help centre for the wording in bold. Checked August 2026."), /* @__PURE__ */ React.createElement("div", { className: "dw-help-grid" }, /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Namecheap"), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Adding money."), " Click ", /* @__PURE__ */ React.createElement("b", null, "Top-up"), " on the Dashboard, or go", " ", /* @__PURE__ */ React.createElement("b", null, "Profile \u2192 Billing \u2192 Balance \u2192 Top-up"), ". Enter an amount, add card details, confirm with ", /* @__PURE__ */ React.createElement("b", null, "Charge and Proceed"), ". Minimum $5, maximum $100,000 by card or PayPal; $1 minimum by Bitcoin, which can take up to 24 hours to land. Balance applies automatically at checkout. Funds can't be transferred between accounts."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "API key."), " ", /* @__PURE__ */ React.createElement("b", null, "Profile \u2192 Tools"), ", scroll to ", /* @__PURE__ */ React.createElement("b", null, "Business & Dev Tools"), ", click ", /* @__PURE__ */ React.createElement("b", null, "Manage"), " beside ", /* @__PURE__ */ React.createElement("b", null, "Namecheap API Access"), ", toggle on, accept terms, re-enter your password. The key appears immediately; your account username doubles as the API username."), /* @__PURE__ */ React.createElement("p", null, 'Two catches. Access needs 20+ domains, a $50 balance, or $50 spent in the last two years. And you must whitelist at least one IPv4 address in the same panel or every call fails with "Invalid API Key" even when the key is correct. Free sandbox at sandbox.namecheap.com with separate credentials.')), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Porkbun"), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Adding money."), " ", /* @__PURE__ */ React.createElement("b", null, "Account \u2192 Settings / Billing"), ", find", " ", /* @__PURE__ */ React.createElement("b", null, "Billing Information"), ", then under ", /* @__PURE__ */ React.createElement("b", null, "Pre-fund Your Account"), " click", " ", /* @__PURE__ */ React.createElement("b", null, "buy account credit"), ". The cart defaults to $100; adjust it (minimum $10) and press apply. Credit applies automatically at checkout and to auto-renewals. Balance also visible at porkbun.com/account/credit."), /* @__PURE__ */ React.createElement("p", null, "ACH bank transfer earns extra discounts but takes up to 7 days to clear, so pre-fund ahead of renewals. Going ACH-only disables cards and PayPal on that account \u2014 the usual workaround is a subaccount designated ACH-only."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "API key."), " ", /* @__PURE__ */ React.createElement("b", null, "Account \u2192 API Access"), " (porkbun.com/account/api). Name the key, click ", /* @__PURE__ */ React.createElement("b", null, "Create API Key"), ", copy both the key and the secret \u2014 the secret is shown once and can't be retrieved later."), /* @__PURE__ */ React.createElement("p", null, "Easy to miss: a key alone isn't enough. Each domain needs ", /* @__PURE__ */ React.createElement("b", null, "API Access"), " switched on individually under ", /* @__PURE__ */ React.createElement("b", null, "Domain Management \u2192 Details"), ". One key pair covers every domain you've enabled.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Cloudflare"), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Adding money."), " You can't. There's no account balance to prepay into \u2014 a valid payment method on file is required instead, set at", " ", /* @__PURE__ */ React.createElement("b", null, "Manage Account \u2192 Billing \u2192 Payment"), ". Registrar orders are processed as new orders charged to that method and won't draw on any credits sitting in the account."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "API token."), " ", /* @__PURE__ */ React.createElement("b", null, "My Profile \u2192 API Tokens"), "(dash.cloudflare.com/profile/api-tokens). Use ", /* @__PURE__ */ React.createElement("b", null, "Create Token"), " and scope it to what you need; the legacy Global API Key is all-or-nothing and best avoided. Shown once at creation."), /* @__PURE__ */ React.createElement("p", null, "Worth knowing: Cloudflare sells domains at wholesale cost with no markup, so renewals roughly match registration. But it only accepts ", /* @__PURE__ */ React.createElement("b", null, "transfers"), " \u2014 you can't register a new domain there. Buy elsewhere, move it after the 60-day ICANN lock.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Dynadot"), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Adding money."), " Fund the account balance from your account panel \u2014 the API exposes it as ", /* @__PURE__ */ React.createElement("code", null, "get_account_balance"), ", and registrations fail with", " ", /* @__PURE__ */ React.createElement("b", null, "insufficient_funds"), " if the balance won't cover the order. Prepaying is effectively required for API buying rather than optional."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "API key."), " Left menu ", /* @__PURE__ */ React.createElement("b", null, "Tools \u2192 API"), ". Unlock the account using the link on that page, and the keys appear: a ", /* @__PURE__ */ React.createElement("b", null, "Production Key"), " and ", /* @__PURE__ */ React.createElement("b", null, "Sandbox Key"), " for the legacy and RESTful APIs, plus ", /* @__PURE__ */ React.createElement("b", null, "Secret Keys"), " used to generate the x-signature for the RESTful API."), /* @__PURE__ */ React.createElement("p", null, "Set authorised IPs in the same panel (single addresses, or CIDR ranges for servers). Allow about 10 minutes for changes to reach the API servers before testing. API access is open to all accounts, but your spending level determines how many concurrent threads you get.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "NameSilo"), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "Adding money."), " Account page \u2192 ", /* @__PURE__ */ React.createElement("b", null, "Account Options"), " \u2192 ", /* @__PURE__ */ React.createElement("b", null, "Account Funds Manager"), " \u2192 ", /* @__PURE__ */ React.createElement("b", null, "Add Funds"), ". No fees on verified credit/debit card, verified PayPal, Bitcoin, or verified Skrill. A prepaid balance also unlocks better per-domain pricing."), /* @__PURE__ */ React.createElement("p", null, "Useful for a portfolio: set a minimum threshold and a replenishment amount, and the balance tops itself up whenever it drops below the threshold \u2014 renewals can't fail for lack of funds."), /* @__PURE__ */ React.createElement("p", null, /* @__PURE__ */ React.createElement("b", null, "API key."), " ", /* @__PURE__ */ React.createElement("b", null, "API Manager"), " from the account menu (namesilo.com/account/api-manager) \u2014 generate the key there and store it somewhere safe."), /* @__PURE__ */ React.createElement("p", null, "You can restrict access to up to 5 originating IP addresses; leaving the fields blank allows connections from anywhere, which isn't what you want for a key that can spend money. NameSilo's API can also add account funds programmatically via", " ", /* @__PURE__ */ React.createElement("code", null, "addAccountFunds"), " against a verified card.")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("h4", { className: "dw-help-h" }, "Which to use for what"), /* @__PURE__ */ React.createElement("p", null, "For buying: ", /* @__PURE__ */ React.createElement("b", null, "Namecheap"), ", ", /* @__PURE__ */ React.createElement("b", null, "Porkbun"), ", ", /* @__PURE__ */ React.createElement("b", null, "Dynadot"), ", and ", /* @__PURE__ */ React.createElement("b", null, "NameSilo"), " all hold a prepaid balance. That caps what a session can spend and stops a renewal failing on an expired card. NameSilo's auto-replenish and Porkbun's ACH discount are the standouts for a portfolio."), /* @__PURE__ */ React.createElement("p", null, "Hold long term at ", /* @__PURE__ */ React.createElement("b", null, "Cloudflare"), " if the portfolio grows \u2014 wholesale renewal pricing compounds across dozens of domains held for years. Transfer them in after the 60-day lock; it can't register new ones."), /* @__PURE__ */ React.createElement("p", null, "Every registrar here except Cloudflare restricts API access by IP address. Set that before your first call \u2014 a wrong or missing IP usually surfaces as an authentication error rather than anything mentioning IPs, which sends people hunting the wrong problem."), /* @__PURE__ */ React.createElement("p", null, "An API key would let a future version of this tool check availability directly instead of the copy-paste round trip. Namecheap's", " ", /* @__PURE__ */ React.createElement("code", null, "namecheap.domains.check"), " takes up to 50 domains per call. That needs a server to hold the key \u2014 never put one in browser code.")))), showRules && /* @__PURE__ */ React.createElement("section", { className: "dw-col", style: { borderBottom: "1px solid var(--rule)" } }, /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { justifyContent: "space-between", marginBottom: 4 } }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow", style: { margin: 0 } }, "Search rules \u2014 most important first, applied to every niche"), /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-btn ghost tiny",
      onClick: () => setAsk({
        key: "restore-rules",
        title: `Throw away all ${state.rules.length} of your rules and put back the ${DEFAULT_RULES.length} originals? No undo.`,
        actions: [
          {
            label: "Restore defaults",
            danger: true,
            run: () => setState((s) => ({ ...s, rules: DEFAULT_RULES.map(mkRule) }))
          }
        ]
      })
    },
    "Restore defaults"
  )), /* @__PURE__ */ React.createElement("p", { className: "dw-hint", style: { margin: "0 0 12px" } }, "Rank matters: when two rules conflict, the higher one wins. Uncheck to mute a rule without losing it."), askStrip("restore-rules"), askStrip("rule"), /* @__PURE__ */ React.createElement("div", { className: "dw-rules" }, state.rules.map((r, i) => /* @__PURE__ */ React.createElement("div", { className: "dw-rule", key: r.id, "data-off": r.on ? "0" : "1" }, /* @__PURE__ */ React.createElement("input", { type: "checkbox", checked: r.on, onChange: () => patchRule(r.id, { on: !r.on }) }), /* @__PURE__ */ React.createElement("span", { className: "dw-rnum" }, String(i + 1).padStart(2, "0")), /* @__PURE__ */ React.createElement(
    "textarea",
    {
      className: "dw-rtext",
      rows: 2,
      value: r.text,
      placeholder: "Describe what makes a name good or bad",
      onChange: (e) => patchRule(r.id, { text: e.target.value })
    }
  ), /* @__PURE__ */ React.createElement("button", { className: "dw-mini", title: "Move up", disabled: i === 0, onClick: () => moveRule(i, -1) }, "\u2191"), /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-mini",
      title: "Move down",
      disabled: i === state.rules.length - 1,
      onClick: () => moveRule(i, 1)
    },
    "\u2193"
  ), /* @__PURE__ */ React.createElement("button", { className: "dw-mini", title: "Delete rule", onClick: () => removeRule(r.id) }, "\xD7")))), /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-btn ghost",
      style: { marginTop: 12 },
      onClick: () => setState((s) => ({ ...s, rules: [...s.rules, mkRule("")] }))
    },
    "+ Add rule"
  ), /* @__PURE__ */ React.createElement("p", { className: "dw-hint" }, "The .com ending, your length limit, and the retired-name check are enforced in code too, so they hold even if a rule here is muted.")), showReg && /* @__PURE__ */ React.createElement("div", { className: "dw-col", style: { borderBottom: "1px solid var(--rule)" } }, /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { justifyContent: "space-between", marginBottom: 4 } }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow", style: { margin: 0 } }, "Names used \u2014 only the spent ones are blocked from future searches"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: clearRegistry }, "Clear registry")), askStrip("clear-reg"), registryList.length ? /* @__PURE__ */ React.createElement("div", { className: "dw-reg" }, registryList.map(([sn, meta]) => {
    const use = bestUse(sn);
    const free = !isBlocked(sn);
    const reason = use ? STATUSES[use.status].label : meta.outcome || "Row removed";
    const tone = use ? STATUSES[use.status].tone : "dead";
    const count = (nameUses[sn] || []).length;
    return /* @__PURE__ */ React.createElement("div", { className: "dw-reg-row", key: sn, "data-free": free ? "1" : "0" }, /* @__PURE__ */ React.createElement("span", { className: "dw-reg-name", "data-free": free ? "1" : "0" }, sn), /* @__PURE__ */ React.createElement("span", { className: "dw-tag", "data-tone": free ? "good" : tone }, free ? "Free to reuse" : reason), /* @__PURE__ */ React.createElement("code", { className: "dw-reg-dom" }, use ? use.domain : meta.domain, count > 1 ? ` +${count - 1}` : ""), /* @__PURE__ */ React.createElement("span", { className: "dw-reg-niche" }, use ? use.niche : meta.niche), /* @__PURE__ */ React.createElement("span", { className: "dw-reg-note" }, use?.note || ""), /* @__PURE__ */ React.createElement("button", { className: "dw-mini", onClick: () => releaseName(sn), title: "Remove from this list" }, "\xD7"));
  })) : /* @__PURE__ */ React.createElement("p", { className: "dw-hint" }, "Empty. Every name is still in circulation.")), /* @__PURE__ */ React.createElement("div", { className: "dw-grid" }, /* @__PURE__ */ React.createElement("nav", { className: "dw-col dw-rail" }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow" }, "Niches"), state.niches.map((n) => /* @__PURE__ */ React.createElement(
    "button",
    {
      key: n.id,
      className: "dw-niche",
      "data-on": n.id === niche.id ? "1" : "0",
      onClick: () => {
        setUndo(null);
        setSuggs(null);
        setState((s) => ({ ...s, activeId: n.id }));
      }
    },
    n.name,
    /* @__PURE__ */ React.createElement("span", { className: "dw-count" }, n.candidates.length)
  )), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost wide", style: { marginTop: 12 }, onClick: addNiche }, "+ Add niche"), askStrip("add-niche")), /* @__PURE__ */ React.createElement("main", { className: "dw-col" }, /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { justifyContent: "space-between", marginBottom: 4 } }, /* @__PURE__ */ React.createElement("h1", { className: "dw-h" }, niche.name), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: deleteNiche }, "Delete niche")), askStrip("del-niche"), err && /* @__PURE__ */ React.createElement("div", { className: "dw-err", style: { marginTop: 12 } }, err), /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { margin: "12px 0 16px" } }, /* @__PURE__ */ React.createElement("button", { className: "dw-btn", onClick: generate, disabled: busy }, busy ? "Searching\u2026" : "Find domains"), /* @__PURE__ */ React.createElement("select", { className: "dw-sel", value: batch, onChange: (e) => setBatch(Number(e.target.value)) }, /* @__PURE__ */ React.createElement("option", { value: 8 }, "8 at a time"), /* @__PURE__ */ React.createElement("option", { value: 12 }, "12 at a time"), /* @__PURE__ */ React.createElement("option", { value: 20 }, "20 at a time"))), /* @__PURE__ */ React.createElement("div", { className: "dw-tabs" }, [["all", "All"], ...STATUS_ORDER.map((k) => [k, STATUSES[k].label])].map(([k, label]) => {
    const n = k === "all" ? niche.candidates.length : tallyMap[k];
    return /* @__PURE__ */ React.createElement(
      "button",
      {
        key: k,
        className: "dw-tab",
        "data-on": filter === k ? "1" : "0",
        "data-empty": n ? "0" : "1",
        onClick: () => {
          setFilter(k);
          setSel([]);
        }
      },
      label,
      " ",
      /* @__PURE__ */ React.createElement("span", { className: "dw-tab-n" }, n)
    );
  })), suggs && /* @__PURE__ */ React.createElement("div", { className: "dw-sugg-box", ref: suggRef }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow", style: { margin: "0 0 2px" } }, "Suggested patterns \u2014 ranked by how customers ask for it"), /* @__PURE__ */ React.createElement("p", { className: "dw-hint", style: { margin: "0 0 10px" } }, "Untick anything you don't want. Order is the recommendation."), suggs.map((s, i) => /* @__PURE__ */ React.createElement("label", { className: "dw-sugg", key: s.kw, "data-off": s.pick ? "0" : "1" }, /* @__PURE__ */ React.createElement(
    "input",
    {
      type: "checkbox",
      checked: s.pick,
      onChange: () => setSuggs((list) => list.map((x, j) => j === i ? { ...x, pick: !x.pick } : x))
    }
  ), /* @__PURE__ */ React.createElement("span", { className: "dw-rnum" }, String(i + 1).padStart(2, "0")), /* @__PURE__ */ React.createElement("code", { className: "dw-sugg-dom" }, `{name}${s.kw}.com`), /* @__PURE__ */ React.createElement("span", { className: "dw-sugg-why" }, s.why))), /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { marginTop: 12 } }, /* @__PURE__ */ React.createElement("button", { className: "dw-btn", onClick: () => takeSuggs("append") }, "Add to list"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost", onClick: () => takeSuggs("replace") }, "Replace my list"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost", onClick: () => setSuggs(null) }, "Dismiss"))), unchecked.length > 0 && /* @__PURE__ */ React.createElement("div", { className: "dw-ready" }, /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { width: "100%" } }, /* @__PURE__ */ React.createElement("span", { className: "dw-ready-n" }, unchecked.length), /* @__PURE__ */ React.createElement("span", null, "unchecked ", unchecked.length === 1 ? "domain" : "domains", " ready for Namecheap"), /* @__PURE__ */ React.createElement("span", { style: { marginLeft: "auto" }, className: "dw-row" }, /* @__PURE__ */ React.createElement("button", { className: "dw-btn", onClick: copyUnchecked }, "Copy list"), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost", onClick: () => setShowList((v) => !v) }, showList ? "Hide list" : "Show list"))), showList && /* @__PURE__ */ React.createElement(
    "textarea",
    {
      className: "dw-ta",
      readOnly: true,
      value: uncheckedText,
      onFocus: (e) => e.target.select(),
      rows: Math.min(unchecked.length, 10),
      style: { marginTop: 10 }
    }
  )), sel.length > 0 && /* @__PURE__ */ React.createElement("div", { className: "dw-bulk" }, /* @__PURE__ */ React.createElement("span", null, /* @__PURE__ */ React.createElement("strong", null, sel.length), " selected \u2014 move to"), ["taken", "pricey", "rejected"].includes(filter) && /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", onClick: restoreSelected }, "\u21A9 Put back"), STATUS_ORDER.filter((k) => k !== filter).map((k) => /* @__PURE__ */ React.createElement("button", { key: k, className: "dw-btn ghost tiny", onClick: () => moveSelected(k) }, STATUSES[k].label)), /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost tiny", style: { marginLeft: "auto" }, onClick: () => setSel([]) }, "Clear")), shown.length ? /* @__PURE__ */ React.createElement("table", { className: "dw-table" }, /* @__PURE__ */ React.createElement("thead", null, /* @__PURE__ */ React.createElement("tr", null, /* @__PURE__ */ React.createElement("th", { style: { width: 26 } }, /* @__PURE__ */ React.createElement(
    "input",
    {
      type: "checkbox",
      title: "Select all shown",
      checked: sel.length > 0 && sel.length === shown.length,
      onChange: (e) => setSel(e.target.checked ? shown.map((c) => c.id) : [])
    }
  )), /* @__PURE__ */ React.createElement("th", { style: { width: 38 } }, "Pri"), /* @__PURE__ */ React.createElement("th", { style: { width: "38%" } }, "Domain"), /* @__PURE__ */ React.createElement("th", { style: { width: 140 } }, "Status"), /* @__PURE__ */ React.createElement("th", null, "Note"), /* @__PURE__ */ React.createElement("th", { style: { width: 28 } }))), /* @__PURE__ */ React.createElement("tbody", null, shown.map((c) => /* @__PURE__ */ React.createElement(React.Fragment, { key: c.id }, /* @__PURE__ */ React.createElement(
    "tr",
    {
      "data-dead": ["taken", "pricey", "rejected"].includes(c.status) ? "1" : "0",
      "data-sel": sel.includes(c.id) ? "1" : "0"
    },
    /* @__PURE__ */ React.createElement("td", null, /* @__PURE__ */ React.createElement("input", { type: "checkbox", checked: sel.includes(c.id), onChange: () => toggleSel(c.id) })),
    /* @__PURE__ */ React.createElement("td", null, /* @__PURE__ */ React.createElement("span", { className: "dw-pri" }, "P", c.tier || 1)),
    /* @__PURE__ */ React.createElement("td", null, /* @__PURE__ */ React.createElement("span", { className: "dw-dom" }, c.domain), " ", c.status === "purchased" && /* @__PURE__ */ React.createElement("span", { className: "dw-stamp" }, "Claimed")),
    /* @__PURE__ */ React.createElement("td", null, /* @__PURE__ */ React.createElement(
      "select",
      {
        className: "dw-sel",
        value: c.status,
        onChange: (e) => setCandidates(
          (cs) => cs.map(
            (x) => x.id === c.id ? { ...x, prev: x.status, status: e.target.value } : x
          )
        )
      },
      STATUS_ORDER.map((k) => /* @__PURE__ */ React.createElement("option", { key: k, value: k }, STATUSES[k].label))
    )),
    /* @__PURE__ */ React.createElement("td", null, /* @__PURE__ */ React.createElement(
      "input",
      {
        className: "dw-note",
        value: c.note,
        placeholder: "\u2014",
        onChange: (e) => setCandidates(
          (cs) => cs.map((x) => x.id === c.id ? { ...x, note: e.target.value } : x)
        )
      }
    )),
    /* @__PURE__ */ React.createElement("td", null, /* @__PURE__ */ React.createElement(
      "button",
      {
        className: "dw-btn ghost tiny",
        title: "Remove row (name stays retired)",
        onClick: () => setAsk({
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
                  registry: s.registry[c.person] ? {
                    ...s.registry,
                    [c.person]: {
                      ...s.registry[c.person],
                      outcome: `Row removed${c.note ? ` \u2014 ${c.note}` : ""}`
                    }
                  } : s.registry
                }));
              }
            },
            {
              label: "Remove, free the name",
              run: () => {
                setCandidates((cs) => cs.filter((x) => x.id !== c.id));
                if (c.person) releaseName(c.person);
              }
            }
          ]
        })
      },
      "\xD7"
    ))
  ), ask && ask.key === `row:${c.id}` && /* @__PURE__ */ React.createElement("tr", null, /* @__PURE__ */ React.createElement("td", { colSpan: 6, style: { padding: "0 0 8px" } }, askStrip(`row:${c.id}`))))))) : /* @__PURE__ */ React.createElement("div", { className: "dw-empty" }, niche.candidates.length ? "Nothing matches that filter." : "No domains yet. Set your criteria, then hit Find domains.")), /* @__PURE__ */ React.createElement("aside", { className: "dw-col dw-side" }, /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow" }, "Domain patterns \u2014 priority order"), /* @__PURE__ */ React.createElement("p", { className: "dw-hint", style: { margin: "-4px 0 10px" } }, "Searches fill P1 first, then work down."), (niche.patterns || []).map((p, i) => /* @__PURE__ */ React.createElement("div", { className: "dw-pat", key: p.id, "data-off": p.on ? "0" : "1" }, /* @__PURE__ */ React.createElement("div", { className: "dw-pat-top" }, /* @__PURE__ */ React.createElement(
    "input",
    {
      type: "checkbox",
      checked: p.on,
      title: p.on ? "In use" : "Skipped",
      onChange: () => patchPattern(p.id, { on: !p.on })
    }
  ), /* @__PURE__ */ React.createElement("span", { className: "dw-pnum" }, "P", i + 1), /* @__PURE__ */ React.createElement(
    "input",
    {
      className: "dw-in",
      value: p.kw,
      placeholder: "appliancerepair",
      onChange: (e) => patchPattern(p.id, { kw: cleanKw(e.target.value) })
    }
  )), /* @__PURE__ */ React.createElement("div", { className: "dw-pat-bot" }, /* @__PURE__ */ React.createElement("code", { className: "dw-prev" }, `{name}${p.kw || "\u2026"}.com`), /* @__PURE__ */ React.createElement("button", { className: "dw-mini", title: "Move up", disabled: i === 0, onClick: () => movePattern(i, -1) }, "\u2191"), /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-mini",
      title: "Move down",
      disabled: i === niche.patterns.length - 1,
      onClick: () => movePattern(i, 1)
    },
    "\u2193"
  ), /* @__PURE__ */ React.createElement("button", { className: "dw-mini", title: "Remove pattern", onClick: () => removePattern(p.id) }, "\xD7")))), askStrip("pat"), /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { margin: "4px 0 18px" } }, /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-btn ghost",
      style: { flex: 1 },
      onClick: () => patchNiche({ patterns: [...niche.patterns || [], mkPattern("")] })
    },
    "+ Add pattern"
  ), /* @__PURE__ */ React.createElement("button", { className: "dw-btn", style: { flex: 1 }, onClick: suggestPatterns, disabled: suggBusy }, suggBusy ? "Thinking\u2026" : "Suggest patterns")), /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow" }, "Criteria"), /* @__PURE__ */ React.createElement("label", { className: "dw-field" }, /* @__PURE__ */ React.createElement("span", { className: "dw-label" }, "Name type"), /* @__PURE__ */ React.createElement(
    "select",
    {
      className: "dw-in",
      value: niche.nameType || "any",
      onChange: (e) => patchNiche({ nameType: e.target.value })
    },
    Object.entries(NAME_TYPES).map(([k, v]) => /* @__PURE__ */ React.createElement("option", { key: k, value: k }, v.label))
  )), /* @__PURE__ */ React.createElement("label", { className: "dw-field" }, /* @__PURE__ */ React.createElement("span", { className: "dw-label" }, "Max characters before .com"), /* @__PURE__ */ React.createElement(
    "input",
    {
      className: "dw-in",
      type: "number",
      min: 8,
      max: 40,
      value: niche.maxLength,
      onChange: (e) => patchNiche({ maxLength: e.target.value })
    }
  )), /* @__PURE__ */ React.createElement("label", { className: "dw-field" }, /* @__PURE__ */ React.createElement("span", { className: "dw-label" }, "Notes for this niche only"), /* @__PURE__ */ React.createElement(
    "textarea",
    {
      className: "dw-ta",
      value: niche.notes,
      onChange: (e) => patchNiche({ notes: e.target.value }),
      placeholder: "Anything true of this niche but not the others."
    }
  )), /* @__PURE__ */ React.createElement("p", { className: "dw-eyebrow", style: { marginTop: 26 } }, "Availability check"), checkers && Object.keys(checkers).length > 0 && /* @__PURE__ */ React.createElement("div", { style: { marginBottom: 14 } }, /* @__PURE__ */ React.createElement(
    "button",
    {
      className: "dw-btn wide",
      onClick: checkAvailability,
      disabled: checking || !shown.length
    },
    checking ? "Asking the registrar\u2026" : `Check ${shown.length} ${shown.length === 1 ? "domain" : "domains"} now`
  ), /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { marginTop: 8 } }, /* @__PURE__ */ React.createElement("span", { className: "dw-hint", style: { margin: 0 } }, "Ask"), /* @__PURE__ */ React.createElement(
    "select",
    {
      className: "dw-sel",
      value: checker,
      onChange: (e) => setChecker(e.target.value),
      style: { flex: 1 }
    },
    Object.entries(checkers).map(([k, c]) => /* @__PURE__ */ React.createElement("option", { key: k, value: k }, c.label, " \u2014 ", c.speed))
  )), /* @__PURE__ */ React.createElement("p", { className: "dw-hint", style: { margin: "6px 0 0" } }, "Checks whatever this tab is showing \u2014 ", filter === "all" ? "all of them" : `the ${STATUSES[filter].label.toLowerCase()} ones`, ".", checker === "namecheap" && /* @__PURE__ */ React.createElement(React.Fragment, null, " ", /* @__PURE__ */ React.createElement("strong", null, "Namecheap returns no price"), " for ordinary names, so your $", state.maxPrice, " ", "ceiling can't apply \u2014 NameSilo prices them."))), /* @__PURE__ */ React.createElement("p", { className: "dw-hint", style: { margin: "0 0 10px" } }, "Or copy the list above the results, run it through a registrar's bulk search, and paste what comes back here."), /* @__PURE__ */ React.createElement(
    "textarea",
    {
      className: "dw-ta",
      value: paste,
      onChange: (e) => setPaste(e.target.value),
      placeholder: "carverappliance.com  Available\nbexleyappliance.com  Unavailable"
    }
  ), /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { margin: "10px 0" } }, /* @__PURE__ */ React.createElement("span", { className: "dw-hint", style: { margin: 0 } }, "Reject over $"), /* @__PURE__ */ React.createElement(
    "input",
    {
      className: "dw-in",
      type: "number",
      min: 0,
      step: 1,
      style: { width: 72 },
      value: state.maxPrice,
      onChange: (e) => setState((s) => ({ ...s, maxPrice: e.target.value }))
    }
  ), /* @__PURE__ */ React.createElement("span", { className: "dw-hint", style: { margin: 0 } }, "0 = no limit")), /* @__PURE__ */ React.createElement("div", { className: "dw-row", style: { margin: "10px 0" } }, /* @__PURE__ */ React.createElement("span", { className: "dw-hint", style: { margin: 0 } }, "If a status can't be read \u2192"), /* @__PURE__ */ React.createElement("select", { className: "dw-sel", value: fallback, onChange: (e) => setFallback(e.target.value) }, /* @__PURE__ */ React.createElement("option", { value: "skip" }, "Leave unchecked"), /* @__PURE__ */ React.createElement("option", { value: "available" }, "Available"), /* @__PURE__ */ React.createElement("option", { value: "taken" }, "Not available"))), /* @__PURE__ */ React.createElement("button", { className: "dw-btn wide", onClick: applyPaste }, "Update from results"), undo && /* @__PURE__ */ React.createElement("button", { className: "dw-btn ghost wide", style: { marginTop: 8 }, onClick: undoPaste }, "Undo last update"), /* @__PURE__ */ React.createElement("details", { className: "dw-doc" }, /* @__PURE__ */ React.createElement("summary", null, "Search brief"), /* @__PURE__ */ React.createElement("pre", { className: "dw-pre" }, buildBrief(niche, batch, state.rules)), /* @__PURE__ */ React.createElement("p", { className: "dw-hint" }, "Sent verbatim on every search, plus the ", registryList.length, " retired names.")))), toast && /* @__PURE__ */ React.createElement("div", { className: "dw-toast" }, toast));
}
const dwRoot = document.getElementById("dw-root");
if (dwRoot) ReactDOM.createRoot(dwRoot).render(React.createElement(DomainWorkbench));
