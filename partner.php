<?php
require_once __DIR__ . '/helpers.php';
$title = "Partner With Us — AIHubpedia";
$meta_desc = "List your AI tool on AIHubpedia, sponsor a review, or become an affiliate partner.";
require __DIR__ . '/layout_top.php';
?>

<article class="card" style="max-width:820px;margin:0 auto">
  <div class="trust-badge-row" style="margin-bottom:1.25rem">
    <span class="trust-badge trust-badge--editorial">✍ Editor Reviewed</span>
  </div>

  <h1>Partner With Us</h1>
  <p class="small" style="opacity:.65;margin-bottom:1.5rem">
    Published by <strong>AIHubpedia Editorial Team</strong> &nbsp;·&nbsp;
    Last Updated: <strong><?= date('j F Y') ?></strong>
  </p>

  <p>AIHubpedia is one of the fastest-growing independent AI tool directories. We help thousands of users every month discover, compare, and choose the right AI tools. If you build AI products, we want to work with you.</p>

  <h2>Partnership options</h2>

  <div class="partner-options" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin:1.5rem 0">

    <div class="card" style="padding:1.25rem">
      <div style="font-size:1.5rem;margin-bottom:.5rem">📋</div>
      <h3 style="margin:0 0 .5rem">Free Listing</h3>
      <p class="small">Submit your tool to be listed in our directory. Our editorial team reviews all submissions before publishing.</p>
      <p class="small"><strong>Cost:</strong> Free</p>
    </div>

    <div class="card" style="padding:1.25rem">
      <div style="font-size:1.5rem;margin-bottom:.5rem">🏆</div>
      <h3 style="margin:0 0 .5rem">Featured Listing</h3>
      <p class="small">Get top placement in your category with a <span class="trust-badge trust-badge--featured">🏆 Featured Tool</span> badge. Drives significantly more clicks.</p>
      <p class="small"><strong>Cost:</strong> Contact us for pricing</p>
    </div>

    <div class="card" style="padding:1.25rem">
      <div style="font-size:1.5rem;margin-bottom:.5rem">⭐</div>
      <h3 style="margin:0 0 .5rem">Sponsored Review</h3>
      <p class="small">An in-depth sponsored review page written by our editorial team, clearly marked as <span class="trust-badge trust-badge--sponsored">⭐ Sponsored</span>. Editorial scores remain independent.</p>
      <p class="small"><strong>Cost:</strong> Contact us for pricing</p>
    </div>

    <div class="card" style="padding:1.25rem">
      <div style="font-size:1.5rem;margin-bottom:.5rem">🤝</div>
      <h3 style="margin:0 0 .5rem">Affiliate Program</h3>
      <p class="small">We drive qualified traffic to your signup page via affiliate links. Performance-based. Your tool must meet our quality bar.</p>
      <p class="small"><strong>Cost:</strong> Revenue share</p>
    </div>

    <div class="card" style="padding:1.25rem">
      <div style="font-size:1.5rem;margin-bottom:.5rem">📊</div>
      <h3 style="margin:0 0 .5rem">Sponsored Comparison</h3>
      <p class="small">Appear prominently in head-to-head comparison pages against relevant competitors. Clearly labelled as sponsored.</p>
      <p class="small"><strong>Cost:</strong> Contact us for pricing</p>
    </div>

    <div class="card" style="padding:1.25rem">
      <div style="font-size:1.5rem;margin-bottom:.5rem">📧</div>
      <h3 style="margin:0 0 .5rem">Newsletter Sponsorship</h3>
      <p class="small">Reach our subscriber base of AI tool users with a dedicated placement in the AIHubpedia Weekly Digest.</p>
      <p class="small"><strong>Cost:</strong> Contact us for pricing</p>
    </div>

  </div>


  <p class="small" style="margin-top:1.5rem;opacity:.65">
    AIHubpedia is operated by <strong>TrustNova Tech</strong>, Germany.
    All partnerships are subject to our <a href="editorial-policy.php">Editorial Policy</a> and <a href="affiliate-disclosure.php">Affiliate Disclosure</a>.
  </p>
</article>

<?php require __DIR__ . '/layout_bottom.php'; ?>
