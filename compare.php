<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$a = seo_param($_GET, 'a') ?: seo_param($_GET, 'id');
$b = seo_param($_GET, 'b');
$ta = $a !== '' ? find_tool($a) : null;
$tb = $b !== '' ? find_tool($b) : null;
if (($a !== '' && !$ta) || ($b !== '' && !$tb)) http_response_code(404);
$title = $ta && $tb ? $ta['name'] . ' vs ' . $tb['name'] . ' — AIHubpedia' : 'Compare AI Tools — AIHubpedia';
$meta_desc = 'Compare recorded features, use cases, pricing information, strengths and limitations. Missing information is clearly marked.';
$canonical = seo_canonical(seo_url('/compare.php', array_filter(['a' => $a, 'b' => $b])));
// Interactive combinations are useful to users but not reviewed editorial landing pages.
$robots_meta = ($a !== '' || $b !== '') ? 'noindex,follow' : 'index,follow';
$breadcrumbs = [['name' => 'Home', 'url' => seo_url('/')], ['name' => 'Compare tools', 'url' => $canonical]];
$page_schemas = [seo_breadcrumb_schema($breadcrumbs)];
require __DIR__ . '/layout_top.php';
seo_breadcrumbs($breadcrumbs);
?>
<h1><?= h($title) ?></h1>
<p><?= h($meta_desc) ?> This comparison uses directory records, not independent performance testing. Check provider sources before deciding.</p>
<form method="get" action="/compare.php" class="card">
  <?php foreach (['a' => $a, 'b' => $b] as $key => $selected): ?>
    <label for="tool-<?= $key ?>">Choose <?= $key === 'a' ? 'first' : 'second' ?> tool</label>
    <select id="tool-<?= $key ?>" name="<?= $key ?>" required>
      <option value="">Select a tool</option>
      <?php foreach ($tools as $tool): ?>
        <option value="<?= h($tool['id']) ?>" <?= $selected === $tool['id'] ? 'selected' : '' ?>><?= h($tool['name']) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endforeach; ?>
  <button class="btn" type="submit">Compare tools</button>
</form>
<?php if ((int)http_response_code() === 404): ?>
<p role="alert">One of the selected tools was not found. Choose an available tool above.</p>
<?php elseif ($ta && $tb): ?>
<div style="overflow-x:auto">
<table>
  <caption>Recorded information for <?= h($ta['name']) ?> and <?= h($tb['name']) ?></caption>
  <thead><tr><th scope="col">Criteria</th><?php foreach ([$ta, $tb] as $tool): ?><th scope="col"><a href="<?= h(seo_url('/tool.php', ['id' => $tool['id']])) ?>"><?= h($tool['name']) ?></a></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach (['short' => 'Summary', 'category' => 'Category', 'pricing' => 'Listed pricing', 'features' => 'Features', 'use_cases' => 'Use cases', 'pros' => 'Recorded strengths', 'cons' => 'Recorded limitations'] as $field => $label): ?>
    <tr><th scope="row"><?= h($label) ?></th>
    <?php foreach ([$ta, $tb] as $tool): ?>
      <td><?php $value = $tool[$field] ?? null;
      if (is_array($value) && $value) {
        echo '<ul>'; foreach ($value as $item) echo '<li>' . h($item) . '</li>'; echo '</ul>';
      } else echo h(is_scalar($value) ? seo_text($value, 'Not recorded') : 'Not recorded'); ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
    <tr><th scope="row">Provider source</th><?php foreach ([$ta, $tb] as $tool): ?><td>
      <?php $source = seo_http_url($tool['website'] ?? ''); if ($source): ?><a href="<?= h($source) ?>" target="_blank" rel="noopener">Provider website</a><?php else: ?>Not recorded<?php endif; ?>
    </td><?php endforeach; ?></tr>
    <tr><th scope="row">Pricing verified on</th><td>Not recorded</td><td>Not recorded</td></tr>
  </tbody>
</table>
</div>
<p>No winner is assigned: the best choice depends on your requirements and verified testing.</p>
<?php endif; ?>
<p><a href="/editorial-policy.php">Editorial policy</a> · <a href="/categories.php">Explore categories</a></p>
<?php require __DIR__ . '/layout_bottom.php'; ?>
