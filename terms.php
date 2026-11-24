<?php
/**
 * terms.php — platform Terms of Service, for agencies signing up to resell
 * PlusCities. DRAFT, not attorney-reviewed — see the banner below. Written
 * 2026-11-24 at Scott's request ("just draft the documents for now, standard
 * shape"). Deliberately a plain standalone page, not routed through
 * site-template.php — this is the PLATFORM's own legal page, not content for
 * a client site built with this tool.
 *
 * Placeholders Scott needs to fill in before this is real: [LEGAL_ENTITY_NAME],
 * [GOVERNING_STATE], [LEGAL_CONTACT_EMAIL], [EFFECTIVE_DATE].
 */
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms of Service — PlusCities</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:0 auto;padding:40px 24px 80px;color:#1f2937;line-height:1.65;}
h1{font-size:1.7rem;color:#0B1B33;}
h2{font-size:1.15rem;color:#0B1B33;margin-top:2.2em;}
p,li{font-size:.95rem;}
.draft-banner{background:#fef3c7;border:1px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:32px;font-size:.88rem;color:#92400e;}
.placeholder{background:#fee2e2;color:#991b1b;padding:1px 5px;border-radius:4px;font-family:monospace;font-size:.85em;}
</style>
</head>
<body>

<div class="draft-banner">
    <strong>Draft — not yet legally reviewed.</strong> This is a starting document, not attorney-reviewed
    or binding legal text. Have a lawyer review it before relying on it, especially the limitation-of-liability
    and governing-law sections. Fields in <span class="placeholder">red</span> need to be filled in before
    this goes live.
</div>

<h1>Terms of Service</h1>
<p>Effective date: <span class="placeholder">[EFFECTIVE_DATE]</span></p>

<p>These Terms of Service ("Terms") govern access to and use of the PlusCities platform (the "Service"),
operated by <span class="placeholder">[LEGAL_ENTITY_NAME]</span> ("PlusCities," "we," "us," or "our"). By
creating an account or otherwise using the Service, you ("Agency," "you," or "your") agree to these Terms.
If you do not agree, do not use the Service.</p>

<h2>1. What PlusCities Is</h2>
<p>PlusCities is a platform that lets an Agency create, manage, and publish local-service web pages on
behalf of the Agency's own clients ("End Clients"). The Service includes tools for drafting page content,
reviewing and approving pages before they go live, and basic reporting.</p>

<h2>2. Accounts</h2>
<p>An Agency account is provisioned by PlusCities directly; there is no public self-signup. You are
responsible for keeping your login credentials confidential and for all activity that occurs under your
account, including activity by any team members you add. Notify us promptly at
<span class="placeholder">[LEGAL_CONTACT_EMAIL]</span> if you suspect unauthorized access.</p>

<h2>3. Fees and Payment</h2>
<p>Use of the Service requires payment of the subscription fee associated with your plan, billed through
our payment processor (Stripe). Fees are non-refundable except where required by law or expressly stated
otherwise in writing. We may suspend access to the Service for any account with a failed or overdue
payment.</p>

<h2>4. Your Clients and Your Content</h2>
<p>You are solely responsible for your relationship with your own End Clients, including obtaining any
consent, rights, or permissions needed to publish content on their behalf, and for the accuracy of the
business facts (name, address, phone number, license information, service descriptions, and similar
details) you provide to the Service for a given End Client. PlusCities does not verify the accuracy of
facts you supply and is not responsible for claims arising from inaccurate information you provided.</p>

<h2>5. Acceptable Use</h2>
<p>You agree not to use the Service to publish content that is unlawful, defamatory, fraudulent, or that
infringes a third party's intellectual property or other rights; to misrepresent your identity or an End
Client's identity; to attempt to gain unauthorized access to the Service or any other account; or to use
the Service in a way that disrupts its operation for other users.</p>

<h2>6. Intellectual Property</h2>
<p>PlusCities retains all rights to the Service itself (software, templates, and underlying technology).
As between you and PlusCities, you retain ownership of the business facts and brand assets (logos, photos)
you provide for your End Clients, and of the specific web pages generated for them once published.</p>

<h2>7. Term, Cancellation, and Termination</h2>
<p>These Terms remain in effect while you have an active account. You may cancel at any time by
contacting <span class="placeholder">[LEGAL_CONTACT_EMAIL]</span>; cancellation does not entitle you to a
refund of fees already paid. We may suspend or terminate your account for violation of these Terms, for
non-payment, or for conduct that creates risk or legal exposure for PlusCities or its other users.</p>

<h2>8. Disclaimers</h2>
<p>The Service is provided "as is" and "as available," without warranties of any kind, express or
implied, including warranties of merchantability, fitness for a particular purpose, or non-infringement.
PlusCities does not guarantee any particular search ranking, traffic, or business outcome from pages
published through the Service.</p>

<h2>9. Limitation of Liability</h2>
<p>To the maximum extent permitted by law, PlusCities will not be liable for any indirect, incidental,
special, consequential, or punitive damages, or for any loss of profits, revenue, or data, arising out of
or related to your use of the Service. PlusCities' total liability for any claim arising out of these
Terms will not exceed the amount you paid to PlusCities in the three (3) months preceding the claim.</p>

<h2>10. Indemnification</h2>
<p>You agree to indemnify and hold PlusCities harmless from any claim, loss, or liability arising from
your use of the Service, your End Client content, or your violation of these Terms.</p>

<h2>11. Changes to These Terms</h2>
<p>We may update these Terms from time to time. Continued use of the Service after an update constitutes
acceptance of the revised Terms. Material changes will be communicated by email or an in-app notice.</p>

<h2>12. Governing Law</h2>
<p>These Terms are governed by the laws of the State of <span class="placeholder">[GOVERNING_STATE]</span>,
without regard to its conflict-of-laws principles.</p>

<h2>13. Contact</h2>
<p>Questions about these Terms can be sent to <span class="placeholder">[LEGAL_CONTACT_EMAIL]</span>.</p>

</body>
</html>
