<?php
require_once __DIR__ . '/helpers.php';
$title = "Editorial Policy — AIHubpedia";
$meta_desc = "How AIHubpedia researches, scores, and publishes AI tool listings and reviews.";
require __DIR__ . '/layout_top.php';
?>

<article class="card" style="max-width:820px;margin:0 auto">
  <div class="trust-badge-row" style="margin-bottom:1.25rem">
    <span class="trust-badge trust-badge--editorial">✍ Editor Reviewed</span>
  </div>

  <h1>Editorial Policy</h1>
  <p class="small" style="opacity:.65;margin-bottom:1.5rem">
    Published by <strong>AIHubpedia Editorial Team</strong> &nbsp;·&nbsp;
    Last Updated: <strong><?= date('j F Y') ?></strong>
  </p>

  <p>AIHubpedia is an independent AI tool directory operated by <strong>TrustNova Tech</strong>, Germany. This policy explains how we research, evaluate, and publish content — and what standards every listing must meet before going live.</p>

  <h2>Independence</h2>
  <p>Our editorial team operates independently from our commercial team. Tool scores, ratings, comparison results, and written reviews are determined solely by our editorial criteria. No payment changes a score or review verdict.</p>

  <h2>How we evaluate tools</h2>
  <p>Every tool listed on AIHubpedia is assessed against these dimensions:</p>
  <ul>
    <li><strong>Feature depth</strong> — breadth and quality of documented capabilities</li>
    <li><strong>Use-case range</strong> — how many distinct workflows the tool genuinely supports</li>
    <li><strong>Integrations</strong> — connections with popular platforms and APIs</li>
    <li><strong>Value for money</strong> — pricing model relative to feature set</li>
    <li><strong>Data quality</strong> — accuracy of listing data, cross-checked against the official website</li>
    <li><strong>Popularity</strong> — real engagement signals from the AIHubpedia community</li>
  </ul>

  <h2>Live data checks</h2>
  <p>Where possible, AIHubpedia fetches live data from a tool's official website to verify features, pricing signals, and platform support. Pages carrying the <span class="trust-badge trust-badge--live">📡 Live Data Checked</span> badge have been verified against the tool's own website within the last 7 days.</p>

  <h2>Verified listings</h2>
  <p>A <span class="trust-badge trust-badge--verified">✓ Verified Listing</span> badge means the tool has been manually reviewed by an AIHubpedia editor, the official website is confirmed active, and core information (pricing, features, use cases) has been validated.</p>

  <h2>Editor Reviewed</h2>
  <p>The <span class="trust-badge trust-badge--editorial">✍ Editor Reviewed</span> badge indicates that a member of the AIHubpedia Editorial Team has written or approved the listing content, tested the tool where practical, and signed off on the overall accuracy of the page.</p>

  <h2>Updates and corrections</h2>
  <p>AI tools change quickly. We re-check live data automatically every 7 days and manually review top listings on a rolling basis. If you spot an error, please <a href="partner.php">contact us</a> — we correct factual mistakes promptly.</p>

  <h2>Removals</h2>
  <p>We remove tools that are discontinued, no longer publicly available, or have received sustained evidence of misleading claims. We do not remove tools for negative reviews or low scores.</p>

  <h2>Affiliate and sponsored content</h2>
  <p>Commercial relationships are fully disclosed. See our <a href="affiliate-disclosure.php">Affiliate Disclosure</a> for details. Sponsored placements are labelled; they do not influence editorial scores.</p>

  <h2>Contact the editorial team</h2>
  <p>Questions, corrections, or suggestions: <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a></p>
</article>

<?php require __DIR__ . '/layout_bottom.php'; ?>
