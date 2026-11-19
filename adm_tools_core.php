<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8C (M1-M3): AI tool management core. Pure logic + a store interface, so every rule is testable with no database.
// MySQL `tools` is authoritative for managed tools; data/tools.json is a read-only seed and fallback that this module never writes.
// Lifecycle uses the existing tools.status column: draft | published | unpublished | deleted (soft delete only, no hard delete in 8C).
// Permissions (owner decision D2): owner and editor create, edit and unpublish; only the owner publishes, deletes and restores. Enforced here, server side.
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/adm_tools_overlay.php';
require_once __DIR__ . '/adm_tools_ops.php';

const ADMT_STATUSES = ['draft', 'published', 'unpublished', 'deleted'];
const ADMT_PRICING = ['Free', 'Freemium', 'Paid', 'Varies'];
const ADMT_PER_PAGE = 25;

/** Which Control Center permission each action needs. create/edit/unpublish = 'tools' (owner, editor); publish/delete/restore = 'tools_publish' (owner). */
function admt_action_perm(string $act): ?string {
  static $ops = ['category_add', 'category_rename', 'category_delete', 'tag_rename', 'bulk_review', 'bulk_apply', 'submission_approve', 'submission_reject', 'submission_needs_info', 'feature_add', 'feature_cancel'];   // M4-M6: owner only
  if (in_array($act, $ops, true)) return 'tools_publish';
  return ['create' => 'tools', 'edit' => 'tools', 'unpublish' => 'tools', 'publish' => 'tools_publish', 'delete' => 'tools_publish', 'restore' => 'tools_publish'][$act] ?? null;
}
/** Allowed status changes. Restore never goes straight to published: back to draft or unpublished, the owner publishes explicitly. */
function admt_transition(string $act, string $from): ?string {
  $m = ['publish' => ['draft' => 'published', 'unpublished' => 'published'], 'unpublish' => ['published' => 'unpublished'],
        'delete' => ['draft' => 'deleted', 'published' => 'deleted', 'unpublished' => 'deleted']];
  return $m[$act][$from] ?? null;
}
function admt_messages(): array {
  return ['created' => 'Tool created as a draft.', 'edited' => 'Changes saved.', 'published' => 'Tool published.', 'unpublished' => 'Tool unpublished.', 'deleted' => 'Tool moved to the trash.', 'restored' => 'Tool restored. It is not published; review it and publish when ready.',
    'category_added' => 'Category added.', 'category_renamed' => 'Category renamed everywhere it is used.', 'category_deleted' => 'Empty category deleted.', 'tag_renamed' => 'Tag renamed on every active tool that used it.', 'bulk' => 'Bulk change applied.',
    'sub_approved' => 'Submission approved. A DRAFT tool was created from it; the owner publishes it after review.', 'sub_rejected' => 'Submission rejected and the reason saved.', 'sub_info' => 'Marked as needing more information; it stays pending.', 'featured' => 'Featured placement added.', 'unfeatured' => 'Featured placement cancelled.'];
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
function admt_validate(array $in, array $categories, ?array $existing = null, array $locked = []): array {
  $e = []; $t = []; $lk = array_flip($locked);
  $t['name'] = admt_text($in['name'] ?? '', 120); if (mb_strlen($t['name']) < 2) $e['name'] = 'Enter a name of 2 to 120 characters.';
  if ($existing === null) {
    $slug = admt_text($in['slug'] ?? '', 120); if ($slug === '') $slug = admt_slugify($t['name']);
    if (!admt_valid_slug($slug)) $e['slug'] = 'Use 2 to 120 lowercase letters, numbers and hyphens. The address cannot be changed later.';
    elseif (in_array($slug, admt_reserved_slugs(), true)) $e['slug'] = 'This address already belongs to a catalogue or editorial record and cannot be reused.';
    $t['slug'] = $slug;
  } else { $t['slug'] = (string)$existing['slug']; }
  if (isset($lk['category']) && $existing !== null) $t['category'] = (string)$existing['category'];   // controlled by the editorial classification: kept as stored
  else { $t['category'] = admt_text($in['category'] ?? '', 120); if (!in_array($t['category'], $categories, true)) $e['category'] = 'Choose one of the public categories.'; }
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
  if ($existing !== null) foreach ($locked as $f) if ($f !== 'category' && array_key_exists($f, $existing)) { $t[$f] = $existing[$f]; unset($e[$f]); }   // overlay-controlled: edits are ignored, the stored value is kept
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
const ADMT_FIELDS = ['name', 'category', 'short', 'description', 'features', 'pros', 'cons', 'use_cases', 'pricing', 'pricing_type', 'website', 'affiliate', 'logo', 'tags', 'seo_title', 'seo_description', 'status', 'featured_level', 'featured_until'];
function admt_snapshot(array $t): array { $o = []; foreach (ADMT_FIELDS as $f) { $v = $t[$f] ?? (in_array($f, ['features', 'pros', 'cons', 'use_cases', 'tags'], true) ? [] : ($f === 'featured_level' ? 0 : '')); $o[$f] = $f === 'featured_level' ? (int)$v : ($f === 'featured_until' ? (string)$v : $v); } return $o; }
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
    if ($cat !== '' && admt_effective_category($t) !== $cat) continue;   // the category visitors see
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
  /** All-or-nothing: $items = [['slug','tool','expected'], ...]. Any conflict or failure writes nothing. Returns ['ok', 'error', 'slug']. */
  public function bulkUpdate(array $items, string $action, string $actor, string $note): array;
  /** Category name => number of tools (any status) that use it. */
  public function categoryCounts(): array;
  public function addCategory(string $name): array;
  public function renameCategory(string $old, string $new): array;
  public function deleteCategory(string $name): array;
  /** Newest first; each row: id, name, category, website, short_desc, plan, price, currency, status, payment_status, created_at, decision, review_note, reviewed_by, reviewed_at, tool_slug. */
  public function submissions(): array;
  public function submission(int $id): ?array;
  /** decision approved|rejected|needs_info. $tool is the DRAFT to create on approval (null otherwise). Refuses a submission that is no longer pending. */
  public function decideSubmission(int $id, string $decision, ?array $tool, string $actor, string $note): array;
  /** Placement rows, newest first: id, tool_slug, level, starts_on, ends_on, source, status, note, created_by, created_at. */
  public function placements(?string $slug = null): array;
  public function addPlacement(string $slug, int $level, string $startsOn, string $endsOn, string $actor, string $note, int $expectedVersion): array;
  public function cancelPlacement(int $id, string $actor, string $note, int $expectedVersion): array;
}

/** Highest active level and its latest end date for today (the values mirrored into tools.featured_level / featured_until). */
function admt_feature_effective(array $rows, string $today): array {
  $lvl = 0; $until = '';
  foreach ($rows as $r) { if (($r['status'] ?? '') !== 'active' || $r['starts_on'] > $today || $r['ends_on'] < $today) continue; if ((int)$r['level'] > $lvl) { $lvl = (int)$r['level']; $until = $r['ends_on']; } elseif ((int)$r['level'] === $lvl && $r['ends_on'] > $until) $until = $r['ends_on']; }
  return [$lvl, $until];
}

/** In-memory store: the test double, and the source for static previews. Same semantics as the database store (versions, conflicts, revisions, all-or-nothing). */
final class AdmToolMemoryStore implements AdmToolStore {
  private array $tools = []; private array $revs = []; private array $cats; private array $subs = []; private array $places = [];
  public int $clock = 1700000000; public string $today = '2026-11-20';
  public function __construct(array $tools = [], array $categories = [], array $submissions = []) {
    $this->cats = $categories; foreach ($submissions as $s) $this->subs[(int)$s['id']] = $s + ['status' => 'pending', 'payment_status' => 'not_required', 'plan' => 'free', 'price' => 0, 'currency' => 'EUR', 'created_at' => '2026-11-01 09:00:00', 'decision' => '', 'review_note' => '', 'reviewed_by' => '', 'reviewed_at' => '', 'tool_slug' => ''];
    foreach ($tools as $t) { $t += ['status' => 'published', '_version' => 0, 'featured_level' => 0, 'featured_until' => '', 'updated_at' => gmdate('Y-m-d H:i:s', $this->clock)]; $this->tools[$t['slug']] = $t; }
  }
  public function all(): array { return array_values($this->tools); }
  public function get(string $slug): ?array { return $this->tools[$slug] ?? null; }
  public function categories(): array { return $this->cats; }
  public function revisions(string $slug): array { return array_reverse($this->revs[$slug] ?? []); }
  private function log(string $slug, int $no, string $action, string $actor, string $note, array $fields): void { $this->revs[$slug][] = ['revision_no' => $no, 'action' => $action, 'changed_by' => $actor, 'changed_at' => gmdate('Y-m-d H:i:s', ++$this->clock), 'note' => $note, 'fields' => $fields]; }
  public function create(array $tool, string $actor, string $note): array {
    if (isset($this->tools[$tool['slug']])) return ['ok' => false, 'error' => 'exists'];
    if (($tool['category'] ?? '') !== '' && !in_array($tool['category'], $this->cats, true)) $this->cats[] = $tool['category'];
    $tool += ['featured_level' => 0, 'featured_until' => '']; $tool['_version'] = 1; $tool['updated_at'] = gmdate('Y-m-d H:i:s', ++$this->clock); $this->tools[$tool['slug']] = $tool; $this->log($tool['slug'], 1, 'create', $actor, $note, admt_diff([], $tool)); return ['ok' => true];
  }
  public function update(string $slug, array $tool, string $action, string $actor, int $expectedVersion, string $note): array {
    $cur = $this->tools[$slug] ?? null; if ($cur === null) return ['ok' => false, 'error' => 'missing'];
    if ((int)$cur['_version'] !== $expectedVersion) return ['ok' => false, 'error' => 'conflict'];
    $fields = admt_diff($cur, $tool); $new = array_merge($cur, $tool, ['slug' => $slug, '_version' => $expectedVersion + 1, 'updated_at' => gmdate('Y-m-d H:i:s', ++$this->clock)]);
    $this->tools[$slug] = $new; $this->log($slug, $expectedVersion + 1, $action, $actor, $note, $fields); return ['ok' => true];
  }
  public function bulkUpdate(array $items, string $action, string $actor, string $note): array {
    foreach ($items as $i) { $c = $this->tools[$i['slug']] ?? null; if ($c === null) return ['ok' => false, 'error' => 'missing', 'slug' => $i['slug']]; if ((int)$c['_version'] !== (int)$i['expected']) return ['ok' => false, 'error' => 'conflict', 'slug' => $i['slug']]; }
    foreach ($items as $i) $this->update($i['slug'], $i['tool'], $action, $actor, (int)$i['expected'], $note);
    return ['ok' => true];
  }
  public function categoryCounts(): array { $o = array_fill_keys($this->cats, 0); foreach ($this->tools as $t) if (isset($o[$t['category'] ?? ''])) $o[$t['category']]++; return $o; }
  private function hasCat(string $n): bool { foreach ($this->cats as $c) if (mb_strtolower($c) === mb_strtolower($n)) return true; return false; }
  public function addCategory(string $name): array { if ($this->hasCat($name)) return ['ok' => false, 'error' => 'exists']; $this->cats[] = $name; sort($this->cats); return ['ok' => true]; }
  public function renameCategory(string $old, string $new): array {
    if (!in_array($old, $this->cats, true)) return ['ok' => false, 'error' => 'missing']; if (mb_strtolower($old) !== mb_strtolower($new) && $this->hasCat($new)) return ['ok' => false, 'error' => 'exists'];
    $this->cats = array_map(fn($c) => $c === $old ? $new : $c, $this->cats); foreach ($this->tools as $k => $t) if (($t['category'] ?? '') === $old) $this->tools[$k]['category'] = $new; sort($this->cats); return ['ok' => true];
  }
  public function deleteCategory(string $name): array { if (!in_array($name, $this->cats, true)) return ['ok' => false, 'error' => 'missing']; if (($this->categoryCounts()[$name] ?? 0) > 0) return ['ok' => false, 'error' => 'in_use']; $this->cats = array_values(array_diff($this->cats, [$name])); return ['ok' => true]; }
  public function submissions(): array { return array_reverse(array_values($this->subs)); }
  public function submission(int $id): ?array { return $this->subs[$id] ?? null; }
  public function decideSubmission(int $id, string $decision, ?array $tool, string $actor, string $note): array {
    $s = $this->subs[$id] ?? null; if ($s === null) return ['ok' => false, 'error' => 'missing']; if ($s['status'] !== 'pending') return ['ok' => false, 'error' => 'decided'];
    if ($tool !== null) { $r = $this->create($tool, $actor, 'From submission #' . $id); if (!$r['ok']) return $r; $this->subs[$id]['tool_slug'] = $tool['slug']; }
    $this->subs[$id]['status'] = $decision === 'approved' ? 'approved' : ($decision === 'rejected' ? 'rejected' : 'pending'); $this->subs[$id]['decision'] = $decision; $this->subs[$id]['review_note'] = $note; $this->subs[$id]['reviewed_by'] = $actor; $this->subs[$id]['reviewed_at'] = gmdate('Y-m-d H:i:s', ++$this->clock);
    return ['ok' => true, 'slug' => $tool['slug'] ?? null];
  }
  public function placements(?string $slug = null): array { return array_values(array_filter(array_reverse($this->places), fn($p) => $slug === null || $p['tool_slug'] === $slug)); }
  private function mirror(string $slug, string $action, string $actor, string $note, int $expected): array {
    $cur = $this->tools[$slug]; [$lvl, $until] = admt_feature_effective($this->placements($slug), $this->today);
    return $this->update($slug, ['featured_level' => $lvl, 'featured_until' => $until], $action, $actor, $expected, $note);
  }
  public function addPlacement(string $slug, int $level, string $startsOn, string $endsOn, string $actor, string $note, int $expectedVersion): array {
    $c = $this->tools[$slug] ?? null; if ($c === null) return ['ok' => false, 'error' => 'missing']; if ((int)$c['_version'] !== $expectedVersion) return ['ok' => false, 'error' => 'conflict'];
    $this->places[] = ['id' => count($this->places) + 1, 'tool_slug' => $slug, 'level' => $level, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'source' => 'manual', 'status' => 'active', 'note' => $note, 'created_by' => $actor, 'created_at' => gmdate('Y-m-d H:i:s', ++$this->clock)];
    return $this->mirror($slug, 'feature', $actor, $note, $expectedVersion);
  }
  public function cancelPlacement(int $id, string $actor, string $note, int $expectedVersion): array {
    foreach ($this->places as $k => $p) if ($p['id'] === $id) {
      $c = $this->tools[$p['tool_slug']] ?? null; if ($c === null || (int)$c['_version'] !== $expectedVersion) return ['ok' => false, 'error' => 'conflict']; if ($p['status'] !== 'active') return ['ok' => false, 'error' => 'not_active'];
      $this->places[$k]['status'] = 'cancelled'; return $this->mirror($p['tool_slug'], 'feature', $actor, $note !== '' ? $note : 'placement cancelled', $expectedVersion);
    }
    return ['ok' => false, 'error' => 'missing'];
  }
}

/**
 * MySQL store (tools + categories + submissions + adm_* tables). NOT verified by database tests in this phase (owner decision): the SQL follows the
 * existing schema in database/full_mysql_schema.sql and the migration in database/phase8c_tools.sql. The (tool_slug, revision_no) unique key makes
 * two concurrent writers collide, so exactly one wins and the other gets 'conflict'.
 */
final class AdmToolPdoStore implements AdmToolStore {
  public function __construct(private PDO $pdo) {}
  public static function ready(PDO $pdo): bool { foreach (['tools', 'categories', 'submissions', 'adm_tool_revision', 'adm_tool_feature', 'adm_submission_review'] as $t) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { return false; } } return true; }
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
  private function catId(string $name): ?int {
    $s = $this->pdo->prepare('SELECT id FROM categories WHERE name = ?'); $s->execute([$name]); $v = $s->fetchColumn(); if ($v !== false) return (int)$v; if ($name === '') return null;
    $slug = admt_slugify($name); $this->pdo->prepare('INSERT INTO categories (slug, name, description) VALUES (?,?,NULL)')->execute([$slug !== '' ? $slug : 'category', $name]); return (int)$this->pdo->lastInsertId();   // a public category that has no database row yet (inside the caller's transaction)
  }
  private function bind(array $t): array {
    $j = fn($v) => json_encode(array_values($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [':name' => $t['name'], ':cid' => $this->catId((string)$t['category']), ':short' => $t['short'], ':descr' => $t['description'], ':features' => $j($t['features']), ':pros' => $j($t['pros']), ':cons' => $j($t['cons']), ':uc' => $j($t['use_cases']),
      ':pricing' => $t['pricing'], ':website' => $t['website'], ':affiliate' => $t['affiliate'], ':tags' => $j($t['tags']), ':logo' => $t['logo'], ':ptype' => $t['pricing_type'], ':seot' => $t['seo_title'], ':seod' => $t['seo_description'], ':status' => $t['status']];
  }
  private function revision(string $slug, int $no, string $action, string $actor, ?array $before, array $after, string $note): void {
    $this->pdo->prepare('INSERT INTO adm_tool_revision (tool_slug, revision_no, action, changed_by, changed_at, before_json, after_json, note) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$slug, $no, $action, substr($actor, 0, 40), gmdate('Y-m-d H:i:s'), $before === null ? null : json_encode(admt_snapshot($before), JSON_UNESCAPED_UNICODE), json_encode(admt_snapshot($after), JSON_UNESCAPED_UNICODE), mb_substr($note, 0, 500)]);
  }
  /** Inside the caller's transaction. */
  private function insertTool(array $tool, string $actor, string $note): void {
    $this->pdo->prepare("INSERT INTO tools (slug, name, category_id, short_description, description, features, pros, cons, use_cases, pricing, website, affiliate, added, tags, logo, pricing_type, seo_title, seo_description, status) VALUES (:slug,:name,:cid,:short,:descr,:features,:pros,:cons,:uc,:pricing,:website,:affiliate,:added,:tags,:logo,:ptype,:seot,:seod,:status)")
      ->execute([':slug' => $tool['slug'], ':added' => gmdate('Y-m-d')] + $this->bind($tool));
    $this->revision($tool['slug'], 1, 'create', $actor, null, $tool, $note);
  }
  /** Inside the caller's transaction. Returns an error string or null. */
  private function updateTool(string $slug, array $tool, string $action, string $actor, int $expected, string $note): ?string {
    $cur = $this->get($slug); if ($cur === null) return 'missing'; if ($cur['_version'] !== $expected) return 'conflict';
    $this->pdo->prepare('UPDATE tools SET name=:name, category_id=:cid, short_description=:short, description=:descr, features=:features, pros=:pros, cons=:cons, use_cases=:uc, pricing=:pricing, website=:website, affiliate=:affiliate, tags=:tags, logo=:logo, pricing_type=:ptype, seo_title=:seot, seo_description=:seod, status=:status WHERE slug=:slug')
      ->execute([':slug' => $slug] + $this->bind($tool));
    $this->revision($slug, $expected + 1, $action, $actor, $cur, $tool, $note); return null;
  }
  private function tx(callable $f): array {
    try { $this->pdo->beginTransaction(); $r = $f(); if (!$r['ok']) { $this->pdo->rollBack(); return $r; } $this->pdo->commit(); return $r; }
    catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); return ['ok' => false, 'error' => (string)$e->getCode() === '23000' ? 'conflict' : 'failed']; }
  }
  public function create(array $tool, string $actor, string $note): array {
    $r = $this->tx(function () use ($tool, $actor, $note) { $this->insertTool($tool, $actor, $note); return ['ok' => true]; });
    return $r['ok'] || $r['error'] === 'failed' ? $r : ['ok' => false, 'error' => 'exists'];
  }
  public function update(string $slug, array $tool, string $action, string $actor, int $expectedVersion, string $note): array {
    return $this->tx(function () use ($slug, $tool, $action, $actor, $expectedVersion, $note) { $e = $this->updateTool($slug, $tool, $action, $actor, $expectedVersion, $note); return $e ? ['ok' => false, 'error' => $e] : ['ok' => true]; });
  }
  public function bulkUpdate(array $items, string $action, string $actor, string $note): array {
    return $this->tx(function () use ($items, $action, $actor, $note) { foreach ($items as $i) { $e = $this->updateTool($i['slug'], $i['tool'], $action, $actor, (int)$i['expected'], $note); if ($e) return ['ok' => false, 'error' => $e, 'slug' => $i['slug']]; } return ['ok' => true]; });
  }
  public function categoryCounts(): array {
    $o = []; foreach ($this->pdo->query('SELECT c.name, COUNT(t.id) AS n FROM categories c LEFT JOIN tools t ON t.category_id = c.id GROUP BY c.id, c.name ORDER BY c.name')->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(string)$r['name']] = (int)$r['n']; return $o;
  }
  private static function catSlug(string $n): string { $s = admt_slugify($n); return $s !== '' ? $s : 'category'; }
  public function addCategory(string $name): array {
    return $this->tx(function () use ($name) { $q = $this->pdo->prepare('SELECT 1 FROM categories WHERE LOWER(name) = LOWER(?) OR slug = ?'); $q->execute([$name, self::catSlug($name)]); if ($q->fetchColumn()) return ['ok' => false, 'error' => 'exists']; $this->pdo->prepare('INSERT INTO categories (slug, name, description) VALUES (?,?,NULL)')->execute([self::catSlug($name), $name]); return ['ok' => true]; });
  }
  public function renameCategory(string $old, string $new): array {
    return $this->tx(function () use ($old, $new) {
      $id = $this->catId($old); if ($id === null) return ['ok' => false, 'error' => 'missing'];
      $q = $this->pdo->prepare('SELECT 1 FROM categories WHERE (LOWER(name) = LOWER(?) OR slug = ?) AND id <> ?'); $q->execute([$new, self::catSlug($new), $id]); if ($q->fetchColumn()) return ['ok' => false, 'error' => 'exists'];
      $this->pdo->prepare('UPDATE categories SET name = ?, slug = ? WHERE id = ?')->execute([$new, self::catSlug($new), $id]); return ['ok' => true];
    });
  }
  public function deleteCategory(string $name): array {
    return $this->tx(function () use ($name) { $id = $this->catId($name); if ($id === null) return ['ok' => false, 'error' => 'missing']; $q = $this->pdo->prepare('SELECT COUNT(*) FROM tools WHERE category_id = ?'); $q->execute([$id]); if ((int)$q->fetchColumn() > 0) return ['ok' => false, 'error' => 'in_use']; $this->pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]); return ['ok' => true]; });
  }
  private const SUBSEL = 'SELECT s.id, s.name, s.category, s.website, s.short_desc, s.plan, s.price, s.currency, s.status, s.payment_status, s.created_at, COALESCE(r.decision, \'\') AS decision, COALESCE(r.note, \'\') AS review_note, COALESCE(r.reviewed_by, \'\') AS reviewed_by, COALESCE(r.reviewed_at, \'\') AS reviewed_at, COALESCE(r.tool_slug, \'\') AS tool_slug FROM submissions s LEFT JOIN adm_submission_review r ON r.submission_id = s.id';
  public function submissions(): array { return $this->pdo->query(self::SUBSEL . ' ORDER BY s.id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC); }
  public function submission(int $id): ?array { $q = $this->pdo->prepare(self::SUBSEL . ' WHERE s.id = ?'); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
  public function decideSubmission(int $id, string $decision, ?array $tool, string $actor, string $note): array {
    return $this->tx(function () use ($id, $decision, $tool, $actor, $note) {
      $q = $this->pdo->prepare('SELECT status FROM submissions WHERE id = ? FOR UPDATE'); $q->execute([$id]); $st = $q->fetchColumn(); if ($st === false) return ['ok' => false, 'error' => 'missing']; if ($st !== 'pending') return ['ok' => false, 'error' => 'decided'];
      if ($tool !== null) $this->insertTool($tool, $actor, 'From submission #' . $id);
      $this->pdo->prepare('UPDATE submissions SET status = ? WHERE id = ?')->execute([$decision === 'approved' ? 'approved' : ($decision === 'rejected' ? 'rejected' : 'pending'), $id]);
      $this->pdo->prepare('INSERT INTO adm_submission_review (submission_id, decision, tool_slug, reviewed_by, reviewed_at, note) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE decision = VALUES(decision), tool_slug = VALUES(tool_slug), reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at), note = VALUES(note)')
        ->execute([$id, $decision, $tool['slug'] ?? null, substr($actor, 0, 40), gmdate('Y-m-d H:i:s'), mb_substr($note, 0, 1000)]);
      return ['ok' => true, 'slug' => $tool['slug'] ?? null];
    });
  }
  public function placements(?string $slug = null): array {
    $q = $this->pdo->prepare('SELECT id, tool_slug, level, starts_on, ends_on, source, status, note, created_by, created_at FROM adm_tool_feature' . ($slug !== null ? ' WHERE tool_slug = ?' : '') . ' ORDER BY id DESC LIMIT 500'); $q->execute($slug !== null ? [$slug] : []);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'level' => (int)$r['level']] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
  }
  private function mirror(string $slug, string $action, string $actor, string $note, int $expected): array {
    $cur = $this->get($slug); if ($cur === null) return ['ok' => false, 'error' => 'missing']; if ($cur['_version'] !== $expected) return ['ok' => false, 'error' => 'conflict'];
    [$lvl, $until] = admt_feature_effective($this->placements($slug), gmdate('Y-m-d'));
    $this->pdo->prepare('UPDATE tools SET featured_level = ?, featured_until = ? WHERE slug = ?')->execute([$lvl, $until !== '' ? $until : null, $slug]);
    $this->revision($slug, $expected + 1, $action, $actor, $cur, ['featured_level' => $lvl, 'featured_until' => $until] + $cur, $note); return ['ok' => true];
  }
  public function addPlacement(string $slug, int $level, string $startsOn, string $endsOn, string $actor, string $note, int $expectedVersion): array {
    return $this->tx(function () use ($slug, $level, $startsOn, $endsOn, $actor, $note, $expectedVersion) {
      $cur = $this->get($slug); if ($cur === null) return ['ok' => false, 'error' => 'missing']; if ($cur['_version'] !== $expectedVersion) return ['ok' => false, 'error' => 'conflict'];
      $this->pdo->prepare("INSERT INTO adm_tool_feature (tool_slug, level, starts_on, ends_on, source, status, note, created_by, created_at) VALUES (?,?,?,?, 'manual', 'active', ?,?,?)")->execute([$slug, $level, $startsOn, $endsOn, mb_substr($note, 0, 500), substr($actor, 0, 40), gmdate('Y-m-d H:i:s')]);
      return $this->mirror($slug, 'feature', $actor, $note, $expectedVersion);
    });
  }
  public function cancelPlacement(int $id, string $actor, string $note, int $expectedVersion): array {
    return $this->tx(function () use ($id, $actor, $note, $expectedVersion) {
      $q = $this->pdo->prepare('SELECT tool_slug, status FROM adm_tool_feature WHERE id = ? FOR UPDATE'); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); if (!$r) return ['ok' => false, 'error' => 'missing']; if ($r['status'] !== 'active') return ['ok' => false, 'error' => 'not_active'];
      $this->pdo->prepare("UPDATE adm_tool_feature SET status = 'cancelled' WHERE id = ?")->execute([$id]);
      return $this->mirror((string)$r['tool_slug'], 'feature', $actor, $note !== '' ? $note : 'placement cancelled', $expectedVersion);
    });
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
  if (function_exists('admt_apply_ops') && in_array($act, ADMT_OPS, true)) return admt_apply_ops($s, $user, $act, $post, $audit);
  if ($act === 'create') {
    [$e, $t] = admt_validate($post, admt_public_categories()); if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $t['slug']);
    if ($s->get($t['slug']) !== null) return $fail(409, 'That address is already used by another tool (including deleted ones).', ['slug' => 'This address is already in use.'], $t['slug']);
    $t['status'] = 'draft'; $r = $s->create($t, $actor, $note);
    if (!$r['ok']) return $fail($r['error'] === 'exists' ? 409 : 500, $r['error'] === 'exists' ? 'That address is already in use.' : 'The tool could not be saved.', [], $t['slug']);
    $audit('tool_create', $t['slug'], 'created draft'); return ['ok' => true, 'code' => 303, 'msg' => 'created', 'slug' => $t['slug'], 'errors' => []];
  }
  $slug = admt_text($post['slug'] ?? '', 120); $cur = admt_valid_slug($slug) ? $s->get($slug) : null; if ($cur === null) return $fail(404, 'Tool not found.', [], $slug);
  $expected = (int)($post['version'] ?? -1); $status = (string)$cur['status'];
  if ($act === 'edit') {
    if ($status === 'deleted') return $fail(409, 'A deleted tool cannot be edited. Restore it first.', [], $slug);
    [$e, $t] = admt_validate($post, admt_public_categories(), $cur, admt_overlay($slug)['locked']); if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $slug);
    $t += $cur; $t['status'] = $status; $act2 = 'edit'; $msg = 'edited';
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
