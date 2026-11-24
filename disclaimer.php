<?php
require_once __DIR__ . '/helpers.php';
$title = "Disclaimer — AIHubpedia";
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo page_hero(['crumbs' => [['Home', site_url('/')], ['Disclaimer', null]], 'eyebrow' => 'Legal', 'icon' => 'shield', 'title_html' => 'Disclaimer', 'lead' => 'What you can and cannot rely on.', 'art' => 'doc', 'size' => 'sm']);
ob_start();
?>



<p>
AIHubpedia is an independent directory of artificial intelligence tools.
</p>

<p>
AIHubpedia lists third-party AI tools for informational purposes.
All trademarks and logos belong to their respective owners.
AIHubpedia is not affiliated with or endorsed by the listed companies.
Pricing, features, and availability may change at any time.
Please verify details directly on the official website of each tool.
</p>

<p>

AIHubpedia is not responsible for third-party services or content.
</p>

<?php $__doc = ob_get_clean(); echo '<div class="wrap pgBody">' . doc_page($__doc) . '</div>'; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>