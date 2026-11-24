<?php
require_once __DIR__ . '/helpers.php';
$title = "Contact — AIHubpedia";
$meta_desc = "Contact AIHubpedia for partnerships, tool updates, paid listings, or feedback.";

sec_session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$sent = isset($_GET['sent']) && $_GET['sent'] === '1';
$err  = trim($_GET['err'] ?? '');

$main_class = 'fullbleed';
$crumbs = [['Home', site_url('/')], ['Contact', null]];
require __DIR__ . '/layout_top.php';
$__topics = [
  ['megaphone', 'Partnership', 'Affiliates, sponsorships and promotions', ['#f59e0b', '#ef4444']],
  ['pen', 'Tool update', 'Fix a link, pricing or feature detail', ['#10b981', '#0ea5e9']],
  ['chart', 'Paid listing', 'Featured placement requests (tool name and plan)', ['#8b5cf6', '#ec4899']],
  ['chat', 'Feedback', 'Bug reports and improvements', ['#3b82f6', '#4f46e5']],
];
?>
<section class="contactSplit stage" data-tone="<?= h(page_tone()) ?>">
  <div class="wrap">
    <div class="contactSplit__copy">
      <div class="pgHero__crumbs" style="padding:0 0 14px"><?= render_breadcrumbs($crumbs) ?></div>
      <span class="eyebrow"><?= ui_icon('mail') ?> Contact</span>
      <h1>Let’s talk about <span class="gradText2">your tool or project</span></h1>
      <p class="lead">For partnerships, tool updates, paid listings or feedback, send us a message. We read every message and reply by email where a reply is needed.</p>
      <ul class="topicList">
        <?php foreach ($__topics as [$ic, $t, $d, $cc]): ?>
          <li><span class="ico3d" style="--c1:<?= $cc[0] ?>;--c2:<?= $cc[1] ?>" aria-hidden="true"><?= ui_icon($ic) ?></span><div><strong><?= h($t) ?></strong><span><?= h($d) ?></span></div></li>
        <?php endforeach; ?>
      </ul>
      <p class="small" style="color:#b5bddf">Submitting a tool? Use the <a href="/submit.php" style="text-decoration:underline">Submit page</a>. Prefer email? <a href="mailto:contact@aihubpedia.com" style="text-decoration:underline">contact@aihubpedia.com</a>.</p>
      <div class="contactSplit__art" aria-hidden="true"><?= art_chat() ?></div>
    </div>

    <div class="consult__form contactForm" id="message">
      <h2 style="margin-top:0">Send a message</h2>
      <?php if ($sent): ?>
        <div class="alertOk" role="status"><?= ui_icon('check') ?> <span><strong>Message sent.</strong> Thanks. We will get back to you if a reply is needed.</span></div>
      <?php elseif ($err): ?>
        <div class="alertErr" role="alert"><?= ui_icon('x') ?> <span><?= h($err) ?></span></div>
      <?php endif; ?>
      <form method="post" action="contact_send.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"/>
        <div class="fieldRow">
          <div class="field"><label for="cName">Your name</label><input class="input" id="cName" name="name" placeholder="Your name" autocomplete="name" required></div>
          <div class="field"><label for="cEmail">Your email</label><input class="input" id="cEmail" name="email" type="email" placeholder="you@example.com" autocomplete="email" required></div>
        </div>
        <div class="field"><label for="cSubject">Subject</label><input class="input" id="cSubject" name="subject" placeholder="Partnership / Tool update / Feedback" required></div>
        <div class="field"><label for="cMsg">Message</label><textarea class="input" id="cMsg" name="message" placeholder="Write your message..." required></textarea><p class="help">Please include the tool URL if you are requesting a listing or update.</p></div>
        <!-- Honeypot (anti-spam) -->
        <input type="text" name="company" value="" style="display:none" tabindex="-1" autocomplete="off">
        <div class="actions"><button class="btn btn--accent" type="submit">Send message</button><a class="btn secondary" href="/">Back to home</a></div>
      </form>
    </div>
  </div>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
