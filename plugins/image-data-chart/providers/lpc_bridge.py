#!/usr/bin/env python3
"""
Thin bridge so LPC (PHP) can call this module's real fetch_city() without
reimplementing any of its NOAA-lookup logic — same "load by file path, not
package import" approach generate.py's own _apply_chart_fetches() already
uses for this exact module. Reads one JSON request from stdin
({"city": {...}, "decls": [...]}), writes one JSON response to stdout
({"updates": {...}, "problems": [...]}). Never estimates; see datasets.py's
own header for why.
"""
import sys, json, os, importlib.util

_here = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location(
    'ms_chart_datasets', os.path.join(_here, 'datasets.py'))
noaa_normals = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(noaa_normals)

req = json.load(sys.stdin)
decls = req.get('decls')
if decls is None:
    # A niche slug is simpler for a caller than hand-building decls itself,
    # and chart_fetch_decls() already carries the "don't drop the benchmark
    # block" fix documented in its own comment in datasets.py.
    decls = noaa_normals.chart_fetch_decls(req.get('niche', ''))
updates, problems = noaa_normals.fetch_city(req.get('city', {}), decls)
print(json.dumps({'updates': updates, 'problems': problems}))
