<?php
// Newsletter archive. Lists only published, validated issues. The newsletter has not launched, so the honest empty state is the norm.
require_once __DIR__ . '/helpers.php';
$issues = newsletter_issues_public();
$title = 'AIHubpedia Newsletter Archive';
$meta_desc = 'Past AIHubpedia newsletter issues: notable AI tool price and plan changes and new guides, each item with its source.';
if (!$issues) $robots = 'noindex';       // an empty archive is a thin page
$crumbs = [['Home', site_url('/')], ['Newsletter archive', null]];
$jsonld = [seo_breadcrumb_node($crumbs)];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
?>
<?php echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Newsletter', 'icon' => 'mail', 'title_html' => 'Newsletter <span class="gradText2">archive</span>', 'lead' => 'Every issue we send is kept here, with the source for each item. Subscribing is optional and double opt-in; you can unsubscribe at any time.', 'art' => 'mail', 'size' => 'sm']); ?>
<div class="wrap pgBody">
<?php if (!$issues): ?>
  <div class="panel"><h2 style="margin-top:0">No issues published yet</h2>
    <p>The AIHubpedia newsletter has not launched, so there is nothing to show. If you subscribe in the footer, you will get a confirmation email first, and nothing is sent until an issue has been reviewed by an editor.</p>
    <p class="small">Meanwhile: <a href="/changelog.php">recently updated profiles</a> · <a href="/guides.php">guides</a>.</p></div>
<?php else: ?>
  <ul class="timeline">
    <?php foreach ($issues as $slug => $i): ?>
      <li><strong><?= $h($i['published_on']) ?></strong> · <a href="<?= $h(newsletter_issue_path($slug)) ?>"><?= $h($i['title']) ?></a><?php if (!empty($i['summary'])): ?><br><span class="small"><?= $h($i['summary']) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
