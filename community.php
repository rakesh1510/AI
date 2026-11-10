<?php
require_once 'helpers.php';

$title = "AI Community Discussions — AIHubpedia";
$meta_desc = "Join AIHubpedia community discussions, read user feedback, vote on AI tools, and share your experience.";

$data = load_tools();
$tools = $data['tools'] ?? [];

// Sort by activity: votes + comments
usort($tools, function($a, $b){
  $aid = $a['id'] ?? '';
  $bid = $b['id'] ?? '';

  $ascore = tool_vote_total($aid) + count(tool_comments($aid));
  $bscore = tool_vote_total($bid) + count(tool_comments($bid));

  if($ascore === $bscore) return 0;
  return ($ascore > $bscore) ? -1 : 1;
});

require __DIR__ . '/layout_top.php';
?>

<section class="hero">
  <h1>💬 AI Community Discussions</h1>

  <p class="small">
    Discover what people are saying about AI tools, share your experience,
    and find better tools through real votes, comments, and feedback.
  </p>

  <div class="trust" style="margin-top:18px;display:flex;gap:14px;flex-wrap:wrap">
    <span class="badge">🔥 Trending discussions</span>
    <span class="badge">👨‍💻 Real user feedback</span>
    <span class="badge">📊 Votes & comments</span>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🔥 Trending Discussions</h2>
  <p class="small">Most active tool conversations on AIHubpedia.</p>

  <div class="grid">
    <?php foreach(array_slice($tools, 0, 6) as $t): ?>
      <?php
        $votes = tool_vote_total($t['id']);
        $comments = count(tool_comments($t['id']));
        $activity = $votes + $comments;
      ?>

      <div class="card" style="background:rgba(255,255,255,.03)">
        <h3 style="margin-top:0">
          <a href="tool.php?id=<?= urlencode($t['id']) ?>#community">
            <?= h($t['name']) ?>
          </a>
        </h3>

        <p><?= h($t['short'] ?? '') ?></p>

        <div class="small">
          👍 <?= (int)$votes ?> votes • 💬 <?= (int)$comments ?> comments
        </div>

        <?php if($activity > 0): ?>
          <div style="margin-top:10px">
            <span class="badge">🔥 Active</span>
          </div>
        <?php endif; ?>

        <div class="actions" style="margin-top:12px">
          <a class="btn" href="tool.php?id=<?= urlencode($t['id']) ?>#community">
            💬 Join discussion
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🧠 All Tool Discussions</h2>
  <p class="small">Browse AI tools and join the conversation.</p>

  <div class="grid">
    <?php foreach(array_slice($tools, 0, 24) as $t): ?>
      <?php
        $votes = tool_vote_total($t['id']);
        $comments = count(tool_comments($t['id']));
      ?>

      <div class="card">
        <h3 style="margin-top:0">
          <a href="tool.php?id=<?= urlencode($t['id']) ?>#community">
            <?= h($t['name']) ?>
          </a>
        </h3>

        <p><?= h($t['short'] ?? '') ?></p>

        <div class="small">
          👍 <?= (int)$votes ?> votes • 💬 <?= (int)$comments ?> comments
        </div>

        <div class="actions" style="margin-top:12px">
          <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>#community">
            💬 Join discussion
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card" style="text-align:center;background:linear-gradient(135deg, rgba(124,58,237,.16), rgba(59,130,246,.10));">
  <h2 style="margin-top:0">🚀 Share your AI tool experience</h2>
  <p class="small">
    Help other users decide which AI tools are worth trying.
  </p>

  <div class="actions" style="justify-content:center">
    <a class="btn" href="/top.php">Explore top tools</a>
    <a class="btn secondary" href="/submit.php">Submit your tool</a>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>