<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
$company = getenv('AIHUB_BUSINESS_NAME') ?: 'TrustNova Tech';
$title = 'AI Consulting & Custom AI Agents by ' . $company;
$meta_desc = 'Request an AI consultation, a custom AI agent or a custom software project from ' . $company . '. Tell us what you want to build and we will reply by email if it is a fit.';
$crumbs = [['Home', site_url('/')], ['For business', null]];
$jsonld = [['@type' => 'WebPage', '@id' => site_url('/business.php#webpage'), 'url' => site_url('/business.php'), 'name' => seo_title($title), 'description' => seo_description($meta_desc), 'isPartOf' => ['@id' => site_url('/#website')]], seo_breadcrumb_node($crumbs)];
$default = in_array($_GET['need'] ?? '', ['consultation', 'ai_agent', 'software_dev'], true) ? $_GET['need'] : 'consultation';
$main_class = 'fullbleed';
$svc = trustnova_services_for_home();
$accents = [['#06b6d4', '#2563eb'], ['#8b5cf6', '#ec4899'], ['#10b981', '#0ea5e9'], ['#f59e0b', '#ef4444']];
require __DIR__ . '/layout_top.php';
?>
<section class="pgHero stage">
  <div class="wrap">
    <div>
      <div class="pgHero__crumbs" style="padding:0 0 14px"><?= render_breadcrumbs($crumbs) ?></div>
      <span class="eyebrow"><?= ui_icon('briefcase') ?> AI development and automation</span>
      <h1>Build the AI your business <span class="gradText2">actually needs</span></h1>
      <p class="lead">AIHubpedia helps you choose existing tools. When no off-the-shelf tool fits, <?= h($company) ?> designs and builds API integrations, workflow automation, custom AI agents and PHP/MySQL software around your process.</p>
      <div class="heroActions">
        <a class="btn btn--accent" href="#request" data-cta="biz_hero_project">Discuss Your Project</a>
        <a class="btn secondary" href="#services">See what we build</a>
      </div>
      <ul class="factRow">
        <li><?= ui_icon('check') ?> Tell us your goal in a short form</li>
        <li><?= ui_icon('check') ?> We reply by email if it is a fit</li>
        <li><?= ui_icon('check') ?> No obligation from sending a request</li>
      </ul>
    </div>
    <div class="pgHero__art" aria-hidden="true"><?= art_agent() ?></div>
  </div>
</section>

<section class="bizBand stage" id="services" aria-labelledby="whatTitle">
 <div class="wrap">
  <div class="sectionHead"><div><h2 id="whatTitle">What you can request</h2><p>Four kinds of project, each with concrete things you receive. Scope and fit are agreed with you before any work starts.</p></div></div>
  <ul class="svcTiles" style="--x:0">
    <?php foreach ($svc as $i => $sv): $ac = $accents[$i % 4]; ?>
    <li><article class="svcTile bizTile" style="--c1:<?= $ac[0] ?>;--c2:<?= $ac[1] ?>">
      <span class="catIcon" aria-hidden="true"><?= ui_icon($sv['icon']) ?></span>
      <h3><?= h($sv['title']) ?></h3>
      <p><?= h($sv['summary']) ?></p>
      <ul><?php foreach ($sv['deliverables'] as $dv): ?><li><?= ui_icon('check') ?><span><?= h($dv) ?></span></li><?php endforeach; ?></ul>
    </article></li>
    <?php endforeach; ?>
  </ul>
 </div>
</section>

<section class="bizBand wrap" aria-labelledby="flowTitle" style="padding-top:0;padding-inline:28px">
  <div class="sectionHead"><div><h2 id="flowTitle">How an automation fits together</h2><p>A typical workflow connects a trigger, your data, an AI step and a result, with a person approving anything important.</p></div></div>
  <div class="flowBox">
    <?= art_flow() ?>
    <ul class="flowLabels">
      <li><strong>Trigger</strong>A form, email, webhook or schedule</li>
      <li><strong>Your data</strong>Systems you already use, through their APIs</li>
      <li><strong>AI step</strong>Classify, summarise, draft or extract</li>
      <li><strong>Outcome</strong>A record, message or task, with review where needed</li>
    </ul>
  </div>
</section>

<section class="bizBand wrap" aria-labelledby="useTitle" style="padding-top:0;padding-inline:28px">
  <div class="sectionHead"><div><h2 id="useTitle">Examples of what we can build</h2><p>Illustrative project types, not client case studies. Tell us your own use case in the form below.</p></div></div>
  <ul class="useGrid">
    <?php foreach ([
      ['chat', 'Support triage', 'Route and draft replies to incoming messages, with a person approving anything sent.', 0],
      ['database', 'Data hand-offs', 'Move records between your CRM, billing and spreadsheets through their APIs, with logging.', 2],
      ['bolt', 'Document processing', 'Extract fields from forms and PDFs into your database for review.', 1],
      ['code', 'Internal tools', 'PHP/MySQL dashboards and admin screens built around how your team works.', 3],
      ['mail', 'Lead handling', 'Capture enquiries, tag them and notify the right person, with consent recorded.', 0],
      ['flow', 'AI agents', 'A scoped agent that uses defined tools and stops for approval at set points.', 1],
    ] as [$ic, $t, $d, $k]): $ac = $accents[$k]; ?>
      <li class="useCard" style="--c1:<?= $ac[0] ?>;--c2:<?= $ac[1] ?>"><span class="catIcon" aria-hidden="true"><?= ui_icon($ic) ?></span><div><h3><?= h($t) ?></h3><p><?= h($d) ?></p></div></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="bizBand stage" aria-labelledby="procTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="procTitle">Project process</h2><p>A clear path from first message to a working result.</p></div></div>
    <ol class="timeline">
      <li><h3>Tell us your goal</h3><p>Use the short form: what you want to solve and who it is for.</p></li>
      <li><h3>We read your request</h3><p>If it looks like a fit, we reply by email to discuss scope.</p></li>
      <li><h3>Agree the scope</h3><p>Deliverables, timing and cost are agreed in writing before work starts.</p></li>
      <li><h3>Build and hand over</h3><p>We build to the agreed scope and hand over what you received.</p></li>
    </ol>
  </div>
</section>

<section class="bizBand stage" id="request" aria-labelledby="reqTitle" style="padding-top:0">
  <div class="wrap consult">
    <div class="consult__copy">
      <span class="eyebrow"><?= ui_icon('chat') ?> Consultation</span>
      <h2 id="reqTitle">Request a consultation</h2>
      <p>Talk through a use case, which tools or approach fit, and what is realistic before you spend on a build.</p>
      <ul>
        <li><?= ui_icon('check') ?><span>The more specific, the better: problem, users, systems involved.</span></li>
        <li><?= ui_icon('check') ?><span>Deadline or budget range, if you have one.</span></li>
        <li><?= ui_icon('check') ?><span>We use your details only to reply to this request.</span></li>
      </ul>
      <p class="small" style="color:#b5bddf"><strong>Independence:</strong> AIHubpedia's reviews and rankings are not influenced by <?= h($company) ?> services, and we do not list our own services as a tool. <a href="/disclosure.php" style="text-decoration:underline">Read the disclosure</a>.</p>
    </div>
    <div class="consult__form">
      <?= render_lead_form(['types' => ['consultation', 'ai_agent', 'software_dev'], 'default' => $default, 'id' => 'biz', 'source' => '/business.php', 'budget' => true, 'message_label' => 'What would you like to build or solve?', 'button' => 'Send request']) ?>
      <p class="small" style="margin-top:12px">You can also email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</p>
    </div>
  </div>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
