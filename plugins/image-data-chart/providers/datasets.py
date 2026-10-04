#!/usr/bin/env python3
"""
Published figures for a city, retrieved from public datasets, so chart values are looked up
rather than invented.

WHY THIS EXISTS
Chart definitions used to declare a `research.ask` for their figures AND a
`research.source_ask` that named the citation to use:

    "source_ask": "... the source of those figures (e.g. \"NOAA 1991-2020 Climate Normals\")"

The research model was therefore handed a source and asked to supply numbers consistent with
it, which it duly did. What that produced, measured against the real data:

  weather          212 records. Median error 11% (rainfall) to 30% (heavy rain days), with
                   single months out by an order of magnitude -- Carrollton TX held 12 days
                   above 90F in June against a real 22.4. heavy_rain_days was worse: the
                   stored values tracked a >=0.50in threshold while the chart asks for
                   >=1.00in, so they were wrong by about 2x (119% median at the right
                   threshold).
  humidity         212 records citing a NOAA product that publishes no moisture variable at
                   all. Unsourceable under any reading; replaced by days-with-rain.
  flood_years      Asked the model for "MAJOR, well-documented" floods and to judge its own
                   confidence. Understated flood frequency in 200 of 202 cities, median 6x
                   and up to 28x. Dallas claimed 1908 and 1949, which the cited database
                   cannot contain -- it does not record floods before 1996.
  homes_by_decade  1,060 percentages, 100% of them whole numbers and 70.8% multiples of five.
                   ACS percentages come from unit counts and carry decimals; this dataset had
                   none anywhere.

All of it rendered as a crawlable HTML data table (blocks.php:77) naming a specific source,
under a "Retrieved <date>" line that was really just the build date.

WHAT IT READS  (no API keys -- api.census.gov now requires one, these do not)
  NOAA Climate Normals 1991-2020   monthly and annual, by station    ncei.noaa.gov
  NOAA Climate at a Glance         statewide precipitation            ncei.noaa.gov
  NOAA Storm Events                county flood history, via a built index
  Census ACS 5-year, B25034        housing age, via a built index from www2.census.gov
  Census geocoder                  lat/lng -> county FIPS and incorporated place

WHAT IT GUARANTEES
  - A value is retrieved or absent. No estimate, no fallback to a nearby figure, no guess.
    A failure is reported to the caller, which logs it.
  - The source string names the geography ACTUALLY used and its distance or type, because
    "NOAA 1991-2020 Climate Normals (Denver, CO station)" on a Littleton page is only honest
    if the reader can see how far away that was. Likewise a county housing figure says it is
    a county figure.
  - Stations are accepted only if they carry EVERY data type asked of them, so one station is
    never cited for a series it is missing.
  - Two charts on different datasets may not share a source_key. They did once, and half of
    water's monthly rainfall tables cited the annual station instead of their own.
  - fetched_at is the real retrieval date, stored with the data, so "Retrieved ..." stops
    being re-stamped to the build date on every rebuild.

ADDING A CITY needs no new research and no new file: every dataset here is national and keyed
on coordinates. Verified against twelve places outside the current list, including Juneau,
Kailua-Kona, Washington DC, an independent city, a tiny mountain town and an unincorporated
CDP -- 12/12 with no gaps.
"""
import json, os, math, time, urllib.parse, urllib.request, datetime

DS_MONTHLY = "normals-monthly-1991-2020"
DS_ANNUAL = "normals-annualseasonal-1991-2020"
SEARCH = "https://www.ncei.noaa.gov/access/services/search/v1/data"
DATA = "https://www.ncei.noaa.gov/access/services/data/v1"
CENSUS = "https://geocoding.geo.census.gov/geocoder/geographies/coordinates"
UA = {"User-Agent": "site-factory/1.0 (+climate normals retrieval)"}

# Repo-root cache/, not a cache beside this file: the provider moved into the plugin
# and the cache should not move with it (and /cache/ is what .gitignore covers).
CACHE_DIR = os.path.join(
    os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                 "..", "..", "..")), "cache")
_mem = {"station": {}, "series": {}, "county": {}}


# ---------------------------------------------------------------- plumbing

def _cache_path(kind):
    return os.path.join(CACHE_DIR, f"noaa_{kind}.json")


def _cache_load(kind):
    if _mem[kind]:
        return _mem[kind]
    try:
        with open(_cache_path(kind), encoding="utf-8") as fh:
            _mem[kind] = json.load(fh)
    except Exception:
        _mem[kind] = {}
    return _mem[kind]


def _cache_save(kind):
    try:
        os.makedirs(CACHE_DIR, exist_ok=True)
        tmp = _cache_path(kind) + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(_mem[kind], fh)
        os.replace(tmp, _cache_path(kind))
    except Exception:
        pass


def _get(url, tries=3):
    last = None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=UA)
            with urllib.request.urlopen(req, timeout=50) as r:
                return json.loads(r.read().decode("utf-8", "replace"))
        except Exception as e:
            last = e
            if i < tries - 1:
                time.sleep(1.5 * (i + 1))
    raise RuntimeError(f"NOAA request failed after {tries} tries: {last}")


def _miles(a, b, c, d):
    p = math.pi / 180
    return 7912 * math.asin(math.sqrt(
        math.sin((c - a) * p / 2) ** 2
        + math.cos(a * p) * math.cos(c * p) * math.sin((d - b) * p / 2) ** 2))


# ---------------------------------------------------------------- lookups

def county_fips(lat, lng):
    """(fips5, 'Angelina County') for a point, or (None, None). Used by the flood-year
    index and, later, the CDC tick / APHIS fire-ant county layers."""
    key = f"{round(float(lat), 4)},{round(float(lng), 4)}"
    c = _cache_load("county")
    if key in c:
        return tuple(c[key]) if c[key] else (None, None)
    q = urllib.parse.urlencode({
        "x": lng, "y": lat, "benchmark": "Public_AR_Current",
        "vintage": "Current_Current", "layers": "Counties", "format": "json"})
    try:
        d = _get(f"{CENSUS}?{q}")
        co = (d["result"]["geographies"]["Counties"] or [])[0]
        val = [co["GEOID"], co["NAME"]]
    except Exception:
        val = None
    c[key] = val
    _cache_save("county")
    return tuple(val) if val else (None, None)


def nearest_station(lat, lng, need_types, dataset=DS_MONTHLY):
    """Nearest normals station carrying EVERY one of need_types.

    Requiring all types up front is deliberate: picking the closest station and then
    discovering it has precipitation but not freeze days would mean either a hole in the
    chart or two different stations cited as one.
    """
    lat, lng = float(lat), float(lng)
    need = sorted(set(need_types))
    key = f"{dataset}|{round(lat * 2) / 2},{round(lng * 2) / 2}|{','.join(need)}"
    c = _cache_load("station")
    if key in c:
        return tuple(c[key]) if c[key] else None

    best = None
    for pad in (0.6, 1.2, 2.5):
        q = urllib.parse.urlencode({
            "dataset": dataset,
            "bbox": f"{lat + pad},{lng - pad},{lat - pad},{lng + pad}",
            "limit": 60, "offset": 0})
        try:
            d = _get(f"{SEARCH}?{q}")
        except Exception:
            continue
        for r in (d.get("results") or []):
            st = (r.get("stations") or [{}])[0]
            sid = st.get("id")
            have = {t.get("id") for t in (st.get("dataTypes") or [])}
            pts = r.get("boundingPoints") or []
            if not sid or not pts or not set(need).issubset(have):
                continue
            lon2, lat2 = pts[0]["coordinates"][0], pts[0]["coordinates"][1]
            dist = _miles(lat, lng, lat2, lon2)
            if best is None or dist < best[2]:
                best = (sid, (st.get("name") or "").strip(), round(dist, 1))
        if best:
            break

    c[key] = list(best) if best else None
    _cache_save("station")
    return best


def series(station_id, data_types, dataset=DS_MONTHLY):
    """{data_type: [12 monthly floats]} for a monthly dataset, or {dt: scalar} for annual.
    One request per station regardless of how many types are wanted."""
    key = f"{dataset}|{station_id}|{','.join(sorted(data_types))}"
    c = _cache_load("series")
    if key in c:
        return c[key]
    q = urllib.parse.urlencode({
        "dataset": dataset, "stations": station_id, "format": "json",
        "startDate": "0001-01-01", "endDate": "9996-12-31",
        "dataTypes": ",".join(sorted(data_types))})
    rows = _get(f"{DATA}?{q}")
    rows = rows if isinstance(rows, list) else [rows]
    out = {}
    if dataset == DS_MONTHLY:
        if len(rows) < 12:
            raise RuntimeError(f"{station_id}: expected 12 monthly rows, got {len(rows)}")
        rows.sort(key=lambda r: str(r.get("DATE", "")))
        for dt in data_types:
            vals = []
            for r in rows[:12]:
                try:
                    vals.append(float(str(r.get(dt, "")).strip()))
                except (TypeError, ValueError):
                    vals.append(None)
            if any(v is None for v in vals):
                raise RuntimeError(f"{station_id}: {dt} has gaps across the 12 months")
            out[dt] = vals
    else:
        r = rows[0]
        for dt in data_types:
            raw = str(r.get(dt, "")).strip()
            try:
                out[dt] = float(raw)
            except (TypeError, ValueError):
                out[dt] = raw or None
    c[key] = out
    _cache_save("series")
    return out


# ---------------------------------------------------------------- public API

def fetch_city(city, decls):
    """Fill every declared `fetch` field for one city.

    decls: [{data_key, source_key, provider, data_type, dataset}]
    Returns (updates, problems). `updates` only ever contains values NOAA actually returned.
    """
    lat, lng = city.get("lat"), city.get("lng")
    if not lat or not lng:
        return {}, [f"{city.get('city', '?')}: no lat/lng, cannot locate a station"]

    updates, problems = {}, []

    # Housing age is a place/county lookup against the ACS index.
    for d in [x for x in decls if x.get("provider") == "census_acs_homes"]:
        vals, src = homes_by_decade(lat, lng)
        if not vals:
            problems.append(f"{city.get('city')}: no ACS housing-age record for this location")
            continue
        updates[d["data_key"]] = vals
        if d.get("source_key"):
            updates[d["source_key"]] = src

    # Flood history is a county lookup, not a station reading, so it is handled before the
    # station logic and never contributes to the station choice.
    for d in [x for x in decls if x.get("provider") == "noaa_storm_events"]:
        dec, src, extra = flood_decades(lat, lng)
        if not src:
            problems.append(f"{city.get('city')}: flood index unavailable or no county")
            continue
        updates[d["data_key"]] = dec
        if d.get("source_key"):
            updates[d["source_key"]] = src
        updates.update({k: v for k, v in extra.items() if v is not None})

    by_ds = {}
    for d in decls:
        if d.get("provider") in LOOKUP_PROVIDERS:
            continue
        by_ds.setdefault(d.get("dataset") or DS_MONTHLY, []).append(d)

    for ds, group in by_ds.items():
        types = [d["data_type"] for d in group]
        st = nearest_station(lat, lng, types, ds)
        if not st:
            problems.append(f"{city.get('city')}: no {ds} station carries {types}")
            continue
        sid, name, miles = st
        try:
            vals = series(sid, types, ds)
        except Exception as e:
            problems.append(f"{city.get('city')}: {sid} fetch failed - {e}")
            continue
        label = name or sid
        src = (f"NOAA 1991-2020 Climate Normals, {label} station "
               f"({miles:g} mi from {city.get('city')})")
        for d in group:
            v = vals.get(d["data_type"])
            if v is None:
                problems.append(f"{city.get('city')}: {d['data_type']} absent at {sid}")
                continue
            updates[d["data_key"]] = v
            sk = d.get("source_key")
            if sk:
                # Two charts on DIFFERENT datasets must not share a source_key. The monthly
                # and annual station searches are independent, so a shared key means one
                # series ends up citing a station its numbers did not come from — Juneau
                # resolved 0.4 mi for annual and 6.5 mi for monthly. Report, do not overwrite.
                if sk in updates and updates[sk] != src:
                    problems.append(
                        f"{city.get('city')}: source_key '{sk}' already set by another "
                        f"dataset with a different station — not overwriting "
                        f"(kept: {updates[sk]!r})")
                else:
                    updates[sk] = src
        updates["noaa_station"] = sid
        updates["noaa_station_miles"] = miles
        updates["noaa_fetched_at"] = datetime.date.today().isoformat()

    # A compare chart's benchmark is a statewide figure, not a station reading, so it comes
    # from a different product. Declared on the chart beside the city figure it compares to.
    for d in decls:
        b = d.get("benchmark") or {}
        vk = b.get("value_key")
        if not vk:
            continue
        val, src = statewide_annual(city.get("state", ""), b.get("element", "pcp"))
        if val is None:
            problems.append(f"{city.get('city')}: no statewide figure for "
                            f"{city.get('state') or '(no state)'} — benchmark left empty")
            continue
        updates[vk] = val
        if b.get("source_key"):
            updates[b["source_key"]] = src
    return updates, problems


def chart_fetch_decls(niche, root=None):
    """Read the `fetch` blocks out of one niche's chart definitions.

    The declaration lives beside the chart that needs it, so adding a fetched metric is a
    JSON edit with no code change -- the same principle chart_research_fields() already uses
    for AI-researched metrics.
    """
    import glob, re
    # niches/ is a SIBLING of providers/ -- this file lives inside the chart plugin, so the
    # definitions are one level up, not under a repo-root plugins/ path. Getting this wrong
    # is silent: chart_fetch_decls() simply returns [] and every figure quietly stops being
    # retrieved, which is the exact failure this whole change exists to remove.
    root = root or os.path.abspath(os.path.join(
        os.path.dirname(os.path.abspath(__file__)), ".."))
    slug = re.sub(r"[^a-z0-9]+", "-", (niche or "").strip().lower()).strip("-")
    if not slug:
        return []
    out = []
    for f in sorted(glob.glob(os.path.join(root, "niches", slug, "*.json"))):
        try:
            with open(f, encoding="utf-8") as fh:
                cfg = json.load(fh)
        except Exception:
            continue
        fe = cfg.get("fetch")
        if not isinstance(fe, dict):
            continue
        # Only the NOAA-normals providers need a data_type. The lookup providers resolve a
        # geography and read an index, so requiring data_type would silently exclude them —
        # which is how a declaration can look correct and never fire.
        if not fe.get("data_type") and fe.get("provider") not in LOOKUP_PROVIDERS:
            continue
        out.append({
            "data_key": (cfg.get("data_key") or "").strip(),
            "source_key": (cfg.get("source_key") or "").strip(),
            "provider": fe.get("provider") or "noaa_normals_monthly",
            "data_type": fe.get("data_type", ""),
            "dataset": DS_ANNUAL if fe.get("provider") == "noaa_normals_annual" else DS_MONTHLY,
            # The benchmark block MUST be carried through. It was omitted here once, so
            # fetch_city's benchmark branch never fired and compare charts silently kept
            # their invented statewide figure (Texas showed 34 in against a real 28.6).
            "benchmark": fe.get("benchmark") or {},
            "chart": os.path.basename(f),
        })
    return out


# ---------------------------------------------------------------- statewide figures

# NOAA Climate at a Glance numbers the contiguous states alphabetically 1-48, then Alaska 50
# and Hawaii 51. Verified against the returned series titles for AL, CA, NY, SC, TX, AK, HI.
# DC has no statewide series, so it simply has no benchmark rather than a borrowed one.
_CAG_STATE = {
    "alabama": 1, "arizona": 2, "arkansas": 3, "california": 4, "colorado": 5,
    "connecticut": 6, "delaware": 7, "florida": 8, "georgia": 9, "idaho": 10,
    "illinois": 11, "indiana": 12, "iowa": 13, "kansas": 14, "kentucky": 15,
    "louisiana": 16, "maine": 17, "maryland": 18, "massachusetts": 19, "michigan": 20,
    "minnesota": 21, "mississippi": 22, "missouri": 23, "montana": 24, "nebraska": 25,
    "nevada": 26, "new hampshire": 27, "new jersey": 28, "new mexico": 29, "new york": 30,
    "north carolina": 31, "north dakota": 32, "ohio": 33, "oklahoma": 34, "oregon": 35,
    "pennsylvania": 36, "rhode island": 37, "south carolina": 38, "south dakota": 39,
    "tennessee": 40, "texas": 41, "utah": 42, "vermont": 43, "virginia": 44,
    "washington": 45, "west virginia": 46, "wisconsin": 47, "wyoming": 48,
    "alaska": 50, "hawaii": 51,
}
CAG = ("https://www.ncei.noaa.gov/access/monitoring/climate-at-a-glance/statewide/"
       "time-series/{code}/{el}/12/12/1991-2020.json")


def statewide_annual(state_name, element="pcp"):
    """(value, source) averaged over the published 1991-2020 series, or (None, '').

    Averaging the 30 published annual values is the statewide equivalent of a 1991-2020
    normal, and it is stated as such in the source string rather than implied.
    """
    code = _CAG_STATE.get((state_name or "").strip().lower())
    if not code:
        return None, ""
    key = f"cag|{code}|{element}"
    c = _cache_load("series")
    if key in c:
        v = c[key]
        return (v[0], v[1]) if v else (None, "")
    try:
        d = _get(CAG.format(code=code, el=element))
        vals = [x["value"] for x in d["data"].values() if x.get("value") is not None]
        if not vals:
            raise RuntimeError("no values")
        val = round(sum(vals) / len(vals), 2)
        src = (f"NOAA Climate at a Glance, {state_name} statewide "
               f"{'precipitation' if element == 'pcp' else element}, "
               f"mean of the published 1991-2020 annual values")
        out = [val, src]
    except Exception:
        out = None
    c[key] = out
    _cache_save("series")
    return (out[0], out[1]) if out else (None, "")


# ---------------------------------------------------------------- flood history

_FLOOD = {"loaded": False, "data": None}


def _flood_index():
    if not _FLOOD["loaded"]:
        _FLOOD["loaded"] = True
        p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "flood_index.json")
        try:
            with open(p, encoding="utf-8") as fh:
                _FLOOD["data"] = json.load(fh)
        except Exception:
            _FLOOD["data"] = None
    return _FLOOD["data"]


def flood_decades(lat, lng):
    """({decade: events}, source, extras) for the county containing a point.

    Returns ({}, '', {}) when the county has no recorded flood event. That is a real
    answer, not a gap — and the source string says which period was searched, because
    Storm Events does not record floods before 1996 and silence earlier is an artefact of
    the database rather than evidence of no flooding.
    """
    idx = _flood_index()
    if not idx:
        return {}, "", {}
    fips, cname = county_fips(lat, lng)
    if not fips:
        return {}, "", {}
    meta = idx.get("meta") or {}
    rec = (idx.get("counties") or {}).get(fips)
    src = (f"NOAA Storm Events Database, {cname or 'county'} "
           f"({meta.get('_coverage', '')}; Flood, Flash Flood and Coastal Flood events)")
    if not rec:
        return {}, src, {"flood_county": cname, "flood_county_fips": fips,
                         "flood_events_total": 0}
    return rec.get("decades") or {}, src, {
        "flood_county": rec.get("county") or cname,
        "flood_county_fips": fips,
        "flood_events_total": rec.get("total_events"),
        "flood_years_with_events": rec.get("years_with_events"),
        "flood_most_recent": rec.get("most_recent"),
        "flood_first_recorded": rec.get("first"),
    }


# ---------------------------------------------------------------- housing age (ACS)

LOOKUP_PROVIDERS = {"noaa_storm_events", "census_acs_homes"}

_ACS = {"loaded": False, "data": None}


def _acs_index():
    if not _ACS["loaded"]:
        _ACS["loaded"] = True
        p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "acs_homes_index.json")
        try:
            with open(p, encoding="utf-8") as fh:
                _ACS["data"] = json.load(fh)
        except Exception:
            _ACS["data"] = None
    return _ACS["data"]


def place_geoid(lat, lng):
    """(geoid, name) of the incorporated place containing a point, or (None, None).

    Not every city in the list is a separate Census place, which is why homes_by_decade
    falls back to the county rather than returning nothing.
    """
    key = f"place|{round(float(lat), 4)},{round(float(lng), 4)}"
    c = _cache_load("county")      # same cache file; keys are namespaced
    if key in c:
        return tuple(c[key]) if c[key] else (None, None)
    q = urllib.parse.urlencode({
        "x": lng, "y": lat, "benchmark": "Public_AR_Current",
        "vintage": "Current_Current", "layers": "Incorporated Places", "format": "json"})
    try:
        d = _get(f"{CENSUS}?{q}")
        geos = d["result"]["geographies"]
        rows = []
        for k, v in geos.items():
            if "Place" in k:
                rows = v or []
                break
        pl = rows[0]
        val = [pl["GEOID"], pl.get("NAME") or ""]
    except Exception:
        val = None
    c[key] = val
    _cache_save("county")
    return tuple(val) if val else (None, None)


def homes_by_decade(lat, lng):
    """({bucket: percent}, source) for a point, or ({}, '').

    Prefers the incorporated place; falls back to the county and SAYS SO in the source, so a
    county figure is never passed off as a city figure.
    """
    idx = _acs_index()
    if not idx:
        return {}, ""
    meta = idx.get("meta") or {}
    labels = meta.get("_buckets") or []
    base = (f"US Census Bureau, American Community Survey {meta.get('_year')} 5-year "
            f"estimates, table {meta.get('_table')}")

    gid, name = place_geoid(lat, lng)
    rec = (idx.get("places") or {}).get(gid) if gid else None
    if rec:
        vals, units = rec
        return (dict(zip(labels, vals)),
                f"{base} — {name} ({units:,} housing units)")

    fips, cname = county_fips(lat, lng)
    rec = (idx.get("counties") or {}).get(fips) if fips else None
    if rec:
        vals, units = rec
        return (dict(zip(labels, vals)),
                f"{base} — {cname} ({units:,} housing units; county figure, as this "
                f"location is not a separate Census place)")
    return {}, ""
