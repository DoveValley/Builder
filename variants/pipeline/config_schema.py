"""
The facts schema (config.json) and generated-prose schema (content.json) for a variant-engine
site. Plain dicts + plain validator functions on purpose — no pydantic/dataclass framework.
This mirrors how the rest of this codebase validates data (see includes/multisite/params.php's
ms_validate_rows()): a function that walks a dict and returns a list of human-readable error
strings, nothing cleverer than that.
"""

SCHEMA_VERSION = 1

REQUIRED_LEGAL_KEYS = ["privacy", "terms", "disclaimer", "about"]


def new_config(domain, master_id, batch_id, variant, identity, niche):
    """Skeleton config.json for a fresh row, before research/content runs."""
    return {
        "schema_version": SCHEMA_VERSION,
        "domain": domain,
        "master_id": master_id,
        "batch_id": batch_id,
        "variant": variant,          # {architecture, type, color, titles, research_prompt, voice, source: {...}}
        "identity": identity,        # {business, phone, tel, email, address, zip, lat, lng, analytics_id, ...}
        "niche": niche,               # {business_descriptor, service_noun, customer_noun, local_angle, tone, guardrails}
        "services": {"mode": "flat", "items": [], "matrix": {"dimensions": [], "rows": []}},
        "cities": [],
        "hero": {"headline_ref": "hero.headline", "cta_label": "Get Help Now"},
        "faqs": [],
        "pages": {"guides": [], "legal": {k: {"slug": _legal_slug(k)} for k in REQUIRED_LEGAL_KEYS}},
        "nav": {"primary_links": ["home", "services", "cities", "about-us", "contact-us"]},
        "images": [],
        "meta": {"created_at": None, "regenerated_at": None, "content_input_hash": None, "current_year": None},
    }


def _legal_slug(key):
    return {"privacy": "privacy", "terms": "terms", "disclaimer": "disclaimer", "about": "about-us"}[key]


def validate_config(config):
    """Returns a list of error strings; empty list = valid enough to run the content pass."""
    errors = []

    def require(path, cond):
        if not cond:
            errors.append(f"config.{path} is missing or invalid")

    require("domain", config.get("domain"))
    require("master_id", config.get("master_id"))
    require("variant", isinstance(config.get("variant"), dict) and config["variant"].get("architecture"))
    require("identity.business", (config.get("identity") or {}).get("business"))
    require("identity.phone", (config.get("identity") or {}).get("phone"))

    niche = config.get("niche") or {}
    for k in ("business_descriptor", "service_noun", "tone", "guardrails"):
        require(f"niche.{k}", niche.get(k))

    services = config.get("services") or {}
    require("services.mode", services.get("mode") in ("flat", "matrix"))
    if services.get("mode") == "flat":
        require("services.items", isinstance(services.get("items"), list) and len(services["items"]) > 0)
    elif services.get("mode") == "matrix":
        matrix = services.get("matrix") or {}
        require("services.matrix.dimensions", isinstance(matrix.get("dimensions"), list) and matrix["dimensions"])
        require("services.matrix.rows", isinstance(matrix.get("rows"), list) and matrix["rows"])

    cities = config.get("cities")
    require("cities", isinstance(cities, list) and len(cities) > 0)
    for i, city in enumerate(cities or []):
        require(f"cities[{i}].name", city.get("name"))
        require(f"cities[{i}].SS", city.get("SS"))
        require(f"cities[{i}].slug", city.get("slug"))

    legal = (config.get("pages") or {}).get("legal") or {}
    for key in REQUIRED_LEGAL_KEYS:
        require(f"pages.legal.{key}", isinstance(legal.get(key), dict) and legal[key].get("slug"))

    return errors


def validate_content(content):
    """Same shape of check, against the generated-prose file."""
    errors = []

    def require(path, cond):
        if not cond:
            errors.append(f"content.{path} is missing or invalid")

    hero = content.get("hero") or {}
    require("hero.headline", hero.get("headline"))
    require("hero.subhead", hero.get("subhead"))

    legal = content.get("legal") or {}
    for key in REQUIRED_LEGAL_KEYS:
        entry = legal.get(key) or {}
        require(f"legal.{key}.body_html", entry.get("body_html"))

    return errors


def meaning_lock_passed(content):
    """True only if every legal page's meaning-lock check is a pass (or not yet run)."""
    legal = content.get("legal") or {}
    for key in REQUIRED_LEGAL_KEYS:
        status = ((legal.get(key) or {}).get("meaning_lock") or {}).get("status")
        if status == "needs_review":
            return False
    return True
