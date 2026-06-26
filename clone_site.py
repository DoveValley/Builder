#!/usr/bin/env python3
"""
clone_site.py — Clone a site in the homepage-builder CMS.

Usage:
  python3 clone_site.py --src granitepmacademy --name "New PM Academy"
  python3 clone_site.py --src granitepmacademy --name "New PM Academy" --mode template
  python3 clone_site.py --src granitepmacademy --dst newpmacademy --name "New PM Academy" --mode template

Modes:
  full      (default) Full data+media copy with path rewrites. Use for backups or dev copies.
  template  Full copy + clears AI-generated block content (unlocks ai_blocks), resets
            cities.json/courses.json/generation_log.json/page-index.json to empty.
            Use when spinning up a new client site from an existing one.

The source site's FTP deploy.json is never copied.
"""

import argparse
import glob
import json
import os
import re
import shutil
import sys
import time


# ── Terminal helpers ──────────────────────────────────────────────────────────

def _log(msg):  print(msg)
def _ok(msg):   print(f'\033[32m✓\033[0m  {msg}')
def _warn(msg): print(f'\033[33m!\033[0m  {msg}', file=sys.stderr)
def _err(msg):  print(f'\033[31m✗\033[0m  {msg}', file=sys.stderr)


# ── ID / name helpers ─────────────────────────────────────────────────────────

def slugify(name: str) -> str:
    s = name.lower().strip()
    s = re.sub(r'[^a-z0-9]+', '-', s)
    s = s.strip('-')
    return s or 'site'


def make_site_id(base_dir: str, name: str, preferred: str = '') -> str:
    sites_dir = os.path.join(base_dir, 'sites')
    candidate = preferred if preferred else slugify(name)
    if not re.match(r'^[a-z0-9][a-z0-9-]{0,59}$', candidate):
        candidate = slugify(candidate)
    if not os.path.isdir(os.path.join(sites_dir, candidate)):
        return candidate
    i = 2
    while os.path.isdir(os.path.join(sites_dir, f'{candidate}-{i}')):
        i += 1
    return f'{candidate}-{i}'


# ── Atomic JSON load/save ─────────────────────────────────────────────────────

def load_json(path):
    if not os.path.exists(path):
        return None
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return None


def save_json(path, data):
    content = json.dumps(data, indent=2, ensure_ascii=False)
    tmp = path + f'.tmp.{os.getpid()}'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(content)
    os.replace(tmp, path)


# ── Path rewriting ────────────────────────────────────────────────────────────

def rewrite_paths_in_dir(data_dir: str, src_id: str, dst_id: str):
    """Replace sites/{src}/uploads/ with sites/{dst}/uploads/ in all JSON files."""
    from_str = f'sites/{src_id}/uploads/'
    to_str   = f'sites/{dst_id}/uploads/'
    count = 0
    for path in glob.glob(os.path.join(data_dir, '**', '*.json'), recursive=True):
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        if from_str not in content:
            continue
        new_content = content.replace(from_str, to_str)
        tmp = path + f'.tmp.{os.getpid()}'
        with open(tmp, 'w', encoding='utf-8') as f:
            f.write(new_content)
        os.replace(tmp, path)
        count += 1
    return count


# ── Template mode: reset AI-generated content ─────────────────────────────────

AI_GENERATED_FIELDS = ('heading_text', 'text', '_ai_generated', '_ai_generated_at',
                        '_ai_model', '_ai_type', '_ai_locked')


def _reset_blocks(blocks: list) -> tuple[list, int]:
    """Clear generated content from ai_block entries. Returns (blocks, reset_count)."""
    count = 0
    for block in blocks:
        if block.get('type') != 'ai_block':
            continue
        if not block.get('_ai_generated') and not block.get('ai_type_id'):
            continue
        for field in AI_GENERATED_FIELDS:
            block.pop(field, None)
        # Keep structure fields: ai_type_id, ai_mode, ai_render_as, ai_model,
        # ai_inject_target, ai_inject_field, ai_inject_mode, ai_prompt_override
        count += 1
    return blocks, count


def reset_ai_content_in_dir(data_dir: str) -> int:
    """Walk all JSON files; clear generated content from ai_block entries."""
    total = 0
    for path in glob.glob(os.path.join(data_dir, '**', '*.json'), recursive=True):
        data = load_json(path)
        if data is None:
            continue
        changed = False

        # site.json: content_blocks at top level
        if isinstance(data, dict):
            blocks = data.get('content_blocks', [])
            if isinstance(blocks, list):
                _, n = _reset_blocks(blocks)
                if n:
                    changed = True
                    total += n
            # Also check pages{} array entries (core pages in site.json)
            for page in data.get('pages', {}).values() if isinstance(data.get('pages'), dict) else []:
                if isinstance(page, dict):
                    pb = page.get('content_blocks', [])
                    if isinstance(pb, list):
                        _, n = _reset_blocks(pb)
                        if n:
                            changed = True
                            total += n

        # Landing page files: content_blocks at top level
        elif isinstance(data, list):
            pass  # cities.json, courses.json — no ai_blocks

        if changed:
            save_json(path, data)

    return total


# ── Copy helpers ──────────────────────────────────────────────────────────────

def copy_dir(src: str, dst: str):
    if not os.path.isdir(src):
        return
    os.makedirs(dst, exist_ok=True)
    shutil.copytree(src, dst, dirs_exist_ok=True)


# ── Main clone logic ──────────────────────────────────────────────────────────

def clone_site(base_dir: str, src_id: str, dst_id: str, name: str, mode: str, dry_run: bool):
    sites_dir = os.path.join(base_dir, 'sites')
    src_dir   = os.path.join(sites_dir, src_id)
    dst_dir   = os.path.join(sites_dir, dst_id)

    _log(f'\n{"═"*54}')
    _log(f'clone_site.py')
    _log(f'  src  : {src_id}')
    _log(f'  dst  : {dst_id}  ("{name}")')
    _log(f'  mode : {mode}')
    _log(f'  dry  : {dry_run}')
    _log(f'{"═"*54}')

    if not os.path.isdir(src_dir):
        _err(f'Source site not found: {src_dir}')
        sys.exit(1)
    if os.path.isdir(dst_dir):
        _err(f'Destination already exists: {dst_dir}')
        sys.exit(1)

    if dry_run:
        _log('\n[dry-run] Would perform:')
        _log(f'  mkdir {dst_dir}/data')
        _log(f'  mkdir {dst_dir}/uploads')
        _log(f'  copy  {src_dir}/data → {dst_dir}/data')
        _log(f'  copy  {src_dir}/uploads → {dst_dir}/uploads')
        _log(f'  rewrite paths: sites/{src_id}/uploads/ → sites/{dst_id}/uploads/')
        _log(f'  delete deploy.json (never clone FTP credentials)')
        if mode == 'template':
            _log('  [template] clear ai_block generated content in all JSON files')
            _log('  [template] reset cities.json → []')
            _log('  [template] reset courses.json → []')
            _log('  [template] reset generation_log.json → []')
            _log('  [template] reset page-index.json → {}')
        _log(f'  write meta.json: name="{name}"')
        return

    # ── 1. Create directory structure ──────────────────────────────────────────
    os.makedirs(os.path.join(dst_dir, 'data'), exist_ok=True)
    os.makedirs(os.path.join(dst_dir, 'uploads', 'media'), exist_ok=True)
    # Protect data/ from direct web access
    with open(os.path.join(dst_dir, 'data', '.htaccess'), 'w') as f:
        f.write('Require all denied\n')
    _ok('Created directory structure')

    # ── 2. Copy data/ ──────────────────────────────────────────────────────────
    src_data = os.path.join(src_dir, 'data')
    dst_data = os.path.join(dst_dir, 'data')
    if os.path.isdir(src_data):
        shutil.copytree(src_data, dst_data, dirs_exist_ok=True)
    _ok(f'Copied data/')

    # ── 3. Copy uploads/ ───────────────────────────────────────────────────────
    src_uploads = os.path.join(src_dir, 'uploads')
    dst_uploads = os.path.join(dst_dir, 'uploads')
    if os.path.isdir(src_uploads):
        shutil.copytree(src_uploads, dst_uploads, dirs_exist_ok=True)
    _ok(f'Copied uploads/')

    # ── 4. Rewrite upload paths in all JSON files ──────────────────────────────
    n = rewrite_paths_in_dir(dst_data, src_id, dst_id)
    _ok(f'Rewrote upload paths in {n} file(s)')

    # ── 5. Strip FTP credentials ───────────────────────────────────────────────
    for fname in ('deploy.json', 'deploy_manifest.json'):
        p = os.path.join(dst_dir, 'data', fname)
        if os.path.exists(p):
            os.remove(p)
    _ok('Stripped deploy credentials (deploy.json)')

    # ── 6. Template-mode resets ────────────────────────────────────────────────
    if mode == 'template':
        n = reset_ai_content_in_dir(dst_data)
        _ok(f'Cleared AI-generated content from {n} ai_block(s)')

        for fname, empty in [
            ('cities.json',        []),
            ('courses.json',       []),
            ('generation_log.json',[]),
            ('page-index.json',    {}),
        ]:
            p = os.path.join(dst_data, fname)
            save_json(p, empty)
        _ok('Reset cities.json, courses.json, generation_log.json, page-index.json')

    # ── 7. Write meta.json ─────────────────────────────────────────────────────
    meta = {
        'name':       name,
        'created_at': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'updated_at': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'cloned_from': src_id,
        'clone_mode':  mode,
    }
    save_json(os.path.join(dst_dir, 'meta.json'), meta)
    _ok(f'Wrote meta.json')

    _log(f'\n{"═"*54}')
    _ok(f'Clone complete: sites/{dst_id}')
    _log(f'{"═"*54}')
    if mode == 'template':
        _log(f'\nNext steps:')
        _log(f'  1. Add cities to sites/{dst_id}/data/cities.json')
        _log(f'  2. Update site_vars / theme / header in the admin')
        _log(f'  3. python3 generate.py --site {dst_id} --research --all')


# ── CLI ───────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description='Clone a homepage-builder site')
    ap.add_argument('--src',     required=True,  help='Source site ID (e.g. granitepmacademy)')
    ap.add_argument('--name',    required=True,  help='Display name for the new site')
    ap.add_argument('--dst',     default='',     help='Destination site ID (auto-generated from name if omitted)')
    ap.add_argument('--mode',    default='full', choices=['full', 'template'],
                    help='full: copy everything (default)  |  template: copy + reset AI content + empty cities/courses')
    ap.add_argument('--dry-run', action='store_true', dest='dry_run',
                    help='Show what would be done without making any changes')
    args = ap.parse_args()

    base_dir = os.path.dirname(os.path.abspath(__file__))
    dst_id   = make_site_id(base_dir, args.name, preferred=args.dst)

    clone_site(base_dir, args.src, dst_id, args.name, args.mode, args.dry_run)


if __name__ == '__main__':
    main()
