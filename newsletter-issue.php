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
  $main_class = 'fullbleed';
  require __DIR__ . '/layout_top.php';
  echo '<div class="wrap pgBody">' . state_box(['art' => 'lost', 'eyebrow' => 'Not found', 'title' => 'Issue not found', 'html' => '<p><a class="btn" href="/newsletter-archive.php">Newsletter archive</a></p>']) . '</div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$title = $i['title'];
$meta_desc = $i['summary'] ?: 'AIHubpedia newsletter issue published ' . $i['published_on'] . '.';
$canonical = newsletter_issue_url($slug);
$crumbs = [['Home', site_url('/')], ['Newsletter archive', site_url('/newsletter-archive.php')], [$i['title'], null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
?>
<?php ob_start(); ?><?= $h($i['title']) ?><?php $__t = ob_get_clean(); ob_start(); ?>Published <?= $h($i['published_on']) ?> · Edited by <?= $h($i['editor']) ?><?php $__l = ob_get_clean();
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Newsletter issue', 'icon' => 'mail', 'title_html' => $__t, 'lead' => $__l, 'art' => 'mail', 'size' => 'sm']); ?>
<div class="wrap pgBody">
<?php ob_start(); ?>
  <?php if (!empty($i['summary'])): ?><p><?= $h($i['summary']) ?></p><?php endif; ?>
  <ul>
    <?php foreach ($i['items'] as $it): ?>
      <li><?= $h($it['note']) ?> <a href="<?= $h($it['source']) ?>" rel="nofollow noopener" target="_blank">Source</a><?php if (!empty($it['tool']) && find_tool($it['tool'])): ?> · <a href="<?= $h(tool_path($it['tool'])) ?>">AIHubpedia profile</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if (!empty($i['sponsor'])): ?><aside class="panel"><p class="adLabel"><span class="adTag">Sponsored</span> <a href="/disclosure.php">Why am I seeing this?</a></p><p><strong><?= $h($i['sponsor']['label']) ?></strong> <?= $h($i['sponsor']['text'] ?? '') ?></p></aside><?php endif; ?>
<?php $__doc = ob_get_clean(); echo doc_page($__doc); ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
