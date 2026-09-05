"""
The SEO validator — "a few key simple validations," deliberately not a heavyweight QA
pipeline and deliberately NOT a performance/Lighthouse gate (speed is handled by building
disciplined templates, not by auditing them after the fact). Advisory only: this never blocks
phase 5 deploy — the batch page's own separate Generate/Upload buttons are already the
"look before you deploy" safety net.
"""

import json
import os
from datetime import datetime, timezone
from urllib.parse import urlparse

from bs4 import BeautifulSoup

TITLE_MIN, TITLE_MAX = 10, 70
META_MIN, META_MAX = 50, 165


def validate_site(out_dir, domain, canonical_base, pages, batch_domains=None):
    """
    pages: the list render.py's render_site() returns — [{'url','kind','expected_schema_type'}]
    batch_domains: every OTHER domain in this batch (not including this site's own), for the
    cross-site-link hard rule. None/empty = skip that check.
    """
    batch_domains = set(batch_domains or []) - {domain}
    errors, warnings = [], []
    titles, metas = {}, {}
    all_internal_hrefs = set()
    linked_targets = set()

    for p in pages:
        path = _file_path(out_dir, p['url'])
        if not os.path.isfile(path):
            errors.append(f"{p['url']}: no HTML file written")
            continue
        with open(path, encoding='utf-8') as fh:
            soup = BeautifulSoup(fh.read(), 'lxml')

        _check_h1(p['url'], soup, errors)
        _check_title_meta(p['url'], soup, titles, metas, errors, warnings)
        _check_canonical(p['url'], soup, canonical_base, errors)
        _check_schema(p['url'], soup, p['expected_schema_type'], errors, warnings)
        _check_images(p['url'], soup, errors)
        _check_faq_schema(p['url'], soup, errors)
        hrefs = _collect_links(soup, canonical_base, batch_domains, p['url'], errors)
        all_internal_hrefs |= hrefs
        linked_targets.add(p['url'])

    _check_uniqueness(titles, 'title', warnings)
    _check_uniqueness(metas, 'meta description', warnings)
    _check_link_graph(pages, all_internal_hrefs, warnings)

    return {
        'domain': domain,
        'page_count': len(pages),
        'errors': errors,
        'warnings': warnings,
        'checked_at': datetime.now(timezone.utc).isoformat(),
    }


def _file_path(out_dir, url):
    rel = url.strip('/')
    return os.path.join(out_dir, rel, 'index.html') if rel else os.path.join(out_dir, 'index.html')


def _check_h1(url, soup, errors):
    h1s = soup.find_all('h1')
    if len(h1s) != 1:
        errors.append(f"{url}: expected exactly 1 <h1>, found {len(h1s)}")


def _check_title_meta(url, soup, titles, metas, errors, warnings):
    title_tag = soup.find('title')
    title = title_tag.get_text(strip=True) if title_tag else ''
    if not title:
        errors.append(f"{url}: missing <title>")
    elif not (TITLE_MIN <= len(title) <= TITLE_MAX):
        warnings.append(f"{url}: title length {len(title)} outside {TITLE_MIN}-{TITLE_MAX} chars")
    titles[url] = title

    meta_tag = soup.find('meta', attrs={'name': 'description'})
    desc = meta_tag.get('content', '').strip() if meta_tag else ''
    if not desc:
        errors.append(f"{url}: missing meta description")
    elif not (META_MIN <= len(desc) <= META_MAX):
        warnings.append(f"{url}: meta description length {len(desc)} outside {META_MIN}-{META_MAX} chars")
    metas[url] = desc


def _check_canonical(url, soup, canonical_base, errors):
    link = soup.find('link', rel='canonical')
    if not link or not link.get('href'):
        errors.append(f"{url}: missing canonical link")
        return
    href = link['href']
    if not href.startswith(canonical_base):
        errors.append(f"{url}: canonical points off-domain ({href})")


def _check_schema(url, soup, expected_type, errors, warnings):
    scripts = soup.find_all('script', attrs={'type': 'application/ld+json'})
    if not scripts:
        errors.append(f"{url}: no JSON-LD schema present")
        return
    for script in scripts:
        try:
            data = json.loads(script.string or '')
        except Exception as exc:
            errors.append(f"{url}: JSON-LD is not valid JSON ({exc})")
            continue
        nodes = data.get('@graph', [data]) if isinstance(data, dict) else []
        types = {n.get('@type') for n in nodes if isinstance(n, dict)}
        if expected_type and expected_type not in types:
            warnings.append(f"{url}: expected schema @type '{expected_type}', found {types or 'none'}")


def _check_faq_schema(url, soup, errors):
    has_faq_markup = bool(soup.select('.cs-accordion-item, [class*="faq"]'))
    if not has_faq_markup:
        return
    scripts = soup.find_all('script', attrs={'type': 'application/ld+json'})
    for script in scripts:
        try:
            data = json.loads(script.string or '')
        except Exception:
            continue
        nodes = data.get('@graph', [data]) if isinstance(data, dict) else []
        if any(isinstance(n, dict) and n.get('@type') == 'FAQPage' for n in nodes):
            return
    errors.append(f"{url}: page has visible FAQ content but no FAQPage schema")


def _check_images(url, soup, errors):
    seen_alt = set()
    for img in soup.find_all('img'):
        if img.get('alt') is None:
            errors.append(f"{url}: <img src={img.get('src')}> has no alt attribute at all")
            continue
        alt = img['alt'].strip()
        if not alt:
            continue  # empty alt is valid for decorative images
        if alt in seen_alt:
            errors.append(f"{url}: duplicate alt text on this page: '{alt}'")
        seen_alt.add(alt)


def _check_uniqueness(values_by_url, label, warnings):
    seen = {}
    for url, value in values_by_url.items():
        if not value:
            continue
        if value in seen:
            warnings.append(f"{label} duplicated between {seen[value]} and {url}: '{value}'")
        else:
            seen[value] = url


def _collect_links(soup, canonical_base, batch_domains, url, errors):
    internal = set()
    for a in soup.find_all('a', href=True):
        href = a['href']
        if href.startswith(('tel:', 'mailto:', '#')):
            continue
        if href.startswith('http'):
            host = urlparse(href).netloc
            if host and host != urlparse(canonical_base).netloc:
                if host in batch_domains:
                    errors.append(f"{url}: links to another site in the same batch ({host}) — never allowed")
                continue
        if href.startswith('/'):
            internal.add(href.rstrip('/') + '/' if href != '/' else '/')
    return internal


def _check_link_graph(pages, all_internal_hrefs, warnings):
    known = {p['url'] for p in pages}
    dangling = {h for h in all_internal_hrefs if h not in known}
    for h in sorted(dangling):
        warnings.append(f"internal link to {h} does not match any generated page")
    orphans = known - all_internal_hrefs - {'/'}
    for o in sorted(orphans):
        warnings.append(f"{o} is not linked from any other page on the site (orphan)")
