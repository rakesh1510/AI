<?php
// TrustNova Tech AI development services: landing pages and a guided project inquiry. No client names, case studies or results are claimed.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
$cat = trustnova_catalog(); $services = $cat['services']; $slug = (string)($_GET['s'] ?? ''); $svc = $services[$slug] ?? null;
if ($slug !== '' && !$svc) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
if ($svc) { $title = $svc['title'] . ' by TrustNova Tech — AIHubpedia'; $meta_desc = mb_substr($svc['summary'], 0, 158); $crumbs = [['Home', site_url('/')], ['Services', site_url('/services.php')], [$svc['title'], null]]; }
else { $title = 'AI Development Services by TrustNova Tech — Agents, RAG, Automation, Custom Software'; $meta_desc = 'TrustNova Tech builds custom AI agents, enterprise assistants, document intelligence, workflow automation and custom software. Describe your project and get a reply by email.'; $crumbs = [['Home', site_url('/')], ['Services', null]]; }
require __DIR__ . '/layout_top.php';
echo render_breadcrumbs($crumbs);
?>
<?php if ($svc): ?>
<div class="pageHero" style="padding-top:0"><h1><?= $h($svc['title']) ?></h1><p class="lead"><?= $h($svc['summary']) ?></p><p><a class="btn" href="#inquiry">Describe your project</a> <a class="btn secondary" href="/services.php">All services</a></p></div>
<div class="grid2">
  <section class="panel"><h2 style="margin-top:0">Who this is for</h2><ul><?php foreach ($svc['good_fit'] as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul>
    <h2>What you can expect</h2><ul><?php foreach ($svc['deliverables'] as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul></section>
  <section class="panel"><h2 style="margin-top:0">Questions we will ask</h2><ul><?php foreach ($svc['questions'] as $x): ?><li><?= $h($x) ?></li><?php endforeach; ?></ul>
    <h2>How an engagement works</h2><ol class="steps"><?php foreach ($cat['process'] as $p): ?><li><div><strong><?= $h($p['step']) ?>.</strong> <?= $h($p['text']) ?></div></li><?php endforeach; ?></ol></section>
</div>
<?php else: ?>
<div class="pageHero" style="padding-top:0"><h1>AI development services by TrustNova Tech</h1><p class="lead">AIHubpedia is built and run by TrustNova Tech. If you need an AI system or custom software built, tell us about the problem. We reply by email if it looks like a fit; there is no obligation.</p><p><a class="btn" href="#inquiry">Describe your project</a></p></div>
<section aria-labelledby="svcTitle"><h2 id="svcTitle">What we build</h2>
  <div class="cardGrid"><?php foreach ($services as $k => $s): ?><a class="card" href="/services.php?s=<?= $h(rawurlencode($k)) ?>"><h3 style="margin-top:0"><?= $h($s['title']) ?></h3><p class="small"><?= $h($s['summary']) ?></p></a><?php endforeach; ?></div></section>
<section class="panel" style="margin-top:22px"><h2 style="margin-top:0">How an engagement works</h2><ol class="steps"><?php foreach ($cat['process'] as $p): ?><li><div><strong><?= $h($p['step']) ?>.</strong> <?= $h($p['text']) ?></div></li><?php endforeach; ?></ol></section>
<?php endif; ?>
<section class="panel" style="margin-top:22px" aria-labelledby="honestTitle"><h2 id="honestTitle" style="margin-top:0">What we do and do not claim</h2><ul><?php foreach ($cat['honest_notes'] as $n): ?><li><?= $h($n) ?></li><?php endforeach; ?></ul>
  <p class="small">AIHubpedia’s independent tool reviews are separate from these services: <a href="/disclosure.php">how paid and editorial content are kept apart</a>.</p></section>
<section class="panel" id="inquiry" style="margin-top:22px" aria-labelledby="inqTitle"><h2 id="inqTitle" style="margin-top:0">Tell us about your project</h2><p class="small">Three short steps. Fields marked optional can be left empty.</p>
  <?= render_project_form(['service' => $slug]) ?></section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
