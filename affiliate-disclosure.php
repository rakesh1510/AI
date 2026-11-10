<?php
require_once __DIR__ . '/helpers.php';
$title = "Affiliate Disclosure — AIHubpedia";
$meta_desc = "AIHubpedia earns commissions from some tool links. Read our full affiliate disclosure.";
require __DIR__ . '/layout_top.php';
?>

<article class="card" style="max-width:820px;margin:0 auto">
  <div class="trust-badge-row" style="margin-bottom:1.25rem">
    <span class="trust-badge trust-badge--editorial">📋 Editorial Policy Applies</span>
  </div>

  <h1>Affiliate Disclosure</h1>
  <p class="small" style="opacity:.65;margin-bottom:1.5rem">
    Published by <strong>AIHubpedia Editorial Team</strong> &nbsp;·&nbsp;
    Last Updated: <strong><?= date('j F Y') ?></strong>
  </p>

  <p>AIHubpedia is an independent AI tool directory operated by <strong>TrustNova Tech</strong>, based in Germany. We research and list AI tools to help users make informed decisions.</p>

  <h2>How we earn money</h2>
  <p>Some of the links on AIHubpedia are <strong>affiliate links</strong>. This means that if you click a link and sign up for or purchase a product, we may receive a commission — at no extra cost to you.</p>
  <p>Affiliate partnerships are marked with the <span class="trust-badge trust-badge--affiliate">🤝 Affiliate Partner</span> badge on the relevant tool listing.</p>

  <h2>What this means for our reviews</h2>
  <p>Our editorial team makes independent decisions about which tools to list, review, and recommend. <strong>Affiliate relationships do not influence our editorial scores, ratings, or comparisons.</strong> We apply the same evaluation criteria to all tools regardless of whether a commercial relationship exists.</p>
  <p>We only list tools we believe are genuinely useful to our audience.</p>

  <h2>Sponsored content</h2>
  <p>Some listings may be <strong>sponsored</strong>, meaning a tool provider has paid for enhanced visibility (e.g., a "Sponsored" badge or featured placement). Sponsored content is clearly labelled with the <span class="trust-badge trust-badge--sponsored">⭐ Sponsored</span> badge and does not override our independent editorial scores.</p>

  <h2>Our commitment</h2>
  <ul>
    <li>We never accept payment to alter editorial ratings or written reviews.</li>
    <li>We disclose all commercial relationships clearly.</li>
    <li>We update listings when products change significantly.</li>
    <li>We remove tools that no longer meet our quality standards.</li>
  </ul>

  <h2>Contact</h2>
  <p>Questions about our affiliate program or disclosure policy? Contact us at <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</p>

  <div class="trust-badge-row" style="margin-top:2rem">
    <span class="trust-badge trust-badge--verified">✓ Verified Listing</span>
    <span class="trust-badge trust-badge--live">📡 Live Data Checked</span>
    <span class="trust-badge trust-badge--editorial">✍ Editor Reviewed</span>
  </div>
</article>

<?php require __DIR__ . '/layout_bottom.php'; ?>
