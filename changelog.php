<?php
// Recently updated tools and the verified change log. Verified entries (editor + evidence URL) are listed separately from
// automatically derived "profile reviewed" markers. Nothing here is generated or guessed.
require_once __DIR__ . '/helpers.php';
$byId = tools_by_id();
$all = changelog_entries($byId);
$verified = array_values(array_filter($all, fn($e) => !empty($e['verified'])));
$derived = array_values(array_filter($all, fn($e) => !empty($e['derived'])));
$title = 'Recently Updated AI Tool Profiles and Verified Change Log';
$meta_desc = 'When AIHubpedia last reviewed each tool profile, and the changes editors have verified against vendor pages. Nothing is guessed.';
$crumbs = [['Home', site_url('/')], ['Recently updated', null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
?>
<?php echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Changelog', 'icon' => 'check', 'title_html' => 'Recently updated <span class="gradText2">tool profiles</span>', 'lead' => 'When each profile was last reviewed, and the changes our editors have verified against vendor pages. We list only what we can point to a source for.', 'art' => 'layers', 'size' => 'sm']); ?>
<div class="wrap pgBody">

<section class="panel" aria-labelledby="verTitle">
  <h2 id="verTitle" style="margin-top:0">Verified changes</h2>
  <?php if (!$verified): ?>
    <p class="noticeBox">No editor-verified changes have been recorded yet. Entries appear here only with a date, a summary, a vendor source page and the name of the editor who checked it.</p>
  <?php else: ?>
    <ul class="timeline">
      <?php foreach ($verified as $e): $t = $byId[$e['tool']]; ?>
        <li><strong><?= $h($e['date']) ?></strong> · <a href="<?= $h(tool_path($e['tool'])) ?>"><?= $h($t['name']) ?></a> · <span class="pill"><?= $h(changelog_kinds()[$e['kind']]) ?></span><br><?= $h($e['summary']) ?>
          <span class="small">Verified by <?= $h($e['verified_by']) ?> on <?= $h($e['verified_on']) ?>. Evidence: <?php foreach ($e['evidence'] as $k => $u): ?><?= $k ? ', ' : '' ?><a href="<?= $h($u) ?>" rel="nofollow noopener" target="_blank">source <?= $k + 1 ?></a><?php endforeach; ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section style="margin-top:20px" aria-labelledby="revTitle">
  <h2 id="revTitle">Profile reviews</h2>
  <p class="small">These are the dates on which AIHubpedia retrieved vendor sources for a profile. They are not announcements of changes by the vendor. <a href="/methodology.php#evidence">What the evidence levels mean</a>.</p>
  <div class="matrixWrap" tabindex="0" role="region" aria-label="Profile review dates, scrollable">
  <table class="matrix">
    <caption class="sr">Tool profiles and when their sources were last retrieved</caption>
    <thead><tr><th scope="col">Tool</th><th scope="col">Sources retrieved</th><th scope="col">Evidence level</th></tr></thead>
    <tbody>
      <?php foreach ($derived as $e): $t = $byId[$e['tool']]; ?>
        <tr><th scope="row"><a href="<?= $h(tool_path($e['tool'])) ?>"><?= $h($t['name']) ?></a></th><td data-label="Sources retrieved"><?= $h($e['date']) ?></td><td data-label="Evidence level"><?= render_evidence_badge($t, false) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$derived): ?><tr><td colspan="3">No reviewed profiles yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
