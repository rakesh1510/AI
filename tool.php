<?php
require_once __DIR__ . '/helpers.php';

/* ─────────────────────────────────────────────
   DYNAMIC CONTENT GENERATORS
   All functions accept a $tool array and derive
   content from: name, category, short, description,
   pricing, website. No hard-coding, no DB columns.
   ───────────────────────────────────────────── */

/**
 * Return a lowercase "signal" string built from the tool's
 * name + category + description for keyword matching.
 */
function _tool_signal(array $t): string {
  return strtolower(
    ($t['name'] ?? '') . ' ' .
    ($t['category'] ?? '') . ' ' .
    ($t['short'] ?? '') . ' ' .
    ($t['description'] ?? '')
  );
}

/**
 * Detect the primary content type from the signal string.
 * Returns one of: website, chat, writing, video, image,
 *                 automation, code, audio, data, seo, general
 */
function _tool_type(array $t): string {
  $s = _tool_signal($t);
  $map = [
    'website'    => ['website builder','landing page','web builder','site builder','webpage'],
    'chat'       => ['chatbot','chat bot','chat ai','conversational','customer support','live chat','messaging'],
    'writing'    => ['writing','copywriting','content writing','blog','article','text generation','copywriter','essay','email writer'],
    'video'      => ['video','videomaker','video editor','video creation','text to video','ai video'],
    'image'      => ['image','photo','picture','art generation','image generation','design','illustration','stable diffusion','dall-e','midjourney','graphic'],
    'automation' => ['automation','workflow','no-code','nocode','zapier','integration','trigger','pipeline'],
    'code'       => ['code','coding','developer','programming','github','ide','debugging','software development'],
    'audio'      => ['audio','voice','speech','text to speech','tts','podcast','music','sound'],
    'data'       => ['data','analytics','dashboard','reporting','spreadsheet','database','csv','bi tool','business intelligence'],
    'seo'        => ['seo','search engine','keyword','rank','serp','backlink','on-page'],
  ];
  foreach ($map as $type => $keywords) {
    foreach ($keywords as $kw) {
      if (str_contains($s, $kw)) return $type;
    }
  }
  return 'general';
}

function generate_features(array $t): array {
  $name = $t['name'] ?? 'This tool';
  $type = _tool_type($t);

  $sets = [
    'website' => [
      "Drag-and-drop visual website builder",
      "Mobile-responsive templates included",
      "Custom domain and hosting support",
      "Built-in SEO settings per page",
      "One-click publishing to the web",
      "Analytics and visitor tracking dashboard",
    ],
    'chat' => [
      "AI-powered conversational responses",
      "Multi-channel deployment (web, mobile, API)",
      "Custom personality and tone configuration",
      "Conversation history and context memory",
      "Live handoff to human agents",
      "Analytics on chat performance and resolution rate",
    ],
    'writing' => [
      "AI-assisted long-form and short-form content generation",
      "Multiple writing tones and style presets",
      "Grammar and readability checker",
      "Plagiarism-safe output generation",
      "SEO keyword integration suggestions",
      "Templates for blogs, emails, ads, and more",
    ],
    'video' => [
      "Text-to-video generation from scripts or prompts",
      "Pre-built scene and transition templates",
      "AI voiceover and subtitle generation",
      "Multi-format export (MP4, GIF, social sizes)",
      "Brand kit with logo and color integration",
      "Collaboration and team sharing features",
    ],
    'image' => [
      "AI image generation from text prompts",
      "Style and artistic filter presets",
      "Background removal and replacement",
      "Batch image generation support",
      "High-resolution export options",
      "Edit and variation generation from existing images",
    ],
    'automation' => [
      "Visual workflow builder with drag-and-drop logic",
      "Triggers based on time, events, or conditions",
      "Integrations with 100+ third-party apps",
      "Multi-step automation sequences",
      "Error handling and retry logic",
      "Real-time logs and workflow monitoring",
    ],
    'code' => [
      "AI-powered code completion and suggestions",
      "Multi-language support (Python, JS, Go, and more)",
      "Automated code review and bug detection",
      "Natural language to code generation",
      "Integrated testing and debugging tools",
      "Git integration and version history",
    ],
    'audio' => [
      "High-quality AI voice synthesis",
      "Multiple voice styles and language options",
      "Background noise removal",
      "Audio editing and trimming tools",
      "Export to MP3, WAV, and other formats",
      "Batch text-to-speech processing",
    ],
    'data' => [
      "Automated data ingestion from multiple sources",
      "Interactive charts and visual dashboards",
      "AI-powered trend detection and anomaly alerts",
      "Scheduled report generation and export",
      "Role-based data access controls",
      "CSV, Excel, and API data import/export",
    ],
    'seo' => [
      "Keyword research and difficulty analysis",
      "Competitor ranking and backlink insights",
      "On-page SEO audit and recommendations",
      "SERP position tracking over time",
      "Content gap analysis",
      "Automated technical SEO reporting",
    ],
    'general' => [
      "AI-powered task automation and suggestions",
      "Intuitive dashboard and user interface",
      "API access for custom integrations",
      "Team collaboration and sharing tools",
      "Role-based access and permissions",
      "Export results in multiple formats",
    ],
  ];

  return $sets[$type] ?? $sets['general'];
}

function generate_pros(array $t): array {
  $name    = $t['name'] ?? 'This tool';
  $pricing = strtolower($t['pricing'] ?? '');
  $type    = _tool_type($t);

  $base = [
    'website'    => ["Easy to get started without technical skills", "Produces professional-looking results quickly", "All-in-one platform reduces need for extra tools"],
    'chat'       => ["Handles high message volumes without extra staff", "Available 24/7 with instant response times", "Reduces customer support workload significantly"],
    'writing'    => ["Dramatically speeds up content production", "Consistent quality across large volumes of text", "Supports multiple content formats in one place"],
    'video'      => ["Creates engaging video content without video editing experience", "Saves hours compared to manual video production", "Supports various social media and presentation formats"],
    'image'      => ["Generates unique visuals in seconds from text alone", "Eliminates need for stock photo subscriptions", "Wide range of styles for any brand or project"],
    'automation' => ["Eliminates repetitive manual tasks permanently", "Reduces human error in routine workflows", "Scales easily as team or business grows"],
    'code'       => ["Speeds up development with intelligent suggestions", "Helps less experienced developers write better code", "Catches bugs early before they reach production"],
    'audio'      => ["Natural-sounding voices suitable for professional use", "Fast turnaround compared to recording real voice actors", "Easy to update content without re-recording"],
    'data'       => ["Turns raw data into clear, shareable insights quickly", "Reduces dependency on manual spreadsheet work", "Scheduled reports keep teams automatically informed"],
    'seo'        => ["Actionable recommendations that improve search rankings", "Saves hours of manual keyword and competitor research", "Helps non-experts make data-driven SEO decisions"],
    'general'    => ["Saves significant time on everyday tasks", "User-friendly interface with minimal learning curve", "Integrates with popular tools already in use"],
  ];

  $pros = $base[$type] ?? $base['general'];

  // Add a pricing-based pro if relevant
  if (str_contains($pricing, 'free')) {
    $pros[] = "Free tier available — easy to evaluate before committing";
  } elseif (str_contains($pricing, 'freemium')) {
    $pros[] = "Freemium model lets you start at zero cost";
  } elseif (str_contains($pricing, 'open')) {
    $pros[] = "Open-source or open-access model gives full transparency";
  } else {
    $pros[] = "Transparent pricing with clear feature tiers";
  }

  return array_slice($pros, 0, 4);
}

function generate_cons(array $t): array {
  $type    = _tool_type($t);
  $pricing = strtolower($t['pricing'] ?? '');

  $base = [
    'website'    => ["Limited design flexibility on lower-tier plans", "Advanced customisation requires learning curve", "Costs can increase quickly as traffic grows"],
    'chat'       => ["AI responses can occasionally misunderstand complex queries", "Requires setup and tuning for best performance", "May not handle highly technical niche topics well"],
    'writing'    => ["Output still requires human editing and fact-checking", "Can feel repetitive without careful prompt variation", "Quality depends heavily on the prompt you provide"],
    'video'      => ["Rendering times can be slow for long videos", "Fine-grained editing control is often limited", "High-quality exports may require a paid plan"],
    'image'      => ["Results can be inconsistent without precise prompting", "Complex compositions may require multiple attempts", "May not fully replicate a specific brand aesthetic"],
    'automation' => ["Complex workflows have a steeper setup learning curve", "Debugging failed automations can take time", "Some integrations require paid plans or extra add-ons"],
    'code'       => ["AI suggestions should always be reviewed before use", "May struggle with very large or highly complex codebases", "Requires internet connection for AI-assisted features"],
    'audio'      => ["Synthesised voices can sound slightly unnatural on long content", "Limited emotional range compared to a real voice actor", "Voice cloning features raise ethical considerations"],
    'data'       => ["Initial data connection and setup can be time-consuming", "Advanced analytics features locked behind higher tiers", "Non-technical users may need training to get full value"],
    'seo'        => ["SEO results take time to materialise regardless of tool used", "Data accuracy depends on index freshness", "Overwhelming amount of data for beginners to interpret"],
    'general'    => ["May require a learning period to unlock full potential", "Some advanced features are behind higher pricing tiers", "Occasional AI errors require human review of outputs"],
  ];

  $cons = $base[$type] ?? $base['general'];

  if (str_contains($pricing, 'paid') || str_contains($pricing, 'subscription')) {
    $cons[] = "Subscription cost adds up over time for small teams or individuals";
  } elseif (!str_contains($pricing, 'free')) {
    $cons[] = "Pricing details require contacting sales for exact figures";
  }

  return array_slice($cons, 0, 4);
}

function generate_use_cases(array $t): array {
  $name = $t['name'] ?? 'This tool';
  $type = _tool_type($t);

  $sets = [
    'website' => [
      "Building a personal portfolio or resume site",
      "Launching a small business or local service page",
      "Creating landing pages for marketing campaigns",
      "Setting up an online store or product showcase",
      "Publishing a blog or knowledge base",
    ],
    'chat' => [
      "Automating first-line customer support on a website",
      "Answering FAQ queries without human involvement",
      "Collecting leads and qualifying prospects automatically",
      "Providing onboarding guidance to new users",
      "Internal team helpdesk or HR assistant",
    ],
    'writing' => [
      "Writing blog posts and SEO articles at scale",
      "Drafting marketing emails and newsletters",
      "Creating social media captions and ad copy",
      "Producing product descriptions for e-commerce",
      "Generating first drafts for reports or proposals",
    ],
    'video' => [
      "Creating explainer videos for products or services",
      "Producing social media video content at scale",
      "Generating training and onboarding videos",
      "Turning blog posts into short video summaries",
      "Building promotional videos for ad campaigns",
    ],
    'image' => [
      "Generating custom illustrations for blog posts",
      "Creating social media graphics without a designer",
      "Producing concept art and mood boards",
      "Designing product mockups and visuals",
      "Generating ad creatives for marketing campaigns",
    ],
    'automation' => [
      "Automating data entry and form processing tasks",
      "Syncing data between CRM, email, and other apps",
      "Sending automated alerts and notifications",
      "Scheduling and publishing social media posts",
      "Streamlining approval and sign-off workflows",
    ],
    'code' => [
      "Accelerating feature development with AI suggestions",
      "Reviewing pull requests for common issues",
      "Generating boilerplate code for new projects",
      "Helping junior developers learn best practices",
      "Documenting existing codebases automatically",
    ],
    'audio' => [
      "Creating voiceovers for videos and presentations",
      "Producing podcast intros and narrations",
      "Generating multilingual audio content",
      "Building voice interfaces for apps",
      "Creating audio descriptions for accessibility",
    ],
    'data' => [
      "Building executive dashboards for KPI tracking",
      "Monitoring sales and revenue trends in real time",
      "Generating automated weekly or monthly reports",
      "Analysing customer behaviour and segmentation",
      "Identifying anomalies and outliers in business data",
    ],
    'seo' => [
      "Finding high-traffic, low-competition keywords",
      "Auditing a website for technical SEO issues",
      "Tracking keyword ranking changes over time",
      "Researching competitor backlink strategies",
      "Planning content calendars based on search demand",
    ],
    'general' => [
      "Streamlining repetitive daily business workflows",
      "Assisting teams with research and summarisation",
      "Generating first drafts of documents and reports",
      "Supporting onboarding and knowledge management",
      "Enabling non-technical users to leverage AI tools",
    ],
  ];

  return $sets[$type] ?? $sets['general'];
}

function generate_tags(array $t): array {
  $type     = _tool_type($t);
  $category = strtolower(trim($t['category'] ?? ''));
  $pricing  = strtolower($t['pricing'] ?? '');
  $name     = $t['name'] ?? '';

  // Core type tags
  $type_tags = [
    'website'    => ['Website Builder','No-Code','Landing Page','Web Design','Online Presence'],
    'chat'       => ['Chatbot','Conversational AI','Customer Support','Live Chat','AI Assistant'],
    'writing'    => ['AI Writing','Copywriting','Content Generation','Blogging','Text AI'],
    'video'      => ['AI Video','Video Creation','Text to Video','Video Editor','Visual Content'],
    'image'      => ['AI Image','Image Generation','Generative Art','Design','Visual AI'],
    'automation' => ['Automation','Workflow','No-Code','Productivity','Integration'],
    'code'       => ['AI Coding','Developer Tools','Code Generation','Programming','DevOps'],
    'audio'      => ['AI Audio','Text to Speech','Voice Synthesis','Podcast','Speech AI'],
    'data'       => ['Data Analytics','Dashboard','Business Intelligence','Reporting','Data Viz'],
    'seo'        => ['SEO','Keyword Research','Search Optimization','Rank Tracking','Content SEO'],
    'general'    => ['AI Tool','Productivity','Automation','AI Assistant','Business AI'],
  ];

  $tags = $type_tags[$type] ?? $type_tags['general'];

  // Add category as a tag if distinct
  if ($category && !in_array(ucwords($category), $tags)) {
    array_unshift($tags, ucwords($category));
  }

  // Add pricing tag
  if (str_contains($pricing, 'free')) {
    $tags[] = 'Free';
  } elseif (str_contains($pricing, 'freemium')) {
    $tags[] = 'Freemium';
  } elseif (str_contains($pricing, 'open')) {
    $tags[] = 'Open Source';
  }

  // Deduplicate and return at least 5
  $tags = array_values(array_unique($tags));
  return array_slice($tags, 0, max(6, count($tags)));
}

/* ─────────────────────────────────────────────
   CTA URL HELPER
   Priority: affiliate → signup_url → website
   ───────────────────────────────────────────── */
function tool_cta_url(array $t): string {
  if (!empty($t['affiliate']))  return $t['affiliate'];
  if (!empty($t['signup_url'])) return $t['signup_url'];
  return $t['website'] ?? '#';
}

/* ─────────────────────────────────────────────
   PAGE BOOTSTRAP
   ───────────────────────────────────────────── */
$id = trim($_GET['id'] ?? '');
$t  = $id ? find_tool($id) : null;

if (!$t) {
  http_response_code(404);
  $title = "Tool not found — AIHubpedia";
  require __DIR__ . '/layout_top.php';
  echo "<h1>Tool not found</h1><p class='small'>The requested tool does not exist.</p>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$title     = ($t['seo_title']       ?? ($t['name'] . " — AIHubpedia"));
$meta_desc = ($t['seo_description'] ?? ($t['short'] ?? ''));
require __DIR__ . '/layout_top.php';

[$avg, $cnt] = rating_avg($t['id']);
$clicks = click_count($t['id']);

// Alternatives: same category
$data = load_tools();
$alts = array_values(array_filter($data['tools'], function ($x) use ($t) {
  return ($x['id'] ?? '') !== ($t['id'] ?? '') && ($x['category'] ?? '') === ($t['category'] ?? '');
}));
$alts = array_slice($alts, 0, 6);

// Generate dynamic content — merge DB values if present, else use generated fallbacks
$features  = !empty($t['features'])  ? $t['features']  : generate_features($t);
$pros      = !empty($t['pros'])      ? $t['pros']      : generate_pros($t);
$cons      = !empty($t['cons'])      ? $t['cons']      : generate_cons($t);
$use_cases = !empty($t['use_cases']) ? $t['use_cases'] : generate_use_cases($t);
$tags      = !empty($t['tags'])      ? $t['tags']      : generate_tags($t);

$cta_url = tool_cta_url($t);
?>

<div class="card">
  <div style="display:flex;gap:12px;align-items:flex-start">
    <img class="logo" style="width:64px;height:64px" src="<?= h(tool_logo($t)) ?>" alt="" />
    <div style="flex:1">
      <h1 style="margin:0 0 6px"><?= h($t['name']) ?></h1>
      <div class="small">
        Category: <b><?= h($t['category']) ?></b> &bull;
        Pricing: <b><?= h($t['pricing'] ?? 'Varies') ?></b> &bull;
        Rating: <?= $cnt ? number_format($avg, 1) : '—' ?> (<?= (int)$cnt ?>) &bull;
        Clicks: <?= (int)$clicks ?>
      </div>
      <p style="margin-top:12px"><?= h($t['description'] ?? $t['short'] ?? '') ?></p>
      <div class="actions">
        <a class="btn" target="_blank" rel="noopener" href="<?= h($cta_url) ?>">Open official website</a>
        <a class="btn secondary" href="compare.php?a=<?= urlencode($t['id']) ?>">Compare</a>
      </div>
      <?php $voteBreakdown = tool_vote_breakdown($t['id']); ?>
      <div class="voteSummary">
        <span class="votePill">👍 Likes: <?= (int)$voteBreakdown['up'] ?></span>
        <span class="votePill">👎 Dislikes: <?= (int)$voteBreakdown['down'] ?></span>
        <span class="votePill">Score: <?= (int)tool_vote_total($t['id']) ?></span>
      </div>
      <?= render_vote_buttons($t['id'], 'tool.php?id=' . urlencode($t['id']) . '#community') ?>
    </div>
  </div>

  <hr>

  <!-- TAGS -->
  <?php if (!empty($tags)): ?>
  <div class="kv">
    <div class="small"><b>Tags</b></div>
    <div>
      <?php foreach ($tags as $tag): ?>
        <span class="badge gray"><?= h($tag) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- FEATURES -->
  <?php if (!empty($features)): ?>
  <div class="kv">
    <div class="small"><b>Features</b></div>
    <div>
      <ul>
        <?php foreach ($features as $f): ?>
          <li><?= h($f) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- USE CASES -->
  <?php if (!empty($use_cases)): ?>
  <div class="kv">
    <div class="small"><b>Use Cases</b></div>
    <div>
      <ul>
        <?php foreach ($use_cases as $uc): ?>
          <li><?= h($uc) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- PROS -->
  <?php if (!empty($pros)): ?>
  <div class="kv">
    <div class="small"><b>Pros</b></div>
    <div>
      <ul>
        <?php foreach ($pros as $p): ?>
          <li><?= h($p) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- CONS -->
  <?php if (!empty($cons)): ?>
  <div class="kv">
    <div class="small"><b>Cons</b></div>
    <div>
      <ul>
        <?php foreach ($cons as $c): ?>
          <li><?= h($c) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <div class="ad">Ad placeholder (Inside tool page)</div>

  <h2>Rate this tool</h2>
  <form method="post" action="rate.php" class="card" style="background:rgba(255,255,255,.03)">
    <input type="hidden" name="id" value="<?= h($t['id']) ?>">
    <label class="small">Stars</label>
    <select name="stars">
      <option value="5">★★★★★ (5)</option>
      <option value="4">★★★★☆ (4)</option>
      <option value="3">★★★☆☆ (3)</option>
      <option value="2">★★☆☆☆ (2)</option>
      <option value="1">★☆☆☆☆ (1)</option>
    </select>
    <div class="actions" style="margin-top:10px">
      <button class="btn" type="submit">Submit rating</button>
      <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Refresh</a>
    </div>
  </form>

  <?php if (count($alts)): ?>
    <h2>Alternatives in <?= h($t['category']) ?></h2>
    <div class="grid">
      <?php foreach ($alts as $a): ?>
        <div class="card">
          <div style="display:flex;gap:10px;align-items:center">
            <img class="logo" src="<?= h(tool_logo($a)) ?>" alt="" />
            <div>
              <h3 style="margin:0 0 4px"><?= h($a['name']) ?></h3>
              <div class="small"><?= h($a['pricing'] ?? 'Varies') ?></div>
            </div>
          </div>
          <p style="margin-top:10px"><?= h($a['short'] ?? '') ?></p>
          <div class="actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($a['id']) ?>">Details</a>
            <a class="btn" href="compare.php?a=<?= urlencode($t['id']) ?>&b=<?= urlencode($a['id']) ?>">Compare</a>
          </div>
          <?= render_vote_buttons($a['id'], 'tool.php?id=' . urlencode($t['id'])) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


<div class="card" style="margin-top:16px">
  <h2 style="margin-top:0">User actions</h2>
  <div class="actions">
    <a class="btn secondary" href="toggle-bookmark.php?id=<?= urlencode($t['id']) ?>">Save / Remove Bookmark</a>
    <a class="btn secondary" href="compare-tools.php?a=<?= urlencode($t['id']) ?>">Compare this tool</a>
    <a class="btn secondary" href="login.php">Demo Login</a>
  </div>
  <p class="small" style="margin-top:10px">Average demo rating: <?= number_format(avg_rating_demo($t['id']), 1) ?>/5</p>
</div>


<div id="community" class="card" style="margin-top:16px">
  <h2 style="margin-top:0">Community Interaction</h2>
  <p class="small">Comments, discussions, and voting help create engagement.</p>
  <?= render_vote_buttons($t['id'], 'tool.php?id=' . urlencode($t['id']) . '#community') ?>
  <?php $communityVotes = tool_vote_breakdown($t['id']); ?>
  <p class="small" style="margin-top:10px">
    Likes: <?= (int)$communityVotes['up'] ?> &bull;
    Dislikes: <?= (int)$communityVotes['down'] ?> &bull;
    Score: <?= (int)tool_vote_total($t['id']) ?> from <?= (int)tool_vote_count($t['id']) ?> votes
  </p>

  <h3 style="margin-top:18px">Discussion</h3>
  <?php $comments_demo = tool_comments($t['id']); ?>
  <?php if ($comments_demo): ?>
    <?php foreach ($comments_demo as $c): ?>
      <div class="noteBox" style="margin-bottom:10px">
        <strong><?= htmlspecialchars($c['name'] ?? 'Anonymous') ?></strong>
        <div class="small"><?= htmlspecialchars($c['created_at'] ?? '') ?></div>
        <p style="margin:8px 0 0"><?= nl2br(htmlspecialchars($c['comment'] ?? '')) ?></p>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="noteBox">No comments yet. Start the discussion.</div>
  <?php endif; ?>

  <form method="post" action="submit-comment.php" style="margin-top:14px">
    <input type="hidden" name="tool_id" value="<?= htmlspecialchars($t['id']) ?>">
    <label>Your name</label>
    <input class="input" name="name" placeholder="Your name">
    <label style="margin-top:12px;display:block">Comment</label>
    <textarea class="input" name="comment" rows="5" placeholder="Share your experience with this tool"></textarea>
    <p style="margin-top:12px"><button class="btn">Post comment</button></p>
  </form>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
