<?php require __DIR__ . '/layout_top.php'; ?>
<section class="hero"><h1>Dark Mode Demo</h1><p class="small">Preview a dark theme toggle.</p></section>
<section class="card"><button class="btn" onclick="document.body.classList.toggle('dark-demo')">Toggle Dark Demo</button></section>
<style>
body.dark-demo{filter:invert(.93) hue-rotate(180deg)}
body.dark-demo img{filter:invert(1) hue-rotate(180deg)}
</style>
<?php require __DIR__ . '/layout_bottom.php'; ?>