<?php
require_once __DIR__ . '/helpers.php';
$guides = guides_all();
$title = 'AI Tool Guides: Comparisons Built From Vendor Sources';
$meta_desc = 'Plain-language AI tool guides comparing plans, free-tier limits, pricing and policies, with source links and dates so you can check every claim.';
$crumbs = [['Home', site_url('/')], ['Guides', null]];
$items = [];
$i = 0;
foreach ($guides as $slug => $g) { $items[] = ['@type' => 'ListItem', 'position' => ++$i, 'url' => guide_url($slug), 'name' => $g['title']]; }
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => site_url('/guides.php#webpage'), 'url' => site_url('/guides.php'), 'name' => 'AI tool guides', 'isPartOf' => ['@id' => site_url('/#website')],
   'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items), 'itemListElement' => $items]],
  seo_breadcrumb_node($crumbs),
];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__mins = function (array $g): int { $w = str_word_count(strip_tags(implode(' ', array_merge((array)($g['intro'] ?? []), array_column((array)($g['picks'] ?? []), 'why'), (array)($g['criteria'] ?? []), (array)($g['checklist'] ?? []))))); return max(2, (int)ceil($w / 200)); };
$__date = fn(string $d) => ($ts = strtotime($d)) ? date('j M Y', $ts) : $d;
$__cats = []; foreach ($guides as $g) { $__cats[(string)($g['category'] ?? 'Guides')] = true; }
$__first = array_key_first($guides);
$__feat = $__first !== null ? $guides[$__first] : null;
$__featTools = [];
if ($__feat) foreach (array_slice((array)$__feat['tools'], 0, 5) as $tid) { if ($tt = find_tool((string)$tid)) $__featTools[] = $tt; }
$__allTools = []; foreach ($guides as $g) foreach ((array)$g['tools'] as $tid) { if (!isset($__allTools[$tid]) && ($tt = find_tool((string)$tid))) $__allTools[$tid] = $tt; }
?>
<section class="pgHero stage" data-tone="<?= h(page_tone()) ?>">
  <div class="wrap">
    <div>
    <div class="pgHero__crumbs"><?= render_breadcrumbs($crumbs) ?></div>
    <div>
      <span class="eyebrow"><?= ui_icon('book') ?> The AIHubpedia guides</span>
      <h1>AI tool guides, <span class="gradText2">checked against sources</span></h1>
      <p class="lead">Each guide compares tools using the vendors' own pricing and help pages, shows the date the facts were collected, and links to every source. We say plainly where vendor pages disagree and where we have not tested a claim. <a href="/methodology.php" style="text-decoration:underline">Read our methodology</a>.</p>
    </div>
    </div>
    <div class="pgHero__art" aria-hidden="true"><?= art_book(true) ?></div>
  </div>
</section>

<section class="mag wrap" aria-labelledby="featTitle">
<?php if ($__feat): [$fc1, $fc2] = cat_theme((string)($__feat['category'] ?? '')); ?>
  <article class="magFeature" style="--c1:<?= h($fc1) ?>;--c2:<?= h($fc2) ?>">
    <?= art_thumb((string)($__feat['category'] ?? ''), 0) ?>
    <div class="magFeature__body">
      <span class="kicker">Featured guide · <?= h($__feat['category'] ?? 'Guide') ?></span>
      <h2 id="featTitle"><a href="<?= h(guide_path($__first)) ?>" style="text-decoration:none;color:inherit"><?= h($__feat['h1']) ?></a></h2>
      <p><?= h($__feat['description']) ?></p>
      <div class="metaRow"><span>Updated <?= h($__date((string)$__feat['updated'])) ?></span><span><?= (int)$__mins($__feat) ?> min read</span><span><?= count($__feat['tools']) ?> tools compared</span></div>
      <?php if ($__featTools): ?><span class="logoStack" aria-hidden="true"><?php foreach ($__featTools as $tt) echo logo_tile($tt, 'md'); ?></span><?php endif; ?>
      <a class="btn btn--accent" style="width:max-content;min-height:48px" href="<?= h(guide_path($__first)) ?>">Read the guide <?= ui_icon('arrow') ?></a>
    </div>
  </article>
<?php endif; ?>
</section>

<section class="section wrap" aria-labelledby="allGuides" style="padding-top:0">
  <div class="sectionHead"><div><h2 id="allGuides">All guides</h2><p>Browse by topic. Every guide shows when it was last updated.</p></div></div>
  <div class="gNav" role="group" aria-label="Filter guides by category">
    <button type="button" hidden data-gfilter="*" aria-pressed="true">All</button>
    <?php foreach (array_keys($__cats) as $gc): ?><button type="button" hidden data-gfilter="<?= h($gc) ?>" aria-pressed="false"><?= h($gc) ?></button><?php endforeach; ?>
  </div>
  <div class="gGrid">
  <?php $__n = 0; foreach ($guides as $slug => $g): [$gc1, $gc2] = cat_theme((string)($g['category'] ?? '')); ?>
    <article class="gCard" data-gcat="<?= h($g['category'] ?? '') ?>" style="--c1:<?= h($gc1) ?>;--c2:<?= h($gc2) ?>">
      <span class="kicker"><?= h($g['category'] ?? 'Guide') ?></span>
      <?= art_thumb((string)($g['category'] ?? ''), ++$__n) ?>
      <div class="gCard__body">
        <h3><a href="<?= h(guide_path($slug)) ?>"><?= h($g['h1']) ?></a></h3>
        <p><?= h($g['description']) ?></p>
        <div class="metaRow"><span>Updated <?= h($__date((string)$g['updated'])) ?></span><span><?= (int)$__mins($g) ?> min read</span></div>
        <div class="gCard__tools"><span class="logoStack" aria-hidden="true"><?php foreach (array_slice((array)$g['tools'], 0, 4) as $tid) { if ($tt = find_tool((string)$tid)) echo logo_tile($tt, 'sm'); } ?></span><span><?= count($g['tools']) ?> tools compared</span></div>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>

<?php if ($__allTools): ?>
<section class="section wrap" aria-labelledby="relTitle" style="padding-top:0">
  <div class="sectionHead"><div><h2 id="relTitle">Tools covered in these guides</h2><p>Open a profile to see its sources, limits and pricing date.</p></div><a class="moreLink" href="/compare.php">Compare tools <?= ui_icon('arrow') ?></a></div>
  <ul class="relTools">
    <?php foreach (array_slice($__allTools, 0, 12) as $tt): ?>
      <li><a class="relTool" href="/tool.php?id=<?= h(urlencode($tt['id'])) ?>"><?= logo_tile($tt, 'md') ?><span><strong><?= h($tt['name']) ?></strong><small><?= h($tt['category'] ?? '') ?></small></span></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
