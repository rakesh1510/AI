<?php
require_once __DIR__ . '/helpers.php';
$title = "Submit a tool — AIHubpedia";

$tiers = pricing_tiers();
$pay = payment_links();

$ok = false;
$err = '';
$selected = trim($_POST['plan'] ?? 'free');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  $name = trim($_POST['name'] ?? '');
  $category = trim($_POST['category'] ?? '');
  $website = trim($_POST['website'] ?? '');
  $short = trim($_POST['short'] ?? '');
  $plan = trim($_POST['plan'] ?? 'free');

  $name = mb_substr($name, 0, 120); $category = mb_substr($category, 0, 80); $short = mb_substr($short, 0, 600);
  $safeSite = sec_safe_external_url((preg_match('#^https?://#i', $website) ? $website : 'https://' . $website));
  if(!sec_csrf_ok('submit') || !empty($_POST['website_url_confirm'])){
    sec_log('submit_rejected', ['why' => empty($_POST['website_url_confirm']) ? 'csrf' : 'honeypot']);
    $err = "Your form expired. Please reload the page and try again.";
  } elseif(sec_rate_limited('submit_tool', 5, 3600)){
    sec_log('rate_limited', ['scope' => 'submit_tool']);
    $err = "Too many submissions from your network. Please try again later.";
  } elseif($name==='' || $category==='' || $website==='' || $short===''){
    $err = "Please fill in all fields.";
  } elseif($safeSite === null){
    $err = "Please enter a valid public website address (http or https).";
  } elseif(!isset($tiers[$plan])){
    $err = "Invalid plan.";
  } else {
    $tier = $tiers[$plan];
    $price = (int)($tier['price'] ?? 0);

    require_once __DIR__ . '/db.php';
try {
$stmt = db()->prepare("
INSERT INTO submissions
(name, category, website, short_desc, plan, price, currency, status, payment_status)
VALUES
(:name,:category,:website,:short_desc,:plan,:price,:currency,'pending',:payment_status)
");

$stmt->execute([
  ':name' => $name,
  ':category' => $category,
  ':website' => $safeSite,
  ':short_desc' => $short,
  ':plan' => $plan,
  ':price' => $price,
  ':currency' => (site_cfg()['currency'] ?? 'USD'),
  ':payment_status' => ($price > 0 ? 'unpaid' : 'not_required')
]);
    $ok = true;
    $selected = $plan;
    } catch(Throwable $e){ sec_log_exception($e, 'submit.php'); $err = "Sorry, your submission could not be saved. Please try again later."; }
  }
}

$data = load_tools();
$cats = $data['categories'] ?? [];

$selectedTier = $tiers[$selected] ?? ['label'=>$selected,'price'=>0];
$selectedPrice = (int)($selectedTier['price'] ?? 0);

$main_class = 'fullbleed';
$crumbs = [['Home', site_url('/')], ['Submit a tool', null]];
require __DIR__ . '/layout_top.php';
$__plans = [
  'free'     => ['Free listing', '$0', 'Manual approval. May take several days depending on queue.', 'Standard review', 'listing', ['#06b6d4', '#2563eb']],
  'priority' => ['Priority approval', '$19', 'Approved within 24 hours (after payment).', '24h approval', 'listing', ['#8b5cf6', '#6366f1']],
  'featured' => ['Featured listing', '$49', 'Homepage placement for 7 days (after approval).', 'Homepage (7 days)', 'featured', ['#a855f7', '#ec4899']],
  'homepage' => ['Homepage Spotlight', '$99', 'Top homepage spotlight for 14 days (after approval).', 'Top spot (14 days)', 'spotlight', ['#f59e0b', '#ef4444']],
];
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Submit an AI tool', 'icon' => 'plug', 'title_html' => 'Get your AI tool <span class="gradText2">listed on AIHubpedia</span>',
  'lead' => 'Free submissions are reviewed manually. Paid listings are prioritized and can be featured on the homepage.', 'art' => 'steps',
  'facts' => ['Every tool is reviewed before publishing', 'Free listing available', 'Paid options never change reviews or rankings']]);
?>
<div class="wrap pgBody">
  <?php if ($ok): ?>
    <div class="stateBox stateBox--ok" role="status" style="margin-bottom:28px">
      <div><span class="eyebrow"><?= ui_icon('check') ?> Submitted</span><h2>Thanks. Your tool is now pending review.</h2>
        <p>We review every submission before publishing. <?php if ($selectedPrice > 0): ?>Your selected plan needs payment before it is approved: see the next step below.<?php else: ?>Free listings are reviewed in order, so this can take several days.<?php endif; ?></p></div>
      <?= art_mail() ?>
    </div>
  <?php elseif ($err): ?>
    <div class="alertErr" role="alert" style="margin-bottom:22px"><?= ui_icon('x') ?> <span><?= h($err) ?></span></div>
  <?php endif; ?>

  <form method="post" class="submitForm">
    <?= sec_csrf_field('submit') ?>
    <input type="text" name="website_url_confirm" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px">

    <fieldset class="planPick">
      <legend><span class="stepNo">1</span> Choose a listing plan</legend>
      <p class="small">Pick a plan. Featured plans get homepage placement. You can change your mind before you submit.</p>
      <div class="planCards">
        <?php foreach ($__plans as $key => [$label, $price, $desc, $pill, $slot, $cc]): if (!isset($tiers[$key])) continue; ?>
          <label class="planCard<?= $key === 'featured' ? ' planCard--rec' : '' ?>" style="--c1:<?= $cc[0] ?>;--c2:<?= $cc[1] ?>">
            <input type="radio" name="plan" value="<?= h($key) ?>"<?= $selected === $key || ($selected === '' && $key === 'free') ? ' checked' : '' ?> required>
            <span class="planCard__body">
              <?php if ($key === 'featured'): ?><span class="planCard__tag">Popular choice</span><?php endif; ?>
              <span class="planCard__top"><strong><?= h($label) ?></strong><span class="planCard__price"><?= h($price) ?></span></span>
              <?= art_placement($slot) ?>
              <span class="planCard__desc"><?= h($desc) ?></span>
              <span class="badge3"><?= h($pill) ?></span>
              <span class="planCard__check" aria-hidden="true"><?= ui_icon('check') ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="submitCols">
      <div class="glass submitCard">
        <h2><span class="stepNo">2</span> Tool information</h2>
        <p class="small">We review all submissions before publishing.</p>
        <div class="fieldRow">
          <div class="field"><label for="sName">Tool name</label><input class="input" id="sName" name="name" placeholder="e.g., MyAwesomeAI" required></div>
          <div class="field"><label for="sCat">Category</label>
            <select id="sCat" name="category" required><option value="">Select</option><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label for="sWeb">Website URL</label><input class="input" id="sWeb" name="website" placeholder="https://..." required><p class="help">Use the official website of the tool.</p></div>
        <div class="field"><label for="sShort">Short description</label><input class="input" id="sShort" name="short" placeholder="One sentence about the tool" required><p class="help">Example: “AI writing assistant for blogs and SEO.”</p></div>
        <div class="actions"><button class="btn btn--accent" type="submit">Submit tool</button><a class="btn secondary" href="/privacy.php">Privacy</a></div>
      </div>

      <aside class="submitAside" aria-label="What happens next">
        <div class="card">
          <h2 style="font-size:1.35rem;margin-top:0">What happens next</h2>
          <ol class="steps3">
            <li><strong>You submit</strong><span>Your tool is saved as pending review.</span></li>
            <li><strong>We review</strong><span>Against our <a href="/methodology.php">methodology</a>. Not every tool is accepted, and paying does not guarantee acceptance.</span></li>
            <li><strong>It goes live</strong><span>Approved tools appear in the directory. Paid placements are labelled “Sponsored”.</span></li>
          </ol>
        </div>
        <?php if ($ok && $selectedPrice > 0): ?>
        <div class="payBox">
          <h3>Next step: Payment</h3>
          <p class="small" style="margin:0 0 10px">You selected: <b><?= h($selectedTier['label'] ?? $selected) ?></b> — <b>$<?= (int)$selectedPrice ?></b>.</p>
          <?php /* Payment buttons are intentionally not shown: no payment is taken on this website. */ ?>
          <p class="help">After payment, send us the receipt (or we mark it as paid in admin), then we approve it.</p>
        </div>
        <?php endif; ?>
      </aside>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
