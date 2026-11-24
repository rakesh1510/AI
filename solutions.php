<?php
// Task / business-problem discovery. Hub (no parameter) and one page per problem in data/solutions.json.
// Results come from the same transparent, deterministic ranking as search; each result says why it matched.
// A problem page is indexable only when at least three reviewed, sourced tools match (evidence gate).
require_once __DIR__ . '/helpers.php';
$slug = trim((string)($_GET['problem'] ?? ''));
$tools = load_tools()['tools'];
$problems = solutions_all();
$crumbs = [['Home', site_url('/')], ['Find tools by problem', $slug === '' ? null : site_url('/solutions.php')]];

if ($slug !== '') {
  $problem = solution_get($slug);
  if (!$problem) {
    http_response_code(404);
    $title = 'Problem Not Found';
    $meta_desc = 'This task or problem is not listed on AIHubpedia.';
    $main_class = 'fullbleed';
    require __DIR__ . '/layout_top.php';
    echo '<div class="wrap pgBody">' . state_box(['art' => 'lost', 'eyebrow' => 'Problem not found', 'title' => 'Problem not found', 'text' => 'Pick a task from the list of problems AIHubpedia can help you shortlist tools for.', 'actions' => '<a class="btn btn--accent" href="/solutions.php">Find tools by problem</a>']) . '</div>';
    require __DIR__ . '/layout_bottom.php';
    exit;
  }
  $results = solution_results_cached($slug, SOLUTION_PAGE_LIMIT);
  $reviewedN = solution_reviewed_count($results);
  $indexable = solution_indexable($results);
  if (!$indexable) $robots = 'noindex';
  $title = $problem['title'] . ': AI Tools Shortlist';
  $meta_desc = $problem['problem'];
  $canonical = solution_url($slug);
  $crumbs[1][1] = site_url('/solutions.php');
  $crumbs[] = [$problem['title'], null];
  $jsonld = [seo_breadcrumb_node($crumbs)];
  if ($indexable) {
    $jsonld[] = ['@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => seo_title($title), 'description' => seo_description($meta_desc),
      'isPartOf' => ['@id' => site_url('/#website')], 'mainEntity' => seo_tool_itemlist_node(array_map(fn($r) => $r['tool'], array_slice($results, 0, 10)), $problem['title'])];
  }
  $guides = [];
  foreach (guides_all() as $gs => $g) {
    foreach ($results as $r) { if (in_array($r['tool']['id'], $g['tools'] ?? [], true)) { $guides[$gs] = $g; break; } }
  }
  $main_class = 'fullbleed';
  require __DIR__ . '/layout_top.php';
  $__pc = cat_theme('Research');
  echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Find tools by problem', 'icon' => $problem['icon'] ?? 'search', 'title' => $problem['title'], 'lead' => h($problem['problem']) . '<br><span class="small" style="color:#b5bddf;display:block;margin-top:12px;font-size:1rem">Shortlist ranked live from the AIHubpedia catalogue. This is a starting list, not a test of output quality. <a href="/methodology.php#search" style="text-decoration:underline">How results are ranked</a>.</span>', 'art' => 'compass']);
  ?>
  <article class="wrap pgBody">
    <div class="bento" style="margin-bottom:var(--s-5)">
      <section class="bento__cell bento__cell--a" aria-labelledby="lookFor"><span class="ico3d" style="--c1:#10b981;--c2:#0ea5e9"><?= ui_icon('check') ?></span>
        <h2 id="lookFor" style="margin:0;font-size:1.5rem">What to look for</h2>
        <ul class="tickList"><?php foreach ($problem['look_for'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul>
      </section>
      <section class="bento__cell bento__cell--a" aria-labelledby="askVendors"><span class="ico3d" style="--c1:#8b5cf6;--c2:#ec4899"><?= ui_icon('chat') ?></span>
        <h2 id="askVendors" style="margin:0;font-size:1.5rem">Questions to ask a vendor</h2>
        <ul class="tickList"><?php foreach ($problem['ask_vendors'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul>
      </section>
    </div>
    <h2 style="margin-top:0">Tools to consider (<?= count($results) ?>)</h2>
    <?php if (!$results): ?>
      <p class="noticeBox">No tools in the catalogue match this problem yet. Try <a href="/search.php?q=<?= h(rawurlencode($problem['query'])) ?>">searching</a> or <a href="/submit.php">submit a tool</a>.</p>
    <?php else: ?>
      <p class="small"><?= (int)$reviewedN ?> of these <?= $reviewedN === 1 ? 'has a' : 'have' ?> reviewed, sourced profile<?= $reviewedN === 1 ? '' : 's' ?>; the others are catalogue listings we have not reviewed.<?= $indexable ? '' : ' This page is not indexed by search engines until at least three reviewed tools match.' ?></p>
      <div class="cardGrid">
        <?php foreach ($results as $r) echo render_tool_card($r['tool'], ['placement' => 'solutions', 'heading' => 3, 'why' => $r['reasons'] ?? [], 'evidence' => true]); ?>
      </div>
    <?php endif; ?>

    <section class="glass" style="margin-top:var(--s-5);padding:clamp(22px,3vw,34px)" aria-labelledby="nextSteps">
      <h2 id="nextSteps" style="margin-top:0">Next steps</h2>
      <ul class="linkList">
        <li><a href="/recommend.php">Answer six questions for a personalised shortlist</a> (no sign-up)</li>
        <li><a href="/compare.php">Compare two tools side by side</a></li>
        <li>No tool fits? <a href="/business.php?need=ai_agent&amp;ref=solutions&amp;utm_campaign=<?= h(rawurlencode($slug)) ?>">Ask TrustNova Tech about a custom AI agent</a></li>
        <?php foreach (array_slice($guides, 0, 2, true) as $gs => $g): ?><li><a href="<?= h(guide_path($gs)) ?>"><?= h($g['h1']) ?></a></li><?php endforeach; ?>
      </ul>
    </section>
  </article>
  <?php
  require __DIR__ . '/layout_bottom.php';
  exit;
}

// Hub
$title = 'Find AI Tools by Problem or Task';
$meta_desc = 'Start from the problem you need to solve, such as transcribing meetings or answering customer questions, and get a transparent, ranked shortlist of AI tools with the reasons for each match.';
$canonical = site_url('/solutions.php');
$jsonld = [seo_breadcrumb_node($crumbs)];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Find tools by problem', 'icon' => 'search', 'title_html' => 'Find AI tools <span class="gradText2">by problem</span>',
  'lead' => 'Start from the job you need done. Each page explains what to look for, which questions to ask vendors, and shows a ranked shortlist with the reason every tool matched. No AI chatbot is involved: the ranking rules are published in our <a href="/methodology.php#search" style="text-decoration:underline">methodology</a>.', 'art' => 'compass',
  'actions' => '<a class="btn btn--accent" href="/recommend.php">Get a personalised shortlist</a><a class="btn secondary" href="/search.php">Search all tools</a>']);
$__ac = [['#06b6d4', '#2563eb'], ['#8b5cf6', '#ec4899'], ['#10b981', '#0ea5e9'], ['#f59e0b', '#ef4444'], ['#3b82f6', '#4f46e5'], ['#d946ef', '#6366f1']];
?>
<div class="wrap pgBody">
  <ul class="catGrid">
  <?php $n = 0; foreach ($problems as $s => $p): $res = solution_results_cached($s, SOLUTION_CACHE_ROWS); if (!$res) continue; $ac = $__ac[$n++ % 6]; ?>
    <li><article class="catTile" style="--c1:<?= $ac[0] ?>;--c2:<?= $ac[1] ?>">
      <span class="catIcon" aria-hidden="true"><?= ui_icon($p['icon'] ?? 'search') ?></span>
      <h2><a href="<?= h(solution_path($s)) ?>"><?= h($p['title']) ?></a></h2>
      <p><?= h($p['problem']) ?></p>
      <div class="catFoot"><span class="catCount"><?= (int)count($res) ?> matching tools · <?= (int)solution_reviewed_count($res) ?> reviewed</span><span class="catGo">Open <?= ui_icon('arrow') ?></span></div>
    </article></li>
  <?php endforeach; ?>
  </ul>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
