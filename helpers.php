
<?php
// ----------------------
// JSON helpers
// ----------------------
function data_path($name){ return __DIR__ . '/data/' . $name; }


function read_json($file, $default){
  $p = data_path($file);
  if(!file_exists($p)) return $default;
  $raw = file_get_contents($p);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : $default;
}

function write_json($file, $data){
  $p = data_path($file);
  file_put_contents($p, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// ----------------------
// Site config
// ----------------------

function site_cfg(){
  if(db_connection_available()){
    try {
      $pdo = db();
      $stmt = $pdo->query("SHOW TABLES LIKE 'site_settings'");
      if($stmt->fetchColumn()){
        $rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
        if($rows){
          $cfg = [];
          foreach($rows as $row){
            $key = (string)($row['setting_key'] ?? '');
            $val = (string)($row['setting_value'] ?? '');
            $decoded = json_decode($val, true);
            $cfg[$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;
          }
          return $cfg;
        }
      }
    } catch(Throwable $e){
      // fallback to JSON
    }
  }
  return read_json('site.json', []);
}
function exclude_categories(){
  $cfg = site_cfg();
  return $cfg['exclude_categories'] ?? [];
}

// ----------------------
// Tools
// ----------------------
function mysql_tables_available_for_content(){
  static $ok = null;
  if($ok !== null) return $ok;
  if(!db_connection_available()) return $ok = false;
  try {
    $pdo = db();
    $required = ['categories','tools','posts','use_cases'];
    foreach($required as $table){
      $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
      if(!$stmt->fetchColumn()) return $ok = false;
    }
    return $ok = true;
  } catch(Throwable $e){
    return $ok = false;
  }
}

function decode_json_field($value, $default = []){
  if($value === null || $value === '') return $default;
  if(is_array($value)) return $value;
  $decoded = json_decode((string)$value, true);
  return (json_last_error() === JSON_ERROR_NONE && $decoded !== null) ? $decoded : $default;
}

function load_tools(){
  if(mysql_tables_available_for_content()){
    try {
      $pdo = db();
      $sql = "SELECT 
                t.slug AS id,
                t.name,
                c.name AS category,
                t.short_description AS short,
                t.description,
                t.features,
                t.pros,
                t.cons,
                t.use_cases,
                t.pricing,
                t.website,
                t.affiliate,
                t.added,
                t.tags,
                t.logo,
                t.signup_url, 
                t.signup_verified,
                t.pricing_type,
                t.seo_title,
                t.seo_description,
                t.featured_level,
                t.featured_until
              FROM tools t
              LEFT JOIN categories c ON c.id = t.category_id
              WHERE t.status = 'published'
              ORDER BY t.signup_verified DESC"
              ;
      $rows = $pdo->query($sql)->fetchAll();
      $exclude = exclude_categories();
      $tools = [];
      foreach($rows as $row){
        $tool = [
          'id' => (string)($row['id'] ?? ''),
            'signup_url' => $row['signup_url'] ?? '',
'signup_verified' => (int)($row['signup_verified'] ?? 0),
          'name' => (string)($row['name'] ?? ''),
          'category' => (string)($row['category'] ?? 'Other'),
          'short' => (string)($row['short'] ?? ''),
          'description' => (string)($row['description'] ?? ''),
          'features' => decode_json_field($row['features'], []),
          'pros' => decode_json_field($row['pros'], []),
          'cons' => decode_json_field($row['cons'], []),
          'use_cases' => decode_json_field($row['use_cases'], []),
          'pricing' => (string)($row['pricing'] ?? ''),
          'website' => (string)($row['website'] ?? ''),
          'affiliate' => (string)($row['affiliate'] ?? ''),
          'added' => (string)($row['added'] ?? ''),
          'tags' => decode_json_field($row['tags'], []),
          'logo' => (string)($row['logo'] ?? ''),
          'pricing_type' => (string)($row['pricing_type'] ?? ''),
          'seo_title' => (string)($row['seo_title'] ?? ''),
          'seo_description' => (string)($row['seo_description'] ?? ''),
          'featured_level' => (int)($row['featured_level'] ?? 0),
          'featured_until' => (string)($row['featured_until'] ?? ''),
        ];
        if(!in_array($tool['category'], $exclude, true)){
          $tools[] = $tool;
        }
      }
      $cats = array_values(array_unique(array_map(function($t){ return $t['category'] ?? 'Other'; }, $tools)));
      sort($cats);
      return ['tools'=>$tools, 'categories'=>$cats];
    } catch(Throwable $e){
      // fallback to JSON
    }
  }

  $data = read_json('tools.json', ['tools'=>[], 'categories'=>[]]);
  $exclude = exclude_categories();
  $tools = array_values(array_filter($data['tools'] ?? [], function($t) use ($exclude){
    $c = $t['category'] ?? '';
    return !in_array($c, $exclude, true);
  }));
  $cats = array_values(array_unique(array_map(function($t){ return $t['category'] ?? 'Other'; }, $tools)));
  sort($cats);
  return ['tools'=>$tools, 'categories'=>$cats];
}

function find_tool($id){
  $id = (string)$id;
  if(mysql_tables_available_for_content()){
    try {
      $pdo = db();
      $sql = "SELECT 
                t.slug AS id,
                t.name,
                c.name AS category,
                t.short_description AS short,
                t.description,
                t.features,
                t.pros,
                t.cons,
                t.use_cases,
                t.pricing,
                t.website,
                t.affiliate,
                t.added,
                t.tags,
                t.logo,
                t.pricing_type,
                t.seo_title,
                t.seo_description,
                t.featured_level,
                t.featured_until
              FROM tools t
              LEFT JOIN categories c ON c.id = t.category_id
              WHERE t.slug = ?
              LIMIT 1";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if($row){
        return [
          'id' => (string)($row['id'] ?? ''),
          'name' => (string)($row['name'] ?? ''),
          'category' => (string)($row['category'] ?? 'Other'),
          'short' => (string)($row['short'] ?? ''),
          'description' => (string)($row['description'] ?? ''),
          'features' => decode_json_field($row['features'], []),
          'pros' => decode_json_field($row['pros'], []),
          'cons' => decode_json_field($row['cons'], []),
          'use_cases' => decode_json_field($row['use_cases'], []),
          'pricing' => (string)($row['pricing'] ?? ''),
          'website' => (string)($row['website'] ?? ''),
          'affiliate' => (string)($row['affiliate'] ?? ''),
          'added' => (string)($row['added'] ?? ''),
          'tags' => decode_json_field($row['tags'], []),
          'logo' => (string)($row['logo'] ?? ''),
          'pricing_type' => (string)($row['pricing_type'] ?? ''),
          'seo_title' => (string)($row['seo_title'] ?? ''),
          'seo_description' => (string)($row['seo_description'] ?? ''),
          'featured_level' => (int)($row['featured_level'] ?? 0),
          'featured_until' => (string)($row['featured_until'] ?? ''),
        ];
      }
    } catch(Throwable $e){
      // fallback
    }
  }

  $data = load_tools();
  foreach($data['tools'] as $t){
    if(($t['id'] ?? '') === $id) return $t;
  }
  return null;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }



function db_connection_available(){
  if(!function_exists('db')){
    $dbFile = __DIR__ . '/db.php';
    if(file_exists($dbFile)) require_once $dbFile;
  }
  return function_exists('db');
}

function interaction_tables_exist(){
  static $checked = null;
  if($checked !== null) return $checked;
  if(!db_connection_available()) return $checked = false;
  try {
    $pdo = db();
    $stmt = $pdo->query("SHOW TABLES LIKE 'tool_votes'");
    $hasVotes = (bool)$stmt->fetchColumn();
    $stmt2 = $pdo->query("SHOW TABLES LIKE 'tool_clicks'");
    $hasClicks = (bool)$stmt2->fetchColumn();
    return $checked = ($hasVotes && $hasClicks);
  } catch(Throwable $e){
    return $checked = false;
  }
}

function client_ip_address(){
  foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key){
    if(empty($_SERVER[$key])) continue;
    $value = trim((string)$_SERVER[$key]);
    if($key === 'HTTP_X_FORWARDED_FOR'){
      $parts = array_map('trim', explode(',', $value));
      foreach($parts as $part){
        if(filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)){
          return substr($part, 0, 45);
        }
      }
      $value = $parts[0] ?? '';
    }
    if($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) return substr($value, 0, 45);
  }
  return null;
}

function visitor_id(){
  static $visitorId = null;
  if($visitorId !== null) return $visitorId;

  $cookieName = 'aihub_visitor_id';
  $existing = trim((string)($_COOKIE[$cookieName] ?? ''));
  if($existing !== '' && preg_match('/^[a-f0-9]{32}$/', $existing)){
    return $visitorId = $existing;
  }

  try {
    $visitorId = bin2hex(random_bytes(16));
  } catch(Throwable $e){
    $visitorId = md5(uniqid((string)mt_rand(), true));
  }

  if(!headers_sent()){
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($cookieName, $visitorId, [
      'expires' => time() + (86400 * 365 * 5),
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    $_COOKIE[$cookieName] = $visitorId;
  }

  return $visitorId;
}

function tool_votes_uses_visitor_id(){
  static $hasColumn = null;
  if($hasColumn !== null) return $hasColumn;
  if(!interaction_tables_exist()) return $hasColumn = false;
  try {
    $pdo = db();
    $stmt = $pdo->query("SHOW COLUMNS FROM tool_votes LIKE 'visitor_id'");
    return $hasColumn = (bool)$stmt->fetchColumn();
  } catch(Throwable $e){
    return $hasColumn = false;
  }
}


function tool_domain($url){
  $host = parse_url($url, PHP_URL_HOST);
  if(!$host) return '';
  $host = preg_replace('/^www\./','',$host);
  return $host;
}


function compare_long_description($tool){
  if(!$tool || !is_array($tool)) return '';
  $name = $tool['name'] ?? 'This tool';
  $category = $tool['category'] ?? 'AI';
  $pricing = $tool['pricing'] ?? 'Varies';
  $short = trim($tool['short'] ?? '');
  $desc = trim($tool['description'] ?? '');
  $features = array_values(array_filter($tool['features'] ?? []));
  $use = array_values(array_filter($tool['use_cases'] ?? []));
  $pros = array_values(array_filter($tool['pros'] ?? []));
  $cons = array_values(array_filter($tool['cons'] ?? []));
  $parts = [];

  if($short !== ''){
    $parts[] = $name . ' is positioned as a ' . strtolower($category) . ' tool and is primarily built for users who want a faster, simpler way to handle this kind of workflow. ' . rtrim($short, '. ') . '.';
  } elseif($desc !== ''){
    $parts[] = $name . ' sits in the ' . $category . ' category and focuses on helping users move from idea to output with less manual work.';
  } else {
    $parts[] = $name . ' is an AI product in the ' . $category . ' category designed to streamline common digital tasks and reduce repetitive work.';
  }

  if($desc !== ''){
    $parts[] = rtrim($desc, '. ') . '.';
  }

  if($features){
    $parts[] = 'Its strongest areas include ' . implode(', ', array_slice($features, 0, 4)) . ', which gives it a more complete day-to-day feature set for people comparing alternatives in the same space.';
  }

  if($use){
    $parts[] = 'In practical terms, it tends to fit best for ' . implode(', ', array_slice($use, 0, 4)) . ', so it can work well for users who want a focused solution instead of a broad all-in-one platform.';
  }

  if($pros){
    $parts[] = 'Users will usually appreciate strengths such as ' . implode(', ', array_slice($pros, 0, 3)) . ', especially when ease of use and quick setup matter.';
  }

  $parts[] = 'Pricing is listed as ' . $pricing . ', which makes it easier to judge whether the tool is suitable for testing, casual use, or a longer-term workflow.';

  if($cons){
    $parts[] = 'Potential watch-outs include ' . implode(', ', array_slice($cons, 0, 2)) . ', so it is worth checking the official product page before adopting it for a heavier workflow.';
  }

  $parts[] = 'Overall, ' . $name . ' looks most attractive for users who care about practical output, a clear use case, and a tool that can be understood quickly without a steep learning curve.';

  return trim(implode(' ', $parts));
}

function compare_tool_total_score($tool){
  if(!$tool || !is_array($tool)) return 0;
  $features = count(array_filter($tool['features'] ?? []));
  $pros = count(array_filter($tool['pros'] ?? []));
  $useCases = count(array_filter($tool['use_cases'] ?? []));
  $hasLong = trim(($tool['description'] ?? '') . ($tool['short'] ?? '')) !== '' ? 1 : 0;
  return ($features * 2) + ($pros * 2) + ($useCases * 2) + ($hasLong * 2);
}

function tool_logo($t){
  if(!empty($t['logo'])) return $t['logo'];
  $url = $t['website'] ?? '';
  $dom = tool_domain($url);
  if(!$dom) return '';
  return 'https://www.google.com/s2/favicons?domain=' . urlencode($dom) . '&sz=128';
}

function tool_url($t){
  $u = $t['affiliate'] ?? '';
  if($u) return $u;
  $site = site_cfg();
  $base = $t['website'] ?? '#';
  $aff = $site['affiliate_default'] ?? '';
  if($base === '#' || $base === '') return '#';
  if($aff === '') return $base;
  // naive: append if no query
  return (strpos($base,'?') !== false) ? ($base . '&' . ltrim($aff,'?&')) : ($base . $aff);
}

// ----------------------
// Click tracking + trending
// ----------------------
function record_click(string $toolId): int
{
    $toolId = trim($toolId);
    if ($toolId === '') {
        return 0;
    }

    $pdo = db();
    $visitor = get_visitor_id();
    $ipHash = get_ip_hash();

    $stmt = $pdo->prepare("
        INSERT INTO tool_clicks (tool_slug, visitor_id, ip_hash, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$toolId, $visitor, $ipHash]);

    $count = $pdo->prepare("
        SELECT COUNT(*) FROM tool_clicks WHERE tool_slug = ?
    ");
    $count->execute([$toolId]);

    return (int)$count->fetchColumn();
}

function click_count($id){
  $id = (string)$id;
  if(interaction_tables_exist()){
    try {
      $pdo = db();
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM tool_clicks WHERE tool_slug = ?");
      $stmt->execute([$id]);
      return (int)$stmt->fetchColumn();
    } catch(Throwable $e){
      // Fall back to JSON.
    }
  }

  $clicks = read_json('clicks.json', []);
  return (int)($clicks[$id] ?? 0);
}


// ----------------------
// Ratings
// ratings.json format: { "toolid": [ {stars:int, ts:string} ... ] }
// ----------------------
function add_rating($id, $stars){
  $stars = max(1, min(5, (int)$stars));
  $ratings = read_json('ratings.json', []);
  if(!isset($ratings[$id]) || !is_array($ratings[$id])) $ratings[$id] = [];
  $ratings[$id][] = ['stars'=>$stars, 'ts'=>date('c')];
  write_json('ratings.json', $ratings);
}

function rating_avg($id){
  $ratings = read_json('ratings.json', []);
  $arr = $ratings[$id] ?? [];
  $cnt = is_array($arr) ? count($arr) : 0;
  if($cnt === 0) return [0,0];
  $sum = 0;
  foreach($arr as $r){ $sum += (int)($r['stars'] ?? 0); }
  return [$sum / $cnt, $cnt];
}

function trending_score($id){
  [$avg,$cnt] = rating_avg($id);
  $clicks = click_count($id);
  // simple: clicks + avg*10 + cnt
  return ($clicks * 1.0) + ($avg * 10.0) + ($cnt * 0.5);
}


function popularity_score($id){
  $id = (string)$id;
  $clicks = click_count($id);
  $votes = tool_vote_breakdown($id);
  return (int)(($votes['up'] * 3) + $clicks - ($votes['down'] * 2));
}

function popularity_breakdown($id){
  $id = (string)$id;
  $votes = tool_vote_breakdown($id);
  return [
    'clicks' => click_count($id),
    'likes' => (int)$votes['up'],
    'dislikes' => (int)$votes['down'],
    'score' => popularity_score($id),
  ];
}


// ----------------------
// Blog
// ----------------------

function load_posts(){
  if(mysql_tables_available_for_content()){
    try {
      $pdo = db();
      $rows = $pdo->query("SELECT slug, title, published_at AS date, excerpt, content FROM posts WHERE status = 'published' ORDER BY published_at DESC, id DESC")->fetchAll();
      if($rows) return $rows;
    } catch(Throwable $e){
      // fallback
    }
  }
  return read_json('posts.json', []);
}
function find_post($slug){
  $slug = (string)$slug;
  if(mysql_tables_available_for_content()){
    try {
      $pdo = db();
      $stmt = $pdo->prepare("SELECT slug, title, published_at AS date, excerpt, content FROM posts WHERE slug = ? LIMIT 1");
      $stmt->execute([$slug]);
      $row = $stmt->fetch();
      if($row) return $row;
    } catch(Throwable $e){
      // fallback
    }
  }
  $posts = load_posts();
  foreach($posts as $p){
    if(($p['slug'] ?? '') === $slug) return $p;
  }
  return null;
}


// ----------------------
// Featured listings
// tools.json tool fields used:
// - featured_level: 0,1,2 (higher = more prominent)
// - featured_until: YYYY-MM-DD (inclusive)
// ----------------------
function is_featured_tool($t){
  $lvl = (int)($t['featured_level'] ?? 0);
  $until = trim($t['featured_until'] ?? '');
  if($lvl <= 0 || $until === '') return false;
  return $until >= date('Y-m-d');
}
function featured_tools($tools, $limit=6){
  $items = array_values(array_filter($tools, function($t){ return is_featured_tool($t); }));
  usort($items, function($a,$b){
    $la = (int)($a['featured_level'] ?? 0);
    $lb = (int)($b['featured_level'] ?? 0);
    if($la === $lb){
      return strcmp($b['featured_until'] ?? '', $a['featured_until'] ?? '');
    }
    return ($la > $lb) ? -1 : 1;
  });
  return array_slice($items, 0, $limit);
}

function pricing_tiers(){
  $cfg = site_cfg();
  return $cfg['payment']['pricing'] ?? [];
}
function payment_links(){
  $cfg = site_cfg();
  return $cfg['payment'] ?? [];
}


// ----------------------
// Final integrated feature helpers
// ----------------------
function read_json_file_safe($file, $default = []){
  if(!file_exists($file)) return $default;
  $raw = file_get_contents($file);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : $default;
}
function write_json_file_safe($file, $data){
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
function users_file(){ return __DIR__ . '/data/users.json'; }
function bookmarks_file(){ return __DIR__ . '/data/bookmarks.json'; }
function reviews_demo_file(){ return __DIR__ . '/data/reviews_demo.json'; }

function load_users_demo(){ return read_json_file_safe(users_file(), ['users'=>[]]); }
function load_bookmarks_demo(){ return read_json_file_safe(bookmarks_file(), []); }
function save_bookmarks_demo($data){ write_json_file_safe(bookmarks_file(), $data); }
function load_reviews_demo(){ return read_json_file_safe(reviews_demo_file(), []); }
function save_reviews_demo($data){ write_json_file_safe(reviews_demo_file(), $data); }

function current_demo_user_id(){
  if(session_status() === PHP_SESSION_NONE){ @session_start(); }
  return $_SESSION['demo_user_id'] ?? null;
}
function is_logged_in_demo(){ return current_demo_user_id() !== null; }

function find_tool_by_id_safe($id){
  $data = load_tools();
  foreach(($data['tools'] ?? []) as $t){
    if((string)($t['id'] ?? '') === (string)$id) return $t;
  }
  return null;
}

function tool_reviews_demo($toolId){
  $all = load_reviews_demo();
  return array_values(array_filter($all, function($r) use ($toolId){
    return (string)($r['tool_id'] ?? '') === (string)$toolId;
  }));
}
function avg_rating_demo($toolId){
  $rows = tool_reviews_demo($toolId);
  if(!$rows) return 0;
  $sum = 0;
  foreach($rows as $r){ $sum += (int)($r['rating'] ?? 0); }
  return round($sum / max(1, count($rows)), 1);
}
function bookmarked_demo($toolId){
  $uid = current_demo_user_id();
  if(!$uid) return false;
  $data = load_bookmarks_demo();
  $list = $data[(string)$uid] ?? [];
  return in_array((string)$toolId, array_map('strval', $list), true);
}


// ----------------------
// Community interaction helpers
// ----------------------
function comments_file(){ return __DIR__ . '/data/tool_comments.json'; }
function votes_file(){ return __DIR__ . '/data/tool_votes.json'; }

function load_tool_comments(){
  return read_json_file_safe(comments_file(), []);
}
function save_tool_comments($rows){
  write_json_file_safe(comments_file(), $rows);
}
function load_tool_votes(){
  return read_json_file_safe(votes_file(), []);
}
function save_tool_votes($rows){
  write_json_file_safe(votes_file(), $rows);
}
function tool_comments($toolId){
  $rows = load_tool_comments();
  return array_values(array_filter($rows, function($r) use ($toolId){
    return (string)($r['tool_id'] ?? '') === (string)$toolId;
  }));
}

function tool_vote_breakdown($toolId){
  $toolId = (string)$toolId;

  if(interaction_tables_exist()){
    try {
      $pdo = db();
      $stmt = $pdo->prepare("SELECT vote_type, COUNT(*) AS total FROM tool_votes WHERE tool_slug = ? GROUP BY vote_type");
      $stmt->execute([$toolId]);
      $counts = ['up'=>0,'down'=>0];

      foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $type = (string)($row['vote_type'] ?? '');

        if ($type === 'up' || $type === 'down') {
          $counts[$type] = (int)($row['total'] ?? 0);
        }
      }

      return $counts;
    } catch(Throwable $e){
      // Fall back to JSON.
    }
  }

  $votes = load_tool_votes();
  $row = $votes[$toolId] ?? ['up'=>0,'down'=>0];

  if(is_array($row) && array_keys($row) === range(0, count($row) - 1)){
    $up = 0; $down = 0;
    foreach($row as $val){
      $intVal = (int)$val;
      if($intVal > 0) $up++;
      if($intVal < 0) $down++;
    }
    return ['up'=>$up, 'down'=>$down];
  }

  return [
    'up' => max(0, (int)($row['up'] ?? 0)),
    'down' => max(0, (int)($row['down'] ?? 0)),
  ];
}

function tool_vote_total($toolId){
  $counts = tool_vote_breakdown($toolId);
  return (int)$counts['up'] - (int)$counts['down'];
}
function tool_vote_count($toolId){
  $counts = tool_vote_breakdown($toolId);
  return (int)$counts['up'] + (int)$counts['down'];
}

function get_visitor_id(): string
{
    if (!empty($_COOKIE['aih_visitor'])) {
        return preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE['aih_visitor']);
    }

    $id = bin2hex(random_bytes(16));

    setcookie('aih_visitor', $id, [
        'expires' => time() + (86400 * 365),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE['aih_visitor'] = $id;

    return $id;
}

function get_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $secret = 'change-this-to-a-long-random-secret';
    return hash('sha256', $ip . $secret);
}

function vote_tool_action(string $toolId, string $type): bool
{
    $pdo = db();
    $visitorId = get_visitor_id();
    $ipHash = get_ip_hash();

    if (!in_array($type, ['up', 'down'], true)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id, vote_type
        FROM tool_votes
        WHERE tool_slug = ? AND visitor_id = ?
        LIMIT 1
    ");
    $stmt->execute([$toolId, $visitorId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['vote_type'] === $type) {
            return false;
        }

        $update = $pdo->prepare("
            UPDATE tool_votes
            SET vote_type = ?, ip_hash = ?, updated_at = NOW()
            WHERE id = ?
        ");
        return $update->execute([$type, $ipHash, $existing['id']]);
    }

    $insert = $pdo->prepare("
        INSERT INTO tool_votes (tool_slug, visitor_id, vote_type, ip_hash, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    return $insert->execute([$toolId, $visitorId, $type, $ipHash]);
}

function render_vote_buttons($toolId, $context = 'tool.php?id='){
  $toolId = (string)$toolId;
  $counts = tool_vote_breakdown($toolId);
  $clicks = click_count($toolId);
  $contextEsc = htmlspecialchars($context, ENT_QUOTES);
  $idEsc = htmlspecialchars($toolId, ENT_QUOTES);
  $up = (int)$counts['up'];
  $down = (int)$counts['down'];
  return '<div class="voteRow toolStatsAjax" data-tool-id="' . $idEsc . '" data-context="' . $contextEsc . '">'
    . '<button type="button" class="voteBtn like js-vote-btn" data-vote="up" data-tool-id="' . $idEsc . '">👍 <span>Like</span> <strong class="js-like-count">' . $up . '</strong></button>'
    . '<button type="button" class="voteBtn dislike js-vote-btn" data-vote="down" data-tool-id="' . $idEsc . '">👎 <span>Dislike</span> <strong class="js-dislike-count">' . $down . '</strong></button>'
    . '<span class="clickPill">👁 <span class="js-click-count">' . (int)$clicks . '</span> clicks</span>'
    . '</div>';
}


// ----------------------
// AI Use-Case Explorer helpers
// ----------------------

function use_cases_file(){ return __DIR__ . '/data/use_cases.json'; }

function load_use_cases(){
  if(mysql_tables_available_for_content()){
    try {
      $pdo = db();
      $rows = $pdo->query("
        SELECT 
          slug,
          label,
          icon,
          description,
          hero_text,
          audience,
          popular_tasks,
          example_tools,
          benefits,
          keywords_json,
          fallback_json
        FROM use_cases
        ORDER BY id ASC
      ")->fetchAll();

      if($rows){
        $out = [];
        foreach($rows as $row){
          $out[(string)$row['slug']] = [
            'label' => (string)($row['label'] ?? ''),
            'icon' => (string)($row['icon'] ?? '✨'),
            'description' => (string)($row['description'] ?? ''),
            'hero_text' => (string)($row['hero_text'] ?? ''),
            'audience' => (string)($row['audience'] ?? ''),
            'popular_tasks' => decode_json_field($row['popular_tasks'], []),
            'example_tools' => decode_json_field($row['example_tools'], []),
            'benefits' => decode_json_field($row['benefits'], []),
            'keywords' => decode_json_field($row['keywords_json'], []),
            'fallback' => decode_json_field($row['fallback_json'], []),
          ];
        }
        return $out;
      }
    } catch(Throwable $e){
      // fallback
    }
  }

  return read_json_file_safe(use_cases_file(), []);
}
function match_tools_for_use_case($slug, $limit = 8){
  $cases = load_use_cases();
  $case = $cases[$slug] ?? null;
  if(!$case) return [];
  $keywords = array_map('strtolower', $case['keywords'] ?? []);
  $fallback = $case['fallback'] ?? [];
  $data = load_tools();
  $tools = $data['tools'] ?? [];
  $scored = [];
  foreach($tools as $t){
    $text = strtolower(trim(($t['name'] ?? '') . ' ' . ($t['short'] ?? '') . ' ' . ($t['category'] ?? '')));
    $score = 0;
    foreach($keywords as $kw){
      if($kw !== '' && strpos($text, $kw) !== false) $score++;
    }
    foreach($fallback as $name){
      if(strtolower($t['name'] ?? '') === strtolower($name)) $score += 3;
    }
    if($score > 0){
      $t['_score'] = $score;
      $scored[] = $t;
    }
  }
  usort($scored, function($a,$b){
    return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
  });
  return array_slice($scored, 0, $limit);
}


// ----------------------
// Compare data (new tables only, no changes to existing tables)
// ----------------------
function compare_tables_exist(){
  static $checked = null;
  if($checked !== null) return $checked;
  if(!db_connection_available()) return $checked = false;
  try {
    $pdo = db();
    foreach(['compare_tool_profiles','compare_tool_points','compare_tool_sources'] as $table){
      $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
      if(!$stmt->fetchColumn()) return $checked = false;
    }
    return $checked = true;
  } catch(Throwable $e){
    return $checked = false;
  }
}

function compare_json_field($value, $default = []){
  return decode_json_field($value, $default);
}

function compare_profile_row(string $toolSlug){
  if($toolSlug === '' || !compare_tables_exist()) return null;
  try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM compare_tool_profiles WHERE tool_slug = ? LIMIT 1");
    $stmt->execute([$toolSlug]);
    $row = $stmt->fetch();
    if(!$row) return null;
    $row['platforms'] = compare_json_field($row['platforms_json'] ?? null, []);
    $row['integrations'] = compare_json_field($row['integrations_json'] ?? null, []);
    return $row;
  } catch(Throwable $e){
    return null;
  }
}

function compare_tool_points(string $toolSlug, string $type): array{
  if($toolSlug === '' || !compare_tables_exist()) return [];
  try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT label, source_url, source_note, evidence_snippet FROM compare_tool_points WHERE tool_slug = ? AND point_type = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$toolSlug, $type]);
    return $stmt->fetchAll() ?: [];
  } catch(Throwable $e){
    return [];
  }
}

function compare_labels_from_points(array $points): array{
  $out = [];
  foreach($points as $row){
    $label = trim((string)($row['label'] ?? ''));
    if($label !== '') $out[] = $label;
  }
  return array_values(array_unique($out));
}

function compare_factual_fallbacks(array $tool): array{
  $platforms = [];
  $website = trim((string)($tool['website'] ?? ''));
  if($website !== '') $platforms[] = 'Web';

  $features = [];
  foreach(array_values(array_filter($tool['features'] ?? [])) as $item){
    $item = trim((string)$item);
    if($item !== '') $features[] = $item;
  }

  $useCases = [];
  foreach(array_values(array_filter($tool['use_cases'] ?? [])) as $item){
    $item = trim((string)$item);
    if($item !== '') $useCases[] = $item;
  }

  $pros = [];
  $pricing = trim((string)($tool['pricing'] ?? ''));
  if($pricing !== '') $pros[] = 'Pricing listed on source profile';
  if($website !== '') $pros[] = 'Official website available';
  if(!empty($features)) $pros[] = count($features) . ' listed features available';

  $watchouts = [];
  if(empty($features)) $watchouts[] = 'Feature data not yet verified for compare profile';
  if(empty($useCases)) $watchouts[] = 'Use-case data not yet verified for compare profile';

  return [
    'platforms' => $platforms,
    'integrations' => [],
    'features' => $features,
    'use_cases' => $useCases,
    'pros' => $pros,
    'watchouts' => $watchouts,
  ];
}

function compare_to_array($value): array{
  if(is_array($value)){
    return array_values(array_filter(array_map(function($v){ return trim((string)$v); }, $value), function($v){ return $v !== ''; }));
  }
  if($value === null || $value === '') return [];
  $decoded = json_decode((string)$value, true);
  if(is_array($decoded)){
    return array_values(array_filter(array_map(function($v){ return trim((string)$v); }, $decoded), function($v){ return $v !== ''; }));
  }
  return [];
}

function compare_integrations_from_tags(array $tool): array{
  $tags = array_map('strtolower', compare_to_array($tool['tags'] ?? []));
  $map = [
    'slack' => 'Slack',
    'google meet' => 'Google Meet',
    'meet' => 'Google Meet',
    'zoom' => 'Zoom',
    'gmail' => 'Gmail',
    'notion' => 'Notion',
    'zapier' => 'Zapier',
    'api' => 'API',
    'chrome' => 'Chrome Extension',
    'teams' => 'Microsoft Teams',
    'microsoft teams' => 'Microsoft Teams',
  ];

  $out = [];
  foreach($tags as $tag){
    if(isset($map[$tag])) $out[] = $map[$tag];
  }
  return array_values(array_unique($out));
}

function compare_platforms_from_tool(array $tool): array{
  $platforms = compare_to_array($tool['platforms'] ?? []);
  if(!empty($platforms)) return $platforms;

  $out = [];
  if(!empty($tool['website'])) $out[] = 'Web';

  $hay = strtolower(implode(' ', compare_to_array($tool['tags'] ?? [])) . ' ' . ($tool['description'] ?? '') . ' ' . ($tool['short'] ?? ''));
  if(strpos($hay, 'ios') !== false || strpos($hay, 'iphone') !== false || strpos($hay, 'mobile') !== false) $out[] = 'iOS / Mobile';
  if(strpos($hay, 'android') !== false) $out[] = 'Android';
  if(strpos($hay, 'chrome') !== false || strpos($hay, 'extension') !== false) $out[] = 'Chrome Extension';
  if(empty($out)) $out[] = 'Web';

  return array_values(array_unique($out));
}

function compare_tool_bundle(array $tool): array{
  $slug = (string)($tool['id'] ?? '');
  $profile = compare_profile_row($slug);

  $profileFeatures = $profile ? compare_labels_from_points(compare_tool_points($slug, 'feature')) : [];
  $profileUseCases = $profile ? compare_labels_from_points(compare_tool_points($slug, 'use_case')) : [];
  $profilePros = $profile ? compare_labels_from_points(compare_tool_points($slug, 'pro')) : [];
  $profileWatchouts = $profile ? compare_labels_from_points(compare_tool_points($slug, 'watchout')) : [];
  $profileIntegrations = $profile ? compare_labels_from_points(compare_tool_points($slug, 'integration')) : [];

  $features = $profileFeatures ?: compare_to_array($tool['features'] ?? []);
  $useCases = $profileUseCases ?: compare_to_array($tool['use_cases'] ?? []);
  $pros = $profilePros ?: compare_to_array($tool['pros'] ?? []);
  $watchouts = $profileWatchouts ?: compare_to_array($tool['cons'] ?? []);

  $integrations = $profileIntegrations ?: compare_to_array($tool['integrations'] ?? []);
  if(empty($integrations)) $integrations = compare_integrations_from_tags($tool);

  $platforms = [];
  if($profile && !empty($profile['platforms'])) $platforms = compare_to_array($profile['platforms']);
  if(empty($platforms)) $platforms = compare_platforms_from_tool($tool);

  $summary = trim((string)($profile['summary'] ?? ''));
  if($summary === '') $summary = trim((string)($tool['description'] ?? ''));
  if($summary === '') $summary = trim((string)($tool['short'] ?? ''));

  $pricing = trim((string)($profile['pricing_model'] ?? ''));
  if($pricing === '') $pricing = trim((string)($tool['pricing'] ?? ($tool['pricing_type'] ?? 'Unknown')));
  if($pricing === '') $pricing = 'Unknown';

  $featureDepth = isset($tool['feature_depth']) && $tool['feature_depth'] !== ''
    ? (int)$tool['feature_depth']
    : min(10, max(3, count($features) + min(3, count($integrations)) + (strlen($summary) > 120 ? 1 : 0)));

  $easeOfUse = isset($tool['ease_of_use']) && $tool['ease_of_use'] !== ''
    ? (int)$tool['ease_of_use']
    : min(10, max(3, 5 + (!empty($platforms) ? 1 : 0) + (stripos($pricing, 'free') !== false ? 1 : 0) + (count($features) >= 3 ? 1 : 0)));

  $useCaseRange = isset($tool['use_case_range']) && $tool['use_case_range'] !== ''
    ? (int)$tool['use_case_range']
    : min(10, max(3, count($useCases) + 3 + min(2, count($platforms))));

  $featureDepth = min(10, max(1, $featureDepth));
  $easeOfUse = min(10, max(1, $easeOfUse));
  $useCaseRange = min(10, max(1, $useCaseRange));

  $hasRealData = !empty($features) || !empty($useCases) || !empty($pros) || !empty($profile);
  $sourceCount = 0;
  if($profile && compare_tables_exist()){
    try {
      $pdo = db();
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM compare_tool_sources WHERE tool_slug = ?");
      $stmt->execute([$slug]);
      $sourceCount = (int)$stmt->fetchColumn();
    } catch(Throwable $e){
      $sourceCount = 0;
    }
  }
  if($sourceCount === 0 && $hasRealData) $sourceCount = 1;

  return [
    'profile' => $profile,
    'real_data' => $hasRealData,
    'source_status' => $profile['source_status'] ?? ($hasRealData ? 'available from tool profile' : 'basic'),
    'source_confidence' => $profile['source_confidence'] ?? ($hasRealData ? 'basic' : 'low'),
    'source_count' => $sourceCount,
    'summary' => $summary,
    'pricing' => $pricing,
    'free_plan' => isset($profile['free_plan']) ? (int)$profile['free_plan'] : null,
    'free_trial' => isset($profile['free_trial']) ? (int)$profile['free_trial'] : null,
    'platforms' => $platforms,
    'integrations' => $integrations,
    'features' => $features,
    'use_cases' => $useCases,
    'pros' => $pros,
    'watchouts' => $watchouts,
    'feature_depth' => $featureDepth,
    'ease_of_use' => $easeOfUse,
    'use_case_range' => $useCaseRange,
    'score_feature_depth' => $featureDepth,
    'score_ease_of_use' => $easeOfUse,
    'score_use_case_range' => $useCaseRange,
  ];
}

function compare_score_items_from_bundle(array $bundle): array{
  return [
    'Feature depth' => (int)($bundle['feature_depth'] ?? $bundle['score_feature_depth'] ?? 0),
    'Ease of use' => (int)($bundle['ease_of_use'] ?? $bundle['score_ease_of_use'] ?? 0),
    'Use-case range' => (int)($bundle['use_case_range'] ?? $bundle['score_use_case_range'] ?? 0),
  ];
}

function compare_snapshot_from_bundle(array $bundle): array{
  return [
    'Features' => count(array_filter($bundle['features'] ?? [])),
    'Use cases' => count(array_filter($bundle['use_cases'] ?? [])),
    'Integrations' => count(array_filter($bundle['integrations'] ?? [])),
  ];
}

function compare_bundle_total_score(array $bundle): int{
  $scores = compare_score_items_from_bundle($bundle);
  return array_sum(array_map('intval', array_values($scores)));
}

function compare_pick_winner_from_bundles(array $bundleA, array $bundleB){
  $sa = compare_bundle_total_score($bundleA);
  $sb = compare_bundle_total_score($bundleB);
  if($sa === $sb) return null;
  return $sa > $sb ? 'a' : 'b';
}

function tool_signup_url(array $t): string {
    $url = trim((string)($t['signup_url'] ?? ''));
    $verified = (int)($t['signup_verified'] ?? 0);

    if ($verified === 1 && $url !== '') {
        return $url;
    }
    return '#';
}

function tool_open_url(array $t): string {
    // prefer affiliate, then website
    $aff = trim((string)($t['affiliate'] ?? ''));
    if ($aff !== '') return $aff;

    $web = trim((string)($t['website'] ?? ''));
    if ($web !== '') return $web;

    return '#';
}

function tool_click_count($tool_id){
  if(!$tool_id) return 0;

  try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tool_clicks WHERE tool_id = ?");
    $stmt->execute([$tool_id]);

    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    error_log("tool_click_count error: " . $e->getMessage());
    return 0;
  }
}

require_once __DIR__ . '/trust-badges.php';