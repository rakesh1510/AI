<?php
require_once 'helpers.php';
session_start();
if(!is_logged_in_demo()){ header('Location: login.php'); exit; }
$list = load_bookmarks_demo()[(string)current_demo_user_id()] ?? [];
require __DIR__ . '/layout_top.php';
?>
<section class="hero"><h1>Saved Tools</h1><p class="small">Bookmarks for your demo account.</p></section>
<section class="grid">
<?php foreach($list as $id): $t=find_tool_by_id_safe($id); if(!$t) continue; ?>
  <div class="card">
    <h3><a href="tool.php?id=<?= urlencode($t['id']) ?>"><?= htmlspecialchars($t['name']) ?></a></h3>
    <p><?= htmlspecialchars($t['short'] ?? '') ?></p>
  </div>
<?php endforeach; ?>
</section>
<?php if(!$list): ?><section class="card">No bookmarks yet. Open a tool page and click Save / Remove Bookmark.</section><?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>