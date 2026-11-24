<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services_catalog.php';

$data = load_tools();
$tools = $data['tools'] ?? [];
$cats  = array_values(array_filter($data['categories'] ?? [], fn($c) => $c !== CATALOG_UNCLASSIFIED));
$byId  = tools_by_id();

$__toolCountLabel = count($tools) >= 100 ? (floor(count($tools) / 100) * 100) . '+' : (string)count($tools);
$title = "AIHubpedia — AI Tools Directory: Discover & Compare {$__toolCountLabel} AI Tools";
$meta_desc = "Browse {$__toolCountLabel} AI tools for writing, coding, design, audio, video and automation. Compare pricing, features and alternatives, and see community votes.";

// Legacy in-page browse filter (?q= / ?cat=) keeps working but is not indexed.
$q   = trim($_GET['q'] ?? '');
$cat = trim($_GET['cat'] ?? '');
$browse = array_values(array_filter($tools, function ($t) use ($q, $cat) {
  if ($cat !== '' && ($t['category'] ?? '') !== $cat) return false;
  if ($q === '') return true;
  $hay = strtolower(($t['name'] ?? '') . ' ' . ($t['short'] ?? '') . ' ' . ($t['description'] ?? '') . ' ' . implode(' ', ($t['tags'] ?? [])));
  return strpos($hay, strtolower($q)) !== false;
}));
if ($q !== '' || $cat !== '') $robots = 'noindex';

// Popular tools: editor picks first, then ranked by clicks/ratings (scores computed once).
$manual_ids = ['chatgpt', 'gemini', 'copilot', 'claude', 'perplexity', 'canva-ai', 'deepseek', 'character-ai', 'grammarly', 'notebooklm'];
$manual = [];
foreach ($manual_ids as $id) { if (isset($byId[$id])) $manual[] = $byId[$id]; }
$rest = array_values(array_filter($tools, fn($t) => !in_array($t['id'], $manual_ids, true)));
$scores = [];
foreach ($rest as $t) { $scores[$t['id']] = trending_score($t['id']); }
usort($rest, fn($a, $b) => ($scores[$b['id']] ?? 0) <=> ($scores[$a['id']] ?? 0));
$popular = array_slice(array_merge($manual, $rest), 0, 12);

// Reviewed profiles (evidence-backed) and guides
$reviewedIds = array_keys(array_filter($byId, fn($t) => !empty($t['curated'])));
$reviewed = [];
foreach ($reviewedIds as $id) { if (!empty($byId[$id]['curated'])) $reviewed[] = $byId[$id]; }
$reviewedCount = count(array_filter($tools, fn($t) => !empty($t['curated'])));
$guides = guides_all();
$pairs = available_comparison_pairs($tools, 8);
$intents = discovery_intents($tools);
$subCounts = [];
$catCounts = [];
foreach ($tools as $t) {
  $catCounts[$t['category']] = ($catCounts[$t['category']] ?? 0) + 1;
  if (!empty($t['subcategory'])) $subCounts[$t['category']][$t['subcategory']] = ($subCounts[$t['category']][$t['subcategory']] ?? 0) + 1;
}
$featured = featured_tools($tools, 3);
$spotlight = array_values(array_filter($tools, fn($t) => !empty($t['spotlight_until']) && strtotime((string)$t['spotlight_until']) >= strtotime(date('Y-m-d'))));
usort($spotlight, fn($a, $b) => strcmp((string)$b['spotlight_until'], (string)$a['spotlight_until']));
$spotlight = array_slice($spotlight, 0, 1);

// Hero stack: three real reviewed tools (no invented ratings or counts)
$stackIds = ['chatgpt', 'claude', 'otter-ai'];
$stack = [];
foreach ($stackIds as $id) { if (!empty($byId[$id]['curated'])) $stack[] = $byId[$id]; }

$jsonld = [[
  '@type' => 'CollectionPage', '@id' => site_url('/#webpage'), 'url' => site_url('/'), 'name' => $title, 'description' => $meta_desc,
  'isPartOf' => ['@id' => site_url('/#website')], 'about' => 'AI tools directory',
]];
$hide_header_search = true;
$main_class = 'fullbleed';
$footer_newsletter = false;   // the newsletter section below replaces the footer sign-up on this page

// Curated selection: reviewed profiles first, then widely used tools, never more than 12 cards, no duplicates.
$curated = []; $seen = [];
foreach (array_merge(array_slice($reviewed, 0, 8), $popular, array_slice($reviewed, 8)) as $t) { if (isset($seen[$t['id']])) continue; $seen[$t['id']] = 1; $curated[] = $t; if (count($curated) >= 12) break; }
$svcCatalog = trustnova_services_for_home();
$tiers = pricing_tiers(); $cur = (string)(site_cfg()['currency'] ?? 'USD'); $sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'][$cur] ?? '';
$price = fn(string $k) => isset($tiers[$k]) ? ((int)($tiers[$k]['price'] ?? 0) === 0 ? 'Free' : $sym . number_format((int)$tiers[$k]['price']) . ($sym === '' ? ' ' . $cur : '')) : '';
$nlLaunched = newsletter_launched();
require __DIR__ . '/layout_top.php';
?>

<section class="homeHero stage" aria-labelledby="heroTitle">
  <div class="wrap heroGrid">
    <div class="heroCopy">
      <span class="eyebrow"><?= ui_icon('shield') ?> Independent directory · sources and dates on reviewed tools</span>
      <h1 id="heroTitle">Find the right <span class="gradText">AI tool</span>, with the sources to back it up</h1>
      <p class="heroLead">Search <?= h(number_format(count($tools))) ?> AI tools, compare plans and limits side by side, and read guides built from vendors' own pages. We say where sources disagree and what we have not tested.</p>
      <?= render_search_box('heroSearch', ['big' => true, 'source' => 'hero', 'placeholder' => 'Try “transcribe meetings”, “ChatGPT” or “resume”']) ?>
      <div class="heroTry"><span>Popular goals:</span>
        <?php foreach (array_slice($intents, 0, 5) as $i): ?><a class="chipLink" href="<?= h($i['url']) ?>"><?= h($i['label']) ?></a><?php endforeach; ?>
      </div>
      <div class="heroBiz">
        <p><strong>Need something built?</strong> TrustNova Tech builds API integrations, workflow automation and custom AI solutions.</p>
        <a class="btn btn--accent" href="/services.php#inquiry" data-cta="hero_project">Discuss Your Project</a>
      </div>
    </div>
    <?php if ($stack): ?>
    <div class="heroStack" aria-hidden="true">
      <?= art_orb(true) ?>
      <?php foreach ($stack as $t): $cp = $t['curated']; ?>
        <div class="floatCard">
          <div class="row"><span class="dot"><?= h(mb_substr($t['name'], 0, 1)) ?></span><div><h3><?= h($t['name']) ?></h3><p><?= h($t['category']) ?> · <?= h($t['subcategory']) ?></p></div></div>
          <p class="fc__price"><?= h(profile_free_option($cp)) ?></p>
          <span class="fc__prov"><?= ui_icon('shield') ?> <?= h(ui_provenance($t)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="wrap"><div class="trustStrip" aria-label="What is on AIHubpedia">
    <span><strong><?= h(number_format(count($tools))) ?></strong>tools listed</span>
    <span><strong><?= (int)$reviewedCount ?></strong><a href="/methodology.php">reviewed profiles</a> with sources</span>
    <span><strong><?= count($guides) ?></strong><a href="/guides.php">comparison guides</a></span>
    <span>Prices carry an “as of” date</span>
  </div></div>
</section>

<section class="section" aria-labelledby="pathsTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="pathsTitle">What brings you here?</h2><p>Three clear paths. Pick the one that fits.</p></div></div>
    <div class="paths">
      <a class="path path--find" href="#discover" data-cta="path_find">
        <span class="bubble"><?= ui_icon('search') ?></span>
        <h3>Find AI Tools</h3>
        <p>Search by goal, compare plans and limits, and read profiles that show their sources and dates.</p>
        <span class="path__go">Start with a goal <?= ui_icon('arrow') ?></span>
      </a>
      <a class="path path--build" href="/services.php#inquiry" data-cta="path_build">
        <span class="bubble"><?= ui_icon('code') ?></span>
        <h3>Build With AI</h3>
        <p>API integrations, workflow automation, PHP/MySQL development and custom AI solutions from TrustNova Tech.</p>
        <span class="path__go">Discuss your project <?= ui_icon('arrow') ?></span>
      </a>
      <a class="path path--promote" href="/advertise.php" data-cta="path_promote">
        <span class="bubble"><?= ui_icon('megaphone') ?></span>
        <h3>Promote Your Tool</h3>
        <p>Free listings, plus clearly labelled sponsored placements. Paid placement never changes a review.</p>
        <span class="path__go">See sponsorship options <?= ui_icon('arrow') ?></span>
      </a>
    </div>
  </div>
</section>

<?php if ($q !== '' || $cat !== ''): ?>
<section class="section section--tint" aria-labelledby="filterTitle" id="filtered">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="filterTitle">Filtered tools</h2><p>Showing <?= number_format(min(24, count($browse))) ?> of <?= number_format(count($browse)) ?> results<?= $q !== '' ? ' for “' . h($q) . '”' : '' ?><?= $cat !== '' ? ' in ' . h($cat) : '' ?>. <a href="/">Clear filters</a></p></div></div>
    <div class="cardGrid"><?php foreach (array_slice($browse, 0, 24) as $t) echo render_tool_card($t, ['placement' => 'home_filtered']); ?></div>
    <p class="actions" style="margin-top:18px"><a class="btn secondary" href="/search.php?q=<?= h(rawurlencode($q)) ?>">Open full search</a><a class="btn secondary" href="/directory.php">Browse all tools</a></p>
  </div>
</section>
<?php endif; ?>

<section class="section section--tint" aria-labelledby="discoverTitle" id="discover">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="discoverTitle">Find the right AI tool</h2><p>Start from what you want to do, or browse a category. Counts come from the live catalogue.</p></div><a class="moreLink" href="/categories.php">All categories <?= ui_icon('arrow') ?></a></div>
    <div class="intentGrid">
      <?php foreach ($intents as $i): ?>
        <a class="intent" href="<?= h($i['url']) ?>"><span class="ico"><?= ui_icon($i['icon']) ?></span><span><strong><?= h($i['label']) ?></strong><span class="n"><?= h(seo_plural($i['count'], 'tool')) ?></span></span></a>
      <?php endforeach; ?>
    </div>
    <?php $__tc = array_filter($cats, fn($c) => $c !== CATALOG_UNCLASSIFIED); usort($__tc, fn($x, $y) => ($catCounts[$y] ?? 0) <=> ($catCounts[$x] ?? 0)); ?>
    <h3 class="subHead">Browse by category</h3>
    <ul class="catGrid catGrid--mini" aria-label="Browse by category">
      <?php foreach (array_slice($__tc, 0, 8) as $c): [$c1, $c2, $ico] = cat_theme((string)$c); ?>
        <li><a class="catTile catTile--mini" style="--c1:<?= h($c1) ?>;--c2:<?= h($c2) ?>" href="<?= h(category_path($c)) ?>">
          <span class="catIcon" aria-hidden="true"><?= ui_icon($ico) ?></span>
          <span class="catTile__txt"><strong><?= h($c) ?></strong><span><?= h(seo_plural((int)($catCounts[$c] ?? 0), 'tool')) ?></span></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
    <div data-recent-list hidden style="margin-top:22px"><h3 style="margin:0 0 10px;font-size:1rem">Continue where you left off</h3><ul class="linkList" style="margin:0"></ul></div>
    <h3 class="subHead">Reviewed profiles and widely used tools</h3>
    <p class="small subHeadNote">Tools with a <span class="pill pill--reviewed"><?= ui_icon('shield') ?> Reviewed</span> badge have a profile with vendor sources, a retrieval date and listed limitations (<a href="/methodology.php">how we review</a>). Other tools are listed without that review. Order reflects editor picks and activity on this site, not payment.</p>
    <div class="cardGrid">
      <?php foreach ($curated as $t) echo render_tool_card($t, ['placement' => 'home_curated']); ?>
    </div>
    <div class="actions actions--center" style="margin-top:24px"><a class="btn" href="/directory.php" data-cta="home_browse_all">Browse All Tools</a><a class="btn secondary" href="/compare.php">Compare tools</a><a class="btn secondary" href="/recommend.php">Get a shortlist</a></div>
  </div>
</section>

<section class="bizBand stage" aria-labelledby="svcTitle" id="services">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="svcTitle">Build with AI</h2><p>AIHubpedia is built and run by TrustNova Tech. These are the projects we take on, and what you receive from each.</p></div><a class="moreLink" href="/services.php">All services <?= ui_icon('arrow') ?></a></div>
    <ul class="svcTiles">
      <?php foreach ($svcCatalog as $i => $sv): $__t = [['#06b6d4', '#2563eb'], ['#8b5cf6', '#ec4899'], ['#10b981', '#0ea5e9'], ['#f59e0b', '#ef4444']][$i % 4]; ?>
      <li><article class="svcTile" style="--c1:<?= $__t[0] ?>;--c2:<?= $__t[1] ?>">
        <span class="catIcon" aria-hidden="true"><?= ui_icon($sv['icon']) ?></span>
        <h3><?= h($sv['title']) ?></h3>
        <p><?= h($sv['summary']) ?></p>
        <ul><?php foreach ($sv['deliverables'] as $dv): ?><li><?= ui_icon('check') ?><span><?= h($dv) ?></span></li><?php endforeach; ?></ul>
        <a class="readGo" href="/services.php?s=<?= h(rawurlencode($sv['slug'])) ?>" style="color:#c4b5fd">Learn more <?= ui_icon('arrow') ?><span class="sr"> about <?= h($sv['title']) ?></span></a>
      </article></li>
      <?php endforeach; ?>
    </ul>
    <div class="svcCta">
      <p><strong>Every project starts with a written scope.</strong> We do not promise outcomes in advance; we agree how success will be measured. No client case studies are published here.</p>
      <a class="btn btn--accent" href="/services.php#inquiry" data-cta="services_project">Discuss Your Project</a>
    </div>
  </div>
</section>

<section class="section section--tint" aria-labelledby="guideTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="guideTitle">Comparisons and guides</h2><p>Comparison tables are generated from the same reviewed profiles, so the facts, sources and pricing dates match everywhere.</p></div><a class="moreLink" href="/guides.php">All guides <?= ui_icon('arrow') ?></a></div>
    <div class="cardGrid cardGrid--four">
      <?php foreach (array_slice($guides, 0, 4, true) as $slug => $g): ?>
        <article class="tCard tCard--guide reveal">
          <p class="tCard__meta"><?= ui_icon('book') ?> Guide · updated <?= h($g['updated']) ?></p>
          <h3 class="tCard__guideTitle"><a href="<?= h(guide_path($slug)) ?>"><?= h($g['h1']) ?></a></h3>
          <p class="tCard__desc"><?= h($g['description']) ?></p>
          <div class="tCard__actions"><a class="btn sm" href="<?= h(guide_path($slug)) ?>">Read guide</a></div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($pairs): ?>
      <h3 class="subHead">Popular head-to-head comparisons</h3>
      <ul class="vsGrid"><?php foreach (array_slice($pairs, 0, 4) as [$pa, $pb]): ?>
        <li><a class="vsCard" href="<?= h(compare_path($pa['id'], $pb['id'])) ?>">
          <span class="vsCard__tools">
            <span class="vsCard__tool"><?= logo_tile($pa, 'lg') ?><strong><?= h($pa['name']) ?></strong><small><?= h($pa['category']) ?></small></span>
            <span class="vsBadge" aria-hidden="true">VS</span>
            <span class="vsCard__tool"><?= logo_tile($pb, 'lg') ?><strong><?= h($pb['name']) ?></strong><small><?= h($pb['category']) ?></small></span>
          </span>
          <span class="vsCard__go">Compare <?= h($pa['name']) ?> vs <?= h($pb['name']) ?> <?= ui_icon('arrow') ?></span>
        </a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="actions" style="margin-top:18px"><a class="btn secondary" href="/compare.php" data-cta="home_compare">Compare any two tools</a></p>
  </div>
</section>

<?php if ($spotlight || $featured): ?>
<section class="section" aria-labelledby="sponsoredTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="sponsoredTitle">Sponsored listings</h2><p>Paid placements, clearly labelled. They do not change a tool's facts, limitations or ranking.</p></div></div>
    <div class="cardGrid">
      <?php foreach (array_merge($spotlight, $featured) as $t) echo render_tool_card($t, ['sponsored' => true, 'placement' => 'home_sponsored']); ?>
    </div>
    <?= render_disclosure_inline() ?>
  </div>
</section>
<?php endif; ?>

<section class="section<?= ($spotlight || $featured) ? ' section--tint' : '' ?>" aria-labelledby="sponsorTitle" id="sponsor">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="sponsorTitle">Promote your AI tool</h2><p>Reach people who are actively comparing tools. Placements are labelled <span class="adTag">Sponsored</span>, reviewed against our <a href="/methodology.php">methodology</a> first, and kept separate from editorial reviews and rankings.</p></div></div>
    <div class="sponsorGrid">
      <div class="sponsorCard">
        <h3>Listings</h3>
        <ul class="sponsorList">
          <li><span>Free listing</span><b><?= h($price('free') ?: 'Free') ?></b><small>Manual review, may take several days</small></li>
          <li><span>Priority approval</span><b><?= h($price('priority')) ?></b><small>Same listing, reviewed within 24 hours of a complete submission</small></li>
        </ul>
        <a class="btn secondary sm" href="/submit.php">Submit a tool</a>
      </div>
      <div class="sponsorCard sponsorCard--hl">
        <h3>Sponsored placements <span class="adTag">Sponsored</span></h3>
        <ul class="sponsorList">
          <li><span>Featured listing</span><b><?= h($price('featured')) ?></b><small>7 days in the labelled homepage section</small></li>
          <li><span>Homepage spotlight</span><b><?= h($price('homepage')) ?></b><small>14 days, top labelled spotlight</small></li>
        </ul>
        <a class="btn sm" href="/advertise.php#request" data-cta="sponsor_enquiry">Ask about a placement</a>
      </div>
      <div class="sponsorCard">
        <h3>By enquiry</h3>
        <ul class="sponsorList">
          <li><span>Monthly featured placement</span><b>On request</b><small>Packages are still being defined: tell us what you need</small></li>
          <li><span>Newsletter sponsorship</span><b>Not available yet</b><small>The newsletter has not launched, so there is no audience figure to quote</small></li>
        </ul>
        <a class="btn secondary sm" href="/advertise.php?need=newsletter_sponsor#request">Register interest</a>
      </div>
    </div>
    <p class="small sponsorNote">List prices come from the site configuration (<?= h($cur) ?>, one-off, excluding taxes). No payment is taken on this website: placements are arranged by enquiry and dates are agreed in writing. We do not publish or promise traffic, click or sales figures. <a href="/disclosure.php">Advertising and affiliate disclosure</a>.</p>
  </div>
</section>

<section class="section section--tint" aria-labelledby="newsTitle2" id="newsletter">
  <div class="wrap">
    <div class="newsBand">
      <div>
        <h2 id="newsTitle2"><?= $nlLaunched ? 'Get AIHubpedia updates' : 'Get launch updates' ?></h2>
        <p><?= $nlLaunched ? 'An email when we publish a new guide or notice a notable price or plan change. Not a fixed schedule.' : 'The AIHubpedia newsletter has not launched yet. Leave your email and we will tell you when it does. There is no fixed schedule to promise yet.' ?> Free to read; unsubscribe any time.</p>
      </div>
      <form class="newsForm" method="post" action="/subscribe.php">
        <input type="hidden" name="form_token" value="<?= h(form_token('newsletter')) ?>">
        <input type="hidden" name="source_page" value="<?= h(rel_url(seo_default_canonical())) ?>">
        <div class="hp" aria-hidden="true"><label>Leave empty<input type="text" name="website_url_confirm" tabindex="-1" autocomplete="off"></label></div>
        <label class="sr" for="homeEmail">Email address</label>
        <div class="newsForm__row"><input class="input" id="homeEmail" type="email" name="email" placeholder="you@example.com" autocomplete="email" required><button class="btn" type="submit" data-cta="home_newsletter">Subscribe</button></div>
        <label class="newsForm__consent"><input type="checkbox" name="consent" value="1" required> I agree to receive these emails. <a href="/privacy.php">Privacy policy</a></label>
      </form>
    </div>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
