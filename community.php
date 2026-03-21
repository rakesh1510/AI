<?php
require_once 'helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
require __DIR__ . '/layout_top.php';
?>
<section class="hero">
  <h1>Community Interaction</h1>
  <p class="small">Comments, discussions, and voting help create engagement around tools.</p>
</section>

<section class="grid">
<?php foreach(array_slice($tools,0,24) as $t): ?>
  <div class="card">
    <h3><a href="tool.php?id=<?= urlencode($t['id']) ?>"><?= htmlspecialchars($t['name']) ?></a></h3>
    <p><?= htmlspecialchars($t['short'] ?? '') ?></p>
    <div class="small">Votes: <?= tool_vote_total($t['id']) ?> • Comments: <?= count(tool_comments($t['id'])) ?></div>
    <div class="actions" style="margin-top:10px">
      <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>#community">Open discussion</a>
    </div>
  </div>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>