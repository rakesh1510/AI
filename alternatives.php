<?php
require_once __DIR__ . '/helpers.php';
$id = trim($_GET['tool'] ?? '');
$byId = tools_by_id();
$t = $byId[$id] ?? null;
$alts = $t ? curated_alternatives($t, $byId, 8) : [];
if (!$t || empty($t['curated']) || count($alts) < 3) {
  http_response_code(404);
  $title = 'Alternatives Not Found';
  $meta_desc = 'No alternatives comparison is available for this tool on AIHubpedia.';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Alternatives not found</h1><p class="small">Alternatives pages exist only for tools with reviewed profiles and at least three reviewed alternatives.</p><div class="actions"><a class="btn" href="/guides.php">Browse guides</a><a class="btn secondary" href="/categories.php">Browse categories</a></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$cp = $t['curated'];
$n = count($alts);
$title = $t['name'] . ' Alternatives: ' . $n . ' Options Compared';
$meta_desc = $n . ' alternatives to ' . $t['name'] . ' compared on free options, listed pricing and limitations: ' . implode(', ', array_map(fn($a) => $a['name'], array_slice($alts, 0, 4))) . '.';
$canonical = alternatives_url($id);
$crumbs = [['Home', site_url('/')], [$t['name'], tool_page_url($id)], ['Alternatives', null]];
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => seo_title($title), 'description' => seo_description($meta_desc),
   'isPartOf' => ['@id' => site_url('/#website')], 'about' => ['@type' => 'SoftwareApplication', 'name' => $t['name'], 'url' => tool_page_url($id)],
   'mainEntity' => seo_tool_itemlist_node($alts, 'Alternatives to ' . $t['name'])],
  seo_breadcrumb_node($crumbs),
];
$related = guides_for_tool($id);
$why = [];
foreach (tool_alternatives_ranked($t, $byId, 12) as $r) $why[$r['tool']['id']] = $r;
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<article class="prose">
  <h1><?= h($t['name']) ?> alternatives</h1>
  <p class="guideMeta"><?= render_evidence_badge($t) ?> · Facts from vendor pages collected <?= h($cp['review']['reviewed_on'] ?? '') ?> · <a href="/methodology.php">Methodology</a></p>
  <p>People look for <?= h($t['name']) ?> alternatives for practical reasons. From its vendor pages, the main limits to be aware of are:</p>
  <ul><?php foreach (array_slice($cp['limitations'] ?? [], 0, 3) as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
  <p>The tools below are other options in the “<?= h($t['subcategory'] ?: $t['category']) ?>” group that have reviewed profiles on AIHubpedia. We have not benchmarked output quality, so use the comparison to shortlist and then test. “Why it is listed” shows the documented reasons; a capability we have no sourced statement about is treated as unknown, not as missing.</p>

  <h2><?= h($t['name']) ?> compared with its alternatives</h2>
  <?= render_profile_table(array_merge([$t], $alts)) ?>

  <?php foreach ($alts as $a): $ap = $a['curated']; ?>
    <h2><?= h($a['name']) ?></h2>
    <p><?= h($ap['tagline']) ?></p>
    <ul>
      <li><strong>Best for:</strong> <?= h(implode('; ', array_slice($ap['best_for'] ?? [], 0, 2))) ?></li>
      <li><strong>Free option:</strong> <?= h(profile_free_option($ap)) ?></li>
      <li><strong>Watch out for:</strong> <?= h(profile_key_limit($ap)) ?></li>
    </ul>
    <?php if (!empty($why[$a['id']]['reasons'])): $w = $why[$a['id']]; ?>
      <p class="altWhy"><strong>Why it is listed:</strong></p>
      <ul class="altWhy"><?php foreach ($w['reasons'] as $rs): ?><li><?= h($rs) ?></li><?php endforeach; ?>
        <?php if ($w['only_candidate']): ?><li>Also documents: <?= h(implode(', ', array_slice($w['only_candidate'], 0, 4))) ?></li><?php endif; ?>
        <?php if ($w['pricing_differs']): ?><li>Pricing model differs from <?= h($t['name']) ?>: check the pricing details</li><?php endif; ?></ul>
    <?php endif; ?>
    <p><a href="<?= h(tool_path($a['id'])) ?>"><?= h($a['name']) ?> profile</a> · <a href="<?= h(compare_path($t['id'], $a['id'])) ?>"><?= h($t['name']) ?> vs <?= h($a['name']) ?></a></p>
  <?php endforeach; ?>

  <?php if ($related): ?>
    <h2>Related guides</h2>
    <ul class="linkList"><?php foreach ($related as $slug => $g): ?><li><a href="<?= h(guide_path($slug)) ?>"><?= h($g['h1']) ?></a></li><?php endforeach; ?></ul>
  <?php endif; ?>
</article>
<?php require __DIR__ . '/layout_bottom.php'; ?>
