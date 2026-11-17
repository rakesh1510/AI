<?php
require_once __DIR__ . '/helpers.php';
$title = 'How AIHubpedia Reviews AI Tools: Methodology & Editorial Policy';
$meta_desc = 'How AIHubpedia classifies AI tools, collects facts from vendor sources, labels review status, handles pricing changes and corrections, and discloses sponsored placements.';
$crumbs = [['Home', site_url('/')], ['Methodology', null]];
$tools = load_tools()['tools'] ?? [];
$counts = ['reviewed' => 0, 'standard' => 0, 'basic' => 0];
foreach ($tools as $t) $counts[tool_quality_tier($t)]++;
$jsonld = [['@type' => 'AboutPage', '@id' => site_url('/methodology.php#webpage'), 'url' => site_url('/methodology.php'), 'name' => seo_title($title), 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')], 'publisher' => ['@id' => site_url('/#organization')]], seo_breadcrumb_node($crumbs)];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<article class="prose">
  <h1>How AIHubpedia reviews AI tools</h1>
  <p>AIHubpedia is an independent directory and comparison site for AI tools. This page explains where our information comes from, how listings are labelled, and what we do not claim.</p>

  <h2>Three levels of listing</h2>
  <ul>
    <li><strong>Reviewed profile (<?= (int)$counts['reviewed'] ?> tools):</strong> facts were collected from the vendor's own pages, each source is linked with its retrieval date, and the pricing note says when it was checked.</li>
    <li><strong>Standard listing (<?= (int)$counts['standard'] ?> tools):</strong> descriptive text that is specific to the tool but has not been through the source-collection process.</li>
    <li><strong>Basic listing (<?= (int)$counts['basic'] ?> tools):</strong> directory data with generic notes. These pages say so, and we are replacing them with reviewed profiles, starting with the most visited tools.</li>
  </ul>

  <h2>Review status</h2>
  <p>Each reviewed profile shows one of two statuses. <em>Evidence reviewed … pending editor verification</em> means the facts were collected from the listed vendor pages but a person has not yet re-checked them on the live page. <em>Verified by an AIHubpedia editor</em> means a person has confirmed them on the live pages on the date shown. We do not mark a profile verified automatically.</p>

  <h2>Sources and conflicts</h2>
  <p>We prefer the vendor's own pricing, help and documentation pages. Where those pages disagree with each other or look out of date, the profile lists the conflict instead of choosing a number. Vendor blog posts are used only when no pricing page was available and are labelled as such.</p>

  <h2>Prices change</h2>
  <p>Plan names, limits and prices change frequently. Every price on AIHubpedia carries an "as of" date; check the vendor's pricing page before you buy.</p>

  <h2>What we do not do</h2>
  <ul>
    <li>We do not publish output-quality rankings or benchmark scores unless we ran the test ourselves and say how.</li>
    <li>We do not invent ratings. Star ratings and votes come from site visitors and are shown with their counts.</li>
    <li>We do not accept payment to change a tool's facts, limitations or review status.</li>
  </ul>

  <h2 id="search">How search and recommendations rank tools</h2>
  <p>Search is rule-based and deterministic; it is not an AI chatbot and uses no external AI service. A tool scores points when your words match its name (most), subcategory, category, tags, one-line description, use cases and description (least). Tools with a reviewed, sourced profile get a small boost, and a capped boost reflects clicks on this site. A short, editorial list of related words (for example "transcribe" and "transcription") lets a search find tools that use a different word; these matches count for less and are always labelled "Related term". Each result lists the reasons it matched.</p>
  <p><strong>Paid placements never change search ranking or recommendations.</strong> Sponsored items appear only in labelled sponsored slots, separate from editorial results.</p>
  <h2 id="evidence">Evidence levels and unknown values</h2>
  <p>Profiles show how well their facts are backed. <em>Verified by an editor</em> means a person checked the vendor's live pages and recorded the date. <em>Sourced, awaiting editor verification</em> means facts come from the vendor sources listed on the page but a person has not yet confirmed them. <em>Limited evidence</em> means few sources, conflicting information or dated pages. <em>Not reviewed</em> is a catalogue listing only. Verified profiles become "due for a re-check" after 90 days.</p>
  <p>In comparisons, a feature is <strong>Documented</strong> only when our sourced profile mentions it. <strong>Unknown</strong> means we have no sourced statement; it does not mean the tool lacks the feature. We never fill gaps with guesses.</p>

  <h2>How categories are assigned</h2>
  <p>Tools are placed in a category and subcategory by a published, rule-based classifier that reads the tool's name and one-line description. High-confidence matches are applied directly, medium-confidence matches are applied and flagged for review, and tools we cannot classify confidently stay in "Other" until an editor reviews them. On a hand-labelled test sample the high-confidence results were correct every time, and the classifier declines to guess rather than force a match. Reviewed profiles are classified by an editor.</p>

  <h2>Sponsored placements and links</h2>
  <p>Featured listings and the homepage spotlight are paid placements and are labelled "Sponsored" or "Homepage Spotlight". Some outbound links may be affiliate links; they never affect a profile's facts or limitations. Outbound links are marked <code>rel="nofollow"</code>.</p>

  <h2>Corrections</h2>
  <p>If a fact is wrong or out of date, email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a> with a link to the vendor page that supports the change. We review corrections and update the review date.</p>

  <h2>Rankings</h2>
  <p>The <a href="/top.php">Top tools</a> and <a href="/leaderboard.php">Leaderboard</a> pages rank tools by activity on AIHubpedia (clicks, ratings, likes and dislikes). They measure interest on this site, not product quality or market share.</p>
</article>
<?php require __DIR__ . '/layout_bottom.php'; ?>
