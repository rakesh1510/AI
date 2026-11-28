<?php
require_once __DIR__ . '/helpers.php';
$title = 'Advertising & Affiliate Disclosure';
$meta_desc = 'How AIHubpedia labels sponsored placements and affiliate links, what the current numbers are, and why paid placements never change a tool profile.';
$tools = load_tools()['tools'];
$affCount = count(array_filter($tools, fn($t) => is_affiliate_link($t)));
$sponsoredCount = count(featured_tools($tools, 1000)) + count(array_filter($tools, fn($t) => !empty($t['spotlight_until']) && strtotime((string)$t['spotlight_until']) >= strtotime(date('Y-m-d'))));
$crumbs = [['Home', site_url('/')], ['Advertising disclosure', null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Disclosure', 'icon' => 'shield', 'title_html' => 'Advertising and affiliate <span class="gradText2">disclosure</span>', 'lead' => 'AIHubpedia is an independent AI tools directory. We earn money in some ways and not in others, and this page explains which, so you can judge our recommendations.', 'art' => 'shield']);
?>
<div class="wrap pgBody">
<?php ob_start(); ?>
  <div class="discloseNow">
    <h2 style="margin-top:0">Right now</h2>
    <ul>
      <li><strong><?= (int)$affCount ?></strong> listing<?= $affCount === 1 ? '' : 's' ?> use<?= $affCount === 1 ? 's' : '' ?> an affiliate link.</li>
      <li><strong><?= (int)$sponsoredCount ?></strong> sponsored placement<?= $sponsoredCount === 1 ? ' is' : 's are' ?> active.</li>
    </ul>
    <p class="small">These numbers are computed from the live catalogue each time this page loads.</p>
  </div>

  <h2>Sponsored placements</h2>
  <p>Featured listings and the homepage spotlight are paid. They appear in sections labelled <span class="adTag">Sponsored</span> and link here. Their outbound links carry <code>rel="sponsored nofollow"</code>. <a href="/advertise.php">See advertising options</a>.</p>

  <h2>Affiliate links</h2>
  <p>Some outbound links may be affiliate links, meaning AIHubpedia could earn a commission if you sign up. When a link is an affiliate link, the tool card shows an “Affiliate link” note next to it and the link carries <code>rel="sponsored"</code>. Using an affiliate link does not add any charge from AIHubpedia.</p>

  <h2>What payment never changes</h2>
  <ul>
    <li>A tool's facts, limitations, pricing notes or review status.</li>
    <li>Votes, ratings and the leaderboard, which reflect visitor activity on this site.</li>
    <li>Which tools appear in our guides and comparisons. Guides are built from vendor sources, as described in our <a href="/methodology.php">methodology</a>.</li>
  </ul>

  <h2>Our own services</h2>
  <p>The company behind this site also offers AI consulting, automation and software solutions (<a href="/business.php">TrustNova Tech</a>). Those services are not reviewed, ranked or recommended inside tool listings.</p>

  <h2>Questions or corrections</h2>
  <p>Email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>. If a label is missing where one is required, we will fix it.</p>
<?php $__doc = ob_get_clean(); echo doc_page($__doc, 'On this page'); ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
