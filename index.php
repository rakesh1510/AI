<?php
require_once __DIR__ . '/helpers.php';

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
$popular = array_slice(array_merge($manual, $rest), 0, 8);

// Reviewed profiles (evidence-backed) and guides
$reviewedIds = ['chatgpt', 'claude', 'perplexity', 'notebooklm', 'otter-ai', 'grammarly'];
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
require __DIR__ . '/layout_top.php';
?>

<section class="homeHero" aria-labelledby="heroTitle">
  <div class="wrap heroGrid">
    <div>
      <span class="eyebrow"><?= ui_icon('shield') ?> Independent directory · sources and dates on reviewed tools</span>
      <h1 id="heroTitle">Find the right <span class="gradText">AI tool</span>, with the sources to back it up</h1>
      <p class="heroLead">Search <?= h(number_format(count($tools))) ?> AI tools, compare plans and limits side by side, and read guides built from vendors' own pages. We say where sources disagree and what we have not tested.</p>
      <?= render_search_box('heroSearch', ['big' => true, 'source' => 'hero', 'placeholder' => 'Try “transcribe meetings”, “ChatGPT” or “resume”']) ?>
      <div class="heroTry"><span>Popular goals:</span>
        <?php foreach (array_slice($intents, 0, 5) as $i): ?><a class="chipLink" href="<?= h($i['url']) ?>"><?= h($i['label']) ?></a><?php endforeach; ?>
      </div>
      <div class="trustStrip" aria-label="What is on AIHubpedia">
        <span><strong><?= h(number_format(count($tools))) ?></strong>tools listed</span>
        <span><strong><?= (int)$reviewedCount ?></strong><a href="/methodology.php">reviewed profiles</a> with sources</span>
        <span><strong><?= count($guides) ?></strong><a href="/guides.php">comparison guides</a></span>
        <span>Prices carry an “as of” date</span>
      </div>
    </div>
    <?php if ($stack): ?>
    <div class="heroStack" aria-hidden="true">
      <?php foreach ($stack as $t): $cp = $t['curated']; ?>
        <div class="floatCard">
          <div class="row"><span class="dot"><?= h(mb_substr($t['name'], 0, 1)) ?></span><div><h3><?= h($t['name']) ?></h3><p><?= h($t['category']) ?> · <?= h($t['subcategory']) ?></p></div></div>
          <p style="margin-top:10px"><?= h(profile_free_option($cp)) ?> · pricing as of <span class="nw"><?= h($cp['pricing']['as_of']) ?></span></p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section" aria-labelledby="journeyTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="journeyTitle">How do you want to start?</h2><p>Three ways in, depending on where you are in your search.</p></div></div>
    <div class="journeys">
      <div class="journey">
        <span class="bubble"><?= ui_icon('search') ?></span>
        <h3>Discover</h3>
        <p>Not sure what exists? Browse by goal or category and see reviewed profiles first.</p>
        <ul>
          <li><a href="/categories.php"><?= ui_icon('layers') ?> Browse all categories</a></li>
          <li><a href="/ai-use-case-explorer.php"><?= ui_icon('sparkle') ?> Explore by use case</a></li>
          <li><a href="/top.php"><?= ui_icon('chart') ?> See what visitors click most</a></li>
        </ul>
        <a class="btn" href="/search.php">Search the directory</a>
      </div>
      <div class="journey">
        <span class="bubble"><?= ui_icon('compare') ?></span>
        <h3>Compare</h3>
        <p>Already have two or three in mind? Put plans, limits and best-for notes side by side.</p>
        <ul>
          <li><a href="/compare.php"><?= ui_icon('compare') ?> Compare any two tools</a></li>
          <li><a href="/comparisons.php"><?= ui_icon('book') ?> Popular comparisons</a></li>
          <li><a href="/alternatives.php?tool=chatgpt"><?= ui_icon('arrow') ?> ChatGPT alternatives</a></li>
        </ul>
        <a class="btn secondary" href="/guides.php">Read the guides</a>
      </div>
      <div class="journey journey--business">
        <span class="bubble"><?= ui_icon('briefcase') ?></span>
        <h3>For business</h3>
        <p>Choosing tools for a team, want something built, or promoting your own AI product?</p>
        <ul>
          <li><a href="/guide.php?slug=best-ai-tools-for-everyday-office-work"><?= ui_icon('book') ?> AI tools for office work</a></li>
          <li><a href="/business.php"><?= ui_icon('bolt') ?> Custom AI agents &amp; software</a></li>
          <li><a href="/advertise.php"><?= ui_icon('chart') ?> Advertise your AI tool</a></li>
        </ul>
        <a class="btn" href="/business.php">Talk to TrustNova Tech</a>
      </div>
    </div>
  </div>
</section>

<section class="section section--tint" aria-labelledby="goalTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="goalTitle">Start from what you want to do</h2><p>Goal-based shortcuts. Counts come from the live catalogue.</p></div></div>
    <div class="intentGrid">
      <?php foreach ($intents as $i): ?>
        <a class="intent" href="<?= h($i['url']) ?>"><span class="ico"><?= ui_icon($i['icon']) ?></span><span><strong><?= h($i['label']) ?></strong><span class="n"><?= h(seo_plural($i['count'], 'tool')) ?></span></span></a>
      <?php endforeach; ?>
    </div>
    <div data-recent-list hidden style="margin-top:22px">
      <h3 style="margin:0 0 10px;font-size:1rem">Continue where you left off</h3>
      <ul class="linkList" style="margin:0"></ul>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="catTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="catTitle">Browse AI tools by category</h2></div><a class="moreLink" href="/categories.php">All categories <?= ui_icon('arrow') ?></a></div>
    <div class="catGrid">
      <?php foreach ($cats as $c): $subs = $subCounts[$c] ?? []; arsort($subs); ?>
        <a class="catCard reveal" href="<?= h(category_path($c)) ?>">
          <span class="ico"><?= ui_icon(ui_category_icon($c)) ?></span>
          <h3><?= h($c) ?> AI tools</h3>
          <p><?= h(seo_plural((int)($catCounts[$c] ?? 0), 'tool')) ?></p>
          <?php if ($subs): ?><span class="subs"><?php foreach (array_slice($subs, 0, 3, true) as $sn => $n): ?><span><?= h($sn) ?></span><?php endforeach; ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($reviewed): ?>
<section class="section section--tint" aria-labelledby="revTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="revTitle">Reviewed profiles with sources</h2><p>Each shows its vendor sources, retrieval date, limitations and any conflicts between vendor pages. <a href="/methodology.php">How we review</a>.</p></div><a class="moreLink" href="/guides.php">See comparison guides <?= ui_icon('arrow') ?></a></div>
    <div class="cardGrid">
      <?php foreach ($reviewed as $t) echo render_tool_card($t, ['placement' => 'home_reviewed']); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section" aria-labelledby="guideTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="guideTitle">Guides and comparisons</h2><p>Comparison tables are generated from the same reviewed profiles, so the facts match everywhere.</p></div><a class="moreLink" href="/guides.php">All guides <?= ui_icon('arrow') ?></a></div>
    <div class="cardGrid">
      <?php foreach (array_slice($guides, 0, 4, true) as $slug => $g): ?>
        <article class="tCard reveal">
          <p class="tCard__meta"><?= ui_icon('book') ?> Guide · updated <?= h($g['updated']) ?></p>
          <h3 style="margin:0;font-size:1.1rem"><a href="<?= h(guide_path($slug)) ?>" style="color:inherit;text-decoration:none"><?= h($g['h1']) ?></a></h3>
          <p class="tCard__desc"><?= h($g['description']) ?></p>
          <div class="tCard__actions"><a class="btn sm" href="<?= h(guide_path($slug)) ?>">Read guide</a></div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($pairs): ?>
      <h3 style="margin:26px 0 10px;font-size:1rem">Popular head-to-head comparisons</h3>
      <ul class="linkList"><?php foreach ($pairs as [$pa, $pb]): ?><li><a href="<?= h(compare_path($pa['id'], $pb['id'])) ?>"><?= h($pa['name']) ?> vs <?= h($pb['name']) ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</section>

<?php if ($spotlight || $featured): ?>
<section class="section section--tint" aria-labelledby="sponsoredTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="sponsoredTitle">Sponsored listings</h2><p>Paid placements, clearly labelled. They do not change a tool's facts or limitations.</p></div><a class="moreLink" href="/advertise.php">Advertise here <?= ui_icon('arrow') ?></a></div>
    <div class="cardGrid">
      <?php foreach (array_merge($spotlight, $featured) as $t) echo render_tool_card($t, ['sponsored' => true, 'placement' => 'home_sponsored']); ?>
    </div>
    <?= render_disclosure_inline() ?>
  </div>
</section>
<?php endif; ?>

<section class="section" aria-labelledby="popTitle">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="popTitle">Popular AI tools</h2><p>Editor picks of widely used tools, followed by tools ranked by AIHubpedia clicks, ratings and votes. Rankings reflect activity on this site only.</p></div><a class="moreLink" href="/top.php">Top tools <?= ui_icon('arrow') ?></a></div>
    <div class="cardGrid">
      <?php foreach ($popular as $t) echo render_tool_card($t, ['placement' => 'home_popular']); ?>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="bizTitleBand">
  <div class="wrap">
    <div class="ctaBand">
      <div class="col">
        <h2 id="bizTitleBand">Have an AI tool to promote?</h2>
        <p>Featured listings and the homepage spotlight are paid, labelled placements. Free listings are reviewed manually. No traffic or revenue results are promised.</p>
        <div class="actions"><a class="btn" href="/advertise.php">See advertising options</a><a class="btn secondary" href="/disclosure.php">Read the disclosure</a></div>
      </div>
      <div class="col">
        <h2>Need something built with AI?</h2>
        <p>TrustNova Tech takes on custom AI agent and software projects and AI consultations. Tell us what you want to build.</p>
        <div class="actions"><a class="btn" href="/business.php">Request a consultation</a></div>
      </div>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="browseTitle" id="browse">
  <div class="wrap">
    <div class="sectionHead"><div><h2 id="browseTitle">Browse tools</h2><p>Showing <?= number_format(count($browse)) ?> results.</p></div></div>
    <form method="get" action="/" class="filterBar" role="search">
      <label class="sr" for="browseQ">Filter tools</label>
      <input class="input" id="browseQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Filter: chat, image, coding, SEO…">
      <label class="sr" for="browseCat">Category</label>
      <select id="browseCat" name="cat" style="max-width:220px">
        <option value="">All categories</option>
        <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>" <?= $c === $cat ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Apply</button>
      <a class="btn secondary" href="/">Reset</a>
    </form>
    <div class="cardGrid">
      <?php foreach (array_slice($browse, 0, 24) as $t) echo render_tool_card($t, ['placement' => 'home_browse']); ?>
    </div>
    <div class="actions" style="margin-top:22px"><a class="btn secondary" href="/categories.php">Browse all categories</a><a class="btn secondary" href="/top.php">See top tools</a><a class="btn secondary" href="/search.php">Search the directory</a></div>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
