<?php
require_once __DIR__ . '/helpers.php';

$slug = seo_param($_GET, 'slug');
$useCases = load_use_cases();
$case = $useCases[$slug] ?? null;

if (!$case) {
    http_response_code(404);
    $title = 'Use case not found — AIHubpedia';
    require __DIR__ . '/layout_top.php';
    echo '<h1>Use case not found</h1><p><a href="/ai-use-case-explorer.php">Explore use cases</a></p>';
    require __DIR__ . '/layout_bottom.php';
    exit;
}

$title = ($case['label'] ?? 'Use Case') . " — AIHubpedia";
$meta_desc = $case['description'] ?? '';

$tools = match_tools_for_use_case($slug, 12);

require __DIR__ . '/layout_top.php';
?>

<style>
.ucDetailHero{position:relative;overflow:hidden;padding:34px;background:linear-gradient(135deg,#f4eeff 0%,#efe9ff 55%,#e9e3ff 100%);}
.ucDetailHero:after{content:"";position:absolute;right:-100px;top:-80px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle, rgba(99,58,237,.12), rgba(99,58,237,0));}
.ucDetailHero h1{margin:0 0 10px;font-size:66px;line-height:1.02;font-weight:900;letter-spacing:-1.4px}
@media (max-width:980px){.ucDetailHero h1{font-size:44px}}
.ucDetailHero p{margin:0;color:#5b5478;font-size:19px;line-height:1.8;max-width:980px}
.ucFlex{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.ucTag{display:inline-flex;align-items:center;padding:9px 14px;border-radius:999px;background:#f6f1ff;border:1px solid #e4d9ff;color:#4b2fa2;font-weight:700;font-size:13px}
.ucBlockTitle{margin-top:0;font-size:32px}
.ucTwoCol{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}
@media (max-width:980px){.ucTwoCol{grid-template-columns:1fr}}
.ucPanel{background:#fcfbff;border:1px solid #e6ddff;border-radius:22px;padding:22px}
.ucPanel p,.ucPanel li{color:#5b5478;line-height:1.75}
.ucPanel ul{margin:0;padding-left:18px}
.ucToolGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}
.ucTrust{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.ucTrustBox{background:#f7f3ff;border:1px solid #e3d7ff;border-radius:20px;padding:18px}
.ucTrustBox h3{margin:0 0 8px;font-size:22px}
.ucTrustBox p{margin:0;color:#5b5478;line-height:1.7}
</style>

<section class="card ucDetailHero">
  <div style="font-size:64px;line-height:1"><?= h($case['icon'] ?? '✨') ?></div>
  <h1><?= h($case['label']) ?></h1>
  <p><?= h($case['description'] ?? '') ?></p>

  <div class="ucFlex">
    <?php if(!empty($case['audience'])): ?>
      <span class="ucTag">👥 <?= h($case['audience']) ?></span>
    <?php endif; ?>
    <?php if(!empty($case['example_tools']) && is_array($case['example_tools'])): ?>
      <span class="ucTag">🧰 <?= count($case['example_tools']) ?> example tools</span>
    <?php endif; ?>
    <span class="ucTag">🔎 Trusted discovery for real use cases</span>
  </div>
</section>

<section class="card">
  <div class="ucTwoCol">
    <div class="ucPanel">
      <h2 class="ucBlockTitle">Why people use this category</h2>
      <p><?= h($case['hero_text'] ?? ($case['description'] ?? '')) ?></p>

      <?php if(!empty($case['benefits']) && is_array($case['benefits'])): ?>
        <h3 style="margin:18px 0 10px;">Key benefits</h3>
        <ul>
          <?php foreach($case['benefits'] as $benefit): ?>
            <li><?= h($benefit) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="ucPanel">
      <h2 class="ucBlockTitle">Popular tasks</h2>
      <?php if(!empty($case['popular_tasks']) && is_array($case['popular_tasks'])): ?>
        <ul>
          <?php foreach($case['popular_tasks'] as $task): ?>
            <li><?= h($task) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p>No tasks added yet.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="card">
  <h2 class="ucBlockTitle">Example tools people often explore</h2>
  <div class="ucFlex">
    <?php if(!empty($case['example_tools']) && is_array($case['example_tools'])): ?>
      <?php foreach($case['example_tools'] as $tool): ?>
        <span class="ucTag"><?= h($tool) ?></span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <h2 class="ucBlockTitle">Why this page feels trustworthy</h2>
  <div class="ucTrust">
    <div class="ucTrustBox">
      <h3>Real user intent</h3>
      <p>These use cases are written around real goals like building websites, automating work, and creating better content.</p>
    </div>
    <div class="ucTrustBox">
      <h3>Clear tool matching</h3>
      <p>Suggested tools are matched by keywords, intent, and fallback tools so users can discover options faster.</p>
    </div>
    <div class="ucTrustBox">
      <h3>Better decision making</h3>
      <p>Visitors can compare examples, tasks, and tool suggestions in one place instead of guessing where to click.</p>
    </div>
  </div>
</section>

<section class="card">
  <h2 class="ucBlockTitle">Suggested tools</h2>

  <?php if(empty($tools)): ?>
    <p>No tools matched this use case yet.</p>
  <?php else: ?>
    <div class="ucToolGrid">
      <?php foreach($tools as $t): ?>
        <div class="card">
          <div style="display:flex;gap:10px;align-items:center">
            <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
            <div>
              <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
              <div class="small">Category: <b><?= h($t['category']) ?></b></div>
            </div>
          </div>

          <p style="margin-top:12px;line-height:1.75;min-height:72px;"><?= h($t['short'] ?? $t['description'] ?? '') ?></p>

          <div class="actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
            <?php if(tool_url($t)!=='#'): ?>
              <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
            <?php endif; ?>
          </div>

          <?= render_vote_buttons($t['id'], 'ai-use-case.php?slug=' . urlencode($slug)) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>

