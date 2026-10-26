#!/usr/bin/env python3
"""
One-time supplement to admin/infra/data/us_cities.csv: adds real municipalities
that Census's own ACS "place" geography structurally excludes.

Why this exists at all: build_cities.py (same directory) pulls every Census
*place* (incorporated city/town/village/borough + CDP) via ACS 5-Year. But
several categories of real, incorporated local government are coded by Census
as a *county subdivision* (COUSUB/MCD) instead, with NO separate place record —
confirmed live against api.census.gov for North Bergen NJ (a real township,
pop 61,202) and Staten Island (a real NYC borough, pop 492,734): neither has
a place record, only a county-subdivision one. This is structural, not a data
gap that a bigger ACS pull would fix — it would need a second whole API source
(COUSUB) merged in on top of the first.

Rather than add that second live source, this script merges in a third-party
gazetteer (SimpleMaps-style "US Cities Database", 2017 vintage, user-supplied —
not refetched by this script) FOR ONLY the government types Census's place
geography is known to exclude: Township, Town, Charter Township, Village,
Borough. It deliberately skips that file's 'CDP' and 'City' rows — Census
ACS place-level data (build_cities.py) already covers those completely and
more currently (2023 vs this file's 2017), and spot-checking the "new" CDP
rows in that file turned up the same name+state appearing twice with two
different populations (e.g. "El Sobrante, CA", "Red Rock, AZ") — signs of
stale/merged places, not real gaps worth trusting over the live Census pull.

Usage (run once against a specific gazetteer export; re-run is idempotent —
rows already present in the base CSV, by lower(name)+state code, are skipped):

    python3 admin/infra/data/merge_cousub_gap.py /path/to/gazetteer.xls

Only touches us_cities.csv. Re-run infra_cities_seed() (the panel's "Re-load
the city list" button) afterward to load the new rows into the live `cities`
table — this script does not touch the database.
"""
import csv, sys

INCLUDE_TYPES = {'Township', 'Town', 'Charter Township', 'Village', 'Borough'}

CSV_PATH = __file__.rsplit('/', 1)[0] + '/us_cities.csv'


def load_base():
    with open(CSV_PATH, newline='', encoding='utf-8') as f:
        rows = list(csv.DictReader(f))
    have = {(r['city'].lower(), r['ss'].lower()) for r in rows}
    return rows, have


def load_gazetteer(path):
    import xlrd
    wb = xlrd.open_workbook(path)
    ws = wb.sheet_by_index(0)
    header = ws.row_values(0)
    idx = {h: i for i, h in enumerate(header)}
    out = []
    for r in range(1, ws.nrows):
        row = ws.row_values(r)
        if row[idx['type']] not in INCLUDE_TYPES:
            continue
        out.append({
            'name': str(row[idx['name']]).strip(),
            'state': str(row[idx['state']]).strip(),
            'ss': str(row[idx['state_code']]).strip(),
            'population': int(row[idx['population']] or 0),
            'lat': row[idx['latitude']],
            'lng': row[idx['longitude']],
            'area_code': str(row[idx['area_code']] or '').strip(),
            'median_income': int(row[idx['median_income']]) if row[idx['median_income']] else None,
        })
    return out


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: python3 merge_cousub_gap.py /path/to/gazetteer.xls')

    base_rows, have = load_base()
    gaz_rows = load_gazetteer(sys.argv[1])

    added = []
    incomes = {}  # (name.lower(), ss.lower()) -> median_income, for the caller to backfill city_data with
    for g in gaz_rows:
        key = (g['name'].lower(), g['ss'].lower())
        if key in have:
            continue
        have.add(key)  # the gazetteer itself has a handful of same-name duplicates; keep the first
        codes = ' '.join(c.strip() for c in g['area_code'].split('/') if c.strip())
        added.append({
            'rank': 0,  # recomputed below
            'city': g['name'], 'state': g['state'], 'ss': g['ss'],
            'population': g['population'],
            'lat': ('%.5f' % g['lat']) if g['lat'] else '',
            'lng': ('%.5f' % g['lng']) if g['lng'] else '',
            'area_codes': codes, 'ac_source': 'exact' if codes else '',
        })
        if g['median_income']:
            incomes[key] = g['median_income']

    all_rows = base_rows + added
    all_rows.sort(key=lambda r: -int(r['population'] or 0))
    for i, r in enumerate(all_rows, 1):
        r['rank'] = i

    with open(CSV_PATH, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=['rank', 'city', 'state', 'ss', 'population', 'lat', 'lng', 'area_codes', 'ac_source'])
        w.writeheader()
        w.writerows(all_rows)

    income_path = '/tmp/cousub_gap_incomes.csv'
    with open(income_path, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(['city', 'ss', 'median_income'])
        for (name, ss), income in incomes.items():
            w.writerow([name, ss, income])

    sys.stderr.write(
        'wrote %s — %d rows total (%d added from %s)\n'
        'wrote %s — %d rows with a median_income to backfill into city_data\n'
        % (CSV_PATH, len(all_rows), len(added), sys.argv[1], income_path, len(incomes)))


if __name__ == '__main__':
    main()
