<?php
require_once __DIR__ . '/helpers.php';

$slug = trim($_GET['slug'] ?? '');
$useCases = load_use_cases();
$case = $useCases[$slug] ?? null;

if (!$case) {
    // Permanent redirect (was a 302) so unknown/legacy slugs consolidate to the explorer.
    header('Location: /ai-use-case-explorer.php', true, 301);
    exit;
}

$title = 'AI Tools to ' . lcfirst((string)($case['label'] ?? 'Use Case'));
if (stripos((string)($case['label'] ?? ''), 'ai ') === 0) $title = (string)$case['label'];
$meta_desc = (string)($case['description'] ?? '') !== '' ? (string)$case['description'] : ('AI tools for ' . ($case['label'] ?? 'this use case') . ' on AIHubpedia.');
$canonical = use_case_page_url($slug);

$tools = match_tools_for_use_case($slug, 12);
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => seo_title($title),
   'isPartOf' => ['@id' => site_url('/#website')], 'mainEntity' => seo_tool_itemlist_node($tools, 'AI tools for ' . ($case['label'] ?? ''))],
  seo_breadcrumb_node([['Home', site_url('/')], ['Use cases', site_url('/ai-use-case-explorer.php')], [(string)($case['label'] ?? ''), null]]),
];

$crumbs = [['Home', site_url('/')], ['Use cases', site_url('/ai-use-case-explorer.php')], [(string)($case['label'] ?? ''), null]];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__facts = [];
if (!empty($case['audience'])) $__facts[] = 'For: ' . $case['audience'];
if (!empty($case['example_tools']) && is_array($case['example_tools'])) $__facts[] = count($case['example_tools']) . ' example tools';
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Use case', 'icon' => 'search', 'title' => (string)$case['label'], 'lead' => h($case['description'] ?? ''), 'art' => 'compass', 'facts' => $__facts, 'size' => 'sm']);
?>
<div class="wrap pgBody">
  <div class="bento">
    <section class="bento__cell bento__cell--a" style="--c1:#8b5cf6;--c2:#ec4899"><span class="ico3d"><?= ui_icon('sparkle') ?></span>
      <h2 style="margin:0;font-size:1.5rem">Why people use this category</h2>
      <p><?= h($case['hero_text'] ?? ($case['description'] ?? '')) ?></p>
      <?php if (!empty($case['benefits']) && is_array($case['benefits'])): ?><h3 style="margin:6px 0 0;font-size:1.1rem">Key benefits</h3><ul class="tickList"><?php foreach ($case['benefits'] as $benefit): ?><li><?= h($benefit) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
    <section class="bento__cell bento__cell--a" style="--c1:#06b6d4;--c2:#2563eb"><span class="ico3d"><?= ui_icon('check') ?></span>
      <h2 style="margin:0;font-size:1.5rem">Popular tasks</h2>
      <?php if (!empty($case['popular_tasks']) && is_array($case['popular_tasks'])): ?><ul class="tickList"><?php foreach ($case['popular_tasks'] as $task): ?><li><?= h($task) ?></li><?php endforeach; ?></ul><?php else: ?><p>No tasks added yet.</p><?php endif; ?>
      <?php if (!empty($case['example_tools']) && is_array($case['example_tools'])): ?><h3 style="margin:10px 0 0;font-size:1.1rem">Example tools people often explore</h3><ul class="chipRow chipRow--light"><?php foreach ($case['example_tools'] as $tool): ?><li><?= h($tool) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>

  <div class="sectionHead" style="margin-top:var(--s-5)"><div><h2>Suggested tools</h2><p>Matched by keywords, intent, and fallback tools so you can discover options faster.</p></div></div>
  <?php if (empty($tools)): ?>
    <div class="emptyMini">No tools matched this use case yet.</div>
  <?php else: ?>
    <div class="cardGrid">
      <?php foreach ($tools as $t) echo render_tool_card($t, ['placement' => 'use_case', 'heading' => 3]); ?>
    </div>
  <?php endif; ?>
  <div class="actions" style="margin-top:var(--s-4)"><a class="btn secondary" href="/ai-use-case-explorer.php">All use cases</a><a class="btn secondary" href="/solutions.php">Find by problem</a></div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
