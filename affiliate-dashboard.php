<?php
// Phase 4: the previous version displayed hard-coded sample figures (clicks, conversions, commission). Showing invented
// business numbers is misleading, so this page now only states what is actually configured.
require_once __DIR__ . '/helpers.php';
$title = 'Affiliate reporting — AIHubpedia';
$tools = load_tools()['tools'] ?? [];
$aff = 0; foreach ($tools as $t) { if (is_affiliate_link($t)) $aff++; }
require __DIR__ . '/layout_top.php';
?>
<section class="hero"><h1>Affiliate reporting</h1><p class="small">No commission or conversion figures are shown here because none are tracked yet.</p></section>
<section class="panel">
  <p>Tools currently carrying an affiliate or referral link: <strong><?= (int)$aff ?></strong>. Affiliate links are always disclosed and marked <code>rel="sponsored"</code>. See the <a href="/disclosure.php">advertising disclosure</a>.</p>
  <p class="small">Revenue reporting will appear in the protected admin area once an affiliate network integration is configured.</p>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
