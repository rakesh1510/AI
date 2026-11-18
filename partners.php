<?php
// Partnerships: who we want to work with, how applications are reviewed, and (only) verified partners. An unverified company is never shown as a partner.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$verified = []; try { if (crm_tables_ready()) $verified = crm_partners_public(db()); } catch (Throwable $e) { sec_log_exception($e, 'partners:list'); }
$title = 'Partner with AIHubpedia and TrustNova Tech — AI Vendors, Agencies, Affiliates';
$meta_desc = 'Apply to partner with AIHubpedia: AI tool vendors, startups, SaaS companies, affiliate partners, technology providers and implementation agencies. Applications are reviewed by a person.';
$crumbs = [['Home', site_url('/')], ['Partnerships', null]];
require __DIR__ . '/layout_top.php'; echo render_breadcrumbs($crumbs);
?>
<div class="pageHero" style="padding-top:0"><h1>Partner with AIHubpedia and TrustNova Tech</h1><p class="lead">We are open to partnerships that help people choose and use AI tools well. Applications are read and reviewed by a person. Applying does not make you a partner, and we never list a company as a partner until the relationship is real and verified.</p><p><a class="btn" href="#apply">Apply to partner</a></p></div>
<section aria-labelledby="whoTitle"><h2 id="whoTitle">Who can apply</h2>
  <div class="cardGrid"><?php foreach (crm_partner_categories() as $k => $l): ?><div class="card"><h3 style="margin-top:0"><?= $h($l) ?></h3></div><?php endforeach; ?></div></section>
<section class="panel" style="margin-top:22px" aria-labelledby="howTitle"><h2 id="howTitle" style="margin-top:0">How applications are handled</h2>
  <ol class="steps"><li><div><strong>Applied.</strong> You describe what you propose. We store your application privately.</div></li><li><div><strong>In review.</strong> A person reads it and may reply by email with questions.</div></li><li><div><strong>Approved or declined.</strong> Approval is a decision to work together, recorded internally.</div></li><li><div><strong>Public listing (separate step).</strong> A partner appears on this page only after we have verified the relationship with a public source and you agree to it.</div></li></ol>
  <p class="small">Partnerships never change editorial evidence or rankings: <a href="/disclosure.php">how we keep paid and editorial content apart</a>.</p></section>
<section class="panel" style="margin-top:22px" aria-labelledby="listTitle"><h2 id="listTitle" style="margin-top:0">Verified partners</h2>
<?php if ($verified): ?><ul><?php foreach ($verified as $p): ?><li><strong><?= $h($p['company_name']) ?></strong> (<?= $h(crm_partner_categories()[$p['category']] ?? $p['category']) ?>) · <a href="<?= $h($p['evidence_url']) ?>" rel="noopener nofollow" target="_blank">verification source</a></li><?php endforeach; ?></ul>
<?php else: ?><p>There are no verified partners to show yet. This list stays empty until a partnership exists and has been verified.</p><?php endif; ?></section>
<section class="panel" id="apply" style="margin-top:22px" aria-labelledby="applyTitle"><h2 id="applyTitle" style="margin-top:0">Apply to partner</h2><?= render_partner_form() ?></section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
