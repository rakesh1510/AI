<?php
// A single published newsletter issue.
require_once __DIR__ . '/helpers.php';
$slug = trim((string)($_GET['slug'] ?? ''));
$issues = newsletter_issues_public();
$i = $issues[$slug] ?? null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
if (!$i) {
  http_response_code(404);
  $title = 'Issue Not Found'; $meta_desc = 'This newsletter issue is not available.';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Issue not found</h1><p><a class="btn" href="/newsletter-archive.php">Newsletter archive</a></p>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$title = $i['title'];
$meta_desc = $i['summary'] ?: 'AIHubpedia newsletter issue published ' . $i['published_on'] . '.';
$canonical = newsletter_issue_url($slug);
$crumbs = [['Home', site_url('/')], ['Newsletter archive', site_url('/newsletter-archive.php')], [$i['title'], null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<article class="prose">
  <h1><?= $h($i['title']) ?></h1>
  <p class="guideMeta">Published <?= $h($i['published_on']) ?> · Edited by <?= $h($i['editor']) ?></p>
  <?php if (!empty($i['summary'])): ?><p><?= $h($i['summary']) ?></p><?php endif; ?>
  <ul>
    <?php foreach ($i['items'] as $it): ?>
      <li><?= $h($it['note']) ?> <a href="<?= $h($it['source']) ?>" rel="nofollow noopener" target="_blank">Source</a><?php if (!empty($it['tool']) && find_tool($it['tool'])): ?> · <a href="<?= $h(tool_path($it['tool'])) ?>">AIHubpedia profile</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if (!empty($i['sponsor'])): ?><aside class="panel"><p class="adLabel"><span class="adTag">Sponsored</span> <a href="/disclosure.php">Why am I seeing this?</a></p><p><strong><?= $h($i['sponsor']['label']) ?></strong> <?= $h($i['sponsor']['text'] ?? '') ?></p></aside><?php endif; ?>
</article>
<?php require __DIR__ . '/layout_bottom.php'; ?>
