<?php
/**
 * privacy.php — platform Privacy Policy, for agencies signing up to resell
 * PlusCities. DRAFT, not attorney-reviewed — see the banner below. Written
 * 2026-11-24 alongside terms.php, same context.
 *
 * Deliberately names the real sub-processors this platform actually uses
 * (Stripe, Anthropic, OpenAI, Cloudflare, Google Search Console) — a privacy
 * policy disclosing real data processors is a different thing from product
 * UI admitting "this is AI" (see the Positioning Compliance section of the
 * Product Roadmap); omitting a real sub-processor here would be the actual
 * legal risk, not a positioning one.
 *
 * Placeholders Scott needs to fill in: [LEGAL_ENTITY_NAME], [GOVERNING_STATE],
 * [LEGAL_CONTACT_EMAIL], [EFFECTIVE_DATE].
 */
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy — PlusCities</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:0 auto;padding:40px 24px 80px;color:#1f2937;line-height:1.65;}
h1{font-size:1.7rem;color:#0B1B33;}
h2{font-size:1.15rem;color:#0B1B33;margin-top:2.2em;}
p,li{font-size:.95rem;}
.draft-banner{background:#fef3c7;border:1px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:32px;font-size:.88rem;color:#92400e;}
.placeholder{background:#fee2e2;color:#991b1b;padding:1px 5px;border-radius:4px;font-family:monospace;font-size:.85em;}
table{border-collapse:collapse;width:100%;margin:12px 0;}
th,td{text-align:left;padding:7px 10px;font-size:.88rem;border-bottom:1px solid #e5e7eb;}
th{color:#52607a;font-weight:600;}
</style>
</head>
<body>

<div class="draft-banner">
    <strong>Draft — not yet legally reviewed.</strong> This is a starting document, not attorney-reviewed
    or binding legal text. Have a lawyer review it before relying on it, especially if you operate in a
    jurisdiction with specific data-protection requirements (e.g. GDPR, CCPA). Fields in
    <span class="placeholder">red</span> need to be filled in before this goes live.
</div>

<h1>Privacy Policy</h1>
<p>Effective date: <span class="placeholder">[EFFECTIVE_DATE]</span></p>

<p>This Privacy Policy explains how <span class="placeholder">[LEGAL_ENTITY_NAME]</span> ("PlusCities,"
"we," "us," or "our") collects, uses, and shares information in connection with the PlusCities platform
(the "Service"). This policy covers Agencies who use the Service directly; it does not cover the separate
privacy practices of any individual Agency toward their own End Clients or website visitors, which are
the Agency's own responsibility.</p>

<h2>1. Information We Collect</h2>
<ul>
<li><strong>Account information</strong> — name, email, login credentials, and agency details you provide
when your account is created.</li>
<li><strong>Billing information</strong> — handled by our payment processor, Stripe; PlusCities does not
store full card numbers.</li>
<li><strong>End Client business information</strong> — the business facts you enter about your own clients
(business name, address, phone number, license details, hours, service descriptions, and similar) in
order to generate and publish their pages.</li>
<li><strong>Usage data</strong> — basic activity logs (logins, pages created, actions taken) used for
security and support.</li>
</ul>

<h2>2. How We Use Information</h2>
<p>We use the information above to operate the Service: to create and publish pages on behalf of your End
Clients, to process payment for your subscription, to provide customer support, and to maintain the
security and reliability of the platform.</p>

<h2>3. Third-Party Service Providers</h2>
<p>To operate the Service, we share limited data with the following categories of third-party providers.
We choose providers who only receive what they need to perform their specific function:</p>
<table>
<tr><th>Provider</th><th>Purpose</th><th>What they receive</th></tr>
<tr><td>Stripe</td><td>Payment processing</td><td>Billing/payment details</td></tr>
<tr><td>Anthropic</td><td>AI-assisted page content drafting</td><td>End Client business facts relevant to the page being written</td></tr>
<tr><td>OpenAI</td><td>AI-assisted image generation</td><td>Niche/style details used to generate stock imagery</td></tr>
<tr><td>Cloudflare</td><td>Content delivery and security</td><td>Standard web traffic metadata</td></tr>
<tr><td>Google Search Console</td><td>Search performance reporting (where connected)</td><td>The End Client's website property, if you choose to connect it</td></tr>
</table>
<p>We do not sell personal information to third parties.</p>

<h2>4. Data Retention</h2>
<p>We retain account and End Client data for as long as your account is active, and for a reasonable
period afterward to comply with legal, tax, or billing obligations. You may request deletion of your
account data by contacting <span class="placeholder">[LEGAL_CONTACT_EMAIL]</span>.</p>

<h2>5. Data Security</h2>
<p>We use reasonable administrative and technical safeguards to protect data in our systems, including
restricted access and encrypted connections. No system is completely secure, and we cannot guarantee
absolute security.</p>

<h2>6. Your Responsibilities for Your Own End Clients</h2>
<p>If you collect personal information from your own End Clients or your End Clients' website visitors
(for example, through a contact form), you are responsible for complying with applicable privacy laws
toward those individuals, including providing your own privacy notice where required. This Policy covers
PlusCities' handling of data you provide to us — it does not substitute for your own End Client-facing
privacy practices.</p>

<h2>7. Your Choices</h2>
<p>You may update or correct your account information at any time by contacting
<span class="placeholder">[LEGAL_CONTACT_EMAIL]</span>.</p>

<h2>8. Children's Privacy</h2>
<p>The Service is intended for business use and is not directed to individuals under 18. We do not
knowingly collect personal information from children.</p>

<h2>9. Changes to This Policy</h2>
<p>We may update this Policy from time to time. Material changes will be communicated by email or an
in-app notice.</p>

<h2>10. Contact</h2>
<p>Questions about this Policy can be sent to <span class="placeholder">[LEGAL_CONTACT_EMAIL]</span>.</p>

</body>
</html>
