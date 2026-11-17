<?php
require_once __DIR__ . '/helpers.php';
$crumbs = [['Home', site_url('/')], ['Saved tools', null]];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<div class="pageHero" style="padding-top:0">
  <h1>Your saved tools</h1>
  <p class="lead">Tools you save are stored only in this browser, so there is no account and nothing is sent to our servers. Clearing your browser data removes them.</p>
</div>
<noscript><p class="noticeBox">Saving tools needs JavaScript. You can still browse <a href="/categories.php">all categories</a>.</p></noscript>
<div data-favorites-app>
  <div class="cardGrid" data-favorites-list></div>
  <div class="panel" data-favorites-empty hidden>
    <h2>No saved tools yet</h2>
    <p class="small">Tap the heart on any tool card to keep it here, then compare your shortlist.</p>
    <div class="actions"><a class="btn" href="/categories.php">Browse categories</a><a class="btn secondary" href="/guides.php">Read the guides</a></div>
  </div>
</div>
<section class="panel" data-shortlists hidden style="margin-top:22px" aria-labelledby="slTitle">
  <h2 id="slTitle" style="margin-top:0">Saved shortlists</h2>
  <p class="small">Shortlists from the <a href="/recommend.php">recommendation wizard</a>, saved in this browser only.</p>
  <ul class="linkList" data-shortlists-list></ul>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
