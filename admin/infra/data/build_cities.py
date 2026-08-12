#!/usr/bin/env python3
"""
Rebuild admin/infra/data/us_cities.csv — the seed list behind the Cities/Niche tab.

Run it from anywhere; it writes next to itself. Needs network access.

    python3 admin/infra/data/build_cities.py

Three public sources, joined:
  1. US Census sub-est2024  — incorporated places + 2024 population estimate.
     This is what decides the rank: 1 = largest. Public domain.
  2. kelvins/US-Cities-Database — latitude/longitude and the 2-letter state code.
     Matches ~85% of places; the rest keep blank coordinates.
  3. ravisorg/Area-Code-Geolocation-Database — area code by city.
     An exact city+state hit is marked `exact`. Everything else borrows from
     area-code cities nearby and is marked `near` — a suggestion to pick from, not
     a fact, which is why the source is recorded per row.

     Neighbours are restricted to the SAME STATE, and every code within NEAR_KM is
     collected rather than just the closest one. Without the state restriction New
     York City drew 201 (New Jersey); without collecting the cluster it drew one
     code where a metro really has four.

Output columns: rank,city,state,ss,population,lat,lng,area_codes,ac_source
"""
import csv, io, math, os, sys, urllib.request

TOP_N   = 10000  # rank 1 (largest) through 10000, per the tab's spec
NEAR_KM = 25     # collect every in-state area code within this radius
MAX_KM  = 120    # if that finds nothing, borrow the single closest in-state code

CENSUS = 'https://www2.census.gov/programs-surveys/popest/datasets/2020-2024/cities/totals/sub-est2024.csv'
GEO    = 'https://raw.githubusercontent.com/kelvins/US-Cities-Database/main/csv/us_cities.csv'
AREA   = 'https://raw.githubusercontent.com/ravisorg/Area-Code-Geolocation-Database/master/us-area-code-cities.csv'

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'us_cities.csv')

# Census place names carry their legal type; "Jersey City city" is not a city called that.
SUFFIXES = (' city', ' town', ' village', ' borough', ' township', ' municipality', ' CDP')
GOV      = (' metropolitan government', ' consolidated government', ' unified government', ' metro government')


def clean_name(n):
    n = n.replace(' (balance)', '')
    for g in GOV:
        n = n.replace(g, '')
    for s in SUFFIXES:
        if n.endswith(s):
            return n[:-len(s)]
    return n


def fetch(url, encoding='utf-8'):
    sys.stderr.write('fetching %s\n' % url)
    with urllib.request.urlopen(url, timeout=120) as r:
        return io.StringIO(r.read().decode(encoding, 'replace'))


def haversine(a, b):
    lat1, lon1 = a
    lat2, lon2 = b
    p = math.pi / 180
    h = (0.5 - math.cos((lat2 - lat1) * p) / 2
         + math.cos(lat1 * p) * math.cos(lat2 * p) * (1 - math.cos((lon2 - lon1) * p)) / 2)
    return 12742 * math.asin(math.sqrt(h))


def main():
    # 1. population + rank
    places = [r for r in csv.DictReader(fetch(CENSUS, 'latin-1')) if r['SUMLEV'] == '162']
    places.sort(key=lambda r: -int(r['POPESTIMATE2024'] or 0))
    places = places[:TOP_N]

    # 2. coordinates + state abbreviation
    geo, ss = {}, {}
    for r in csv.DictReader(fetch(GEO)):
        key = (r['CITY'].strip().lower(), r['STATE_NAME'].strip().lower())
        geo.setdefault(key, (float(r['LATITUDE']), float(r['LONGITUDE'])))
        ss[r['STATE_NAME'].strip().lower()] = r['STATE_CODE']

    # 3. area codes, both by name and by position (positions bucketed by state)
    by_name, points = {}, {}
    for r in csv.reader(fetch(AREA)):
        if len(r) < 6:
            continue
        code, city, state = r[0].strip(), r[1].strip(), r[2].strip()
        by_name.setdefault((city.lower(), state.lower()), set()).add(code)
        try:
            points.setdefault(state.lower(), []).append(((float(r[4]), float(r[5])), code))
        except ValueError:
            pass

    rows, stats = [], {'exact': 0, 'near': 0, '': 0}
    for i, p in enumerate(places, 1):
        name = clean_name(p['NAME'])
        state = p['STNAME']
        key = (name.lower(), state.lower())
        latlng = geo.get(key)

        codes = sorted(by_name.get(key, []))
        src = 'exact' if codes else ''
        if not codes and latlng:
            near, best, bestd = {}, None, MAX_KM
            for pt, code in points.get(state.lower(), []):
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

        rows.append([i, name, state, ss.get(state.lower(), ''),
                     int(p['POPESTIMATE2024'] or 0),
                     '%.5f' % latlng[0] if latlng else '',
                     '%.5f' % latlng[1] if latlng else '',
                     ' '.join(codes), src])

    with open(OUT, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(['rank', 'city', 'state', 'ss', 'population', 'lat', 'lng', 'area_codes', 'ac_source'])
        w.writerows(rows)

    sys.stderr.write('wrote %s — %d rows; area codes: %d exact, %d nearest, %d none\n'
                     % (OUT, len(rows), stats['exact'], stats['near'], stats['']))


if __name__ == '__main__':
    main()
