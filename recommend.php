<?php
// Recommendation wizard: six questions, a deterministic, explainable shortlist built only from reviewed, sourced profiles.
// No sign-up, no stored answers (they live in the URL you can share), no external AI service. See recommend_engine.php.
require_once __DIR__ . '/helpers.php';
$cfg = recommend_config();
$byId = tools_by_id();
$submitted = isset($_GET['objective']) || isset($_GET['budget']) || isset($_GET['go']);
$ans = recommend_normalize_answers($_GET);
$result = ($submitted && $ans['complete']) ? recommend($ans, $byId) : null;
if ($submitted) $robots = 'noindex';          // result URLs are personal combinations of answers: not for the index
$title = 'AI Tool Recommendation Wizard: Get a Shortlist in Six Questions';
$meta_desc = 'Answer six questions about your goal, budget, team and experience and get a transparent shortlist of AI tools with the reasons, pricing sources and limitations. No sign-up.';
$crumbs = [['Home', site_url('/')], ['Recommendation wizard', null]];
$jsonld = [['@type' => 'WebPage', '@id' => site_url('/recommend.php#webpage'), 'url' => site_url('/recommend.php'), 'name' => seo_title($title), 'description' => seo_description($meta_desc), 'isPartOf' => ['@id' => site_url('/#website')]], seo_breadcrumb_node($crumbs)];
if ($result && $result['status'] !== 'incomplete') event_record_server('wizard_complete', $ans['objective'], $ans['budget']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$radio = function (string $name, array $options, string $current, bool $required = false) use ($h) {
  $o = '';
  foreach ($options as $k => $def) {
    $id = $name . '-' . $k;
    $o .= '<label class="choice" for="' . $h($id) . '"><input type="radio" id="' . $h($id) . '" name="' . $h($name) . '" value="' . $h($k) . '"' . ($current === (string)$k ? ' checked' : '') . ($required ? ' required' : '') . '><span>' . $h($def['label']) . '</span></label>';
  }
  return $o;
};
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<header class="pageHero" style="padding-top:0">
  <span class="eyebrow"><?= ui_icon('sparkle') ?> Recommendation wizard</span>
  <h1>Get an AI tool shortlist in six questions</h1>
  <p class="lead">No sign-up and no chatbot. Your answers are matched against what our sourced profiles document, and every recommendation explains why. Nothing you choose here is stored on our servers.</p>
</header>

<form class="wizard panel" method="get" action="/recommend.php#results" data-wizard>
  <input type="hidden" name="go" value="1">
  <fieldset>
    <legend>1. What is your role?</legend>
    <label class="sr" for="role">Role</label>
    <select class="input" id="role" name="role">
      <?php foreach ($cfg['roles'] as $k => $def): ?><option value="<?= $h($k) ?>"<?= $ans['role'] === $k && $submitted ? ' selected' : '' ?>><?= $h($def['label']) ?></option><?php endforeach; ?>
    </select>
  </fieldset>
  <fieldset>
    <legend>2. What do you mainly want to do? <span class="req">(required)</span></legend>
    <?php if ($submitted && $ans['objective'] === ''): ?><p class="fieldError" role="alert">Choose what you want to do.</p><?php endif; ?>
    <div class="choiceGrid"><?= $radio('objective', $cfg['objectives'], $ans['objective'], true) ?></div>
  </fieldset>
  <fieldset>
    <legend>3. What is your budget? <span class="req">(required)</span></legend>
    <?php if ($submitted && $ans['budget'] === ''): ?><p class="fieldError" role="alert">Choose a budget.</p><?php endif; ?>
    <div class="choiceGrid"><?= $radio('budget', $cfg['budget'], $ans['budget'], true) ?></div>
  </fieldset>
  <fieldset>
    <legend>4. How technical are you?</legend>
    <div class="choiceGrid"><?= $radio('experience', $cfg['experience'], $submitted ? $ans['experience'] : 'intermediate') ?></div>
  </fieldset>
  <fieldset>
    <legend>5. Who will use it?</legend>
    <div class="choiceGrid"><?= $radio('team', $cfg['team'], $submitted ? $ans['team'] : 'solo') ?></div>
  </fieldset>
  <fieldset>
    <legend>6. Which integrations matter? <span class="opt">(optional)</span></legend>
    <div class="choiceGrid">
      <?php foreach ($cfg['integrations'] as $k => $def): ?>
        <label class="choice" for="int-<?= $h($k) ?>"><input type="checkbox" id="int-<?= $h($k) ?>" name="integrations[]" value="<?= $h($k) ?>"<?= in_array($k, $ans['integrations'], true) ? ' checked' : '' ?>><span><?= $h($def['label']) ?></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <p><button class="btn" type="submit">Show my shortlist</button> <a class="btn secondary" href="/recommend.php">Start over</a></p>
</form>

<?php if ($submitted): ?>
<section id="results" class="wizardResults" aria-labelledby="resTitle" tabindex="-1">
  <?php if (!$result): ?>
    <h2 id="resTitle">Almost there</h2>
    <div class="noticeBox" role="alert"><?php foreach ($ans['errors'] as $e): ?><p style="margin:0"><?= $h($e) ?></p><?php endforeach; ?></div>
  <?php else: ?>
    <h2 id="resTitle">Your shortlist: <?= $h($cfg['objectives'][$ans['objective']]['label']) ?></h2>
    <p class="small">Based only on tools with reviewed, sourced profiles (<?= count(array_filter($byId, fn($t) => !empty($t['curated']))) ?> today). This is a starting point, not a test of output quality. <a href="/methodology.php#search">How we match</a>.</p>
    <?php foreach ($result['notes'] as $n): ?><p class="noticeBox"><?= $h($n) ?></p><?php endforeach; ?>

    <?php if ($result['status'] === 'none'): ?>
      <div class="panel"><h3 style="margin-top:0">No reviewed tool documents this goal with your settings</h3>
        <p>We would rather say so than guess. Try a different budget, or look at the <a href="/solutions.php">problem pages</a> and <a href="/search.php">search</a>, which also list tools we have not reviewed (clearly labelled).</p></div>
    <?php else: ?>
      <?php if ($result['status'] === 'few'): ?><p class="noticeBox">Only <?= count($result['items']) ?> reviewed tool<?= count($result['items']) === 1 ? '' : 's' ?> document this goal so far, so the list is short. More reviews will widen it.</p><?php endif; ?>
      <ol class="recList">
        <?php foreach ($result['items'] as $i => $it): $t = $it['tool']; ?>
          <li class="recItem">
            <article class="panel" aria-labelledby="rec-<?= $h($t['id']) ?>">
              <header class="recHead">
                <img class="tCard__logo" src="<?= $h(tool_logo($t)) ?>" alt="" width="44" height="44" loading="lazy" decoding="async">
                <div><h3 id="rec-<?= $h($t['id']) ?>" style="margin:0"><span class="recRank"><?= $i + 1 ?>.</span> <a href="<?= $h(tool_path($t['id'])) ?>"><?= $h($t['name']) ?></a></h3>
                  <p class="small" style="margin:2px 0 0"><?= $h($t['short'] ?? '') ?></p></div>
              </header>
              <p><?= render_evidence_badge($t) ?> <span class="small">Match score: <?= $h(rtrim(rtrim(number_format($it['score'], 1), '0'), '.')) ?></span></p>
              <h4>Why it matches</h4>
              <ul class="recWhy"><?php foreach ($it['reasons'] as $r): ?><li><?= $h($r) ?></li><?php endforeach; ?></ul>
              <?php if ($it['unknown']): ?>
                <details><summary>Not documented in our sources (<?= count($it['unknown']) ?>)</summary>
                  <p class="small">These are not statements that the tool lacks them: we have no sourced information, so check the vendor.</p>
                  <ul class="small"><?php foreach ($it['unknown'] as $u): ?><li><?= $h($u) ?></li><?php endforeach; ?></ul></details>
              <?php endif; ?>
              <h4>Pricing, as stated in sources</h4>
              <p><?= $h($it['pricing']['summary']) ?> <span class="small">(as of <?= $h($it['pricing']['as_of']) ?><?= $it['pricing']['stale'] ? ', older than ' . (int)EVIDENCE_STALE_DAYS . ' days: check the vendor page' : '' ?>; <?= $h($it['pricing']['note']) ?>)</span></p>
              <?php if ($it['limitations']): ?><h4>Limitations to know about</h4><ul><?php foreach ($it['limitations'] as $l): ?><li><?= $h($l) ?></li><?php endforeach; ?></ul><?php endif; ?>
              <?php if ($it['alternatives']): ?><p class="small"><strong>Alternatives:</strong> <?php foreach ($it['alternatives'] as $k => $alt): ?><?= $k ? ', ' : '' ?><a href="<?= $h(tool_path($alt['tool']['id'])) ?>"><?= $h($alt['tool']['name']) ?></a> (<?= $h($alt['reasons'][0] ?? '') ?>)<?php endforeach; ?></p><?php endif; ?>
              <div class="actions">
                <a class="btn sm" href="<?= $h(tool_path($t['id'])) ?>">View profile</a>
                <?php if (tool_url($t) !== '#'): ?><a class="btn sm secondary" target="_blank" rel="<?= $h(outbound_rel($t)) ?>" href="/redirect.php?id=<?= $h(rawurlencode($t['id'])) ?>" data-outbound data-affiliate="<?= is_affiliate_link($t) ? '1' : '0' ?>" data-placement="wizard">Visit site<?= ui_icon('external') ?><span class="sr"> (new tab)</span></a><?php endif; ?>
                <?php if (isset($result['items'][$i + 1])): ?><a class="btn sm ghost" href="<?= $h(compare_path($t['id'], $result['items'][$i + 1]['tool']['id'])) ?>">Compare with <?= $h($result['items'][$i + 1]['tool']['name']) ?></a><?php endif; ?>
              </div>
            </article>
          </li>
        <?php endforeach; ?>
      </ol>

      <?php if ($result['combos']): ?>
        <section class="panel" aria-labelledby="comboTitle">
          <h3 id="comboTitle" style="margin-top:0">Tool combinations</h3>
          <p class="small">Only shown when each tool documents something the other does not, for the needs you stated.</p>
          <ul><?php foreach ($result['combos'] as $c): ?><li><strong><?= $h($c['a']['name']) ?></strong> for <?= $h(implode(', ', $c['a_for'])) ?> + <strong><?= $h($c['b']['name']) ?></strong> for <?= $h(implode(', ', $c['b_for'])) ?></li><?php endforeach; ?></ul>
        </section>
      <?php endif; ?>

      <section class="panel" aria-labelledby="wfTitle" style="margin-top:16px">
        <h3 id="wfTitle" style="margin-top:0">A practical way to get started</h3>
        <ol><?php foreach ($result['workflow'] as $step): ?><li><?= $h($step) ?></li><?php endforeach; ?></ol>
      </section>
    <?php endif; ?>

    <details class="panel" style="margin-top:16px"><summary><strong>How your answers were used</strong></summary>
      <ul><?php foreach ($result['explain'] as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
      <p class="small">Points: +3/+2/+1 per documented capability in your goal, +2 for a fitting subcategory, small role/experience/team adjustments, +2 per documented integration you asked for, and +1 or +2 for stronger evidence. Unknown capabilities add nothing and subtract nothing.</p>
    </details>
    <p class="small" style="margin-top:12px">Want something custom instead? <a href="/business.php?need=ai_agent&amp;ref=wizard&amp;utm_campaign=<?= $h(rawurlencode($ans['objective'])) ?>">Ask TrustNova Tech about a custom AI agent</a> or <a href="/solutions.php">browse problem pages</a>.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
