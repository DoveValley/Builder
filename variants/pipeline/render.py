"""
PASS 2 (part 1) — renders one site's config.json + content.json through its assigned
architecture into static HTML, at the exact output path the EXISTING phase-5 "Upload sites"
button already expects (ms_batch_output_dir()'s slug algorithm, reproduced in slugify() below)
so nothing on the PHP/deploy side needs to change.
"""

import json
import os
import re
import shutil

import jinja2

VARIANTS_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def slugify(domain):
    """Must match includes/multisite/batch.php's ms_batch_output_dir() EXACTLY:
    preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($domain))), trimmed of leading/trailing _."""
    s = re.sub(r'[^a-z0-9]+', '_', domain.strip().lower())
    return s.strip('_')


def _plain_text_summary(body_html, max_len):
    """Strips HTML tags before truncating — body_html is markup, a meta description attribute
    must be plain text, so a raw slice risks cutting mid-tag and leaking markup into <head>."""
    text = re.sub(r'<[^>]+>', ' ', body_html or '')
    text = re.sub(r'\s+', ' ', text).strip()
    return text[:max_len]


def _substitute(template, subs):
    """Leaves unknown {tokens} intact rather than raising — same convention generate.py's
    substitute_vars() already uses, so a missing key fails visibly (unreplaced text) instead
    of crashing a whole batch run."""
    def repl(m):
        key = m.group(1)
        return str(subs.get(key, m.group(0)))
    return re.sub(r'\{(\w+)\}', repl, template)


def load_json(path, default=None):
    if os.path.isfile(path):
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    return default if default is not None else {}


def load_architecture(architecture_id):
    arch_dir = os.path.join(VARIANTS_ROOT, 'architectures', architecture_id)
    manifest = load_json(os.path.join(arch_dir, 'manifest.json'))
    env = jinja2.Environment(
        loader=jinja2.FileSystemLoader(os.path.join(arch_dir, 'templates')),
        autoescape=False,  # content is already-authored HTML (body_html fields); title/meta are plain strings we control
    )
    return arch_dir, manifest, env


def resolve_title(titles, page_kind, subs):
    pattern = titles.get('patterns', {}).get(page_kind, '{business}')
    meta_pattern = titles.get('meta_patterns', {}).get(page_kind, '')
    return _substitute(pattern, subs), _substitute(meta_pattern, subs)


def build_schema(schema_type, site, page, canonical_url):
    node = _build_schema_node(schema_type, site, page, canonical_url)
    if page.get('kind') == 'home' and site.get('faqs'):
        faq_node = _build_faqpage_schema(site, page)
        if faq_node:
            return {'@context': 'https://schema.org', '@graph': [node, faq_node]}
    return {'@context': 'https://schema.org', **node}


def _build_faqpage_schema(site, page):
    content = page.get('_content_ref')  # set by render_page below
    if not content:
        return None
    entries = []
    for faq in site['faqs']:
        f = content.get('faqs', {}).get(faq['id'], {})
        if f.get('question') and f.get('answer'):
            entries.append({'@type': 'Question', 'name': f['question'],
                             'acceptedAnswer': {'@type': 'Answer', 'text': f['answer']}})
    if not entries:
        return None
    return {'@type': 'FAQPage', 'mainEntity': entries}


def _build_schema_node(schema_type, site, page, canonical_url):
    identity = site['identity']
    if schema_type == 'LocalBusiness':
        node = {
            '@type': 'LocalBusiness',
            '@id': f"{canonical_url}#localbusiness",
            'name': identity['business'],
            'url': canonical_url,
            'telephone': identity.get('phone'),
        }
        if identity.get('address'):
            node['address'] = {'@type': 'PostalAddress', 'streetAddress': identity.get('address'),
                                 'addressLocality': page.get('city', {}).get('name') if page.get('city') else None,
                                 'addressRegion': page.get('city', {}).get('SS') if page.get('city') else None,
                                 'postalCode': identity.get('zip')}
        if identity.get('lat') and identity.get('lng'):
            node['geo'] = {'@type': 'GeoCoordinates', 'latitude': identity['lat'], 'longitude': identity['lng']}
        return node
    if schema_type == 'Service':
        return {
            '@type': 'Service',
            'name': page.get('service', {}).get('name'),
            'provider': {'@type': 'LocalBusiness', 'name': identity['business']},
            'areaServed': page.get('city', {}).get('name') if page.get('city') else None,
        }
    if schema_type == 'Article':
        return {
            '@type': 'Article',
            'headline': page.get('title_h1') or page.get('title'),
            'author': {'@type': 'Organization', 'name': identity['business']},
        }
    return {'@type': 'WebPage', 'name': page.get('title')}


def _write(out_dir, rel_path, html):
    full_dir = os.path.join(out_dir, rel_path)
    os.makedirs(full_dir, exist_ok=True)
    with open(os.path.join(full_dir, 'index.html'), 'w', encoding='utf-8') as fh:
        fh.write(html)


def render_site(config, content, type_json, color_json, titles_json, batch_output_dir):
    """Renders every page-kind for one site into {batch_output_dir}/{slug}/. Returns the list
    of relative URLs written (used for the sitemap and by seo_validate.py's link-check)."""
    domain = config['domain']
    slug = slugify(domain)
    out_dir = os.path.join(batch_output_dir, slug)
    canonical_base = f"https://{domain}"

    arch_dir, manifest, env = load_architecture(config['variant']['architecture'])
    page_kinds = manifest['page_kinds']
    urls_written = []

    def render_page(kind, rel_path, page_extra):
        canonical_url = canonical_base + '/' + (rel_path.rstrip('/') + '/' if rel_path else '')
        title, meta_description = page_extra.get('_title_override', (None, None))
        page = {
            'kind': kind,
            'canonical_url': canonical_url,
            'title': title,
            'meta_description': meta_description,
            '_content_ref': content,
            **page_extra,
        }
        expected_schema_type = page_kinds[kind]['schema_type']
        page['schema_json'] = json.dumps(build_schema(expected_schema_type, config, page, canonical_url))
        html = env.get_template(page_kinds[kind]['template']).render(site=config, content=content, type=type_json, color=color_json, page=page)
        _write(out_dir, rel_path, html)
        url = '/' + rel_path.rstrip('/') + '/' if rel_path else '/'
        urls_written.append({'url': url, 'kind': kind, 'expected_schema_type': expected_schema_type})

    subs_base = {
        'business': config['identity']['business'],
        'service_noun': config['niche']['service_noun'],
        'service_noun_title': config['niche']['service_noun'].title(),
    }
    single_city = len(config['cities']) == 1
    per_city_services = bool(manifest.get('per_city_services')) and config['services']['mode'] == 'flat'
    # A single-city site's site-wide "/services/{slug}/" page and its "/cities/{city}/services/
    # {slug}/" page would otherwise be near-identical duplicate-content pages (the validator
    # caught exactly this on the first real test render) — when there's only one city, the
    # per-city page IS the service's one canonical page; no separate top-level page is needed.
    skip_site_wide_services = per_city_services and single_city
    for svc in config['services'].get('items', []):
        if skip_site_wide_services:
            svc['url'] = f"/cities/{config['cities'][0]['slug']}/services/{svc['slug']}/"
        else:
            svc['url'] = f"/services/{svc['slug']}/"

    # Home
    subs = dict(subs_base, city=config['cities'][0]['name'] if config['cities'] else '', SS=config['cities'][0]['SS'] if config['cities'] else '')
    render_page('home', '', {'_title_override': resolve_title(titles_json, 'home', subs)})

    # City hubs
    for city in config['cities']:
        subs = dict(subs_base, city=city['name'], SS=city['SS'])
        render_page('city_hub', f"cities/{city['slug']}", {'city': city, '_title_override': resolve_title(titles_json, 'city_hub', subs)})

        if per_city_services:
            for svc in config['services']['items']:
                subs2 = dict(subs, service_name=svc['name'], service_short_desc=svc.get('short_desc', ''))
                render_page('service', f"cities/{city['slug']}/services/{svc['slug']}",
                            {'service': svc, 'city': city, '_title_override': resolve_title(titles_json, 'service', subs2)})

    # Site-wide service pages — skipped entirely for a single-city site (see above)
    if config['services']['mode'] == 'flat' and not skip_site_wide_services:
        for svc in config['services']['items']:
            subs = dict(subs_base, city=config['cities'][0]['name'] if config['cities'] else '',
                        SS=config['cities'][0]['SS'] if config['cities'] else '',
                        service_name=svc['name'], service_short_desc=svc.get('short_desc', ''))
            render_page('service', f"services/{svc['slug']}", {'service': svc, 'city': None,
                        '_title_override': resolve_title(titles_json, 'service', subs)})

    # Guides
    cities_by_id = {c['id']: c for c in config['cities']}
    for guide in config['pages']['guides']:
        city = cities_by_id.get(guide.get('city_id'))
        g_content = content.get('guides', {}).get(guide['slug'], {})
        subs = dict(subs_base, guide_title=g_content.get('title', guide['topic']),
                    guide_summary=_plain_text_summary(g_content.get('body_html', ''), 150))
        render_page('guide', f"guides/{guide['slug']}", {'guide': guide, 'city': city,
                    'title_h1': g_content.get('title'), '_title_override': resolve_title(titles_json, 'guide', subs)})

    # Legal
    legal_labels = {'privacy': 'Privacy Policy', 'terms': 'Terms of Service', 'disclaimer': 'Disclaimer', 'about': 'About Us'}
    for key, meta in config['pages']['legal'].items():
        legal_body = content.get('legal', {}).get(key, {}).get('body_html', '')
        fallback = f"{legal_labels.get(key, key)} for {config['identity']['business']}."
        subs = dict(subs_base, legal_title=legal_labels.get(key, key),
                    legal_summary=_plain_text_summary(legal_body, 155) or fallback)
        render_page('legal', meta['slug'], {'legal_key': key, 'title_h1': legal_labels.get(key, key),
                    '_title_override': resolve_title(titles_json, 'legal', subs)})

    # Contact — a fixed utility page every architecture must provide (linked from nav/footer)
    subs = dict(subs_base, legal_title='Contact Us', legal_summary=f"Contact {config['identity']['business']} directly by phone or email.")
    render_page('contact', 'contact-us', {'title_h1': 'Contact Us', '_title_override': resolve_title(titles_json, 'legal', subs)})

    # Static asset: the architecture's own stylesheet
    shutil.copyfile(os.path.join(arch_dir, manifest['css_file']), os.path.join(out_dir, 'style.css'))

    write_sitemap(out_dir, canonical_base, urls_written)
    write_robots(out_dir, canonical_base)
    write_404(out_dir, env, config, content, type_json, color_json, page_kinds)

    return urls_written


def write_sitemap(out_dir, canonical_base, pages):
    entries = "\n".join(f"  <url><loc>{canonical_base}{p['url']}</loc></url>" for p in pages)
    xml = f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{entries}\n</urlset>\n'
    with open(os.path.join(out_dir, 'sitemap.xml'), 'w', encoding='utf-8') as fh:
        fh.write(xml)


def write_robots(out_dir, canonical_base):
    with open(os.path.join(out_dir, 'robots.txt'), 'w', encoding='utf-8') as fh:
        fh.write(f"User-agent: *\nAllow: /\nSitemap: {canonical_base}/sitemap.xml\n")


def write_404(out_dir, env, config, content, type_json, color_json, page_kinds):
    try:
        tmpl = env.get_template('404.html.j2')
    except jinja2.TemplateNotFound:
        html = "<!DOCTYPE html><html><head><title>Page Not Found</title></head><body><h1>Page Not Found</h1></body></html>"
    else:
        page = {'kind': '404', 'title': 'Page Not Found', 'meta_description': '', 'canonical_url': '', 'schema_json': None}
        html = tmpl.render(site=config, content=content, type=type_json, color=color_json, page=page)
    with open(os.path.join(out_dir, '404.html'), 'w', encoding='utf-8') as fh:
        fh.write(html)
