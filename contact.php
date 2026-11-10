<?php
require_once __DIR__ . '/helpers.php';
$title = "Contact — AIHubpedia";
$meta_desc = "Contact AIHubpedia for partnerships, tool updates, paid listings, or feedback.";

session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$sent = isset($_GET['sent']) && $_GET['sent'] === '1';
$err  = trim($_GET['err'] ?? '');

require __DIR__ . '/layout_top.php';
?>

<style>
.contactWrap{max-width:1100px;margin:0 auto}
.contactGrid{display:grid;grid-template-columns:1fr 1.1fr;gap:16px;align-items:start}
@media (max-width: 980px){.contactGrid{grid-template-columns:1fr}}
.infoCard{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:18px;padding:16px}
.formCard{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:18px;padding:16px}
.kv{display:grid;grid-template-columns:140px 1fr;gap:8px 12px;margin-top:12px}
.kv div{font-size:13px;color:#9ca3af}
.kv b{color:#e5e7eb}
.alertOk{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);border-radius:16px;padding:14px;margin-bottom:14px}
.alertErr{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.25);border-radius:16px;padding:14px;margin-bottom:14px}
.help{font-size:12px;color:#9ca3af;margin-top:6px}
.fieldRow{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 720px){.fieldRow{grid-template-columns:1fr}}
textarea.input{min-height:140px;resize:vertical}
</style>

<div class="contactWrap">
  <section class="hero" style="padding-bottom:10px">
    <h1>Contact</h1>
    <p>For partnerships, tool updates, paid listings, or feedback — send us a message. We usually reply within 1–2 business days.</p>
  </section>

  <?php if($sent): ?>
    <div class="alertOk">✅ Message sent! Thanks — we’ll get back to you soon.</div>
  <?php elseif($err): ?>
    <div class="alertErr">❌ <?= h($err) ?></div>
  <?php endif; ?>

  <div class="contactGrid">

    <!-- Left: info -->
    <div class="infoCard">
      <h2 style="margin-top:0">Quick info</h2>
      <p class="small">Use the form to reach us. For paid listings, include your tool name + plan.</p>

      <div class="kv">
        <div><b>Topic</b></div><div><b>Best for</b></div>
        <div>Partnership</div><div>Affiliates, sponsorships, promos</div>
        <div>Tool update</div><div>Fix links, update pricing/features</div>
        <div>Paid listing</div><div>Featured placement requests</div>
        <div>Feedback</div><div>Bug reports and improvements</div>
      </div>

      <div style="margin-top:14px" class="small">
        <b>Tip:</b> If you’re submitting a tool, use the <a href="submit.php">Submit page</a>.
      </div>

      <!--<div class="ad" style="margin-top:14px">Ad placeholder (Contact sidebar)</div>-->
    </div>

    <!-- Right: form -->
    <div class="formCard">
      <h2 style="margin-top:0">Send a message</h2>

      <form method="post" action="contact_send.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"/>

        <div class="fieldRow">
          <div>
            <label class="small">Your name</label>
            <input class="input" name="name" placeholder="Your name" required>
          </div>
          <div>
            <label class="small">Your email</label>
            <input class="input" name="email" type="email" placeholder="you@example.com" required>
          </div>
        </div>

        <label class="small" style="margin-top:10px">Subject</label>
        <input class="input" name="subject" placeholder="Partnership / Tool update / Feedback" required>

        <label class="small" style="margin-top:10px">Message</label>
        <textarea class="input" name="message" placeholder="Write your message..." required></textarea>
        <div class="help">Please include tool URL if you’re requesting a listing/update.</div>

        <!-- Honeypot (anti-spam) -->
        <input type="text" name="company" value="" style="display:none" tabindex="-1" autocomplete="off">

        <div class="actions" style="margin-top:12px">
          <button class="btn" type="submit">Send message</button>
          <a class="btn secondary" href="index.php">Back</a>
        </div>
      </form>
    </div>

  </div>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
