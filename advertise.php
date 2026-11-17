<?php
require_once __DIR__ . '/helpers.php';
$title = 'Advertise on AIHubpedia: Featured & Spotlight Listings';
$meta_desc = 'Pricing and options for labelled featured listings and homepage spotlight placements on AIHubpedia, plus affiliate partnership enquiries.';
$tiers = pricing_tiers();
$cur = (string)(site_cfg()['currency'] ?? 'USD');
$sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'][$cur] ?? '';
$crumbs = [['Home', site_url('/')], ['Advertise', null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
$default = in_array($_GET['need'] ?? '', ['affiliate_partner', 'newsletter_sponsor'], true) ? $_GET['need'] : 'advertiser';
$pkg = (string)($_GET['package'] ?? '');
if (!array_key_exists($pkg, lead_package_options())) $pkg = '';
if ($pkg === 'newsletter_sponsor') $default = 'newsletter_sponsor';
$faq = [
  ['Will paying change my tool\'s profile, ranking or reviews?', 'No. Profile facts, limitations, review status, search ranking, problem pages, comparisons and recommendations follow published rules and ignore payment. Paid slots are separate and labelled “Sponsored”.'],
  ['What results will I get?', 'We do not promise traffic, clicks, sign-ups or sales, and we do not publish audience figures. Outbound clicks through our own link redirect are recorded, and we can discuss what can be measured for your placement before you commit.'],
  ['Do you accept every tool?', 'No. Every tool is reviewed against our <a href="/methodology.php">methodology</a> first, and paying does not guarantee acceptance.'],
  ['How does payment work?', 'No payment is taken on this website. Placement dates and invoicing are agreed with you in writing before anything goes live.'],
  ['Is newsletter sponsorship available?', 'Not yet. The AIHubpedia newsletter has not launched, so there is no audience to describe. You can register interest and we will get in touch if sponsor slots are created.'],
  ['Do you offer affiliate partnerships?', 'We are open to discussing them. Affiliate links on AIHubpedia are always marked, use <code>rel="sponsored"</code> and never affect a profile\'s facts. At the moment no listing uses an affiliate link.'],
  ['My tool is not listed yet. What should I do?', 'Submit it on the <a href="/submit.php">submit page</a>. A free listing is reviewed manually; priority approval is a paid option for faster review.'],
  ['What happens to the details I send?', 'We use them to reply to your enquiry. See the <a href="/privacy.php">privacy policy</a> and our <a href="/disclosure.php">advertising disclosure</a>.'],
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<div class="pageHero" style="padding-top:0">
  <span class="eyebrow"><?= ui_icon('chart') ?> For AI vendors</span>
  <h1>Advertise your AI tool on AIHubpedia</h1>
  <p class="lead">Paid placements put your tool in front of people comparing AI tools. Every paid placement is labelled “Sponsored”, and we do not promise traffic, clicks or revenue.</p>
</div>

<section aria-labelledby="priceTitle">
  <h2 id="priceTitle">Package comparison</h2>
  <p class="small">Prices are the current list prices from our configuration (<?= h($cur) ?>, one-off, excluding any taxes). Dates are agreed in writing before payment. No payment is taken on this website.</p>
  <div class="matrixWrap" tabindex="0" role="region" aria-label="Package comparison table, scrollable">
    <table class="matrix pkgTable">
      <caption class="sr">Listing and sponsorship packages compared</caption>
      <thead><tr><th scope="col">Package</th><th scope="col">Price</th><th scope="col">Duration</th><th scope="col">Where it appears</th><th scope="col">Review</th><th scope="col">Next step</th></tr></thead>
      <tbody>
      <?php foreach (sponsorship_packages() as $key => $pk):
        $listed = ($pk['kind'] ?? '') === 'listed'; $tier = $tiers[$key] ?? null; ?>
        <tr>
          <th scope="row"><?= h($pk['label']) ?></th>
          <td data-label="Price"><?php if ($listed && $tier): ?><?= (int)($tier['price'] ?? 0) === 0 ? 'Free' : h($sym . number_format((int)$tier['price'])) . ($sym === '' ? ' ' . h($cur) : '') ?><?php else: ?><span class="cellState s-unknown">Not published</span><span class="cellEv"><?= h($pk['status_text'] ?? 'Price on request') ?></span><?php endif; ?></td>
          <td data-label="Duration"><?= h($pk['duration']) ?></td>
          <td data-label="Where it appears"><?= h($pk['placement']) ?><?= !empty($pk['paid']) ? '' : '' ?></td>
          <td data-label="Review"><?= h($pk['review']) ?></td>
          <td data-label="Next step"><?php if (in_array($key, ['free', 'priority'], true)): ?><a class="btn sm secondary" href="/submit.php">Submit a tool</a><?php else: ?><a class="btn sm" href="/advertise.php?package=<?= h(rawurlencode($key)) ?>#request">Ask about this</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small">AIHubpedia does not publish audience, traffic or conversion figures for placements, because we have not verified any we could stand behind. Ask us what can be measured for your placement before you decide.</p>
</section>

<section class="panel" style="margin-top:28px" aria-labelledby="indepTitle">
  <h2 id="indepTitle" style="margin-top:0">Editorial and paid are separate</h2>
  <div class="matrixWrap" tabindex="0" role="region" aria-label="Editorial versus paid, scrollable">
  <table class="matrix">
    <caption class="sr">What paid placements can and cannot affect</caption>
    <thead><tr><th scope="col">Part of the site</th><th scope="col">Can payment influence it?</th></tr></thead>
    <tbody>
      <tr><th scope="row">Tool profile facts, limitations and review status</th><td data-label="Can payment influence it?">No</td></tr>
      <tr><th scope="row">Search ranking, problem pages and the recommendation wizard</th><td data-label="Can payment influence it?">No. They use the published rules in the <a href="/methodology.php#search">methodology</a></td></tr>
      <tr><th scope="row">Guides, comparisons and alternatives</th><td data-label="Can payment influence it?">No</td></tr>
      <tr><th scope="row">Labelled “Sponsored” slots</th><td data-label="Can payment influence it?">Yes, and they are always marked and link to our <a href="/disclosure.php">disclosure</a></td></tr>
    </tbody>
  </table>
  </div>
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
    <p class="small">Tell us what you would like to do. Choosing a package is optional and not a commitment.</p>
    <?= render_lead_form(['types' => ['advertiser', 'affiliate_partner', 'newsletter_sponsor'], 'default' => $default, 'package' => $pkg, 'packages' => true, 'id' => 'adv', 'source' => '/advertise.php', 'budget' => false, 'message_label' => 'Tell us about your tool and what you would like to do', 'button' => 'Send enquiry']) ?>
  </div>
</div>

<section style="margin-top:32px" aria-labelledby="faqTitle">
  <h2 id="faqTitle">Advertiser FAQ</h2>
  <?php foreach ($faq as [$q, $a]): ?>
    <details class="faqItem"><summary><?= h($q) ?></summary><p><?= $a ?></p></details>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
