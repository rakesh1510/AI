<?php
// Partnerships: who we want to work with, how applications are reviewed, and (only) verified partners. An unverified company is never shown as a partner.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$verified = []; try { if (crm_tables_ready()) $verified = crm_partners_public(db()); } catch (Throwable $e) { sec_log_exception($e, 'partners:list'); }
$title = 'Partner with AIHubpedia and TrustNova Tech — AI Vendors, Agencies, Affiliates';
$meta_desc = 'Apply to partner with AIHubpedia: AI tool vendors, startups, SaaS companies, affiliate partners, technology providers and implementation agencies. Applications are reviewed by a person.';
$crumbs = [['Home', site_url('/')], ['Partnerships', null]];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__pi = ['ai_tool_vendor' => ['plug', '#8b5cf6', '#ec4899', 'You build an AI tool and want it assessed for listing or a labelled placement.'], 'ai_startup' => ['sparkle', '#06b6d4', '#2563eb', 'An early-stage AI product looking for visibility and honest feedback.'], 'saas' => ['layers', '#3b82f6', '#4f46e5', 'A SaaS company with AI features worth comparing fairly.'], 'affiliate' => ['megaphone', '#f59e0b', '#ef4444', 'A referral or affiliate programme. Links are always marked as such.'], 'technology_provider' => ['database', '#10b981', '#0ea5e9', 'Infrastructure or tooling that helps people use AI well.'], 'implementation_agency' => ['code', '#a855f7', '#6366f1', 'An agency that implements AI for clients and wants to collaborate.'], 'enterprise' => ['briefcase', '#0ea5e9', '#7c3aed', 'A larger organisation exploring a longer-term arrangement.']];
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Partnerships', 'icon' => 'megaphone', 'title_html' => 'Partner with AIHubpedia <span class="gradText2">and TrustNova Tech</span>',
  'lead' => 'We are open to partnerships that help people choose and use AI tools well. Applications are read and reviewed by a person. Applying does not make you a partner, and we never list a company as a partner until it has been verified.', 'art' => 'network',
  'actions' => '<a class="btn btn--accent" href="#apply" data-cta="partner_apply">Apply to partner</a><a class="btn secondary" href="#who">Who can apply</a>']);
?>
<div class="wrap pgBody" id="who">
  <div class="sectionHead"><div><h2 id="whoTitle">Who can apply</h2><p>Seven partnership categories. Pick the closest fit in the application.</p></div></div>
  <ul class="partnerGrid" aria-labelledby="whoTitle">
    <?php foreach (crm_partner_categories() as $k => $l): $pi = $__pi[$k] ?? ['sparkle', '#7c6cf0', '#4a35e8', '']; ?>
      <li class="partnerCard" style="--c1:<?= $pi[1] ?>;--c2:<?= $pi[2] ?>"><span class="ico3d" aria-hidden="true"><?= ui_icon($pi[0]) ?></span><h3><?= $h($l) ?></h3><?php if ($pi[3] !== ''): ?><p><?= $h($pi[3]) ?></p><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<section class="bizBand stage" aria-labelledby="howTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="howTitle">How applications are handled</h2><p>A visible process, with a person making every decision.</p></div></div>
    <ol class="timeline timeline--3">
      <li><h3>Applied</h3><p>You describe what you propose. We store your application privately.</p></li>
      <li><h3>In review</h3><p>A person reads it and may reply by email with questions.</p></li>
      <li><h3>Approved or declined</h3><p>Approval is a decision a person records. Only verified partners are ever listed.</p></li>
    </ol>
    <p class="small" style="color:#b5bddf;margin-top:22px">Partnerships never change editorial evidence or rankings: <a href="/disclosure.php" style="text-decoration:underline">how we keep paid and editorial content apart</a>.</p>
  </div>
</section>
<div class="wrap pgBody" aria-labelledby="listTitle">
  <div class="sectionHead"><div><h2 id="listTitle">Verified partners</h2></div></div>
  <?php if ($verified): ?>
    <ul class="partnerGrid"><?php foreach ($verified as $pv): ?><li class="partnerCard"><span class="badge3 badge3--ok"><?= ui_icon('check') ?> Verified</span><h3><?= $h($pv['company_name']) ?></h3><p><?= $h(crm_partner_categories()[$pv['category']] ?? $pv['category']) ?> · <a href="<?= $h($pv['evidence_url']) ?>" rel="noopener nofollow" target="_blank">verification source</a></p></li><?php endforeach; ?></ul>
  <?php else: ?>
    <div class="emptyMini"><?= art_network() ?><p style="margin:12px 0 0"><strong>There are no verified partners to show yet.</strong><br>This list stays empty until a partnership exists and has been verified.</p></div>
  <?php endif; ?>
</div>
<section class="bizBand stage" id="apply" aria-labelledby="applyTitle" style="padding-top:clamp(40px,6vw,80px)">
  <div class="wrap consult">
    <div class="consult__copy"><span class="eyebrow"><?= ui_icon('megaphone') ?> Application</span><h2 id="applyTitle">Apply to partner</h2><p>Tell us who you are and what you propose. Applications are reviewed by a person; applying is not a commitment on either side.</p></div>
    <div class="consult__form"><?= render_partner_form() ?></div>
  </div>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
