<?php
/**
 * includes/wp_builder_profiles/pluscities.php — the PlusCities marketing
 * homepage, built per the Nov 19 2026 build spec. First "custom" profile:
 * every block here is scoped to WPB_PLUSCITIES only (not shared) until one
 * is genuinely reused on a second bespoke build. Homepage only, per the
 * spec's own build order ("stop and show Scott" before the other routes).
 *
 * Placeholders deliberately left visible, per the spec's own instruction —
 * see the bottom of this file for the full list.
 */

const WPB_PLUSCITIES = 'pluscities';

function wpb_pc_normalize(array $post): array
{
    return [
        'business_name'    => 'PlusCities',
        'lead_email'       => trim((string) ($post['lead_email'] ?? '')),
        'partner_discount' => trim((string) ($post['partner_discount'] ?? '')) ?: '[PARTNER DISCOUNT]',
        'stripe_starter'   => trim((string) ($post['stripe_starter'] ?? '')) ?: '#',
        'stripe_growth'    => trim((string) ($post['stripe_growth'] ?? '')) ?: '#',
        'stripe_dominate'  => trim((string) ($post['stripe_dominate'] ?? '')) ?: '#',
        'turnaround'       => trim((string) ($post['turnaround'] ?? '')) ?: '[TURNAROUND]',
        'calendar_link'    => trim((string) ($post['calendar_link'] ?? '')) ?: '#',
    ];
}

/** The founder-video poster, reused as-is on both the homepage hero and the How It Works page — same content, same loads-on-click behavior, written once. */
function pc_founder_video_block(): string
{
    return '
    <div class="pc-video-wrap">
      <button type="button" class="pc-video" id="pc-video" aria-label="Play: See it install in 90 seconds">
        <span class="pc-video-play"><svg width="26" height="26" viewBox="0 0 24 24" fill="#0B1B33"><path d="M8 5v14l11-7z"/></svg></span>
        <span class="pc-video-caption">See it install in 90 seconds</span>
        <span class="pc-video-nametag">Scott &middot; Founder, PlusCities</span>
      </button>
    </div>
    <div class="pc-video-pad"></div>
';
}

/** Small page-header band used by every non-homepage page — not in the spec verbatim (those pages were only described in prose), but keeps every interior page visually anchored the same way instead of each inventing its own. */
function pc_page_hero(string $eyebrow, string $h1, string $lead = ''): string
{
    $leadHtml = $lead !== '' ? '<p class="pc-lead" style="margin-top:14px;max-width:640px;">' . $lead . '</p>' : '';
    return '
<section class="pc-section pc-navy-section" style="padding-bottom:56px;">
  <div class="pc-container">
    <span class="pc-eyebrow on-navy">' . h($eyebrow) . '</span>
    <h1 style="color:#fff;font-size:44px;">' . $h1 . '</h1>
    ' . $leadHtml . '
  </div>
</section>
';
}

function wpb_pc_block_hero(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-hero">
  <div class="pc-container">
    <div class="pc-hero-grid">
      <div>
        <span class="pc-pill"><span class="dot"></span> For marketing agencies &amp; WordPress sites</span>
        <h1>Add every town you serve.<br><span class="pc-lime">+50 cities</span> by Friday.</h1>
        <p class="pc-lead">City + service landing pages built from real local detail &mdash; neighborhoods, permit rules, weather and unique photos &mdash; live on your client&rsquo;s own domain, in their own theme.</p>

        <form class="pc-preview-bar" id="pc-hero-form">
          <input type="text" id="pc-hero-service" placeholder="Med spa" aria-label="Service">
          <input type="text" id="pc-hero-city" placeholder="Keller, TX" aria-label="City">
          <button type="submit" class="pc-btn pc-btn-lime" data-pc-event="preview_submit">Preview my page &rarr;</button>
        </form>
        <div class="pc-form-msg" id="pc-hero-msg"></div>

        <div class="pc-checks">
          <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg> Installs in minutes</span>
          <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg> Matches any theme</span>
          <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg> White-label for agencies</span>
        </div>
      </div>

      <div class="pc-hero-coverage-wrap">
        <div class="pc-coverage-card">
          <div class="pc-coverage-title">Service area coverage <span class="pc-badge-added">+9 towns added</span></div>
          <div class="pc-chip-row">
            <span class="pc-chip pc-chip-home">Southlake &middot; home</span>
            <span class="pc-chip pc-chip-lime">+ Keller</span>
            <span class="pc-chip pc-chip-lime">+ Grapevine</span>
            <span class="pc-chip pc-chip-lime">+ Colleyville</span>
            <span class="pc-chip pc-chip-lime">+ Roanoke</span>
            <span class="pc-chip pc-chip-lime">+ Trophy Club</span>
            <span class="pc-chip pc-chip-lime">+ Westlake</span>
            <span class="pc-chip pc-chip-lime">+ Flower Mound</span>
            <span class="pc-chip pc-chip-lime">+ Coppell</span>
            <span class="pc-chip pc-chip-lime">+ Hurst</span>
            <span class="pc-chip pc-chip-dashed">Euless</span>
            <span class="pc-chip pc-chip-dashed">Bedford</span>
          </div>
        </div>
        <div class="pc-browser">
          <div class="pc-browser-bar"><span class="pc-dot"></span><span class="pc-dot"></span><span class="pc-dot"></span><span class="pc-browser-url">yourclient.com/med-spa/keller-tx</span></div>
          <div class="pc-browser-body">
            <h4>Botox &amp; Fillers in Keller, TX</h4>
            <div class="pc-browser-tags"><span>Neighborhood guide</span><span>Local photos</span><span>City rules</span></div>
            <div class="pc-browser-line" style="width:100%"></div>
            <div class="pc-browser-line" style="width:92%"></div>
            <div class="pc-browser-line" style="width:70%"></div>
          </div>
        </div>
      </div>
    </div>

    ' . pc_founder_video_block() . '
  </div>
</section>
');
}

function wpb_pc_block_how_it_works(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section" id="how">
  <div class="pc-container">
    <span class="pc-eyebrow">How it works</span>
    <h2>Three steps. No developers. No writers.</h2>
    <div class="pc-steps">
      <div class="pc-step-card">
        <div class="pc-step-num">01</div>
        <h3>Install the plugin</h3>
        <p>Upload it to any WordPress site and paste your API key. It reads the theme&rsquo;s fonts, colors and layout automatically.</p>
      </div>
      <div class="pc-step-card">
        <div class="pc-step-num">02</div>
        <h3>Pick towns &amp; services</h3>
        <p>Choose the cities the business actually serves and the services it sells. 5 cities &times; 5 services = 25 pages.</p>
      </div>
      <div class="pc-step-card pc-navy">
        <div class="pc-step-num">03</div>
        <h3>Pages go live</h3>
        <p>A Service Areas link appears in the footer, leading to a hub page that links every city page &mdash; on the client&rsquo;s domain.</p>
      </div>
    </div>
  </div>
</section>
');
}

/** Real img if that slot's photo was uploaded, otherwise the styled [SCREENSHOT] placeholder the spec itself calls for. */
function wpb_pc_shot(array $ctx, string $photoKey, string $alt): string
{
    $url = $ctx['photos'][$photoKey] ?? '';
    return $url !== ''
        ? '<div class="pc-demo-shot pc-demo-shot-real"><img src="' . h($url) . '" alt="' . h($alt) . '" loading="lazy"></div>'
        : '<div class="pc-demo-shot">[SCREENSHOT]</div>';
}

function wpb_pc_block_see_it_live(array $d, array $ctx): string
{
    $shotFooter = wpb_pc_shot($ctx, 'demo_footer', 'Service Areas link in a real client site footer');
    $shotHub    = wpb_pc_shot($ctx, 'demo_hub', 'Real service area hub page listing towns and services');
    return wpb_html('
<section class="pc-section pc-mist" id="demos">
  <div class="pc-container">
    <div class="pc-section-head">
      <div>
        <span class="pc-eyebrow">See it on a real site</span>
        <h2>Looks like the client built it. Because it uses their theme.</h2>
      </div>
      <a href="/demos" class="pc-btn pc-btn-outline-navy" data-pc-event="demo_click">Open a live demo site &rarr;</a>
    </div>
    <div class="pc-demo-cards">
      <div class="pc-demo-card">
        <div class="pc-demo-label">1 &middot; Client footer</div>
        <h4>&nbsp;</h4>
        ' . $shotFooter . '
        <p>One link added to the footer. Nothing else on the site changes.</p>
      </div>
      <div class="pc-demo-card">
        <div class="pc-demo-label">2 &middot; Service area hub</div>
        <h4>&nbsp;</h4>
        ' . $shotHub . '
        <p>A clean hub page links every town and service.</p>
      </div>
      <div class="pc-demo-card">
        <div class="pc-demo-label">3 &middot; City page</div>
        <h4>AC Repair in Grapevine, TX</h4>
        <div class="pc-demo-shot">[SCREENSHOT]</div>
        <p>Unique copy and photos for every town and service.</p>
      </div>
    </div>
  </div>
</section>
');
}

function wpb_pc_block_partner_program(array $d, array $ctx): string
{
    $discount = h($d['partner_discount']);
    return wpb_html("
<section class=\"pc-section pc-navy-section\" id=\"agencies\">
  <div class=\"pc-container pc-partner-grid\">
    <div>
      <span class=\"pc-eyebrow on-navy\">Partner program</span>
      <h2>Your brand. Our engine. <span class=\"pc-lime\">Your margin.</span></h2>
      <p class=\"pc-lead\">Sell local coverage to every client you have &mdash; under your own name. We build and host the pages. You keep the relationship and the markup.</p>
      <ul class=\"pc-bullets\">
        <li><span class=\"plus\">+</span><span><strong>White-label dashboard</strong> &mdash; your logo, your login, every client in one place.</span></li>
        <li><span class=\"plus\">+</span><span><strong>Wholesale pricing</strong> &mdash; {$discount} off retail, billed through Stripe.</span></li>
        <li><span class=\"plus\">+</span><span><strong>One API key per client</strong> &mdash; add, pause or upgrade in a click.</span></li>
      </ul>
    </div>
    <div>
      <div class=\"pc-calc-card\" id=\"pc-calculator\">
        <div class=\"pc-calc-top\">Margin calculator <span>Example</span></div>
        <div class=\"pc-calc-label\">Plan</div>
        <div class=\"pc-pill-group\">
          <button type=\"button\" class=\"pc-pill-btn\" data-plan-pages=\"25\" aria-pressed=\"true\">25 pages (\$300)</button>
          <button type=\"button\" class=\"pc-pill-btn\" data-plan-pages=\"50\" aria-pressed=\"false\">50 pages (\$600)</button>
          <button type=\"button\" class=\"pc-pill-btn\" data-plan-pages=\"100\" aria-pressed=\"false\">100 pages (\$1,200)</button>
        </div>
        <div class=\"pc-calc-label\">Resale markup</div>
        <div class=\"pc-pill-group\">
          <button type=\"button\" class=\"pc-pill-btn\" data-markup=\"2\" aria-pressed=\"false\"><span class=\"pc-markup-amt\">2&times;</span></button>
          <button type=\"button\" class=\"pc-pill-btn\" data-markup=\"2.5\" aria-pressed=\"true\"><span class=\"pc-markup-amt\">2.5&times;</span></button>
          <button type=\"button\" class=\"pc-pill-btn\" data-markup=\"3\" aria-pressed=\"false\"><span class=\"pc-markup-amt\">3&times;</span></button>
        </div>
        <label class=\"pc-calc-label\" for=\"pc-calc-clients\">Clients</label>
        <div class=\"pc-calc-slider-row\">
          <input type=\"range\" id=\"pc-calc-clients\" min=\"1\" max=\"50\" value=\"10\" aria-label=\"Number of clients\">
          <span class=\"pc-calc-slider-val\" id=\"pc-calc-clients-val\">10</span>
        </div>
        <div class=\"pc-calc-outputs\">
          <div class=\"pc-calc-row\"><span>Your cost / client</span><strong id=\"pc-calc-cost\">\$300</strong></div>
          <div class=\"pc-calc-row\"><span>You charge / client</span><strong id=\"pc-calc-charge\">\$750</strong></div>
          <div class=\"pc-calc-profit\">
            <div class=\"pc-calc-row\"><span>Your monthly profit</span><strong id=\"pc-calc-monthly\">\$4,500</strong></div>
            <div class=\"pc-calc-yearly\">Per year: <strong id=\"pc-calc-yearly\">\$54,000</strong></div>
          </div>
        </div>
      </div>
      <a href=\"/agencies#apply\" class=\"pc-btn pc-btn-lime\" style=\"margin-top:20px;width:100%;justify-content:center;\">Apply to be a partner &rarr;</a>
    </div>
  </div>
</section>
");
}

function wpb_pc_block_industries(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section" id="industries">
  <div class="pc-container">
    <h2>Built for businesses that drive to the customer &mdash; or that customers drive to.</h2>
    <div class="pc-pill-links">
      <a href="/industries/med-spas" class="pc-ind-pill featured">Med Spas</a>
      <a href="/industries/hvac" class="pc-ind-pill">HVAC</a>
      <a href="/industries/roofing" class="pc-ind-pill">Roofing</a>
      <a href="/industries/plumbing" class="pc-ind-pill">Plumbing</a>
      <a href="/industries/electrical" class="pc-ind-pill">Electrical</a>
      <a href="/industries/landscaping" class="pc-ind-pill">Landscaping</a>
      <a href="/industries/pest-control" class="pc-ind-pill">Pest Control</a>
      <a href="/industries/garage-doors" class="pc-ind-pill">Garage Doors</a>
      <a href="/industries/remodeling" class="pc-ind-pill">Remodeling</a>
      <a href="/industries/dental" class="pc-ind-pill">Dental</a>
      <a href="/demo" class="pc-ind-pill dashed">+ Your niche</a>
    </div>
  </div>
</section>
');
}

function wpb_pc_block_quality(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section pc-lime-soft" id="quality">
  <div class="pc-container">
    <span class="pc-eyebrow">Quality &amp; Google</span>
    <h2>Not doorway pages. Pages a local would actually read.</h2>
    <p class="pc-lead" style="margin-top:14px;">We don&rsquo;t promise rankings. We promise real, useful coverage for the towns your client serves.</p>
    <div class="pc-quality-cards">
      <div class="pc-quality-card"><h3>Real neighborhoods</h3><p>Local landmarks and areas named on every page.</p></div>
      <div class="pc-quality-card"><h3>City rules &amp; permits</h3><p>What that city actually requires for the job.</p></div>
      <div class="pc-quality-card"><h3>Local weather</h3><p>Seasonal context that changes the service advice.</p></div>
      <div class="pc-quality-card"><h3>Unique photos</h3><p>No stock image reused across towns.</p></div>
    </div>
  </div>
</section>
');
}

function wpb_pc_block_pricing(array $d, array $ctx): string
{
    $starter  = h($d['stripe_starter']);
    $growth   = h($d['stripe_growth']);
    $dominate = h($d['stripe_dominate']);
    return wpb_html("
<section class=\"pc-section\" id=\"pricing\">
  <div class=\"pc-container\">
    <span class=\"pc-eyebrow\">Pricing</span>
    <h2>Pick how far you want to reach.</h2>
    <p class=\"pc-lead\" style=\"margin-top:10px;\">Agencies get partner pricing. <a href=\"/agencies\">See the program &rarr;</a></p>
    <div class=\"pc-price-cards\">
      <div class=\"pc-price-card\">
        <h3>Starter</h3>
        <div class=\"pc-price-amt\">\$300<span>/mo</span></div>
        <div class=\"pc-price-sub\">25 pages &middot; 5 cities &times; 5 services</div>
        <a href=\"{$starter}\" class=\"pc-btn pc-btn-outline-navy\">Get started</a>
      </div>
      <div class=\"pc-price-card pc-featured\">
        <div class=\"pc-popular-badge\">Most popular</div>
        <h3>Growth</h3>
        <div class=\"pc-price-amt\">\$600<span>/mo</span></div>
        <div class=\"pc-price-sub\">50 pages &middot; e.g. 10 cities &times; 5 services</div>
        <a href=\"{$growth}\" class=\"pc-btn pc-btn-lime\">Get started</a>
      </div>
      <div class=\"pc-price-card\">
        <h3>Dominate</h3>
        <div class=\"pc-price-amt\">\$1,200<span>/mo</span></div>
        <div class=\"pc-price-sub\">100 pages &middot; e.g. 20 cities &times; 5 services</div>
        <a href=\"{$dominate}\" class=\"pc-btn pc-btn-outline-navy\">Get started</a>
      </div>
    </div>
  </div>
</section>
");
}

function wpb_pc_block_faq(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section pc-mist">
  <div class="pc-container">
    <h2 style="text-align:center;">Questions agencies ask</h2>
    <div class="pc-faq">
      <details><summary>Will the pages match my client&rsquo;s theme?</summary><p>Yes. The plugin renders inside the site&rsquo;s own header, footer and styles, so the pages look native.</p></details>
      <details><summary>Do you guarantee rankings?</summary><p>No one honest does. We add real, local coverage for every town your client serves &mdash; that&rsquo;s the part you control.</p></details>
      <details><summary>Where are the pages hosted?</summary><p>We host and update the content; the plugin caches it locally and serves it on the client&rsquo;s own domain.</p></details>
      <details><summary>What happens if a client cancels?</summary><p>[CANCELLATION POLICY]</p></details>
    </div>
  </div>
</section>
') . pc_faq_schema();
}

function pc_faq_schema(): string
{
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'FAQPage',
        'mainEntity' => [
            ['@type' => 'Question', 'name' => "Will the pages match my client's theme?", 'acceptedAnswer' => ['@type' => 'Answer', 'text' => "Yes. The plugin renders inside the site's own header, footer and styles, so the pages look native."]],
            ['@type' => 'Question', 'name' => 'Do you guarantee rankings?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => "No one honest does. We add real, local coverage for every town your client serves — that's the part you control."]],
            ['@type' => 'Question', 'name' => 'Where are the pages hosted?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => "We host and update the content; the plugin caches it locally and serves it on the client's own domain."]],
            ['@type' => 'Question', 'name' => 'What happens if a client cancels?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '[CANCELLATION POLICY]']],
        ],
    ];
    return wpb_html('<script type="application/ld+json">' . json_encode($schema) . '</script>');
}

function wpb_pc_block_final_cta(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section pc-navy-section pc-final-cta">
  <div class="pc-container">
    <h2>Your clients serve more towns than their website says.</h2>
    <p>Fix that this week.</p>
    <div class="pc-btn-row">
      <a href="/demo" class="pc-btn pc-btn-lime">Book a 15-min demo</a>
      <a href="/audit" class="pc-btn pc-btn-outline-onnavy">Free coverage audit</a>
    </div>
  </div>
</section>
');
}

/** /how-it-works: the three homepage steps in more depth, each with its own screenshot slot, plus the founder video and a "what's on every page" recap — per the spec's own description of this page. */
function wpb_pc_block_how_it_works_detail(array $d, array $ctx): string
{
    return wpb_html('
' . pc_page_hero('How it works', 'Install the plugin. Pick towns. Pages go live.', 'No developers, no writers — three steps, start to finish.') . '
<section class="pc-section">
  <div class="pc-container">
    <div class="pc-steps" style="grid-template-columns:1fr;gap:40px;">
      <div class="pc-step-card pc-step-detail">
        <div><div class="pc-step-num">01</div><h3>Install the plugin</h3><p>Upload it to any WordPress site and paste your API key. It reads the theme&rsquo;s fonts, colors and layout automatically &mdash; no template to pick, no CSS to match by hand.</p></div>
        <div class="pc-demo-shot">[SCREENSHOT]</div>
      </div>
      <div class="pc-step-card pc-step-detail">
        <div><div class="pc-step-num">02</div><h3>Pick towns &amp; services</h3><p>Choose the cities the business actually serves and the services it sells. 5 cities &times; 5 services = 25 pages, generated from real local detail for each one.</p></div>
        <div class="pc-demo-shot">[SCREENSHOT]</div>
      </div>
      <div class="pc-step-card pc-navy pc-step-detail">
        <div><div class="pc-step-num">03</div><h3>Pages go live</h3><p>A Service Areas link appears in the footer, leading to a hub page that links every city page &mdash; on the client&rsquo;s own domain, in their own theme.</p></div>
        <div class="pc-demo-shot">[SCREENSHOT]</div>
      </div>
    </div>
  </div>
</section>
<section class="pc-section pc-mist">
  <div class="pc-container">
    <h2 style="text-align:center;">See it install in 90 seconds</h2>
    ' . pc_founder_video_block() . '
  </div>
</section>
');
}

/** /agencies: dashboard screenshot — the one genuinely new section beyond what the homepage's partner_program block already covers. */
function wpb_pc_block_agencies_dashboard(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section">
  <div class="pc-container">
    <span class="pc-eyebrow">Your dashboard</span>
    <h2>One login. Every client.</h2>
    <p class="pc-lead" style="margin-top:12px;max-width:640px;">Add a client, pause a client, upgrade a plan &mdash; all from one white-label dashboard with your logo, not ours.</p>
    <div class="pc-demo-shot" style="aspect-ratio:16/8;margin-top:28px;">[SCREENSHOT]</div>
  </div>
</section>
');
}

function wpb_pc_block_agencies_billing(array $d, array $ctx): string
{
    $discount = h($d['partner_discount'] ?? '[PARTNER DISCOUNT]');
    return wpb_html("
<section class=\"pc-section pc-mist\">
  <div class=\"pc-container\">
    <span class=\"pc-eyebrow\">How billing works</span>
    <h2>Wholesale in, your price out.</h2>
    <div class=\"pc-quality-cards pc-quality-cards-3\" style=\"margin-top:28px;\">
      <div class=\"pc-quality-card\"><h3>You're billed wholesale</h3><p>{$discount} off retail, charged to your card on file through Stripe &mdash; one invoice for every client.</p></div>
      <div class=\"pc-quality-card\"><h3>You bill your client</h3><p>Whatever you charge them is between you and them. We never see or touch that invoice.</p></div>
      <div class=\"pc-quality-card\"><h3>Change anytime</h3><p>Add a client, pause one, or move a plan up or down in a click &mdash; billing adjusts automatically.</p></div>
    </div>
  </div>
</section>
");
}

function wpb_pc_block_agencies_apply(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section pc-navy-section" id="apply">
  <div class="pc-container" style="max-width:640px;">
    <span class="pc-eyebrow on-navy">Apply</span>
    <h2 style="color:#fff;">Become a partner.</h2>
    <form class="pc-apply-form" id="pc-apply-form" data-pc-lead-form="partner-apply" style="margin-top:24px;display:flex;flex-direction:column;gap:14px;">
      <input type="text" name="name" placeholder="Your name" required>
      <input type="text" name="agency" placeholder="Agency name" required>
      <input type="email" name="email" placeholder="you@agency.com" required>
      <input type="text" name="phone" placeholder="Phone">
      <select name="clients" required>
        <option value="">Number of clients</option>
        <option value="1-5">1&ndash;5</option>
        <option value="6-20">6&ndash;20</option>
        <option value="21-50">21&ndash;50</option>
        <option value="50+">50+</option>
      </select>
      <input type="text" name="niches" placeholder="Main niches (e.g. HVAC, Roofing)">
      <textarea name="message" rows="3" placeholder="Anything else we should know?"></textarea>
      <input type="text" class="pc-hp-field" tabindex="-1" autocomplete="off" name="website">
      <button type="submit" class="pc-btn pc-btn-lime">Apply to be a partner &rarr;</button>
      <div class="pc-form-msg" id="pc-apply-msg" role="status"></div>
    </form>
  </div>
</section>
');
}

/** /docs: plain install/troubleshooting guide — the spec's own content list for this page. */
function wpb_pc_block_docs(array $d, array $ctx): string
{
    return wpb_html('
' . pc_page_hero('Plugin docs', 'Install guide') . '
<section class="pc-section">
  <div class="pc-container" style="max-width:720px;">
    <h3>1. Install the plugin</h3>
    <p>Upload the PlusCities plugin zip in your WordPress admin under Plugins &rarr; Add New &rarr; Upload Plugin, then activate it.</p>
    <h3>2. Paste your API key</h3>
    <p>Go to Settings &rarr; PlusCities in your WordPress admin and paste the API key from your dashboard. The plugin reads your theme&rsquo;s fonts, colors and layout automatically &mdash; nothing else to configure here.</p>
    <h3>3. Choose your towns</h3>
    <p>Towns and services are managed from your PlusCities dashboard, not from WordPress &mdash; add, pause or change them any time and the plugin picks up the change automatically.</p>
    <h3>4. Caching</h3>
    <p>The plugin caches every page locally on your WordPress install, so pages keep working even if it can&rsquo;t reach PlusCities right away. Use Settings &rarr; PlusCities &rarr; Clear Cache after a big content change if you want to force a refresh.</p>
    <h3>5. Troubleshooting</h3>
    <p>Most issues are one of two things: an expired or mistyped API key, or a caching plugin on your own site serving a stale version of the Service Areas page. Try clearing your site&rsquo;s own cache first.</p>
    <p style="margin-top:24px;">Still stuck? Email <strong>[SUPPORT EMAIL]</strong>.</p>
  </div>
</section>
');
}

/** /terms and /privacy: structure is real, the body text is explicitly [PLACEHOLDER] per the spec — "Legal: [PLACEHOLDER] text from Scott." Don't invent legal language standing in for a real policy. */
function wpb_pc_block_legal(array $d, array $ctx): string
{
    $title = $ctx['page'] === 'privacy' ? 'Privacy Policy' : 'Terms &amp; Conditions';
    return wpb_html('
' . pc_page_hero('Legal', $title) . '
<section class="pc-section">
  <div class="pc-container" style="max-width:720px;">
    <p style="color:var(--muted);font-style:italic;">[PLACEHOLDER &mdash; ' . $title . ' text from Scott]</p>
  </div>
</section>
');
}

/** /audit: the lead-magnet form per the spec's own field list (Website URL, Main service, Home city, Email, optional Agency name). Confirmation text names [TURNAROUND] honestly, same placeholder convention as partner_discount/stripe URLs — never invented. */
function wpb_pc_block_audit(array $d, array $ctx): string
{
    $successMsg = h('Got it. Your coverage audit lands in your inbox within ' . $d['turnaround'] . '.');
    return wpb_html('
' . pc_page_hero('Free coverage audit', 'How many towns is your client missing?', 'Tell us the site and we&rsquo;ll show you the towns it&rsquo;s invisible in.') . '
<section class="pc-section">
  <div class="pc-container" style="max-width:560px;">
    <form class="pc-apply-form" id="pc-audit-form" data-pc-lead-form="audit" data-pc-success-msg="' . $successMsg . '" style="display:flex;flex-direction:column;gap:14px;">
      <input type="text" name="site_url" placeholder="Website URL" required>
      <input type="text" name="main_service" placeholder="Main service (e.g. HVAC, Med Spa)" required>
      <input type="text" name="home_city" placeholder="Home city (e.g. Keller, TX)" required>
      <input type="email" name="email" placeholder="you@email.com" required>
      <input type="text" name="agency_name" placeholder="Agency name (optional)">
      <input type="text" class="pc-hp-field" tabindex="-1" autocomplete="off" name="website">
      <button type="submit" class="pc-btn pc-btn-lime">Get my free coverage audit &rarr;</button>
      <div class="pc-form-msg" id="pc-audit-msg" role="status"></div>
    </form>
  </div>
</section>
');
}

/** /demo: a calendar link when one exists, else the same visible-placeholder convention as every other unset input — not an iframe, since the provider (Calendly vs Cal.com) is still an open Scott decision per the spec. */
function wpb_pc_block_demo_booking(array $d, array $ctx): string
{
    $hasLink = ($d['calendar_link'] ?? '#') !== '#';
    $cta = $hasLink
        ? '<a href="' . h($d['calendar_link']) . '" target="_blank" rel="noopener" class="pc-btn pc-btn-lime" data-pc-event="demo_click" style="margin-top:8px;">Pick a time &rarr;</a>'
        : '<div class="pc-demo-shot" style="aspect-ratio:16/7;margin-top:8px;">[CALENDAR LINK]</div>';
    return wpb_html('
' . pc_page_hero('Book a demo', 'See it on your own clients&rsquo; towns.', 'A real 15-minute walkthrough, not a sales pitch.') . '
<section class="pc-section">
  <div class="pc-container" style="max-width:720px;">
    <div class="pc-quality-cards pc-quality-cards-3">
      <div class="pc-quality-card"><h3>How it reads your theme</h3><p>See the plugin pick up your (or your client&rsquo;s) fonts, colors and layout automatically.</p></div>
      <div class="pc-quality-card"><h3>Picking towns &amp; services</h3><p>A live walkthrough of choosing real towns and services for a real business.</p></div>
      <div class="pc-quality-card"><h3>Partner pricing</h3><p>Margin and wholesale pricing, if you&rsquo;re asking on behalf of an agency.</p></div>
    </div>
    ' . $cta . '
  </div>
</section>
');
}

/** /faq: every FAQ in groups per the spec (Product, Agencies, Billing, Technical), with its own FAQPage schema distinct from the homepage's smaller one. */
function wpb_pc_faq_groups(): array
{
    return [
        'Product' => [
            ["Will the pages match my client's theme?", "Yes. The plugin renders inside the site's own header, footer and styles, so the pages look native."],
            ['Do you guarantee rankings?', "No one honest does. We add real, local coverage for every town your client serves — that's the part you control."],
            ['Where are the pages hosted?', "We host and update the content; the plugin caches it locally and serves it on the client's own domain."],
            ['Can I try it on one town first?', 'Yes — every plan can start with a single city and service, then grow from there.'],
        ],
        'Agencies' => [
            ['How does the partner program work?', "You get wholesale pricing and a white-label dashboard to manage every client — you bill them whatever you want and keep the difference."],
            ['Is there a minimum number of clients?', 'No. The Apply form asks how many clients you expect so we can set your dashboard up right, not to gate access.'],
            ['Can I fully white-label the dashboard?', 'Yes — the dashboard your clients never see carries your own logo and login, not ours.'],
        ],
        'Billing' => [
            ['What happens if I outgrow my plan?', 'Move up a tier anytime — the pages you already have stay live, and your next invoice reflects the new plan.'],
            ['Do unused pages carry over?', 'No — pricing is coverage, not a page bank. Pick the tier that matches how many towns and services the business actually serves today.'],
            ['Can I move from Direct to Agency pricing?', "Yes — if you're running client sites through an agency relationship, apply to the partner program and we'll move you to wholesale pricing."],
            ['What happens if a client cancels?', '[CANCELLATION POLICY]'],
        ],
        'Technical' => [
            ["How do I install the plugin?", "Upload it in your WordPress admin and paste the API key from your dashboard — it reads your theme's fonts, colors and layout automatically."],
            ['How fast do changes show up?', 'The plugin caches pages locally on your WordPress install. Use Settings → PlusCities → Clear Cache to force an immediate refresh after a change.'],
            ['Does it work with any theme?', "Yes — it renders with core WordPress blocks inside your theme's own header and footer, with no page-builder plugin dependency."],
            ['What happens if PlusCities is briefly unreachable?', "Nothing breaks — the plugin's local cache keeps serving the last version of every page."],
        ],
    ];
}

function wpb_pc_block_faq_full(array $d, array $ctx): string
{
    $groupsHtml = '';
    $entities = [];
    foreach (wpb_pc_faq_groups() as $group => $items) {
        $itemsHtml = '';
        foreach ($items as $item) {
            [$q, $a] = $item;
            $itemsHtml .= '<details><summary>' . h($q) . '</summary><p>' . h($a) . '</p></details>';
            $entities[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
        $groupsHtml .= '<h3 style="max-width:780px;margin:44px auto 0;">' . h($group) . '</h3><div class="pc-faq" style="margin-top:4px;">' . $itemsHtml . '</div>';
    }
    $schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
    return wpb_html('
' . pc_page_hero('FAQ', 'Every question, in one place.') . '
<section class="pc-section">
  <div class="pc-container">' . $groupsHtml . '</div>
</section>
') . wpb_html('<script type="application/ld+json">' . json_encode($schema) . '</script>');
}

/** /quality: the spec's own "expanded version of the homepage section" — same 4 cards plus how pages link together and the plain promise, reusing existing component classes rather than inventing new ones. */
function wpb_pc_block_quality_full(array $d, array $ctx): string
{
    return wpb_html('
' . pc_page_hero('Quality & Google', 'Not doorway pages. Pages a local would actually read.', "We don't promise rankings. We promise real, useful coverage for the towns your client serves.") . '
<section class="pc-section">
  <div class="pc-container">
    <h2>What makes each page unique</h2>
    <div class="pc-quality-cards" style="margin-top:28px;">
      <div class="pc-quality-card"><h3>Real neighborhoods</h3><p>Local landmarks and areas named on every page, not a generic city-wide blur.</p></div>
      <div class="pc-quality-card"><h3>City rules &amp; permits</h3><p>What that specific city actually requires for the job — these rules vary town to town in most trades.</p></div>
      <div class="pc-quality-card"><h3>Local weather</h3><p>Seasonal context that changes the service advice itself, not just the headline.</p></div>
      <div class="pc-quality-card"><h3>Unique photos</h3><p>No stock image reused across towns &mdash; every page looks genuinely distinct.</p></div>
    </div>
  </div>
</section>
<section class="pc-section pc-mist">
  <div class="pc-container">
    <h2>How the pages link together</h2>
    <p class="pc-lead" style="margin-top:14px;max-width:720px;">One link added to your client&rsquo;s existing footer leads to a Service Areas hub page, which links every town and service the business covers. Each of those leads to its own unique city page. Nothing else about the site changes.</p>
    <div class="pc-demo-cards" style="margin-top:32px;">
      <div class="pc-demo-card"><div class="pc-demo-label">1 &middot; Footer</div><p>One new link, in the client&rsquo;s own site footer.</p></div>
      <div class="pc-demo-card"><div class="pc-demo-label">2 &middot; Service area hub</div><p>Lists every town and service the business actually covers.</p></div>
      <div class="pc-demo-card"><div class="pc-demo-label">3 &middot; City page</div><p>Unique local copy and photos for that one town.</p></div>
    </div>
  </div>
</section>
<section class="pc-section">
  <div class="pc-container" style="max-width:720px;">
    <h2>The plain promise</h2>
    <p class="pc-lead" style="margin-top:14px;">We don&rsquo;t promise rankings &mdash; nobody honest does. What we promise is real coverage: a genuinely useful page for every town a business serves, built from that town&rsquo;s own detail instead of a copy-pasted template.</p>
  </div>
</section>
');
}

/**
 * The industries data file the spec asks for ("so Scott can add niches
 * without code") — all 10 niches from the homepage pills, each with its own
 * pain points written for that actual trade, not one template with the noun
 * swapped. Three are wired to real /industries/[niche] pages so far (Med
 * Spas, HVAC, Roofing — the spec's own phased start); the rest already have
 * real content sitting here, ready to flip on by adding a page_type entry,
 * no new writing required when that happens.
 */
function wpb_pc_industries_data(): array
{
    return [
        'med-spas' => [
            'label' => 'Med Spas', 'built' => true, 'example_title' => 'Botox & Fillers in Keller, TX',
            'pain_points' => [
                'Patients search by neighborhood, not just city — "Botox Keller" and "Botox Southlake" pull up completely different competitors.',
                "A single homepage can't realistically rank for every treatment in every town a practice actually draws patients from.",
                'Generic stock photos of spas make every med spa site look interchangeable to a patient comparing options.',
            ],
        ],
        'hvac' => [
            'label' => 'HVAC', 'built' => true, 'example_title' => 'AC Repair in Grapevine, TX',
            'pain_points' => [
                "A broken AC in July is a same-day call — if the site doesn't show up for that specific town, a competitor's does.",
                'One HVAC company can cover 15+ towns, but a single "Service Areas" list buried in the footer does nothing for local search.',
                'Seasonal urgency means the advice on the page should change with the season, not stay identical in January and July.',
            ],
        ],
        'roofing' => [
            'label' => 'Roofing', 'built' => true, 'example_title' => 'Roof Replacement in Southlake, TX',
            'pain_points' => [
                'After a hailstorm, homeowners search their own town, not the metro area — "roof repair [town]" beats "roofing company near me."',
                'Permit and code requirements for a roof replacement vary by city, and most roofing sites never mention it.',
                'A crew driving 30+ minutes to a job needs the page to prove they actually serve that town, not just the home base.',
            ],
        ],
        'plumbing' => [
            'label' => 'Plumbing', 'built' => true, 'example_title' => 'Drain Cleaning in Colleyville, TX',
            'pain_points' => [
                'A burst pipe at 2am means someone is searching from their phone, in a panic, for a plumber in their own neighborhood right now.',
                'Backflow-prevention and permit rules differ by city, and a generic "plumbing services" page never says so.',
                'One page covering a whole metro area can\'t answer "do you actually serve my street" at a glance.',
            ],
        ],
        'electrical' => [
            'label' => 'Electrical', 'built' => true, 'example_title' => 'Panel Upgrade in Trophy Club, TX',
            'pain_points' => [
                'Electrical permit and inspection rules vary city by city — homeowners researching a panel upgrade notice when a site doesn\'t know that.',
                'An electrician covering a metro area loses every hyper-local search to a competitor with a dedicated page for that one town.',
                'Emergency issues like a sparking outlet mean a slow or generic site costs the call outright.',
            ],
        ],
        'landscaping' => [
            'label' => 'Landscaping', 'built' => true, 'example_title' => 'Spring Cleanup in Westlake, TX',
            'pain_points' => [
                'What grows well — and what the HOA allows — differs street to street, let alone town to town; generic copy ignores both.',
                'A crew\'s own photos of real yards in a specific town build more trust than stock photos of someone else\'s lawn.',
                'Seasonal work needs the page\'s advice to actually match the season and that town\'s climate, not a generic year-round list.',
            ],
        ],
        'pest-control' => [
            'label' => 'Pest Control', 'built' => true, 'example_title' => 'Termite Treatment in Flower Mound, TX',
            'pain_points' => [
                'The pests that matter change by region — fire ants here, termites there — and a generic page talks about neither specifically.',
                'Pest control is a recurring relationship, not a one-time job, so proving local expertise early earns a yearly contract, not just a call.',
                'A single "areas we serve" list doesn\'t answer a homeowner\'s real question: do you know OUR town\'s actual pest problems?',
            ],
        ],
        'garage-doors' => [
            'label' => 'Garage Doors', 'built' => true, 'example_title' => 'Garage Door Repair in Coppell, TX',
            'pain_points' => [
                'A garage door that won\'t close is a same-day security concern — whoever shows up first in a hyper-local search gets the job.',
                'A company covering a wide area needs each town to feel like a dedicated local business, not a satellite office.',
                'Most garage door sites list brands and models but never actually say which towns they install and repair in.',
            ],
        ],
        'remodeling' => [
            'label' => 'Remodeling', 'built' => true, 'example_title' => 'Kitchen Remodel in Hurst, TX',
            'pain_points' => [
                'Remodeling is a high-trust, high-consideration purchase — homeowners research extensively, and a generic page doesn\'t build local credibility.',
                'Permit requirements and typical timelines vary by city, and most remodeling sites never address it.',
                'A portfolio organized by town proves a contractor actually works in the area, not just claims to.',
            ],
        ],
        'dental' => [
            'label' => 'Dental', 'built' => true, 'example_title' => 'Teeth Whitening in Euless, TX',
            'pain_points' => [
                'Patients choosing a dentist search by neighborhood and by insurance network, not just "dentist near me" broadly.',
                'A single practice serving a wide area needs each town\'s page to feel written for that community, not copy-pasted.',
                'New-patient decisions are local and relationship-driven — a generic stock-photo site doesn\'t build that trust.',
            ],
        ],
    ];
}

function wpb_pc_block_industries_grid(array $d, array $ctx): string
{
    $cards = '';
    foreach (wpb_pc_industries_data() as $slug => $niche) {
        if (!empty($niche['built'])) {
            $cards .= '<a href="/industries/' . h($slug) . '" class="pc-industry-card">'
                . '<h3>' . h($niche['label']) . '</h3>'
                . '<p>' . h($niche['pain_points'][0]) . '</p>'
                . '<span class="pc-industry-link">See examples &rarr;</span>'
                . '</a>';
        } else {
            $cards .= '<div class="pc-industry-card pc-industry-card-soon">'
                . '<h3>' . h($niche['label']) . '</h3>'
                . '<p>' . h($niche['pain_points'][0]) . '</p>'
                . '<span class="pc-industry-soon">Coming soon</span>'
                . '</div>';
        }
    }
    return wpb_html('
' . pc_page_hero('Industries', 'Built for businesses that drive to the customer &mdash; or that customers drive to.') . '
<section class="pc-section">
  <div class="pc-container">
    <div class="pc-industry-grid">' . $cards . '</div>
  </div>
</section>
');
}

/** One function generates every niche page — the data above is the only thing that changes per niche, per the spec\'s own "add a niche without code" goal. */
function wpb_pc_block_niche_page(array $d, array $ctx): string
{
    $data = wpb_pc_industries_data();
    $niche = $data[$ctx['page']] ?? reset($data);
    $label = h($niche['label']);
    $points = '';
    foreach ($niche['pain_points'] as $p) {
        $points .= '<div class="pc-quality-card"><p>' . h($p) . '</p></div>';
    }
    return wpb_html('
' . pc_page_hero('Industries', 'City pages for ' . $label) . '
<section class="pc-section">
  <div class="pc-container">
    <h2>What ' . $label . ' clients run into</h2>
    <div class="pc-quality-cards pc-quality-cards-3" style="margin-top:28px;">' . $points . '</div>
  </div>
</section>
<section class="pc-section pc-mist">
  <div class="pc-container">
    <h2>What a page looks like</h2>
    <div class="pc-browser" style="max-width:640px;margin-top:28px;">
      <div class="pc-browser-bar"><span class="pc-dot"></span><span class="pc-dot"></span><span class="pc-dot"></span><span class="pc-browser-url">yourclient.com/' . h($ctx['page']) . '/keller-tx</span></div>
      <div class="pc-browser-body"><h4>' . h($niche['example_title']) . '</h4></div>
    </div>
    <a href="/demos" class="pc-btn pc-btn-outline-navy" style="margin-top:28px;">Open a live demo site &rarr;</a>
  </div>
</section>
');
}

/** Reads a real percent out of the partner-discount field (e.g. "40%" or "40") — returns null if it's still the unset placeholder, so the wholesale table can show an honest [PARTNER PRICES] instead of a fabricated number. */
function wpb_pc_discount_pct(string $text): ?float
{
    return preg_match('/(\d+(?:\.\d+)?)\s*%?/', $text, $m) ? (float) $m[1] : null;
}

function wpb_pc_block_pricing_tabs(array $d, array $ctx): string
{
    $tiers = [
        ['label' => 'Starter', 'pages' => 25, 'retail' => 300, 'sub' => '5 cities &times; 5 services'],
        ['label' => 'Growth', 'pages' => 50, 'retail' => 600, 'sub' => 'e.g. 10 cities &times; 5 services', 'popular' => true],
        ['label' => 'Dominate', 'pages' => 100, 'retail' => 1200, 'sub' => 'e.g. 20 cities &times; 5 services'],
    ];
    $pct = wpb_pc_discount_pct($d['partner_discount'] ?? '');

    $directCards = '';
    foreach ($tiers as $t) {
        $cls = !empty($t['popular']) ? ' pc-featured' : '';
        $badge = !empty($t['popular']) ? '<div class="pc-popular-badge">Most popular</div>' : '';
        $btnCls = !empty($t['popular']) ? 'pc-btn-lime' : 'pc-btn-outline-navy';
        $directCards .= '<div class="pc-price-card' . $cls . '">' . $badge
            . '<h3>' . h($t['label']) . '</h3>'
            . '<div class="pc-price-amt">$' . number_format($t['retail']) . '<span>/mo</span></div>'
            . '<div class="pc-price-sub">' . $t['pages'] . ' pages &middot; ' . $t['sub'] . '</div>'
            . '<a href="#" class="pc-btn ' . $btnCls . '">Get started</a>'
            . '</div>';
    }

    $agencyRows = '';
    foreach ($tiers as $t) {
        $wholesale = $pct !== null ? '$' . number_format($t['retail'] * (1 - $pct / 100)) : '[PARTNER PRICES]';
        $agencyRows .= '<tr><td>' . h($t['label']) . ' &middot; ' . $t['pages'] . ' pages</td><td>' . $wholesale . '</td><td>$' . number_format($t['retail']) . '</td></tr>';
    }

    $comparisonRows = '<tr><td>Pages</td><td>25</td><td>50</td><td>100</td></tr>'
        . '<tr><td>Example coverage</td><td>5 cities &times; 5 services</td><td>10 cities &times; 5 services</td><td>20 cities &times; 5 services</td></tr>'
        . '<tr><td>Hosting &amp; updates</td><td>Included</td><td>Included</td><td>Included</td></tr>'
        . '<tr><td>Support</td><td>Email</td><td>Priority email</td><td>Priority email</td></tr>';

    return wpb_html('
' . pc_page_hero('Pricing', 'Pick how far you want to reach.') . '
<section class="pc-section">
  <div class="pc-container">
    <div class="pc-pricing-tabs" role="tablist">
      <button type="button" class="pc-pill-btn" data-pricing-tab="direct" aria-pressed="true">Direct</button>
      <button type="button" class="pc-pill-btn" data-pricing-tab="agency" aria-pressed="false">Agency</button>
    </div>

    <div class="pc-pricing-panel" data-pricing-panel="direct">
      <div class="pc-price-cards" style="margin-top:28px;">' . $directCards . '</div>
    </div>

    <div class="pc-pricing-panel" data-pricing-panel="agency" hidden>
      <p class="pc-lead" style="margin-top:28px;max-width:640px;">Partner pricing is wholesale &mdash; bill your clients whatever you want, and keep the difference.</p>
      <table class="pc-table" style="margin-top:20px;">
        <thead><tr><th>Plan</th><th>You pay (wholesale)</th><th>Typical resale</th></tr></thead>
        <tbody>' . $agencyRows . '</tbody>
      </table>
      <a href="/agencies#apply" class="pc-btn pc-btn-lime" style="margin-top:20px;">Apply to be a partner &rarr;</a>
    </div>

    <h2 style="margin-top:64px;">What&rsquo;s included</h2>
    <table class="pc-table" style="margin-top:20px;">
      <thead><tr><th></th><th>Starter</th><th>Growth</th><th>Dominate</th></tr></thead>
      <tbody>' . $comparisonRows . '</tbody>
    </table>
  </div>
</section>
');
}

function wpb_pc_block_pricing_faq(array $d, array $ctx): string
{
    return wpb_html('
<section class="pc-section pc-mist">
  <div class="pc-container">
    <h2 style="text-align:center;">Pricing questions</h2>
    <div class="pc-faq">
      <details><summary>What happens if I outgrow my plan?</summary><p>Move up a tier anytime &mdash; the pages you already have stay live, and your next invoice reflects the new plan.</p></details>
      <details><summary>Do unused pages carry over?</summary><p>No &mdash; pricing is coverage, not a page bank. Pick the tier that matches how many towns and services the business actually serves today.</p></details>
      <details><summary>Can I move from Direct to Agency pricing?</summary><p>Yes &mdash; if you\'re running client sites through an agency relationship, apply to the partner program and we\'ll move you to wholesale pricing.</p></details>
      <details><summary>What happens if a client cancels?</summary><p>[CANCELLATION POLICY]</p></details>
    </div>
  </div>
</section>
');
}

/** Human-readable name for a known WordPress theme slug — the point of this page is "look, a real theme," so the raw slug (e.g. "generatepress") isn't good enough. */
function wpb_pc_theme_label(string $slug): string
{
    $known = [
        'oceanwp' => 'OceanWP', 'twentytwentyfive' => 'Twenty Twenty-Five', 'blocksy' => 'Blocksy',
        'sydney' => 'Sydney', 'storefront' => 'Storefront', 'generatepress' => 'GeneratePress',
        'twentytwentythree' => 'Twenty Twenty-Three', 'twentytwentyfour' => 'Twenty Twenty-Four',
        'neve' => 'Neve', 'astra' => 'Astra', 'hello-elementor' => 'Hello Elementor', 'kadence' => 'Kadence',
    ];
    return $known[$slug] ?? ucwords(str_replace(['-', '_'], ' ', $slug));
}

function wpb_pc_niche_label(string $slug): string
{
    if ($slug === 'hvac') return 'HVAC';
    return ucwords(str_replace('-', ' ', $slug));
}

/**
 * Real demo sites, read live from the LPC demo box every time this page is
 * built — not a hardcoded snapshot that could drift from what's actually
 * running there. These are internal q111.xyz placeholder domains with no
 * public DNS yet (a known, separate open item — see the placeholder list at
 * the end of this file), so "Open demo" won't resolve for a real visitor
 * until that's done; the links are wired to the real domains now so nothing
 * needs to change here once DNS is live.
 */
function wpb_pc_block_demos(array $d, array $ctx): string
{
    $cards = '';
    if (function_exists('lpc_demo_list_sites')) {
        foreach (lpc_demo_list_sites() as $site) {
            $domain = $site['domain'] ?? '';
            $niche = $site['niche'] ?? '';
            if ($domain === '' || in_array($niche, ['wpbuilder-seed', 'acme-plumbing-test', 'pluscities'], true)) {
                continue;
            }
            $cards .= '<div class="pc-demo-card">'
                . '<div class="pc-demo-label">' . h(wpb_pc_theme_label($site['theme'] ?? '')) . ' theme</div>'
                . '<h4>' . h(wpb_pc_niche_label($niche)) . '</h4>'
                . '<div class="pc-demo-shot">[SCREENSHOT]</div>'
                . '<p><a href="http://' . h($domain) . '/" target="_blank" rel="noopener">Open demo &rarr;</a></p>'
                . '</div>';
        }
    }
    return wpb_html('
' . pc_page_hero('Live demos', 'Real plugin, real themes.', 'Every card below is a real WordPress install on a different theme, built to prove the plugin adapts &mdash; not a mockup.') . '
<section class="pc-section">
  <div class="pc-container">
    <div class="pc-demo-cards">' . $cards . '</div>
  </div>
</section>
');
}

foreach ([
    'hero'            => 'wpb_pc_block_hero',
    'how_it_works'    => 'wpb_pc_block_how_it_works',
    'see_it_live'     => 'wpb_pc_block_see_it_live',
    'partner_program' => 'wpb_pc_block_partner_program',
    'industries'      => 'wpb_pc_block_industries',
    'quality'         => 'wpb_pc_block_quality',
    'pricing'         => 'wpb_pc_block_pricing',
    'faq'             => 'wpb_pc_block_faq',
    'final_cta'       => 'wpb_pc_block_final_cta',
    'how_it_works_detail' => 'wpb_pc_block_how_it_works_detail',
    'agencies_dashboard'  => 'wpb_pc_block_agencies_dashboard',
    'agencies_billing'    => 'wpb_pc_block_agencies_billing',
    'agencies_apply'      => 'wpb_pc_block_agencies_apply',
    'docs'                => 'wpb_pc_block_docs',
    'legal'               => 'wpb_pc_block_legal',
    'industries_grid'     => 'wpb_pc_block_industries_grid',
    'niche_page'          => 'wpb_pc_block_niche_page',
    'pricing_tabs'        => 'wpb_pc_block_pricing_tabs',
    'pricing_faq'         => 'wpb_pc_block_pricing_faq',
    'demos'               => 'wpb_pc_block_demos',
    'audit'               => 'wpb_pc_block_audit',
    'demo_booking'        => 'wpb_pc_block_demo_booking',
    'faq_full'            => 'wpb_pc_block_faq_full',
    'quality_full'        => 'wpb_pc_block_quality_full',
] as $blockKey => $fn) {
    wpb_register_block(WPB_PLUSCITIES, $blockKey, $fn);
}

wpb_register_profile(WPB_PLUSCITIES, [
    'label' => 'PlusCities (custom)',
    'description' => 'The PlusCities marketing homepage, built to the Nov 19 2026 spec. Custom header/footer, '
        . 'custom blocks (none shared with other profiles yet), margin calculator, lead-capture form. '
        . 'Homepage only for now — other routes come after this is reviewed.',
    'fields' => [
        'lead_email'       => ['required' => false, 'label' => 'Lead notification email', 'hint' => 'blank = site admin email'],
        'partner_discount' => ['required' => false, 'label' => 'Partner discount text', 'placeholder' => 'e.g. 40%', 'hint' => 'blank = [PARTNER DISCOUNT] placeholder'],
        'stripe_starter'   => ['required' => false, 'label' => 'Stripe checkout URL — Starter', 'hint' => 'blank = # placeholder'],
        'stripe_growth'    => ['required' => false, 'label' => 'Stripe checkout URL — Growth', 'hint' => 'blank = # placeholder'],
        'stripe_dominate'  => ['required' => false, 'label' => 'Stripe checkout URL — Dominate', 'hint' => 'blank = # placeholder'],
        'turnaround'       => ['required' => false, 'label' => 'Coverage audit turnaround', 'placeholder' => 'e.g. 24 hours', 'hint' => 'blank = [TURNAROUND] placeholder'],
        'calendar_link'    => ['required' => false, 'label' => 'Calendar booking link (Calendly or Cal.com)', 'hint' => 'blank = [CALENDAR LINK] placeholder'],
    ],
    'photos' => [
        'demo_footer' => ['field' => 'demo_footer_shot', 'label' => '"See it live" card 1 — client footer screenshot', 'required_ui' => false, 'hint' => 'blank = [SCREENSHOT] placeholder'],
        'demo_hub'    => ['field' => 'demo_hub_shot', 'label' => '"See it live" card 2 — service area hub screenshot', 'required_ui' => false, 'hint' => 'blank = [SCREENSHOT] placeholder'],
    ],
    'normalize' => 'wpb_pc_normalize',
    'site_title' => fn(array $d): string => 'PlusCities',
    'home_page' => 'home',
    'page_types' => [
        'home' => [
            'slug' => 'home',
            'title' => 'Home',
            'blocks' => ['hero', 'how_it_works', 'see_it_live', 'partner_program', 'industries', 'quality', 'pricing', 'faq', 'final_cta'],
            'seo' => fn(array $d) => [
                'PlusCities — Add every town you serve',
                "City + service landing pages built from real local detail — neighborhoods, permit rules, weather and unique photos — live on your client's own domain, in their own theme.",
            ],
        ],
        'how-it-works' => [
            'slug' => 'how-it-works',
            'title' => 'How It Works',
            'blocks' => ['how_it_works_detail', 'quality', 'final_cta'],
            'seo' => fn(array $d) => [
                'How It Works | PlusCities',
                'Install the plugin, pick your towns and services, and pages go live on your own domain. No developers, no writers.',
            ],
        ],
        'agencies' => [
            'slug' => 'agencies',
            'title' => 'For Agencies',
            'blocks' => ['partner_program', 'agencies_dashboard', 'agencies_billing', 'faq', 'agencies_apply', 'final_cta'],
            'seo' => fn(array $d) => [
                'For Agencies | PlusCities Partner Program',
                'Sell local coverage to every client you have, under your own name. White-label dashboard, wholesale pricing, one API key per client.',
            ],
        ],
        'docs' => [
            'slug' => 'docs',
            'title' => 'Plugin Docs',
            'blocks' => ['docs', 'final_cta'],
            'seo' => fn(array $d) => [
                'Plugin Docs | PlusCities',
                'How to install the PlusCities plugin, add your API key, choose towns, and troubleshoot common issues.',
            ],
        ],
        'terms' => [
            'slug' => 'terms',
            'title' => 'Terms & Conditions',
            'blocks' => ['legal', 'final_cta'],
            'seo' => fn(array $d) => ['Terms & Conditions | PlusCities', 'PlusCities terms and conditions.'],
        ],
        'privacy' => [
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'blocks' => ['legal', 'final_cta'],
            'seo' => fn(array $d) => ['Privacy Policy | PlusCities', 'PlusCities privacy policy.'],
        ],
        'industries' => [
            'slug' => 'industries',
            'title' => 'Industries',
            'blocks' => ['industries_grid', 'final_cta'],
            'seo' => fn(array $d) => [
                'Industries | PlusCities',
                'City + service landing pages for the trades that drive to the customer, or that customers drive to.',
            ],
        ],
        // Niche pages nest under 'industries' (parent, declared above) for a real
        // /industries/[niche]/ URL. Only the ones marked built=true in the data
        // file get a route — the rest already have real content, just not wired
        // to a page yet, per the spec's own phased rollout.
        'med-spas' => [
            'slug' => 'med-spas', 'title' => 'Med Spas', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Med Spas | PlusCities', 'See how PlusCities builds city + service landing pages for med spas.'],
        ],
        'hvac' => [
            'slug' => 'hvac', 'title' => 'HVAC', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for HVAC | PlusCities', 'See how PlusCities builds city + service landing pages for HVAC companies.'],
        ],
        'roofing' => [
            'slug' => 'roofing', 'title' => 'Roofing', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Roofing | PlusCities', 'See how PlusCities builds city + service landing pages for roofing companies.'],
        ],
        'plumbing' => [
            'slug' => 'plumbing', 'title' => 'Plumbing', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Plumbing | PlusCities', 'See how PlusCities builds city + service landing pages for plumbing companies.'],
        ],
        'electrical' => [
            'slug' => 'electrical', 'title' => 'Electrical', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Electrical | PlusCities', 'See how PlusCities builds city + service landing pages for electrical companies.'],
        ],
        'landscaping' => [
            'slug' => 'landscaping', 'title' => 'Landscaping', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Landscaping | PlusCities', 'See how PlusCities builds city + service landing pages for landscaping companies.'],
        ],
        'pest-control' => [
            'slug' => 'pest-control', 'title' => 'Pest Control', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Pest Control | PlusCities', 'See how PlusCities builds city + service landing pages for pest control companies.'],
        ],
        'garage-doors' => [
            'slug' => 'garage-doors', 'title' => 'Garage Doors', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Garage Doors | PlusCities', 'See how PlusCities builds city + service landing pages for garage door companies.'],
        ],
        'remodeling' => [
            'slug' => 'remodeling', 'title' => 'Remodeling', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Remodeling | PlusCities', 'See how PlusCities builds city + service landing pages for remodeling companies.'],
        ],
        'dental' => [
            'slug' => 'dental', 'title' => 'Dental', 'parent' => 'industries',
            'blocks' => ['niche_page', 'final_cta'],
            'seo' => fn(array $d) => ['City Pages for Dental | PlusCities', 'See how PlusCities builds city + service landing pages for dental practices.'],
        ],
        'audit' => [
            'slug' => 'audit',
            'title' => 'Free Coverage Audit',
            'blocks' => ['audit', 'final_cta'],
            'seo' => fn(array $d) => [
                'Free Coverage Audit | PlusCities',
                "See how many towns your client's website is missing, and get a free coverage audit in your inbox.",
            ],
        ],
        'demo' => [
            'slug' => 'demo',
            'title' => 'Book a Demo',
            'blocks' => ['demo_booking', 'final_cta'],
            'seo' => fn(array $d) => [
                'Book a Demo | PlusCities',
                'Book a real 15-minute walkthrough of the PlusCities plugin — no sales pitch.',
            ],
        ],
        'faq' => [
            'slug' => 'faq',
            'title' => 'FAQ',
            'blocks' => ['faq_full', 'final_cta'],
            'seo' => fn(array $d) => [
                'FAQ | PlusCities',
                'Every PlusCities question, grouped by product, agencies, billing and technical.',
            ],
        ],
        'quality' => [
            'slug' => 'quality',
            'title' => 'Quality & Google',
            'blocks' => ['quality_full', 'final_cta'],
            'seo' => fn(array $d) => [
                'Quality & Google | PlusCities',
                "Why PlusCities pages aren't doorway pages, and the plain promise: coverage, not rankings.",
            ],
        ],
        'pricing' => [
            'slug' => 'pricing',
            'title' => 'Pricing',
            'blocks' => ['pricing_tabs', 'pricing_faq', 'final_cta'],
            'seo' => fn(array $d) => [
                'Pricing | PlusCities',
                'Direct pricing starts at $300/mo for 25 pages. Agencies get wholesale pricing and keep the resale margin.',
            ],
        ],
        'demos' => [
            'slug' => 'demos',
            'title' => 'Live Demos',
            'blocks' => ['demos', 'final_cta'],
            'seo' => fn(array $d) => [
                'Live Demos | PlusCities',
                'Real WordPress installs on different themes, proving the PlusCities plugin adapts to any theme.',
            ],
        ],
    ],
    'site_options' => function (array $d, array $photos = []): array {
        return [
            'wpb_business_name'      => 'PlusCities',
            'wpb_schema_type'        => 'SoftwareApplication',
            'wpb_lead_email'         => $d['lead_email'],
            'wpb_partner_discount'   => $d['partner_discount'],
            'wpb_stripe_starter_url' => $d['stripe_starter'],
            'wpb_stripe_growth_url'  => $d['stripe_growth'],
            'wpb_stripe_dominate_url'=> $d['stripe_dominate'],
            'wpb_og_image'           => '',
        ];
    },
    'chrome_dir' => rtrim(__DIR__ . '/../../wp-plugin/wp-builder-pluscities-chrome', '/'),
    // This is a real public site, not an internal demo/rehearsal one — strip the inherited back-link badge.
    'strip_internal_badges' => true,
]);

/**
 * Placeholders left visible on this build, per the spec's own instruction —
 * surfaced here so they're never silently forgotten:
 *   [CANCELLATION POLICY]— homepage FAQ, /pricing FAQ, and /faq's Billing group
 *   [SCREENSHOT] x2       — "See it on a real site" cards 1 and 2 (card 3 has a real title, no image yet)
 *   [FOUNDER VIDEO]       — shown only after the poster is clicked (loads-on-click, per spec)
 *   Stripe checkout URLs  — all three pricing "Get started" buttons link to # until supplied
 *   [CALENDAR LINK]       — /demo shows a styled placeholder box until a real Calendly/Cal.com URL is set
 *   [TURNAROUND]          — /audit's confirmation line names it literally until a real value is set
 *   Lead notification email — falls back to the site's WP admin email until a real one is set
 *   Analytics (Plausible/GA4) — not wired; dataLayer.push() calls are no-ops until a real tag exists
 *   Turnstile — not wired, honeypot only
 *   PARTNER DISCOUNT is set (40%, live on /pricing's wholesale column) — no longer a placeholder.
 *   app.pluscities.com (Log in / Client login) — deliberately out of scope for this project; the
 *     real agency/billing app already exists but isn't exposed at that public address yet.
 *   Every other route in the spec's site map now exists: /how-it-works, /agencies, /docs, /terms,
 *     /privacy, /industries (+ all 10 niches), /pricing, /demos, /audit, /demo, /faq, /quality.
 */
