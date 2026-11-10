
<?php require 'layout_top.php'; ?>
<h1>AI Problem Solver</h1>
<form method="get">
<input class="input" name="problem" placeholder="Describe your task e.g. create marketing videos">
<button class="btn">Suggest Tools</button>
</form>
<?php if(isset($_GET['problem'])): ?>
<div class="card">
<h3>Suggested Workflow</h3>
<p>Idea generation → Script writing → Image creation → Video editing</p>
<p>Recommended tools: ChatGPT, Midjourney, Runway, Descript</p>
</div>
<?php endif; ?>
<?php require 'layout_bottom.php'; ?>
