<?php
// AI tool pricing and capability comparison for a group of reviewed tools. Pricing is shown exactly as stated in the sources, with the as-of
// date and confidence note; nothing is calculated or estimated. Exists only for groups that pass the editorial evidence gate.
require_once __DIR__ . '/helpers.php';
$byId = tools_by_id();
$groups = pricing_groups($byId);
$slug = trim((string)($_GET['group'] ?? ''));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
if ($slug !== '' && !isset($groups[$slug])) {
  http_response_code(404);
  $title = 'Comparison Not Found'; $meta_desc = 'No pricing comparison is available for this group.';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Comparison not found</h1><p class="small">Pricing comparisons exist only for groups with at least three reviewed, sourced profiles.</p><p><a class="btn" href="/pricing-comparison.php">All pricing comparisons</a></p>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$crumbs = [['Home', site_url('/')], ['Pricing comparisons', $slug === '' ? null : site_url('/pricing-comparison.php')]];
if ($slug === '') {
  $title = 'AI Tool Pricing Comparisons: Plans, Free Options and Sources';
  $meta_desc = 'Compare AI tool pricing as stated by vendors, with the date each price was retrieved, a confidence note and links to the sources. Groups appear only when at least three profiles are reviewed.';
  if (!$groups) $robots = 'noindex';
  $canonical = site_url('/pricing-comparison.php');
  $jsonld = [seo_breadcrumb_node($crumbs)];
  require __DIR__ . '/layout_top.php';
  echo render_breadcrumbs($crumbs);
  ?>
  <header class="pageHero" style="padding-top:0"><h1>AI tool pricing comparisons</h1><p class="lead">Prices exactly as vendors state them, with the date we retrieved them and how confident we are. We never calculate or estimate prices. Prices change often: always confirm on the vendor's page.</p></header>
  <?php if (!$groups): ?><p class="noticeBox">No group has enough reviewed profiles yet.</p><?php else: ?>
    <ul class="linkList"><?php foreach ($groups as $s => $g): ?><li><a href="/pricing-comparison.php?group=<?= $h(rawurlencode($s)) ?>"><?= $h($g['label']) ?>: pricing and capabilities (<?= count($g['tools']) ?> reviewed tools)</a></li><?php endforeach; ?></ul>
  <?php endif;
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$g = $groups[$slug];
$title = $g['label'] . ': AI Tool Pricing and Capability Comparison';
$meta_desc = 'Pricing as stated by vendors, free options, limitations and documented capabilities for ' . count($g['tools']) . ' reviewed ' . $g['label'] . ' tools, with sources and retrieval dates.';
$canonical = site_url('/pricing-comparison.php?group=' . rawurlencode($slug));
$crumbs[1][1] = site_url('/pricing-comparison.php'); $crumbs[] = [$g['label'], null];
$jsonld = [seo_breadcrumb_node($crumbs), ['@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => seo_title($title), 'description' => seo_description($meta_desc), 'isPartOf' => ['@id' => site_url('/#website')], 'mainEntity' => seo_tool_itemlist_node($g['tools'], $g['label'])]];
require __DIR__ . '/layout_top.php';
echo render_breadcrumbs($crumbs);
?>
<article>
<header class="pageHero" style="padding-top:0"><h1><?= $h($g['label']) ?>: pricing and capabilities</h1>
  <p class="lead">Only tools with reviewed, sourced profiles are listed. Pricing is shown as the vendor states it. "Unknown" means our sources do not say, not that the tool lacks the feature. <a href="/methodology.php#evidence">How to read the evidence levels</a>.</p></header>
<div class="matrixWrap" tabindex="0" role="region" aria-label="Pricing comparison, scrollable">
<table class="matrix pkgTable">
  <caption class="sr">Pricing as stated in vendor sources for <?= $h($g['label']) ?> tools</caption>
  <thead><tr><th scope="col">Tool</th><th scope="col">Free option</th><th scope="col">Pricing as stated</th><th scope="col">Evidence</th><th scope="col">Main limitation</th></tr></thead>
  <tbody>
  <?php foreach ($g['tools'] as $t): $p = $t['curated']; $e = tool_evidence($t); ?>
    <tr>
      <th scope="row"><a href="<?= $h(tool_path($t['id'])) ?>"><?= $h($t['name']) ?></a></th>
      <td data-label="Free option"><?= $h(profile_free_option($p)) ?></td>
      <td data-label="Pricing as stated"><?= $h($e['pricing_summary']) ?><span class="cellEv">As of <?= $h($e['pricing_as_of']) ?><?= $e['pricing_stale'] ? ' (older than ' . (int)EVIDENCE_STALE_DAYS . ' days)' : '' ?>; <?= $h($e['pricing_note']) ?></span></td>
      <td data-label="Evidence"><?= render_evidence_badge($t) ?></td>
      <td data-label="Main limitation"><?= $h(profile_key_limit($p)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $rows = capability_matrix($g['tools']); if ($rows): ?>
<h2 style="margin-top:28px">Documented capabilities</h2>
<div class="matrixWrap" tabindex="0" role="region" aria-label="Capability comparison, scrollable">
<table class="matrix">
  <caption class="sr">Capabilities documented in sources, with unknown values marked</caption>
  <thead><tr><th scope="col">Capability</th><?php foreach ($g['tools'] as $t): ?><th scope="col"><?= $h($t['name']) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr class="<?= $row['differs'] ? 'differs' : '' ?>"><th scope="row"><?= $h($row['label']) ?></th>
      <?php foreach ($row['cells'] as $i => $c): ?><td data-label="<?= $h($g['tools'][$i]['name']) ?>"><span class="cellState s-<?= $h($c['state']) ?>"><?= $h(capability_state_text($c['state'])) ?></span></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<h2 style="margin-top:28px">Sources</h2>
<ul class="small"><?php foreach ($g['tools'] as $t): $e = tool_evidence($t); ?><li><strong><?= $h($t['name']) ?>:</strong> <?php foreach ($e['sources'] as $k => $s): ?><?= $k ? ' · ' : '' ?><a href="<?= $h($s['url']) ?>" rel="nofollow noopener" target="_blank"><?= $h($s['label']) ?></a> (retrieved <?= $h($s['retrieved'] ?? '') ?>)<?php endforeach; ?></li><?php endforeach; ?></ul>
<p class="small">Need a personalised shortlist? <a href="/recommend.php">Try the recommendation wizard</a>. Want to compare two tools? <a href="/compare.php">Use the comparison tool</a>.</p>
</article>
<?php require __DIR__ . '/layout_bottom.php'; ?>
