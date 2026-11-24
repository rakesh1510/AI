<?php
require_once __DIR__ . '/helpers.php';
$slug = trim($_GET['slug'] ?? '');
$g = $slug !== '' ? guide_get($slug) : null;
if (!$g) {
  http_response_code(404);
  $title = 'Guide Not Found';
  $meta_desc = 'The guide you requested could not be found on AIHubpedia.';
  $main_class = 'fullbleed';
  require __DIR__ . '/layout_top.php';
  echo '<div class="wrap pgBody">' . state_box(['art' => 'lost', 'eyebrow' => 'Not found', 'title' => 'Guide not found', 'html' => '<div class="actions"><a class="btn" href="/guides.php">Browse all guides</a></div>']) . '</div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$byId = tools_by_id();
$rows = [];
foreach ($g['tools'] as $id) { if (isset($byId[$id]) && !empty($byId[$id]['curated'])) $rows[] = $byId[$id]; }
$title = $g['title'];
$meta_desc = $g['description'];
$canonical = guide_url($slug);
$og_type = 'article';
$crumbs = [['Home', site_url('/')], ['Guides', site_url('/guides.php')], [$g['h1'], null]];
$article = [
  '@type' => 'Article', '@id' => $canonical . '#article',
  'headline' => seo_truncate($g['h1'], 110), 'description' => seo_description($meta_desc), 'url' => $canonical, 'mainEntityOfPage' => $canonical,
  'inLanguage' => 'en', 'datePublished' => $g['published'], 'dateModified' => $g['updated'],
  'author' => ['@type' => 'Organization', 'name' => guide_author(), 'url' => site_url('/methodology.php')],
  'publisher' => ['@id' => site_url('/#organization')], 'image' => site_url('/logo.png'),
  'about' => array_map(fn($t) => ['@type' => 'SoftwareApplication', 'name' => $t['name'], 'url' => tool_page_url($t['id'])], $rows),
];
$jsonld = [$article, seo_tool_itemlist_node($rows, $g['h1']), seo_breadcrumb_node($crumbs)];
$head_extra = '<meta property="article:published_time" content="' . h($g['published']) . '"><meta property="article:modified_time" content="' . h($g['updated']) . '">';
// Aggregate sources across the profiles used.
$sources = [];
foreach ($rows as $t) foreach (($t['curated']['sources'] ?? []) as $s) $sources[$s['url']] = $s + ['tool' => $t['name']];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
?>
<?php ob_start(); ?><?= h($g['h1']) ?><?php $__t = ob_get_clean(); ob_start(); ?>By <?= h(guide_author()) ?> · Updated <time datetime="<?= h($g['updated']) ?>"><?= h($g['updated']) ?></time> · Facts from vendor pages collected <?= h($rows ? ($rows[0]['curated']['review']['reviewed_on'] ?? $g['updated']) : $g['updated']) ?> · <a href="/methodology.php">Methodology</a><?php $__l = ob_get_clean();
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Guide', 'icon' => 'book', 'title_html' => $__t, 'lead' => $__l, 'art' => 'lens', 'size' => 'sm']); ?>
<div class="wrap pgBody">
<?php ob_start(); ?>

  <?php foreach ($g['intro'] as $para): ?><p><?= h($para) ?></p><?php endforeach; ?>

  <div class="noticeBox"><strong>How to read this guide.</strong> Prices and limits change often and several vendor pages disagree with each other; each tool profile lists the conflicts we found. AIHubpedia has not benchmarked output quality or accuracy, and profiles are marked as pending editor verification until a person confirms them on the live pages.</div>

  <h2>How we compared</h2>
  <ul><?php foreach ($g['criteria'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>

  <h2>Comparison at a glance</h2>
  <?= render_profile_table($rows) ?>

  <h2>Which one fits you</h2>
  <?php foreach ($g['picks'] as $pick): $t = $byId[$pick['tool']] ?? null; if (!$t) continue; ?>
    <h3><?= h($pick['label']) ?>: <a href="<?= h(tool_path($t['id'])) ?>"><?= h($t['name']) ?></a></h3>
    <p><?= h($pick['why']) ?></p>
  <?php endforeach; ?>

  <h2>Before you choose</h2>
  <ul><?php foreach ($g['checklist'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>

  <h2>Frequently asked questions</h2>
  <?php foreach ($g['faq'] as $f): ?>
    <h3><?= h($f['q']) ?></h3>
    <p><?= h($f['a']) ?></p>
  <?php endforeach; ?>

  <h2>Tool profiles and alternatives</h2>
  <ul class="linkList">
    <?php foreach ($rows as $t): ?>
      <li><a href="<?= h(tool_path($t['id'])) ?>"><?= h($t['name']) ?> profile</a></li>
      <?php if (count(curated_alternatives($t, $byId, 8)) >= 3): ?><li><a href="<?= h(alternatives_path($t['id'])) ?>"><?= h($t['name']) ?> alternatives</a></li><?php endif; ?>
    <?php endforeach; ?>
  </ul>

  <h2>Sources</h2>
  <ul class="small">
    <?php foreach ($sources as $s): ?>
      <li><a href="<?= h($s['url']) ?>" rel="nofollow noopener" target="_blank"><?= h($s['label']) ?></a> (<?= h($s['tool']) ?>, <?= h($s['type']) ?>, retrieved <?= h($s['retrieved']) ?>)</li>
    <?php endforeach; ?>
  </ul>
  <p class="small">Spotted something out of date? Email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a> and we will review it.</p>
<?php $__doc = ob_get_clean(); echo doc_page($__doc); ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
