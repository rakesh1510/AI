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
    require __DIR__ . '/layout_top.php';
    echo '<h1>Problem not found</h1><p class="small">Pick a task from the list of problems AIHubpedia can help you shortlist tools for.</p><p><a class="btn" href="/solutions.php">Find tools by problem</a></p>';
    require __DIR__ . '/layout_bottom.php';
    exit;
  }
  $results = solution_results($tools, $problem, SOLUTION_PAGE_LIMIT);
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
  require __DIR__ . '/layout_top.php';
  ?>
  <?= render_breadcrumbs($crumbs) ?>
  <article>
    <header class="pageHero" style="padding-top:0">
      <span class="eyebrow">Find tools by problem</span>
      <h1><?= h($problem['title']) ?></h1>
      <p class="lead"><?= h($problem['problem']) ?></p>
      <p class="small">Shortlist ranked live from the AIHubpedia catalogue. This is a starting list, not a test of output quality. <a href="/methodology.php#search">How results are ranked</a>.</p>
    </header>

    <div class="grid2" style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
      <section class="panel" aria-labelledby="lookFor">
        <h2 id="lookFor" style="margin-top:0">What to look for</h2>
        <ul><?php foreach ($problem['look_for'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul>
      </section>
      <section class="panel" aria-labelledby="askVendors">
        <h2 id="askVendors" style="margin-top:0">Questions to ask a vendor</h2>
        <ul><?php foreach ($problem['ask_vendors'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul>
      </section>
    </div>

    <h2 style="margin-top:28px">Tools to consider (<?= count($results) ?>)</h2>
    <?php if (!$results): ?>
      <p class="noticeBox">No tools in the catalogue match this problem yet. Try <a href="/search.php?q=<?= h(rawurlencode($problem['query'])) ?>">searching</a> or <a href="/submit.php">submit a tool</a>.</p>
    <?php else: ?>
      <p class="small"><?= (int)$reviewedN ?> of these <?= $reviewedN === 1 ? 'has a' : 'have' ?> reviewed, sourced profile<?= $reviewedN === 1 ? '' : 's' ?>; the others are catalogue listings we have not reviewed.<?= $indexable ? '' : ' This page is not indexed by search engines until at least three reviewed tools match.' ?></p>
      <div class="cardGrid">
        <?php foreach ($results as $r) echo render_tool_card($r['tool'], ['placement' => 'solutions', 'heading' => 3, 'why' => $r['reasons'] ?? [], 'evidence' => true]); ?>
      </div>
    <?php endif; ?>

    <section class="panel" style="margin-top:24px" aria-labelledby="nextSteps">
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
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<header class="pageHero" style="padding-top:0">
  <h1>Find AI tools by problem</h1>
  <p class="lead">Start from the job you need done. Each page explains what to look for, which questions to ask vendors, and shows a ranked shortlist with the reason every tool matched. No AI chatbot is involved: the ranking rules are published in our <a href="/methodology.php#search">methodology</a>.</p>
</header>
<div class="cardGrid">
  <?php foreach ($problems as $s => $p): $res = solution_results($tools, $p, 40); if (!$res) continue; ?>
    <article class="tCard">
      <div class="tCard__top"><span class="ico" aria-hidden="true"><?= ui_icon($p['icon'] ?? 'search') ?></span><div class="tCard__title"><h2><a href="<?= h(solution_path($s)) ?>"><?= h($p['title']) ?></a></h2></div></div>
      <p class="tCard__desc"><?= h($p['problem']) ?></p>
      <p class="small"><?= (int)count($res) ?> matching tools · <?= (int)solution_reviewed_count($res) ?> reviewed</p>
    </article>
  <?php endforeach; ?>
</div>
<p style="margin-top:24px"><a class="btn" href="/recommend.php">Get a personalised shortlist</a> <a class="btn secondary" href="/search.php">Search all tools</a></p>
<?php require __DIR__ . '/layout_bottom.php'; ?>
