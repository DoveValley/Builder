#!/usr/bin/env python3
"""
Real NOAA 1991-2020 Climate Normals for a city, so chart figures are retrieved rather than
guessed.

WHY THIS EXISTS
Chart definitions declared a `research.ask` for figures like rainfall_monthly AND a
`research.source_ask` that literally suggested the citation to use:

    "source_ask": "... the source of those figures (e.g. \"NOAA 1991-2020 Climate Normals\")"

So the research model was handed a citation and asked to supply numbers to match it. It
obliged. Measured across 212 city records, the stored values were off from the real normals
by a median of 11% (rainfall) to 30% (heavy rain days), with individual months wrong by
10-30x -- Greenville SC claimed 4 days above 90F in May against a real 0.2. humidity_monthly
was worse than inaccurate: NOAA's normals publish NO moisture variable at all beyond
precipitation, so those 12 values could not have come from the cited source under any
reading. All of it shipped as a crawlable HTML data table (blocks.php:77) naming a specific
NOAA station, under a "Retrieved <date>" stamp that was really just the build date.

WHAT THIS GUARANTEES
  - A value is either retrieved from NOAA or absent. There is no fallback, no estimate, no
    nearest-guess. A failed fetch leaves the field alone and is reported.
  - The source string names the station ACTUALLY used and its distance from the city, because
    "NOAA 1991-2020 Climate Normals (Denver, CO station)" for a Littleton page is only honest
    if the reader can see it was 10 miles away.
  - fetched_at is the real retrieval date, stored with the data, so "Retrieved ..." stops
    being re-stamped to the build date on every rebuild.
  - Stations are only accepted if they actually carry every data type asked of them, so a
    station is never used for one field and silently missing another.

NO API KEY. Both services are open:
  station search  https://www.ncei.noaa.gov/access/services/search/v1/data
  data            https://www.ncei.noaa.gov/access/services/data/v1
  county FIPS     https://geocoding.geo.census.gov/geocoder (FCC's equivalent is currently
                  returning a backend auth fault, so Census is the one that works)
"""
import json, os, math, time, urllib.parse, urllib.request, datetime

DS_MONTHLY = "normals-monthly-1991-2020"
DS_ANNUAL = "normals-annualseasonal-1991-2020"
SEARCH = "https://www.ncei.noaa.gov/access/services/search/v1/data"
DATA = "https://www.ncei.noaa.gov/access/services/data/v1"
CENSUS = "https://geocoding.geo.census.gov/geocoder/geographies/coordinates"
UA = {"User-Agent": "site-factory/1.0 (+climate normals retrieval)"}

CACHE_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cache")
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
    by_ds = {}
    for d in decls:
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
            if d.get("source_key"):
                updates[d["source_key"]] = src
        updates["noaa_station"] = sid
        updates["noaa_station_miles"] = miles
        updates["noaa_fetched_at"] = datetime.date.today().isoformat()
    return updates, problems


def chart_fetch_decls(niche, root=None):
    """Read the `fetch` blocks out of one niche's chart definitions.

    The declaration lives beside the chart that needs it, so adding a fetched metric is a
    JSON edit with no code change -- the same principle chart_research_fields() already uses
    for AI-researched metrics.
    """
    import glob, re
    root = root or os.path.dirname(os.path.abspath(__file__))
    slug = re.sub(r"[^a-z0-9]+", "-", (niche or "").strip().lower()).strip("-")
    if not slug:
        return []
    out = []
    for f in sorted(glob.glob(os.path.join(
            root, "plugins", "image-data-chart", "niches", slug, "*.json"))):
        try:
            with open(f, encoding="utf-8") as fh:
                cfg = json.load(fh)
        except Exception:
            continue
        fe = cfg.get("fetch")
        if not isinstance(fe, dict) or not fe.get("data_type"):
            continue
        out.append({
            "data_key": (cfg.get("data_key") or "").strip(),
            "source_key": (cfg.get("source_key") or "").strip(),
            "provider": fe.get("provider") or "noaa_normals_monthly",
            "data_type": fe["data_type"],
            "dataset": DS_ANNUAL if fe.get("provider") == "noaa_normals_annual" else DS_MONTHLY,
            "chart": os.path.basename(f),
        })
    return out
