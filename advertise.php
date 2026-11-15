<?php
require_once __DIR__ . '/helpers.php';
$title = 'Advertise on AIHubpedia: Featured & Spotlight Listings';
$meta_desc = 'Pricing and options for labelled featured listings and homepage spotlight placements on AIHubpedia, plus affiliate partnership enquiries.';
$tiers = pricing_tiers();
$cur = (string)(site_cfg()['currency'] ?? 'USD');
$sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'][$cur] ?? '';
$crumbs = [['Home', site_url('/')], ['Advertise', null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
$default = ($_GET['need'] ?? '') === 'affiliate_partner' ? 'affiliate_partner' : 'advertiser';
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<div class="pageHero" style="padding-top:0">
  <span class="eyebrow"><?= ui_icon('chart') ?> For AI vendors</span>
  <h1>Advertise your AI tool on AIHubpedia</h1>
  <p class="lead">Paid placements put your tool in front of people comparing AI tools. Every paid placement is labelled “Sponsored”, and we do not promise traffic, clicks or revenue.</p>
</div>

<section aria-labelledby="priceTitle">
  <h2 id="priceTitle">Listing options</h2>
  <div class="pricingGrid">
    <?php foreach ($tiers as $key => $tier): ?>
      <div class="priceCard">
        <h3><?= h($tier['label'] ?? $key) ?></h3>
        <div class="amt"><?= (int)($tier['price'] ?? 0) === 0 ? 'Free' : h($sym . number_format((int)$tier['price'])) . (($sym === '') ? ' ' . h($cur) : '') ?></div>
        <p class="small"><?= h($tier['desc'] ?? '') ?></p>
        <?php if (in_array($key, ['free', 'priority'], true)): ?><a class="btn sm secondary" href="/submit.php">Submit a tool</a><?php else: ?><a class="btn sm" href="#request">Ask about this</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small">Prices are the current list prices from our configuration and exclude any taxes. Placement dates are agreed in writing before payment.</p>
</section>

<div class="twoCol" style="margin-top:28px">
  <div>
    <div class="panel">
      <h2>What a paid placement does and does not do</h2>
      <h3>It does</h3>
      <ul class="small"><li>Show your tool in a section labelled “Sponsored”, with a link to our <a href="/disclosure.php">disclosure</a></li><li>Link to your tool's AIHubpedia profile and website (with <code>rel="sponsored"</code>)</li></ul>
      <h3>It does not</h3>
      <ul class="small"><li>Change your profile's facts, limitations, review status, votes or ratings</li><li>Guarantee a number of visits, clicks, sign-ups or sales</li><li>Buy a review, a guide mention or a ranking</li></ul>
    </div>
    <div class="panel" style="margin-top:18px">
      <h2>Affiliate partnerships</h2>
      <p class="small">If your product has an affiliate or referral programme, tell us. Affiliate links on AIHubpedia are always marked and never affect a profile's facts. At the moment no listing uses an affiliate link.</p>
    </div>
    <div class="panel" style="margin-top:18px">
      <h2>How it works</h2>
      <ol class="steps"><li><div>Send your request below (or <a href="/submit.php">submit your tool</a> for a standard listing).</div></li><li><div>We review the tool against our <a href="/methodology.php">methodology</a>; not every tool is accepted.</div></li><li><div>If accepted, we agree the placement and dates by email before anything goes live.</div></li></ol>
    </div>
  </div>
  <div class="panel" id="request">
    <h2>Advertiser or partner enquiry</h2>
    <?= render_lead_form(['types' => ['advertiser', 'affiliate_partner'], 'default' => $default, 'id' => 'adv', 'source' => '/advertise.php', 'budget' => false, 'message_label' => 'Tell us about your tool and what you would like to do', 'button' => 'Send enquiry']) ?>
  </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
