<?php
require_once __DIR__ . '/helpers.php';
$title = "AI Use-Case Explorer — AIHubpedia";
$meta_desc = "Explore AI tools by real use case such as writing blog posts, creating videos, building websites, automating work, and designing graphics.";

$useCases = load_use_cases();
require __DIR__ . '/layout_top.php';
?>

<style>
.ucHero{position:relative;overflow:hidden;padding:36px;background:linear-gradient(135deg,#f4eeff 0%,#efe9ff 55%,#e9e3ff 100%);}
.ucHero:before{content:"";position:absolute;inset:auto -80px -80px auto;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle, rgba(99,58,237,.16), rgba(99,58,237,0));}
.ucHeroGrid{display:grid;grid-template-columns:1.25fr .75fr;gap:22px;align-items:center}
@media (max-width:980px){.ucHeroGrid{grid-template-columns:1fr}}
.ucHeroTitle{margin:0;font-size:72px;line-height:1.02;font-weight:900;letter-spacing:-1.5px}
@media (max-width:980px){.ucHeroTitle{font-size:48px}}
.ucHeroLead{font-size:20px;line-height:1.7;color:#5b5478;margin:14px 0 0}
.ucHeroPanel{background:rgba(255,255,255,.72);border:1px solid rgba(95,69,196,.12);border-radius:26px;padding:22px;box-shadow:0 10px 30px rgba(55,30,120,.06);}
.ucMiniStatWrap{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:14px}
.ucMiniStat{background:#fff;border:1px solid rgba(95,69,196,.10);border-radius:18px;padding:16px;}
.ucMiniStat strong{display:block;font-size:28px}
.ucSectionTitle{margin-top:0;font-size:34px;line-height:1.15}
.ucCards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}
.ucCardLink{text-decoration:none;color:inherit}
.ucGoalCard{height:100%;background:linear-gradient(180deg,#ffffff 0%,#fcfbff 100%);border:1px solid #e6ddff;border-radius:24px;padding:22px;box-shadow:0 10px 24px rgba(38,16,92,.04);transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;}
.ucGoalCard:hover{transform:translateY(-4px);box-shadow:0 16px 34px rgba(38,16,92,.08);border-color:#d8ccff;}
.ucIcon{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#6b3df0,#8f6bff);color:#fff;font-size:28px;margin-bottom:16px;}
.ucGoalTitle{margin:0 0 12px;font-size:24px;line-height:1.2}
.ucGoalDesc{margin:0;color:#5b5478;font-size:16px;line-height:1.7}
.ucMeta{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.ucPill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:#f5f1ff;border:1px solid #e3d9ff;color:#4b2fa2;font-size:13px;font-weight:700}
.ucInfoGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px}
.ucInfoCard{background:#fbf9ff;border:1px solid #e8deff;border-radius:22px;padding:20px}
.ucInfoCard h3{margin:0 0 10px;font-size:22px}
.ucInfoCard p,.ucInfoCard li{color:#5b5478;line-height:1.7}
.ucInfoCard ul{margin:0;padding-left:18px}
</style>

<section class="card ucHero">
  <div class="ucHeroGrid">
    <div>
      <div style="font-size:72px;line-height:1">💡</div>
      <h1 class="ucHeroTitle">AI Use-Case Explorer</h1>
      <p class="ucHeroLead">
        Find the right AI tools by real goals, not just category names. Explore trusted tools for writing,
        videos, automation, websites, and visual design — with clearer descriptions, stronger intent, and a more useful discovery flow.
      </p>
    </div>

    <div class="ucHeroPanel">
      <div class="small" style="text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:#6f5ea7;">Why users love this</div>
      <h3 style="margin:10px 0 8px;font-size:28px;">Choose by task, not guesswork</h3>
      <p style="margin:0;color:#5b5478;line-height:1.7;">
        People often know what they want to do, but not which tool to use. This explorer helps visitors click faster,
        trust the platform more, and discover tools based on real work they want to get done.
      </p>

      <div class="ucMiniStatWrap">
        <div class="ucMiniStat">
          <strong><?= count($useCases) ?></strong>
          <span>Core use cases</span>
        </div>
        <div class="ucMiniStat">
          <strong><?= count(load_tools()['tools'] ?? []) ?></strong>
          <span>Suggested tools</span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="card">
  <h2 class="ucSectionTitle">What do you want to do?</h2>
  <p class="small" style="margin-top:-4px;margin-bottom:18px;">Explore polished use-case pages with richer descriptions, example tools, popular tasks, and suggested matches.</p>

  <div class="ucCards">
    <?php foreach($useCases as $slug => $case): ?>
      <a href="ai-use-case.php?slug=<?= urlencode($slug) ?>" class="ucCardLink">
        <article class="ucGoalCard">
          <div class="ucIcon"><?= h($case['icon'] ?? '✨') ?></div>
          <h3 class="ucGoalTitle"><?= h($case['label'] ?? $slug) ?></h3>
          <p class="ucGoalDesc"><?= h($case['description'] ?? 'Explore suggested tools for this use case.') ?></p>

          <div class="ucMeta">
            <?php if(!empty($case['audience'])): ?>
              <span class="ucPill">👥 <?= h($case['audience']) ?></span>
            <?php endif; ?>
            <?php if(!empty($case['example_tools']) && is_array($case['example_tools'])): ?>
              <span class="ucPill">🧰 <?= count($case['example_tools']) ?> example tools</span>
            <?php endif; ?>
          </div>
        </article>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 class="ucSectionTitle">Popular use cases on AIHubpedia</h2>

  <div class="ucInfoGrid">
    <?php foreach($useCases as $slug => $case): ?>
      <div class="ucInfoCard">
        <h3><?= h($case['label'] ?? $slug) ?></h3>
        <p style="margin:0 0 12px;"><?= h($case['hero_text'] ?? ($case['description'] ?? '')) ?></p>

        <?php if(!empty($case['popular_tasks']) && is_array($case['popular_tasks'])): ?>
          <ul>
            <?php foreach(array_slice($case['popular_tasks'], 0, 4) as $task): ?>
              <li><?= h($task) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
