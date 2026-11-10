<?php
/**
 * homepage-trust-section.php
 * Drop this snippet inside index.php where you want the trust strip to appear.
 * Example: <?php require __DIR__ . '/homepage-trust-section.php'; ?>
 */
?>
<section class="card" style="margin:1.5rem 0">
  <h2 style="margin-top:0">Why trust AIHubpedia?</h2>
  <p class="small" style="opacity:.7;margin-bottom:1.25rem">
    An independent AI directory built on real data, community feedback, and editorial transparency.
    Operated by <strong>TrustNova Tech</strong>, Germany.
  </p>

  <div class="trust-strip">
    <div class="trust-strip__item">
      <span class="trust-strip__icon">🗂️</span>
      <!--<strong>512+ AI Tools Listed</strong>-->
       <strong> <?= number_format(count($tools)) ?>+ AI tools listed</strong>
      <span>Manually reviewed and categorised across 40+ categories.</span>
    </div>
    <div class="trust-strip__item">
      <span class="trust-strip__icon">🔍</span>
      <strong>Independent Directory</strong>
      <span>No tool pays to influence its score or editorial rating.</span>
    </div>
    <div class="trust-strip__item">
      <span class="trust-strip__icon">⚖️</span>
      <strong>Real Comparisons</strong>
      <span>Side-by-side data drawn from live websites and verified sources.</span>
    </div>
    <div class="trust-strip__item">
      <span class="trust-strip__icon">💬</span>
      <strong>Community Feedback</strong>
      <span>Ratings, votes, and comments from real users shape every listing.</span>
    </div>
    <div class="trust-strip__item">
      <span class="trust-strip__icon">🇩🇪</span>
      <strong>Germany-Based Company</strong>
      <span>Operated by TrustNova Tech, held to EU data-protection standards.</span>
    </div>
    <div class="trust-strip__item">
      <span class="trust-strip__icon">📡</span>
      <strong>Live Data Checks</strong>
      <span>Key tool information verified against official websites every 7 days.</span>
    </div>
  </div>

  <div class="trust-badge-row" style="margin-top:.75rem">
    <span class="trust-badge trust-badge--verified">✓ Verified Listing</span>
    <span class="trust-badge trust-badge--live">📡 Live Data Checked</span>
    <span class="trust-badge trust-badge--editorial">✍ Editor Reviewed</span>
  </div>
</section>
