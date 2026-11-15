<?php
require_once __DIR__ . '/helpers.php';
$company = getenv('AIHUB_BUSINESS_NAME') ?: 'TrustNova Tech';
$title = 'AI Consulting & Custom AI Agents by ' . $company;
$meta_desc = 'Request an AI consultation, a custom AI agent or a custom software project from ' . $company . '. Tell us what you want to build and we will reply by email if it is a fit.';
$crumbs = [['Home', site_url('/')], ['For business', null]];
$jsonld = [['@type' => 'WebPage', '@id' => site_url('/business.php#webpage'), 'url' => site_url('/business.php'), 'name' => seo_title($title), 'description' => seo_description($meta_desc), 'isPartOf' => ['@id' => site_url('/#website')]], seo_breadcrumb_node($crumbs)];
$default = in_array($_GET['need'] ?? '', ['consultation', 'ai_agent', 'software_dev'], true) ? $_GET['need'] : 'consultation';
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<div class="pageHero" style="padding-top:0">
  <span class="eyebrow"><?= ui_icon('briefcase') ?> For business</span>
  <h1>Build or choose AI with <?= h($company) ?></h1>
  <p class="lead">AIHubpedia helps you choose existing tools. When no off-the-shelf tool fits, <?= h($company) ?> can help with an AI consultation or build a custom AI agent or software project.</p>
</div>

<div class="twoCol">
  <div>
    <div class="panel">
      <h2>What you can request</h2>
      <ul class="serviceList">
        <li><span class="ico"><?= ui_icon('chat') ?></span><div><strong>AI consultation</strong><span>Talk through a use case, which tools or approach fit, and what is realistic before you spend on a build.</span></div></li>
        <li><span class="ico"><?= ui_icon('bolt') ?></span><div><strong>Custom AI agents</strong><span>An agent designed around your workflow and data, built to your requirements.</span></div></li>
        <li><span class="ico"><?= ui_icon('code') ?></span><div><strong>Custom software development</strong><span>Web and software projects, with or without AI features.</span></div></li>
      </ul>
    </div>
    <div class="panel" style="margin-top:18px">
      <h2>How it works</h2>
      <ol class="steps">
        <li><div><strong>Tell us your goal.</strong> Use the form. The more specific, the better.</div></li>
        <li><div><strong>We read your request.</strong> If it looks like a fit, we reply by email to discuss scope.</div></li>
        <li><div><strong>You decide.</strong> There is no obligation from sending a request.</div></li>
      </ol>
      <h3 style="margin-top:20px">Helpful to include</h3>
      <ul class="small"><li>The problem you want to solve and who will use the result</li><li>Systems or data it needs to work with</li><li>Any deadline or budget range</li></ul>
    </div>
    <p class="small" style="margin-top:14px"><strong>Independence:</strong> AIHubpedia's reviews and rankings are not influenced by <?= h($company) ?> services, and we do not list our own services as a tool. <a href="/disclosure.php">Read the disclosure</a>.</p>
  </div>

  <div class="panel" id="request">
    <h2>Request a consultation</h2>
    <?= render_lead_form(['types' => ['consultation', 'ai_agent', 'software_dev'], 'default' => $default, 'id' => 'biz', 'source' => '/business.php', 'budget' => true, 'message_label' => 'What would you like to build or solve?', 'button' => 'Send request']) ?>
    <p class="small" style="margin-top:12px">We use your details only to reply to this request. You can also email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</p>
  </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
