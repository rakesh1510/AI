<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8C (M1-M3): AI tool management core. Pure logic + a store interface, so every rule is testable with no database.
// MySQL `tools` is authoritative for managed tools; data/tools.json is a read-only seed and fallback that this module never writes.
// Lifecycle uses the existing tools.status column: draft | published | unpublished | deleted (soft delete only, no hard delete in 8C).
// Permissions (owner decision D2): owner and editor create, edit and unpublish; only the owner publishes, deletes and restores. Enforced here, server side.
require_once __DIR__ . '/security.php';

const ADMT_STATUSES = ['draft', 'published', 'unpublished', 'deleted'];
const ADMT_PRICING = ['Free', 'Freemium', 'Paid', 'Varies'];
const ADMT_PER_PAGE = 25;

/** Which Control Center permission each action needs. create/edit/unpublish = 'tools' (owner, editor); publish/delete/restore = 'tools_publish' (owner). */
function admt_action_perm(string $act): ?string {
  return ['create' => 'tools', 'edit' => 'tools', 'unpublish' => 'tools', 'publish' => 'tools_publish', 'delete' => 'tools_publish', 'restore' => 'tools_publish'][$act] ?? null;
}
/** Allowed status changes. Restore never goes straight to published: back to draft or unpublished, the owner publishes explicitly. */
function admt_transition(string $act, string $from): ?string {
  $m = ['publish' => ['draft' => 'published', 'unpublished' => 'published'], 'unpublish' => ['published' => 'unpublished'],
        'delete' => ['draft' => 'deleted', 'published' => 'deleted', 'unpublished' => 'deleted']];
  return $m[$act][$from] ?? null;
}
function admt_messages(): array {
  return ['created' => 'Tool created as a draft.', 'edited' => 'Changes saved.', 'published' => 'Tool published.', 'unpublished' => 'Tool unpublished.', 'deleted' => 'Tool moved to the trash.', 'restored' => 'Tool restored. It is not published; review it and publish when ready.'];
}

// ---- text helpers -------------------------------------------------------------------------------------------------------------------
function admt_text($v, int $max): string {
  if (!is_string($v)) return '';
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
  return mb_substr(trim(strip_tags($v)), 0, $max);
}
/** One item per line (or comma for tags). Drops empties and duplicates, caps the count. */
function admt_list_field($v, int $maxItems, int $maxLen, string $sep = "\n"): array {
  if (is_array($v)) $v = implode("\n", array_map('strval', $v));
  if (!is_string($v)) return [];
  $parts = $sep === ',' ? preg_split('/[,\n]+/', $v) : preg_split('/\R/', $v);
  $o = [];
  foreach ($parts ?: [] as $p) { $p = admt_text($p, $maxLen); if ($p !== '' && !in_array($p, $o, true)) $o[] = $p; if (count($o) >= $maxItems) break; }
  return $o;
}
function admt_slugify(string $name): string { return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-'); }
function admt_valid_slug(string $s): bool { return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/', $s) && strlen($s) >= 2; }

/** Validate a create/edit form. $existing is null on create (slug is accepted once) and the stored tool on edit (slug can never change). Returns [errors, tool fields]. */
function admt_validate(array $in, array $categories, ?array $existing = null): array {
  $e = []; $t = [];
  $t['name'] = admt_text($in['name'] ?? '', 120); if (mb_strlen($t['name']) < 2) $e['name'] = 'Enter a name of 2 to 120 characters.';
  if ($existing === null) {
    $slug = admt_text($in['slug'] ?? '', 120); if ($slug === '') $slug = admt_slugify($t['name']);
    if (!admt_valid_slug($slug)) $e['slug'] = 'Use 2 to 120 lowercase letters, numbers and hyphens. The address cannot be changed later.';
    $t['slug'] = $slug;
  } else { $t['slug'] = (string)$existing['slug']; }
  $t['category'] = admt_text($in['category'] ?? '', 120); if (!in_array($t['category'], $categories, true)) $e['category'] = 'Choose one of the existing categories.';
  $t['short'] = admt_text($in['short'] ?? '', 300); $sl = mb_strlen($t['short']); if ($sl < 20 || $sl > 300) $e['short'] = 'The short description must be 20 to 300 characters.';
  $t['description'] = admt_text($in['description'] ?? '', 5000);
  foreach (['features', 'pros', 'cons', 'use_cases'] as $f) $t[$f] = admt_list_field($in[$f] ?? '', 10, 200);
  $t['pricing'] = admt_text($in['pricing'] ?? '', 120);
  $t['pricing_type'] = admt_text($in['pricing_type'] ?? '', 20); if (!in_array($t['pricing_type'], ADMT_PRICING, true)) $e['pricing_type'] = 'Choose a pricing type.';
  $w = sec_safe_external_url((string)($in['website'] ?? '')); if ($w === null) $e['website'] = 'Enter a public web address starting with http:// or https://.'; $t['website'] = $w ?? '';
  $aff = trim((string)($in['affiliate'] ?? '')); if ($aff === '') $t['affiliate'] = ''; else { $a = sec_safe_external_url($aff); if ($a === null) $e['affiliate'] = 'The affiliate link must be a public http(s) address, or empty.'; $t['affiliate'] = $a ?? ''; }
  $logo = trim((string)($in['logo'] ?? '')); if ($logo === '' ) $t['logo'] = ''; elseif (preg_match('#^/assets/[A-Za-z0-9/_.\-]{1,200}$#', $logo) && strpos($logo, '..') === false) $t['logo'] = $logo; elseif (($l = sec_safe_external_url($logo)) !== null) $t['logo'] = $l; else { $e['logo'] = 'The logo must be an /assets/ path or a public http(s) address, or empty.'; $t['logo'] = ''; }
  $tags = admt_list_field($in['tags'] ?? '', 12, 30, ','); foreach ($tags as $g) if (mb_strlen($g) < 2 || !preg_match('/^[\p{L}\p{N} &.+\-]+$/u', $g)) { $e['tags'] = 'Tags are 2 to 30 letters, numbers, spaces, &, ., + or -.'; break; } $t['tags'] = $tags;
  $t['seo_title'] = admt_text($in['seo_title'] ?? '', 70); $t['seo_description'] = admt_text($in['seo_description'] ?? '', 200);
  return [$e, $t];
}
/** What a tool still needs before it may be published. Empty list = ready. */
function admt_readiness(array $t): array {
  $m = [];
  if (mb_strlen(trim((string)($t['name'] ?? ''))) < 2) $m[] = 'name';
  if (trim((string)($t['category'] ?? '')) === '') $m[] = 'category';
  if (mb_strlen(trim((string)($t['short'] ?? ''))) < 20) $m[] = 'short description';
  if (sec_safe_external_url((string)($t['website'] ?? '')) === null) $m[] = 'website';
  if (empty($t['features'])) $m[] = 'at least one feature';
  return $m;
}
const ADMT_FIELDS = ['name', 'category', 'short', 'description', 'features', 'pros', 'cons', 'use_cases', 'pricing', 'pricing_type', 'website', 'affiliate', 'logo', 'tags', 'seo_title', 'seo_description', 'status'];
function admt_snapshot(array $t): array { $o = []; foreach (ADMT_FIELDS as $f) $o[$f] = $t[$f] ?? (in_array($f, ['features', 'pros', 'cons', 'use_cases', 'tags'], true) ? [] : ''); return $o; }
/** Names of the fields that differ between two tool snapshots. */
function admt_diff(array $before, array $after): array { $o = []; $b = admt_snapshot($before); $a = admt_snapshot($after); foreach (ADMT_FIELDS as $f) if ($b[$f] !== $a[$f]) $o[] = $f; return $o; }

// ---- listing (one code path for every store: filter, sort and paginate in PHP; the catalogue is a few hundred rows) ---------------------
function admt_list(array $tools, array $q): array {
  $needle = mb_strtolower(trim((string)($q['q'] ?? ''))); $status = (string)($q['status'] ?? ''); $cat = (string)($q['category'] ?? ''); $tag = mb_strtolower(trim((string)($q['tag'] ?? '')));
  $sort = in_array($q['sort'] ?? '', ['name', 'updated', 'status', 'category'], true) ? (string)$q['sort'] : 'name'; $dir = (($q['dir'] ?? 'asc') === 'desc') ? -1 : 1;
  $counts = array_fill_keys(ADMT_STATUSES, 0); foreach ($tools as $t) { $s = (string)($t['status'] ?? 'draft'); if (isset($counts[$s])) $counts[$s]++; }
  $rows = [];
  foreach ($tools as $t) {
    $s = (string)($t['status'] ?? 'draft');
    if ($status === 'deleted' ? $s !== 'deleted' : ($s === 'deleted' || ($status !== '' && $status !== 'all' && $s !== $status))) continue;
    if ($cat !== '' && (string)($t['category'] ?? '') !== $cat) continue;
    if ($tag !== '' && !in_array($tag, array_map('mb_strtolower', (array)($t['tags'] ?? [])), true)) continue;
    if (!empty($q['featured']) && !(int)($t['featured_level'] ?? 0)) continue;
    if (!empty($q['incomplete']) && !admt_readiness($t)) continue;
    if ($needle !== '' && strpos(mb_strtolower((string)($t['name'] ?? '') . ' ' . ($t['slug'] ?? '') . ' ' . ($t['category'] ?? '') . ' ' . implode(' ', (array)($t['tags'] ?? []))), $needle) === false) continue;
    $rows[] = $t;
  }
  $key = ['name' => 'name', 'updated' => 'updated_at', 'status' => 'status', 'category' => 'category'][$sort];
  usort($rows, fn($a, $b) => $dir * (strcasecmp((string)($a[$key] ?? ''), (string)($b[$key] ?? '')) ?: strcasecmp((string)$a['slug'], (string)$b['slug'])));
  $total = count($rows); $pages = max(1, (int)ceil($total / ADMT_PER_PAGE)); $page = min($pages, max(1, (int)($q['page'] ?? 1)));
  return ['rows' => array_slice($rows, ($page - 1) * ADMT_PER_PAGE, ADMT_PER_PAGE), 'total' => $total, 'pages' => $pages, 'page' => $page, 'counts' => $counts, 'sort' => $sort, 'dir' => $dir === -1 ? 'desc' : 'asc'];
}

// ---- store contract -----------------------------------------------------------------------------------------------------------------
interface AdmToolStore {
  /** Every tool including deleted ones, each with 'slug', 'status', 'updated_at' and '_version' (latest revision number, 0 when never changed here). */
  public function all(): array;
  public function get(string $slug): ?array;
  public function categories(): array;
  /** Newest first: ['revision_no', 'action', 'changed_by', 'changed_at', 'note', 'fields' => string[]]. */
  public function revisions(string $slug): array;
  /** Insert a new tool. Returns ['ok' => bool, 'error' => 'exists'|'failed']. */
  public function create(array $tool, string $actor, string $note): array;
  /** Replace a tool in one transaction with its revision row. $expectedVersion must equal the stored _version, otherwise ['ok'=>false,'error'=>'conflict']. */
  public function update(string $slug, array $tool, string $action, string $actor, int $expectedVersion, string $note): array;
}

/** In-memory store: the test double, and the source for static previews. Same semantics as the database store (versions, conflicts, revisions). */
final class AdmToolMemoryStore implements AdmToolStore {
  private array $tools = []; private array $revs = []; private array $cats;
  public int $clock = 1700000000;
  public function __construct(array $tools = [], array $categories = []) { $this->cats = $categories; foreach ($tools as $t) { $t += ['status' => 'published', '_version' => 0, 'updated_at' => gmdate('Y-m-d H:i:s', $this->clock)]; $this->tools[$t['slug']] = $t; } }
  public function all(): array { return array_values($this->tools); }
  public function get(string $slug): ?array { return $this->tools[$slug] ?? null; }
  public function categories(): array { return $this->cats; }
  public function revisions(string $slug): array { return array_reverse($this->revs[$slug] ?? []); }
  private function log(string $slug, int $no, string $action, string $actor, string $note, array $fields): void { $this->revs[$slug][] = ['revision_no' => $no, 'action' => $action, 'changed_by' => $actor, 'changed_at' => gmdate('Y-m-d H:i:s', ++$this->clock), 'note' => $note, 'fields' => $fields]; }
  public function create(array $tool, string $actor, string $note): array {
    if (isset($this->tools[$tool['slug']])) return ['ok' => false, 'error' => 'exists'];
    $tool['_version'] = 1; $tool['updated_at'] = gmdate('Y-m-d H:i:s', ++$this->clock); $this->tools[$tool['slug']] = $tool; $this->log($tool['slug'], 1, 'create', $actor, $note, admt_diff([], $tool)); return ['ok' => true];
  }
  public function update(string $slug, array $tool, string $action, string $actor, int $expectedVersion, string $note): array {
    $cur = $this->tools[$slug] ?? null; if ($cur === null) return ['ok' => false, 'error' => 'missing'];
    if ((int)$cur['_version'] !== $expectedVersion) return ['ok' => false, 'error' => 'conflict'];
    $fields = admt_diff($cur, $tool); $new = array_merge($cur, $tool, ['slug' => $slug, '_version' => $expectedVersion + 1, 'updated_at' => gmdate('Y-m-d H:i:s', ++$this->clock)]);
    $this->tools[$slug] = $new; $this->log($slug, $expectedVersion + 1, $action, $actor, $note, $fields); return ['ok' => true];
  }
}

/**
 * MySQL store (tools + categories + adm_tool_revision). NOT verified by database tests in this phase (owner decision): the SQL below follows the
 * existing schema in database/full_mysql_schema.sql and the migration in database/phase8c_tools.sql. The (tool_slug, revision_no) unique key makes
 * two concurrent writers collide, so exactly one wins and the other gets 'conflict'.
 */
final class AdmToolPdoStore implements AdmToolStore {
  public function __construct(private PDO $pdo) {}
  public static function ready(PDO $pdo): bool { foreach (['tools', 'categories', 'adm_tool_revision'] as $t) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { return false; } } return true; }
  private function shape(array $r): array {
    $j = fn($v) => (is_string($v) && ($d = json_decode($v, true)) !== null && is_array($d)) ? array_values(array_map('strval', $d)) : [];
    return ['slug' => (string)$r['slug'], 'name' => (string)$r['name'], 'category' => (string)($r['category'] ?? ''), 'short' => (string)$r['short_description'], 'description' => (string)$r['description'],
      'features' => $j($r['features']), 'pros' => $j($r['pros']), 'cons' => $j($r['cons']), 'use_cases' => $j($r['use_cases']), 'pricing' => (string)$r['pricing'], 'pricing_type' => (string)$r['pricing_type'],
      'website' => (string)$r['website'], 'affiliate' => (string)$r['affiliate'], 'logo' => (string)$r['logo'], 'tags' => $j($r['tags']), 'seo_title' => (string)$r['seo_title'], 'seo_description' => (string)$r['seo_description'],
      'status' => (string)$r['status'], 'featured_level' => (int)$r['featured_level'], 'featured_until' => (string)($r['featured_until'] ?? ''), 'added' => (string)$r['added'], 'updated_at' => (string)$r['updated_at'], '_version' => (int)$r['_version']];
  }
  private const SELECT = "SELECT t.*, c.name AS category, COALESCE((SELECT MAX(r.revision_no) FROM adm_tool_revision r WHERE r.tool_slug = t.slug), 0) AS _version FROM tools t LEFT JOIN categories c ON c.id = t.category_id";
  public function all(): array { return array_map([$this, 'shape'], $this->pdo->query(self::SELECT . ' ORDER BY t.name')->fetchAll(PDO::FETCH_ASSOC)); }
  public function get(string $slug): ?array { $s = $this->pdo->prepare(self::SELECT . ' WHERE t.slug = ?'); $s->execute([$slug]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ? $this->shape($r) : null; }
  public function categories(): array { return array_map('strval', $this->pdo->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)); }
  public function revisions(string $slug): array {
    $s = $this->pdo->prepare('SELECT revision_no, action, changed_by, changed_at, note, before_json, after_json FROM adm_tool_revision WHERE tool_slug = ? ORDER BY revision_no DESC LIMIT 100'); $s->execute([$slug]); $o = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $b = json_decode((string)$r['before_json'], true) ?: []; $a = json_decode((string)$r['after_json'], true) ?: []; $o[] = ['revision_no' => (int)$r['revision_no'], 'action' => (string)$r['action'], 'changed_by' => (string)$r['changed_by'], 'changed_at' => (string)$r['changed_at'], 'note' => (string)$r['note'], 'fields' => admt_diff($b, $a)]; }
    return $o;
  }
  private function catId(string $name): ?int { $s = $this->pdo->prepare('SELECT id FROM categories WHERE name = ?'); $s->execute([$name]); $v = $s->fetchColumn(); return $v === false ? null : (int)$v; }
  private function bind(array $t): array {
    $j = fn($v) => json_encode(array_values($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [':name' => $t['name'], ':cid' => $this->catId((string)$t['category']), ':short' => $t['short'], ':descr' => $t['description'], ':features' => $j($t['features']), ':pros' => $j($t['pros']), ':cons' => $j($t['cons']), ':uc' => $j($t['use_cases']),
      ':pricing' => $t['pricing'], ':website' => $t['website'], ':affiliate' => $t['affiliate'], ':tags' => $j($t['tags']), ':logo' => $t['logo'], ':ptype' => $t['pricing_type'], ':seot' => $t['seo_title'], ':seod' => $t['seo_description'], ':status' => $t['status']];
  }
  private function revision(string $slug, int $no, string $action, string $actor, ?array $before, array $after, string $note): void {
    $this->pdo->prepare('INSERT INTO adm_tool_revision (tool_slug, revision_no, action, changed_by, changed_at, before_json, after_json, note) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$slug, $no, $action, substr($actor, 0, 40), gmdate('Y-m-d H:i:s'), $before === null ? null : json_encode(admt_snapshot($before), JSON_UNESCAPED_UNICODE), json_encode(admt_snapshot($after), JSON_UNESCAPED_UNICODE), mb_substr($note, 0, 500)]);
  }
  public function create(array $tool, string $actor, string $note): array {
    try {
      $this->pdo->beginTransaction();
      $this->pdo->prepare("INSERT INTO tools (slug, name, category_id, short_description, description, features, pros, cons, use_cases, pricing, website, affiliate, added, tags, logo, pricing_type, seo_title, seo_description, status) VALUES (:slug,:name,:cid,:short,:descr,:features,:pros,:cons,:uc,:pricing,:website,:affiliate,:added,:tags,:logo,:ptype,:seot,:seod,:status)")
        ->execute([':slug' => $tool['slug'], ':added' => gmdate('Y-m-d')] + $this->bind($tool));
      $this->revision($tool['slug'], 1, 'create', $actor, null, $tool, $note); $this->pdo->commit(); return ['ok' => true];
    } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); return ['ok' => false, 'error' => (string)$e->getCode() === '23000' ? 'exists' : 'failed']; }
  }
  public function update(string $slug, array $tool, string $action, string $actor, int $expectedVersion, string $note): array {
    try {
      $this->pdo->beginTransaction(); $cur = $this->get($slug);
      if ($cur === null) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'missing']; }
      if ($cur['_version'] !== $expectedVersion) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'conflict']; }
      $this->pdo->prepare('UPDATE tools SET name=:name, category_id=:cid, short_description=:short, description=:descr, features=:features, pros=:pros, cons=:cons, use_cases=:uc, pricing=:pricing, website=:website, affiliate=:affiliate, tags=:tags, logo=:logo, pricing_type=:ptype, seo_title=:seot, seo_description=:seod, status=:status WHERE slug=:slug')
        ->execute([':slug' => $slug] + $this->bind($tool));
      $this->revision($slug, $expectedVersion + 1, $action, $actor, $cur, $tool, $note); $this->pdo->commit(); return ['ok' => true];
    } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); return ['ok' => false, 'error' => (string)$e->getCode() === '23000' ? 'conflict' : 'failed']; }
  }
}

/**
 * Apply one management action. Order: known action, permission, input, existence, state rule, stale-edit check, write, audit.
 * Returns ['ok', 'code' (HTTP status), 'msg' (key of admt_messages()), 'slug', 'errors']. $audit(action, slug, summary) records the audit row.
 */
function admt_apply(AdmToolStore $s, array $user, string $act, array $post, callable $audit): array {
  $fail = fn(int $c, string $m, array $e = [], ?string $slug = null) => ['ok' => false, 'code' => $c, 'msg' => $m, 'slug' => $slug, 'errors' => $e + ['_' => $m]];
  $perm = admt_action_perm($act); if ($perm === null) return $fail(400, 'Unknown action.');
  if (!function_exists('adm_can') || !adm_can((string)($user['role'] ?? ''), $perm)) { $audit('tool_denied', (string)($post['slug'] ?? ''), $act); return $fail(403, 'Your role may not do this.'); }
  $actor = (string)($user['username'] ?? 'unknown'); $note = admt_text($post['note'] ?? '', 500);
  if ($act === 'create') {
    [$e, $t] = admt_validate($post, $s->categories()); if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $t['slug']);
    if ($s->get($t['slug']) !== null) return $fail(409, 'That address is already used by another tool (including deleted ones).', ['slug' => 'This address is already in use.'], $t['slug']);
    $t['status'] = 'draft'; $r = $s->create($t, $actor, $note);
    if (!$r['ok']) return $fail($r['error'] === 'exists' ? 409 : 500, $r['error'] === 'exists' ? 'That address is already in use.' : 'The tool could not be saved.', [], $t['slug']);
    $audit('tool_create', $t['slug'], 'created draft'); return ['ok' => true, 'code' => 303, 'msg' => 'created', 'slug' => $t['slug'], 'errors' => []];
  }
  $slug = admt_text($post['slug'] ?? '', 120); $cur = admt_valid_slug($slug) ? $s->get($slug) : null; if ($cur === null) return $fail(404, 'Tool not found.', [], $slug);
  $expected = (int)($post['version'] ?? -1); $status = (string)$cur['status'];
  if ($act === 'edit') {
    if ($status === 'deleted') return $fail(409, 'A deleted tool cannot be edited. Restore it first.', [], $slug);
    [$e, $t] = admt_validate($post, $s->categories(), $cur); if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $slug);
    $t['status'] = $status; $act2 = 'edit'; $msg = 'edited';
  } elseif ($act === 'restore') {
    if ($status !== 'deleted') return $fail(409, 'Only a deleted tool can be restored.', [], $slug);
    $wasPublished = false; foreach ($s->revisions($slug) as $rv) if ($rv['action'] === 'publish') { $wasPublished = true; break; }
    $t = $cur; $t['status'] = $wasPublished ? 'unpublished' : 'draft'; $act2 = 'restore'; $msg = 'restored';   // never straight to published
  } else {
    if ($act === 'delete' && ($post['confirm'] ?? '') !== '1') return $fail(422, 'Tick the confirmation box to move a tool to the trash.', [], $slug);
    $to = admt_transition($act, $status); if ($to === null) return $fail(409, "A {$status} tool cannot be changed that way.", [], $slug);
    if ($act === 'publish' && ($miss = admt_readiness($cur))) return $fail(422, 'Not ready to publish. Missing: ' . implode(', ', $miss) . '.', ['_' => 'Missing: ' . implode(', ', $miss)], $slug);
    $t = $cur; $t['status'] = $to; $act2 = $act; $msg = ['publish' => 'published', 'unpublish' => 'unpublished', 'delete' => 'deleted'][$act];
  }
  $r = $s->update($slug, $t, $act2, $actor, $expected, $note);
  if (!$r['ok']) { $c = $r['error'] === 'conflict' ? 409 : ($r['error'] === 'missing' ? 404 : 500); return $fail($c, $r['error'] === 'conflict' ? 'This tool was changed by someone else (or your form is out of date). Reload and try again.' : 'The change could not be saved.', [], $slug); }
  $audit('tool_' . $act2, $slug, implode(',', admt_diff($cur, $t)) ?: 'status'); return ['ok' => true, 'code' => 303, 'msg' => $msg, 'slug' => $slug, 'errors' => []];
}
