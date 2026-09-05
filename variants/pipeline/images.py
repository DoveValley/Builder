"""
Image pipeline for the variant engine — Pillow only, no ImageMagick subprocess and no
matplotlib. Everything here re-implements the *idea* proven in includes/multisite/
image_overlay.php (EXIF strip, per-site unique renaming, seeded perturbation to defeat
duplicate-image detection) natively in Python, since the new render layer never touches
content_blocks and there's no reason to shell into the old PHP pipeline.

Hero-text-baked-onto-photo is kept as a capability (stamp_hero_text) but is never called
unless an architecture's manifest.json explicitly sets "stamp_hero": true — the pilot
architecture (classic-service) leaves it off, per the standing decision that baked pixel text
is a template-farm tell with zero SEO value.
"""

import difflib
import hashlib
import json
import os

from PIL import Image, ImageDraw, ImageEnhance, ImageFont

import claude_client as cc

RESPONSIVE_WIDTHS = [400, 800, 1200, 1600]


def strip_exif(im):
    """Pillow doesn't carry EXIF forward unless you explicitly re-attach it — so a plain
    re-save already strips it. This wrapper exists so the intent is a named, visible step
    (matches the old pipeline's explicit -strip flag) rather than an implicit side effect."""
    data = list(im.getdata())
    clean = Image.new(im.mode, im.size)
    clean.putdata(data)
    return clean


def _seed_int(seed_str, salt=''):
    return int(hashlib.sha256(f"{salt}|{seed_str}".encode('utf-8')).hexdigest()[:8], 16)


def perturb(im, seed_str):
    """Deterministic per-domain micro-crop + brightness/contrast jitter so no two sites in a
    batch share a byte-identical photo. Small enough to be visually unnoticeable."""
    seed = _seed_int(seed_str, 'perturb')
    w, h = im.size
    crop_pct = 0.01 + (seed % 300) / 10000.0  # 1.0% - 4.0%
    dx, dy = int(w * crop_pct), int(h * crop_pct)
    # Crop from a seed-chosen edge so the perturbation isn't always the same corner.
    edge = seed % 4
    box = {
        0: (dx, dy, w, h),
        1: (0, dy, w - dx, h),
        2: (0, 0, w - dx, h - dy),
        3: (dx, 0, w, h - dy),
    }[edge]
    im = im.crop(box).resize((w, h))
    brightness = 0.96 + ((seed >> 8) % 80) / 1000.0   # 0.96 - 1.04
    contrast = 0.96 + ((seed >> 16) % 80) / 1000.0
    im = ImageEnhance.Brightness(im).enhance(brightness)
    im = ImageEnhance.Contrast(im).enhance(contrast)
    return im


def unique_rename(original_name, site_slug, city_slug=None, role=None):
    base, ext = os.path.splitext(os.path.basename(original_name))
    parts = [base, site_slug]
    if city_slug:
        parts.append(city_slug)
    name = "-".join(parts)
    if role == 'hero':
        sig = hashlib.md5(f"{name}|{role}".encode('utf-8')).hexdigest()[:8]
        name = f"{name}__{sig}"
    return f"{name}.webp"


def to_webp_responsive(im, out_dir, base_name, widths=None, quality=82):
    """Writes {base_name}-{width}.webp for each width (never upscaling past the source), plus
    a plain {base_name}.webp at the source's own width as the non-responsive fallback src.
    Returns the manifest entry render.py needs to build a <picture>/srcset."""
    widths = widths or RESPONSIVE_WIDTHS
    os.makedirs(out_dir, exist_ok=True)
    src_w = im.size[0]
    written = []
    for w in [x for x in widths if x <= src_w] or [src_w]:
        h = int(im.size[1] * (w / src_w))
        resized = im.resize((w, h), Image.LANCZOS)
        fname = f"{base_name}-{w}.webp"
        resized.save(os.path.join(out_dir, fname), format='WEBP', quality=quality)
        written.append({'width': w, 'file': fname})
    im.save(os.path.join(out_dir, f"{base_name}.webp"), format='WEBP', quality=quality)
    return {'default': f"{base_name}.webp", 'sizes': written}


def stamp_hero_text(im, line1, line2, seed_str, font_path=None):
    """Kept for architectures that opt in via manifest.json's stamp_hero flag. Off by default."""
    im = im.convert('RGBA')
    overlay = Image.new('RGBA', im.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    try:
        font1 = ImageFont.truetype(font_path or "DejaVuSans-Bold.ttf", size=int(im.size[1] * 0.08))
        font2 = ImageFont.truetype(font_path or "DejaVuSans-Bold.ttf", size=int(im.size[1] * 0.05))
    except Exception:
        font1 = font2 = ImageFont.load_default()
    draw.rectangle([0, im.size[1] - int(im.size[1] * 0.22), im.size[0], im.size[1]], fill=(0, 0, 0, 140))
    draw.text((24, im.size[1] - int(im.size[1] * 0.20)), line1, font=font1, fill=(255, 255, 255, 255))
    draw.text((24, im.size[1] - int(im.size[1] * 0.09)), line2, font=font2, fill=(255, 255, 255, 255))
    return Image.alpha_composite(im, overlay).convert('RGB')


def draw_city_stat_chart(stats, color, out_path, width=640, height=360):
    """A simple horizontal bar chart drawn directly from real per-city research numbers, in
    that site's own palette — no matplotlib dependency needed for something this simple."""
    im = Image.new('RGB', (width, height), color.get('bg_alt', '#eeeeee'))
    draw = ImageDraw.Draw(im)
    try:
        font = ImageFont.truetype("DejaVuSans.ttf", size=16)
        font_bold = ImageFont.truetype("DejaVuSans-Bold.ttf", size=18)
    except Exception:
        font = font_bold = ImageFont.load_default()

    values = [s.get('value') or 0 for s in stats]
    max_val = max(values) if values and max(values) > 0 else 1
    bar_h = min(48, (height - 40) // max(len(stats), 1) - 12)
    y = 20
    for stat in stats[:6]:
        val = stat.get('value') or 0
        bar_w = int((width - 220) * (val / max_val))
        draw.rectangle([200, y, 200 + bar_w, y + bar_h], fill=color.get('primary', '#0b3d5c'))
        draw.text((10, y + bar_h // 2 - 8), str(stat.get('label', ''))[:26], font=font, fill=color.get('text', '#111'))
        draw.text((205 + bar_w, y + bar_h // 2 - 9), f"{val}{stat.get('unit', '')}", font=font_bold, fill=color.get('text', '#111'))
        y += bar_h + 12

    im.save(out_path, format='WEBP', quality=90)
    return out_path


def chart_alt_text(stats, city_name):
    """Alt text built directly from the same data used to draw the chart — no vision call
    needed, and it can never mismatch what's actually drawn."""
    labels = ", ".join(f"{s.get('label')}: {s.get('value')}{s.get('unit', '')}" for s in stats[:6])
    return f"Chart of local statistics for {city_name}: {labels}"


def vision_alt_text(image_path, page_context, api_key, dry_run=False):
    """Describes the ACTUAL photo content, never guessed from where it's placed on the page."""
    prompt = (
        "Describe this photo in one concise sentence suitable as HTML alt text for a "
        f"{page_context} page. Describe only what is literally visible — do not invent a "
        "location, brand, or service context that isn't shown in the image."
    )
    return cc.call_claude_vision(image_path, prompt, cc.VISION_MODEL, api_key, dry_run=dry_run)


# ── Alt-text uniqueness across a batch (footprint check, not just per-page) ──────────────

def load_alt_registry(path):
    if os.path.isfile(path):
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    return {'entries': []}


def save_alt_registry(path, registry):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(registry, fh, indent=2)
    os.replace(tmp, path)


def register_alt_text(registry, domain, image_id, alt_text, similarity_threshold=0.88):
    """Flags (does not silently rewrite) a near-duplicate alt string reused across a different
    domain in the same batch — the same footprint concern as the photo bytes themselves, just
    in text form. Returns a list of flagged duplicates (empty = fine)."""
    flags = []
    for entry in registry['entries']:
        if entry['domain'] == domain:
            continue
        ratio = difflib.SequenceMatcher(None, entry['alt_text'].lower(), alt_text.lower()).ratio()
        if ratio >= similarity_threshold:
            flags.append({'domain': entry['domain'], 'image_id': entry['image_id'], 'similarity': round(ratio, 3)})
    registry['entries'].append({'domain': domain, 'image_id': image_id, 'alt_text': alt_text})
    return flags


def decorative_alt(role):
    """Decorative images (icons, dividers, background textures) get alt="" — a real
    accessibility rule, not an oversight."""
    return role in ('icon', 'divider', 'decoration')
