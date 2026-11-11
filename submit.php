<?php
require_once __DIR__ . '/helpers.php';
$title = "Submit a tool — AIHubpedia";

$tiers = pricing_tiers();
$pay = payment_links();

$ok = false;
$err = '';
$selected = trim($_POST['plan'] ?? 'free');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  if(!csrf_validate()){
    $err = "Security validation failed. Please refresh and try again.";
  } else {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $short = trim($_POST['short'] ?? '');
    $plan = trim($_POST['plan'] ?? 'free');

    if($name==='' || $category==='' || $website==='' || $short===''){
      $err = "Please fill in all fields.";
    } elseif(!isset($tiers[$plan])){
      $err = "Invalid plan.";
    } else {
      $tier = $tiers[$plan];
      $price = (int)($tier['price'] ?? 0);

      $saveRes = save_submission_dual([
        'name' => $name,
        'category' => $category,
        'website' => $website,
        'short' => $short,
        'plan' => $plan,
        'price' => $price,
        'currency' => (site_cfg()['currency'] ?? 'USD'),
        'status' => 'pending',
        'payment_status' => ($price > 0 ? 'unpaid' : 'not_required')
      ]);

      if ($saveRes['success']) {
        $ok = true;
        $selected = $plan;
      } else {
        $err = "Submission failed: " . ($saveRes['error'] ?? 'Please try again later.');
      }
    }
  }
}

$data = load_tools();
$cats = $data['categories'] ?? [];

$selectedTier = $tiers[$selected] ?? ['label'=>$selected,'price'=>0];
$selectedPrice = (int)($selectedTier['price'] ?? 0);

require __DIR__ . '/layout_top.php';
?>

<style>
/* Submit page layout */
.submitWrap{max-width:1100px;margin:0 auto}
.submitHeader{margin:10px 0 18px}
.submitGrid{display:grid;grid-template-columns:1fr 1.25fr;gap:16px;align-items:start}
@media (max-width: 980px){.submitGrid{grid-template-columns:1fr}}

.alertOk{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);border-radius:16px;padding:14px}
.alertErr{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.25);border-radius:16px;padding:14px}

.planGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 520px){.planGrid{grid-template-columns:1fr}}

.plan{
  padding:14px;border-radius:16px;background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.07)
}
.plan .top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.plan .name{font-weight:900}
.plan .price{font-weight:900}
.plan .desc{margin-top:8px;color:#9ca3af;font-size:13px;line-height:1.4}
.plan .pill{
  display:inline-block;margin-top:10px;font-size:12px;padding:6px 10px;border-radius:999px;
  border:1px solid rgba(255,255,255,.10);color:#e5e7eb
}
.plan.featured{
  background:rgba(34,197,94,.08);
  border:1px solid rgba(34,197,94,.25);
}

.payBox{
  margin-top:14px;
  background:rgba(59,130,246,.10);
  border:1px solid rgba(59,130,246,.22);
  border-radius:16px;
  padding:14px
}
.payBox h3{margin:0 0 6px}

.formCard{
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.07);
  border-radius:18px;
  padding:16px
}
.fieldRow{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 720px){.fieldRow{grid-template-columns:1fr}}
.help{font-size:12px;color:#9ca3af;margin-top:6px}
.hr{height:1px;background:rgba(255,255,255,.08);margin:14px 0}
</style>

<div class="submitWrap">

  <div class="submitHeader">
    <h1 style="margin:0 0 8px">Submit an AI tool</h1>
    <p class="small" style="margin:0;color:#9ca3af">
      Free submissions are reviewed manually. Paid listings are prioritized and can be featured on the homepage.
    </p>
  </div>

  <?php if($ok): ?>
    <div class="alertOk" style="margin-bottom:14px">✅ Submitted! It is now <b>pending review</b>.</div>
  <?php elseif($err): ?>
    <div class="alertErr" style="margin-bottom:14px">❌ <?= h($err) ?></div>
  <?php endif; ?>

  <div class="submitGrid">

    <!-- LEFT: Plans -->
    <div class="card">
      <h2 style="margin-top:0">Pricing</h2>
      <p class="small">Pick a plan. Featured plans get homepage placement.</p>

      <div class="planGrid">
        <div class="plan">
          <div class="top">
            <div class="name">Free listing</div>
            <div class="price">$0</div>
          </div>
          <div class="desc">Manual approval. May take several days depending on queue.</div>
          <span class="pill">Standard review</span>
        </div>

        <div class="plan">
          <div class="top">
            <div class="name">Priority approval</div>
            <div class="price">$19</div>
          </div>
          <div class="desc">Approved within 24 hours (after payment).</div>
          <span class="pill">24h approval</span>
        </div>

        <div class="plan featured">
          <div class="top">
            <div class="name">Featured listing</div>
            <div class="price">$49</div>
          </div>
          <div class="desc">Homepage placement for 7 days (after approval).</div>
          <span class="pill">Homepage (7 days)</span>
        </div>

        <div class="plan featured">
          <div class="top">
            <div class="name">Homepage Spotlight</div>
            <div class="price">$99</div>
          </div>
          <div class="desc">Top homepage spotlight for 14 days (after approval).</div>
          <span class="pill">Top spot (14 days)</span>
        </div>
      </div>

      <div class="hr"></div>
  <!--    <p class="small" style="margin:0">
        Configure PayPal/Stripe URLs in <code>data/site.json</code>.
      </p>
-->
      <?php if($ok && $selectedPrice > 0): ?>
        <div class="payBox">
          <h3>Next step: Payment</h3>
          <p class="small" style="margin:0 0 10px">
            You selected: <b><?= h($selectedTier['label'] ?? $selected) ?></b> — <b>$<?= (int)$selectedPrice ?></b>.
          </p>
 <!--         <div class="actions">
            <a class="btn" target="_blank" rel="noopener" href="<?= h($pay['stripe_url'] ?? 'https://stripe.com/') ?>">Pay with Stripe</a>
            <a class="btn secondary" target="_blank" rel="noopener" href="<?= h($pay['paypal_url'] ?? 'https://paypal.com/') ?>">Pay with PayPal</a>
          </div> -->
          <p class="help">After payment, send us the receipt (or we mark it as paid in admin), then we approve it.</p>
        </div>
      <?php endif; ?>

    </div>

    <!-- RIGHT: Form -->
    <div class="formCard">
      <h2 style="margin-top:0">Tool information</h2>
      <p class="small">We review all submissions before publishing.</p>

      <form method="post">
        <?= csrf_field() ?>
        <div class="fieldRow">
          <div>
            <label class="small">Tool name</label>
            <input class="input" name="name" placeholder="e.g., MyAwesomeAI" required>
          </div>

          <div>
            <label class="small">Category</label>
            <select name="category" required>
              <option value="">Select</option>
              <?php foreach($cats as $c): ?>
                <option value="<?= h($c) ?>"><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <label class="small" style="margin-top:10px">Website URL</label>
        <input class="input" name="website" placeholder="https://..." required>
        <div class="help">Use the official website of the tool.</div>

        <label class="small" style="margin-top:10px">Short description</label>
        <input class="input" name="short" placeholder="One sentence about the tool" required>
        <div class="help">Example: “AI writing assistant for blogs and SEO.”</div>

        <label class="small" style="margin-top:10px">Listing plan</label>
        <select name="plan" required>
          <?php foreach($tiers as $k=>$t): ?>
            <option value="<?= h($k) ?>"><?= h(($t['label'] ?? $k) . ' — $' . (int)($t['price'] ?? 0)) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="actions" style="margin-top:12px">
          <button class="btn" type="submit">Submit</button>
          <a class="btn secondary" href="privacy.php">Privacy</a>
        </div>
      </form>
    </div>

  </div>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>