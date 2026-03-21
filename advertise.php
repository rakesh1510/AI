<?php
require_once __DIR__ . '/helpers.php';
$title = "Advertise — AIHubpedia";
$meta_desc = "Promote your AI tool on AIHubpedia with featured listings and homepage spotlight placements.";
require __DIR__ . '/layout_top.php';
?>

<h1>Advertise on AIHubpedia</h1>

<div class="card">
  <p class="small" style="font-size:16px;line-height:1.7">
    Reach people actively searching for the best AI tools. Promote your product on AIHubpedia
    with premium placements designed for visibility, traffic, and discovery.
  </p>
</div>

<section class="grid" style="margin-top:20px">

  <div class="card" style="background:rgba(255,255,255,.03)">
    <div class="badge" style="margin-bottom:10px">Featured Listing</div>
    <h2 style="margin:0 0 10px">$49</h2>
    <p class="small" style="margin-bottom:16px">
      Homepage placement for 7 days after approval.
    </p>

    <ul class="small" style="padding-left:18px;line-height:1.9;margin:0 0 18px">
      <li>Homepage featured section</li>
      <li>Visible to AI tool seekers</li>
      <li>Direct traffic to your tool page</li>
      <li>Best for launches and promotions</li>
    </ul>

    <div class="actions">
      <a class="btn" href="submit.php">Choose Featured</a>
    </div>
  </div>

  <div class="card" style="background:linear-gradient(135deg, rgba(124,58,237,.18), rgba(34,197,94,.12));border:1px solid rgba(255,255,255,.15)">
    <div class="badge" style="margin-bottom:10px">Homepage Spotlight</div>
    <h2 style="margin:0 0 10px">$99</h2>
    <p class="small" style="margin-bottom:16px">
      Top homepage spotlight for 14 days after approval.
    </p>

    <ul class="small" style="padding-left:18px;line-height:1.9;margin:0 0 18px">
      <li>Premium top homepage visibility</li>
      <li>Maximum clicks and attention</li>
      <li>Ideal for product launches</li>
      <li>Longer placement duration</li>
    </ul>

    <div class="actions">
      <a class="btn" href="submit.php">Choose Spotlight</a>
    </div>
  </div>

</section>

<section class="card" style="margin-top:20px">
  <h2 style="margin-top:0">How it works</h2>
  <div class="grid">
    <div class="card" style="background:rgba(255,255,255,.03)">
      <h3 style="margin-top:0">1. Submit your tool</h3>
      <p class="small">
        Send us your AI tool details, website link, category, and a short description.
      </p>
    </div>

    <div class="card" style="background:rgba(255,255,255,.03)">
      <h3 style="margin-top:0">2. Review and approval</h3>
      <p class="small">
        We review each submission to make sure it matches the quality and purpose of AIHubpedia.
      </p>
    </div>

    <div class="card" style="background:rgba(255,255,255,.03)">
      <h3 style="margin-top:0">3. Go live</h3>
      <p class="small">
        Once approved, your featured placement goes live and starts getting visibility on the platform.
      </p>
    </div>
  </div>
</section>

<section class="card" style="margin-top:20px">
  <h2 style="margin-top:0">Why promote on AIHubpedia?</h2>
  <ul class="small" style="padding-left:18px;line-height:1.9;margin:0">
    <li>Reach users actively exploring AI tools</li>
    <li>Increase awareness for your product</li>
    <li>Get more targeted clicks and visibility</li>
    <li>Stand out in a growing AI tools directory</li>
  </ul>
</section>

<section class="card" style="margin-top:20px">
  <h2 style="margin-top:0">Ready to promote your AI tool?</h2>
  <p class="small">
    Choose a placement and submit your tool today.
  </p>
  <div class="actions">
    <a class="btn" href="submit.php">Submit your tool</a>
    <a class="btn secondary" href="contact.php">Contact us</a>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>