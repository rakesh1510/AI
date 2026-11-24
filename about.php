<?php
require_once __DIR__ . '/helpers.php';
$title = "About — AIHubpedia";
$meta_desc = "About AIHubpedia — a trusted platform to discover, compare, and explore AI tools for productivity, business, marketing, design, and development.";
$main_class = 'fullbleed';
$crumbs = [['Home', site_url('/')], ['About', null]];
require __DIR__ . '/layout_top.php';
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'About AIHubpedia', 'icon' => 'shield', 'title_html' => 'Helping users and businesses discover the right AI tools <span class="gradText2">with confidence</span>',
  'lead' => 'AIHubpedia is a growing platform built to make AI tool discovery simple, trustworthy, and practical. We help users, founders, teams, and businesses explore high-value AI tools in one place, with clear listings, smart comparisons, and useful insights that save time and support better decisions.', 'art' => 'shield',
  'actions' => '<a class="btn btn--accent" href="/categories.php">Explore AI tools</a><a class="btn secondary" href="/methodology.php">How we review</a>']);
?>
<div class="wrap pgBody">
  <div class="bento">
    <section class="bento__cell bento__cell--b" style="--c1:#8b5cf6;--c2:#ec4899"><span class="ico3d"><?= ui_icon('sparkle') ?></span><h2 style="font-size:1.5rem">Our Mission</h2><p>Our mission is to make artificial intelligence easier to understand and easier to use by helping people find the most relevant tools for their real needs.</p></section>
    <section class="bento__cell bento__cell--b" style="--c1:#06b6d4;--c2:#2563eb"><span class="ico3d"><?= ui_icon('search') ?></span><h2 style="font-size:1.5rem">Our Focus</h2><p>We focus on practical value, clean presentation, and quality discovery so visitors can quickly understand what a tool does, who it is for, and why it matters.</p></section>
    <section class="bento__cell bento__cell--b" style="--c1:#10b981;--c2:#0ea5e9"><span class="ico3d"><?= ui_icon('chart') ?></span><h2 style="font-size:1.5rem">Our Goal</h2><p>We aim to become a trusted destination for users looking for AI solutions and for companies seeking visibility in front of an audience actively exploring AI products.</p></section>
  </div>
</div>

<section class="bizBand stage" aria-labelledby="whyT">
  <div class="wrap pgHero" style="padding:0"><div class="wrap" style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:clamp(20px,4vw,56px);align-items:center;padding:0">
    <div>
      <span class="eyebrow"><?= ui_icon('compare') ?> Why we exist</span>
      <h2 id="whyT" style="margin:14px 0">Why AIHubpedia Exists</h2>
      <p class="lead" style="font-size:1.0625rem">The AI space is moving fast, and new tools appear every day. For many users, this creates confusion: too many choices, too much noise, and not enough clarity. AIHubpedia was created to solve that problem.</p>
      <p class="lead" style="font-size:1.0625rem;margin-bottom:0">Instead of wasting time jumping between websites, blogs, and product pages, users can come to AIHubpedia to explore tools in a more organized and reliable way. Our goal is to reduce friction in discovery and help people find tools that genuinely improve productivity, creativity, automation, business operations, and daily work.</p>
    </div>
    <div class="pgHero__art" aria-hidden="true"><?= art_compass() ?></div>
  </div></div>
</section>

<div class="wrap pgBody">
  <div class="sectionHead"><div><h2>What We Offer</h2><p>AIHubpedia is more than a simple list of tools. We are building a platform where visitors can discover, compare, and understand AI products in a way that feels easy and trustworthy.</p></div></div>
  <div class="bento">
    <?php foreach ([['book', 'Curated Listings', 'Well-organized AI tools across multiple categories and use cases.', '#8b5cf6', '#6366f1'], ['compare', 'Practical Comparisons', 'Clear comparison views to help users evaluate options faster.', '#06b6d4', '#2563eb'], ['search', 'Use-Case Discovery', 'Visitors can explore tools based on real needs, not just names.', '#10b981', '#0ea5e9'], ['megaphone', 'Growing Visibility', 'AI companies can reach relevant users who are actively searching.', '#f59e0b', '#ef4444']] as [$ic, $t, $d, $c1, $c2]): ?>
      <section class="bento__cell bento__cell--b" style="--c1:<?= $c1 ?>;--c2:<?= $c2 ?>;grid-column:span 3"><span class="ico3d"><?= ui_icon($ic) ?></span><h3><?= h($t) ?></h3><p><?= h($d) ?></p></section>
    <?php endforeach; ?>
    <section class="bento__cell bento__cell--w bento__cell--dark"><h3>Built for</h3>
      <ul class="chipRow"><li>Professionals looking to work faster</li><li>Businesses searching for smart AI solutions</li><li>Marketers, developers, and creators</li><li>Startups exploring productivity and automation</li><li>AI companies wanting exposure and credibility</li></ul></section>
  </div>

  <div class="sectionHead" style="margin-top:var(--s-6)"><div><h2>Why Users and Clients Can Trust AIHubpedia</h2></div></div>
  <ul class="trustRow trustRow--4">
    <?php foreach ([['Clear Presentation', 'We aim to keep information easy to understand, so visitors can make decisions without confusion.'], ['Practical Value', 'We focus on usefulness, not just trends — helping people find tools that solve real problems.'], ['Professional Approach', 'AIHubpedia is designed to feel reliable, modern, and valuable for both users and product teams.'], ['Long-Term Vision', 'We are building a platform that grows with the AI ecosystem and supports meaningful discovery over time.']] as $n => [$t, $d]): ?>
      <li class="card"><span class="ico3d" style="--c1:#8b5cf6;--c2:#2563eb"><?= $n + 1 ?></span><h3><?= h($t) ?></h3><p><?= h($d) ?></p></li>
    <?php endforeach; ?>
  </ul>

  <section class="glass" style="margin-top:var(--s-6);padding:clamp(24px,4vw,44px)">
    <h2 style="margin-top:0">For AI Tool Companies and Clients</h2>
    <p>AIHubpedia is also a visibility platform for founders, product teams, and AI businesses that want to reach the right audience. If you offer a valuable AI product, being present on AIHubpedia can help more people discover your solution, understand its strengths, and consider it with confidence.</p>
    <p>We want to create a win-win ecosystem: users discover better tools faster, and quality AI products gain stronger exposure, trust, and awareness in a competitive market.</p>
    <div class="actions"><a class="btn" href="/submit.php">Submit a tool</a><a class="btn secondary" href="/advertise.php">Advertise</a></div>
  </section>
</div>

<section class="bizBand stage visionBand" aria-labelledby="visT">
  <div class="wrap" style="text-align:center;max-width:900px">
    <span class="eyebrow"><?= ui_icon('sparkle') ?> Vision</span>
    <h2 id="visT" style="margin:14px 0">Our Vision</h2>
    <p class="lead" style="margin:0 auto;font-size:var(--t-lead)">We believe the future of work will be shaped by intelligent tools, better workflows, and smarter decisions. AIHubpedia exists to make that future easier to access — by helping people discover the right AI tools with trust, clarity, and confidence.</p>
  </div>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
