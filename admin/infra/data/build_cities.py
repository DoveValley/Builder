#!/usr/bin/env python3
"""
Rebuild admin/infra/data/us_cities.csv — the seed list behind the Cities/Niche tab,
and the table every niche's city research/matching runs against.

Run it from anywhere; it writes next to itself. Needs network access and the same
Census API key infra/lib/census.php already uses (admin/infra/config/census.json),
since this script shares that key rather than requesting its own.

    python3 admin/infra/data/build_cities.py

Two public sources, joined on the Census place GEOID (2-digit state FIPS +
5-digit place FIPS):
  1. Census ACS 5-Year (B01003_001E, for=place:*) — population for EVERY place
     the Census recognizes, incorporated and Census-Designated Place (CDP)
     alike. Previously this came from sub-est2024 filtered to SUMLEV '162'
     (incorporated only), capped at the top 10,000 by population — that
     silently dropped every CDP/unincorporated community (Castro Valley CA,
     Staten Island NY, Glenside PA, Mashpee MA, Bear DE, ...), which is why
     real eLocal results for those places showed up as "unmatched to a known
     city" in Data 2 research runs rather than as real candidates. No cap
     here: every place ACS returns a row for is kept (~32k, not ~10k).
  2. Census Gazetteer national place file — lat/lng and the 2-letter state
     code (USPS), matched by that same GEOID. No name-matching needed for
     this join (unlike the old kelvins CSV, which matched ~85% of places by
     name) — GEOID is exact, so a handful of places that fall outside the
     Gazetteer's vintage just get blank coordinates, not a wrong city.

  3. ravisorg/Area-Code-Geolocation-Database — area code by city. Unchanged
     from before: an exact city+state hit is marked `exact`; everything else
     borrows from area-code cities nearby (same state only) and is marked
     `near` — a suggestion to pick from, not a fact, which is why the source
     is recorded per row.

ACS and Gazetteer are pinned to the same vintage (2023) so population and
geography describe the same year, not two different ones.

Output columns: rank,city,state,ss,population,lat,lng,area_codes,ac_source
"""
import csv, io, json, math, os, sys, urllib.request, zipfile

NEAR_KM = 25     # collect every in-state area code within this radius
MAX_KM  = 120    # if that finds nothing, borrow the single closest in-state code

ACS_YEAR = 2023
GAZ_YEAR = 2023  # kept equal to ACS_YEAR on purpose — see module docstring

AREA_URL = 'https://raw.githubusercontent.com/ravisorg/Area-Code-Geolocation-Database/master/us-area-code-cities.csv'
GAZ_URL  = 'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/{0}_Gazetteer/{0}_Gaz_place_national.zip'

HERE          = os.path.dirname(os.path.abspath(__file__))
OUT           = os.path.join(HERE, 'us_cities.csv')
CENSUS_CONFIG = os.path.join(HERE, '..', 'config', 'census.json')

# Same 51 entries (50 states + DC) as infra_census_state_fips() in
# admin/infra/lib/census.php — keep the two lists in sync if either changes.
STATE_FIPS = {
    'Alabama': '01', 'Alaska': '02', 'Arizona': '04', 'Arkansas': '05',
    'California': '06', 'Colorado': '08', 'Connecticut': '09', 'Delaware': '10',
    'District of Columbia': '11', 'Florida': '12', 'Georgia': '13', 'Hawaii': '15',
    'Idaho': '16', 'Illinois': '17', 'Indiana': '18', 'Iowa': '19', 'Kansas': '20',
    'Kentucky': '21', 'Louisiana': '22', 'Maine': '23', 'Maryland': '24',
    'Massachusetts': '25', 'Michigan': '26', 'Minnesota': '27', 'Mississippi': '28',
    'Missouri': '29', 'Montana': '30', 'Nebraska': '31', 'Nevada': '32',
    'New Hampshire': '33', 'New Jersey': '34', 'New Mexico': '35', 'New York': '36',
    'North Carolina': '37', 'North Dakota': '38', 'Ohio': '39', 'Oklahoma': '40',
    'Oregon': '41', 'Pennsylvania': '42', 'Rhode Island': '44', 'South Carolina': '45',
    'South Dakota': '46', 'Tennessee': '47', 'Texas': '48', 'Utah': '49',
    'Vermont': '50', 'Virginia': '51', 'Washington': '53', 'West Virginia': '54',
    'Wisconsin': '55', 'Wyoming': '56',
}

# Fallback only — used when a place's GEOID isn't found in the Gazetteer (so
# there's no USPS column to read). Keeps `ss` populated either way, since
# `infra_cities_seed()` keys every row's id on city-slug + ss; a blank ss
# would collide different states' same-named cities onto one id.
STATE_ABBR = {
    'Alabama': 'AL', 'Alaska': 'AK', 'Arizona': 'AZ', 'Arkansas': 'AR',
    'California': 'CA', 'Colorado': 'CO', 'Connecticut': 'CT', 'Delaware': 'DE',
    'District of Columbia': 'DC', 'Florida': 'FL', 'Georgia': 'GA', 'Hawaii': 'HI',
    'Idaho': 'ID', 'Illinois': 'IL', 'Indiana': 'IN', 'Iowa': 'IA', 'Kansas': 'KS',
    'Kentucky': 'KY', 'Louisiana': 'LA', 'Maine': 'ME', 'Maryland': 'MD',
    'Massachusetts': 'MA', 'Michigan': 'MI', 'Minnesota': 'MN', 'Mississippi': 'MS',
    'Missouri': 'MO', 'Montana': 'MT', 'Nebraska': 'NE', 'Nevada': 'NV',
    'New Hampshire': 'NH', 'New Jersey': 'NJ', 'New Mexico': 'NM', 'New York': 'NY',
    'North Carolina': 'NC', 'North Dakota': 'ND', 'Ohio': 'OH', 'Oklahoma': 'OK',
    'Oregon': 'OR', 'Pennsylvania': 'PA', 'Rhode Island': 'RI', 'South Carolina': 'SC',
    'South Dakota': 'SD', 'Tennessee': 'TN', 'Texas': 'TX', 'Utah': 'UT',
    'Vermont': 'VT', 'Virginia': 'VA', 'Washington': 'WA', 'West Virginia': 'WV',
    'Wisconsin': 'WI', 'Wyoming': 'WY',
}

# ACS's NAME field carries the legal/statistical type; "Jersey City city" is
# not a city called that. Same suffix list the old sub-est2024 path used —
# CDP was already handled here, so no change needed to pick those up.
SUFFIXES = (' city', ' town', ' village', ' borough', ' township', ' municipality', ' CDP')
GOV      = (' metropolitan government', ' consolidated government', ' unified government', ' metro government')


def clean_name(name, state_name):
    n = name
    suffix = ', ' + state_name
    if n.endswith(suffix):
        n = n[:-len(suffix)]
    n = n.replace(' (balance)', '')
    for g in GOV:
        n = n.replace(g, '')
    for s in SUFFIXES:
        if n.endswith(s):
            return n[:-len(s)]
    return n


def fetch_text(url, encoding='utf-8', timeout=120):
    sys.stderr.write('fetching %s\n' % url)
    with urllib.request.urlopen(url, timeout=timeout) as r:
        return r.read().decode(encoding, 'replace')


def fetch_bytes(url, timeout=120):
    sys.stderr.write('fetching %s\n' % url)
    with urllib.request.urlopen(url, timeout=timeout) as r:
        return r.read()


def haversine(a, b):
    lat1, lon1 = a
    lat2, lon2 = b
    p = math.pi / 180
    h = (0.5 - math.cos((lat2 - lat1) * p) / 2
         + math.cos(lat1 * p) * math.cos(lat2 * p) * (1 - math.cos((lon2 - lon1) * p)) / 2)
    return 12742 * math.asin(math.sqrt(h))


def census_key():
    with open(CENSUS_CONFIG, encoding='utf-8') as f:
        key = (json.load(f).get('key') or '').strip()
    if not key:
        sys.exit('No Census API key found at ' + CENSUS_CONFIG)
    return key


def fetch_acs_population(key):
    """One state at a time (for=place:*&in=state:FIPS returns every place —
    incorporated and CDP — in one call; 51 calls covers the whole country,
    same per-state pattern infra_census_backfill() already uses for income)."""
    places = []
    for state_name, fips in STATE_FIPS.items():
        url = ('https://api.census.gov/data/%d/acs/acs5?get=NAME,B01003_001E'
               '&for=place:*&in=state:%s&key=%s') % (ACS_YEAR, fips, key)
        data = json.loads(fetch_text(url))
        idx = {c: i for i, c in enumerate(data[0])}
        for r in data[1:]:
            pop = int(r[idx['B01003_001E']] or 0)
            if pop < 0:   # Census sentinel for "not available" (e.g. -666666666)
                pop = 0
            places.append({
                'name': clean_name(r[idx['NAME']], state_name),
                'state': state_name,
                'geoid': r[idx['state']] + r[idx['place']],
                'population': pop,
            })
        sys.stderr.write('  %-22s %4d places\n' % (state_name, len(data) - 1))
    return places


def fetch_gazetteer():
    """GEOID -> (lat, lng, ss). GEOID is the exact join key, so this needs no
    name matching at all — unlike the old kelvins CSV (~85% name-match rate)."""
    buf = io.BytesIO(fetch_bytes(GAZ_URL.format(GAZ_YEAR)))
    out = {}
    with zipfile.ZipFile(buf) as z:
        with z.open(z.namelist()[0]) as f:
            text = io.TextIOWrapper(f, encoding='latin-1')
            # Vintage varies: 2023 is tab-delimited with trailing padding spaces
            # baked into the last column's header; later years switched to '|'
            # with no padding. Detect rather than pin to one, so GAZ_YEAR can
            # move independently of this still working.
            first_line = text.readline()
            delim = '|' if '|' in first_line else '\t'
            reader = csv.DictReader(io.StringIO(first_line + text.read()), delimiter=delim)
            reader.fieldnames = [fn.strip() for fn in reader.fieldnames]
            for r in reader:
                out[r['GEOID'].strip()] = (r['INTPTLAT'].strip(), r['INTPTLONG'].strip(), r['USPS'].strip())
    return out


def fetch_area_codes():
    """(city, state) -> set(codes), and per-state list of (point, code) for
    the nearest-neighbour fallback. Unchanged from the old script."""
    by_name, points = {}, {}
    for r in csv.reader(io.StringIO(fetch_text(AREA_URL))):
        if len(r) < 6:
            continue
        code, city, state = r[0].strip(), r[1].strip(), r[2].strip()
        by_name.setdefault((city.lower(), state.lower()), set()).add(code)
        try:
            points.setdefault(state.lower(), []).append(((float(r[4]), float(r[5])), code))
        except ValueError:
            pass
    return by_name, points


def main():
    places = fetch_acs_population(census_key())
    geo = fetch_gazetteer()
    by_name, points = fetch_area_codes()

    places.sort(key=lambda p: -p['population'])

    rows, stats, no_coords = [], {'exact': 0, 'near': 0, '': 0}, 0
    for i, p in enumerate(places, 1):
        g = geo.get(p['geoid'])
        lat, lng, ss = g if g else ('', '', STATE_ABBR.get(p['state'], ''))
        if not lat:
            no_coords += 1

        name_key = (p['name'].lower(), p['state'].lower())
        codes = sorted(by_name.get(name_key, []))
        src = 'exact' if codes else ''
        if not codes and lat:
            latlng = (float(lat), float(lng))
            near, best, bestd = {}, None, MAX_KM
            for pt, code in points.get(p['state'].lower(), []):
                d = haversine(latlng, pt)
                if d <= NEAR_KM:
                    near[code] = min(d, near.get(code, d))
                if d < bestd:
                    best, bestd = code, d
            if near:
                codes = sorted(near, key=lambda c: near[c])
                src = 'near'
            elif best:
                codes, src = [best], 'near'
        stats[src] += 1

        rows.append([i, p['name'], p['state'], ss, p['population'], lat, lng, ' '.join(codes), src])

    with open(OUT, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(['rank', 'city', 'state', 'ss', 'population', 'lat', 'lng', 'area_codes', 'ac_source'])
        w.writerows(rows)

    sys.stderr.write(
        'wrote %s — %d rows (%d missing coordinates); area codes: %d exact, %d nearest, %d none\n'
        % (OUT, len(rows), no_coords, stats['exact'], stats['near'], stats['']))


if __name__ == '__main__':
    main()
