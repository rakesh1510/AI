<?php
require_once __DIR__ . '/helpers.php';
$title = "AI Use Case Explorer — Find AI Tools by Goal";
$meta_desc = "Explore AI tools by real use case such as writing blog posts, creating videos, building websites, automating work, and designing graphics.";

$useCases = load_use_cases();
$main_class = 'fullbleed';
$crumbs = [['Home', site_url('/')], ['Use cases', null]];
require __DIR__ . '/layout_top.php';
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Use-case explorer', 'icon' => 'layers', 'title_html' => 'Find AI tools <span class="gradText2">by goal</span>',
  'lead' => 'Find the right AI tools by real goals, not just category names. Explore trusted tools for writing, videos, automation, websites, and visual design — with clearer descriptions, stronger intent, and a more useful discovery flow.', 'art' => 'compass',
  'facts' => [count($useCases) . ' core use cases', count(load_tools()['tools'] ?? []) . ' suggested tools']]);
$__ac = [['#8b5cf6', '#ec4899'], ['#06b6d4', '#2563eb'], ['#10b981', '#0ea5e9'], ['#f59e0b', '#ef4444'], ['#3b82f6', '#4f46e5'], ['#d946ef', '#6366f1']];
?>
<div class="wrap pgBody">
  <div class="sectionHead"><div><h2>What do you want to do?</h2><p>Explore use-case pages with descriptions, example tools, popular tasks, and suggested matches.</p></div></div>
  <ul class="catGrid">
    <?php $n = 0; foreach ($useCases as $slug => $case): $ac = $__ac[$n++ % 6]; ?>
      <li><article class="catTile" style="--c1:<?= $ac[0] ?>;--c2:<?= $ac[1] ?>">
        <span class="catIcon ucEmoji" aria-hidden="true"><?= h($case['icon'] ?? '✨') ?></span>
        <h2><a href="ai-use-case.php?slug=<?= urlencode($slug) ?>"><?= h($case['label'] ?? $slug) ?></a></h2>
        <p><?= h($case['description'] ?? 'Explore suggested tools for this use case.') ?></p>
        <?php if (!empty($case['popular_tasks']) && is_array($case['popular_tasks'])): ?><ul class="tickList" style="position:relative;z-index:2"><?php foreach (array_slice($case['popular_tasks'], 0, 3) as $task): ?><li><?= h($task) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <div class="catFoot"><span class="catCount"><?= !empty($case['example_tools']) && is_array($case['example_tools']) ? count($case['example_tools']) . ' example tools' : 'Use case' ?></span><span class="catGo">Explore <?= ui_icon('arrow') ?></span></div>
      </article></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
