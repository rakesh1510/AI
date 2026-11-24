<?php
// TrustNova Tech AI development services: landing pages and a guided project inquiry. No client names, case studies or results are claimed.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
$cat = trustnova_catalog(); $services = $cat['services']; $slug = (string)($_GET['s'] ?? ''); $svc = $services[$slug] ?? null;
if ($slug !== '' && !$svc) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
if ($svc) { $title = $svc['title'] . ' by TrustNova Tech — AIHubpedia'; $meta_desc = mb_substr($svc['summary'], 0, 158); $crumbs = [['Home', site_url('/')], ['Services', site_url('/services.php')], [$svc['title'], null]]; }
else { $title = 'AI Development Services by TrustNova Tech — Agents, RAG, Automation, Custom Software'; $meta_desc = 'TrustNova Tech builds custom AI agents, enterprise assistants, document intelligence, workflow automation and custom software. Describe your project and get a reply by email.'; $crumbs = [['Home', site_url('/')], ['Services', null]]; }
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__ico = ['custom-ai-agents' => 'sparkle', 'enterprise-ai-assistants' => 'chat', 'rag-document-intelligence' => 'book', 'ai-workflow-automation' => 'flow', 'custom-saas-applications' => 'layers', 'api-integrations' => 'plug', 'backend-development' => 'database', 'software-modernization' => 'code', 'cloud-solutions' => 'bolt', 'ai-business-applications' => 'briefcase'];
$__acc = [['#8b5cf6', '#ec4899'], ['#06b6d4', '#2563eb'], ['#10b981', '#0ea5e9'], ['#f59e0b', '#ef4444'], ['#3b82f6', '#4f46e5']];
$__spans = ['a', 'b', 'b', 'b', 'b', 'b', 'b', 'b', 'a', 'a'];   // bento rhythm: 4+2, then threes of 2, then 3+3
$__spanClass = [0 => 'c', 1 => 'b'];
?>
<?php if ($svc): ?>
<?= page_hero(['crumbs' => $crumbs, 'eyebrow' => 'AI development services', 'icon' => $__ico[$slug] ?? 'code', 'title' => $svc['title'], 'lead' => $h($svc['summary']), 'art' => 'agent',
  'actions' => '<a class="btn btn--accent" href="#inquiry" data-cta="svc_describe">Describe your project</a><a class="btn secondary" href="/services.php">All services</a>']) ?>
<div class="wrap pgBody">
  <div class="bento">
    <section class="bento__cell bento__cell--a"><span class="ico3d" style="--c1:#06b6d4;--c2:#2563eb"><?= ui_icon('check') ?></span><h2 style="font-size:1.5rem">Who this is for</h2><ul class="tickList"><?php foreach ($svc['good_fit'] as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul></section>
    <section class="bento__cell bento__cell--a"><span class="ico3d" style="--c1:#10b981;--c2:#0ea5e9"><?= ui_icon('layers') ?></span><h2 style="font-size:1.5rem">What you can expect</h2><ul class="tickList"><?php foreach ($svc['deliverables'] as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul></section>
    <section class="bento__cell bento__cell--a"><span class="ico3d" style="--c1:#8b5cf6;--c2:#ec4899"><?= ui_icon('chat') ?></span><h2 style="font-size:1.5rem">Questions we will ask</h2><ul class="tickList"><?php foreach ($svc['questions'] as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul></section>
    <section class="bento__cell bento__cell--a bento__cell--dark"><h2 style="font-size:1.5rem">How an engagement works</h2><ol class="miniSteps"><?php foreach ($cat['process'] as $pp): ?><li><strong><?= $h($pp['step']) ?></strong><span><?= $h($pp['text']) ?></span></li><?php endforeach; ?></ol></section>
  </div>
</div>
<?php else: ?>
<?= page_hero(['crumbs' => $crumbs, 'eyebrow' => 'AI development services', 'icon' => 'code', 'title_html' => 'Custom AI and software, <span class="gradText2">built around your process</span>',
  'lead' => 'AIHubpedia is built and run by TrustNova Tech. If you need an AI system or custom software built, tell us about the problem. We reply by email if it looks like a fit; there is no obligation.', 'art' => 'agent',
  'actions' => '<a class="btn btn--accent" href="#inquiry" data-cta="svc_hero_project">Discuss Your Project</a><a class="btn secondary" href="#build">See what we build</a>',
  'facts' => ['Written scope before work starts', 'No outcomes promised in advance', 'No client case studies claimed']]) ?>
<div class="wrap pgBody" id="build">
  <div class="sectionHead"><div><h2 id="svcTitle">What we build</h2><p>Ten kinds of project. Open one to see who it suits, what you receive and what we will ask.</p></div></div>
  <div class="bento" aria-labelledby="svcTitle">
    <?php $n = 0; foreach ($services as $k => $sv): $ac = $__acc[$n % 5]; $span = in_array($n, [0]) ? 'c' : (in_array($n, [8, 9]) ? 'a' : 'b'); $dark = $n === 0; ?>
      <a class="bento__cell bento__cell--<?= $span ?><?= $dark ? ' bento__cell--dark' : '' ?>" href="/services.php?s=<?= $h(rawurlencode($k)) ?>" style="--c1:<?= $ac[0] ?>;--c2:<?= $ac[1] ?>;text-decoration:none;color:inherit">
        <span class="ico3d"><?= ui_icon($__ico[$k] ?? 'sparkle') ?></span>
        <h3><?= $h($sv['title']) ?></h3>
        <p><?= $h($sv['summary']) ?></p>
        <span class="readGo">Learn more <?= ui_icon('arrow') ?><span class="sr"> about <?= $h($sv['title']) ?></span></span>
        <?php if ($dark): ?><span class="bento__art" aria-hidden="true"><?= art_agent() ?></span><?php endif; ?>
      </a>
    <?php $n++; endforeach; ?>
  </div>
</div>
<section class="bizBand wrap" aria-labelledby="flowT" style="padding-top:0;padding-inline:28px">
  <div class="sectionHead"><div><h2 id="flowT">How an automation fits together</h2><p>A typical workflow connects a trigger, your data, an AI step and a result, with a person approving anything important.</p></div></div>
  <div class="flowBox"><?= art_flow() ?>
    <ul class="flowLabels"><li><strong>Trigger</strong>A form, email, webhook or schedule</li><li><strong>Your data</strong>Systems you already use, through their APIs</li><li><strong>AI step</strong>Classify, summarise, draft or extract</li><li><strong>Outcome</strong>A record, message or task, with review where needed</li></ul></div>
</section>
<section class="bizBand stage" aria-labelledby="procT">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="procT">How an engagement works</h2><p>From first message to handover.</p></div></div>
    <ol class="timeline timeline--5"><?php foreach ($cat['process'] as $pp): ?><li><h3><?= $h($pp['step']) ?></h3><p><?= $h($pp['text']) ?></p></li><?php endforeach; ?></ol>
  </div>
</section>
<?php endif; ?>
<div class="wrap pgBody" aria-labelledby="honestTitle">
  <div class="sectionHead"><div><h2 id="honestTitle">What we do and do not claim</h2><p>Plain statements, so you know what to expect.</p></div></div>
  <ul class="trustRow"><?php foreach ($cat['honest_notes'] as $nn): ?><li class="card"><span class="ico3d" style="--c1:#10b981;--c2:#0ea5e9"><?= ui_icon('shield') ?></span><p><?= $h($nn) ?></p></li><?php endforeach; ?></ul>
  <p class="small" style="margin-top:18px">AIHubpedia’s independent tool reviews are separate from these services: <a href="/disclosure.php">how paid and editorial content are kept apart</a>.</p>
</div>
<section class="bizBand stage" id="inquiry" aria-labelledby="inqTitle" style="padding-top:clamp(40px,6vw,80px)">
  <div class="wrap consult">
    <div class="consult__copy">
      <span class="eyebrow"><?= ui_icon('briefcase') ?> Project enquiry</span>
      <h2 id="inqTitle">Tell us about your project</h2>
      <p>A short form: your goal, your name and email. Everything under “Add details” is optional.</p>
      <ul><li><?= ui_icon('check') ?><span>Describe the problem and who will use the result.</span></li><li><?= ui_icon('check') ?><span>We reply by email if it looks like a fit.</span></li><li><?= ui_icon('check') ?><span>No obligation from sending an enquiry.</span></li></ul>
    </div>
    <div class="consult__form"><?= render_project_form(['service' => $slug]) ?></div>
  </div>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
