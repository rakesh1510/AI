<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * AI Universe service layer (Phase 7): the ONLY write path to the uni_* provenance ledger and the ONLY public read path.
 *
 * PRIMARY ENFORCEMENT of the cross-table rules (database/phase7_triggers.sql is optional defence in depth; everything here works without it):
 *   - maker != checker (the reviewer is never the proposer), reviewer holds role `verifier`, proposer holds `editor` (or `owner`)
 *   - every actor is a real, active crm_users row, re-read from the database on each call (the shared admin password session has no user id and can never write)
 *   - events and edges need >= 1 source with a non-empty quote and a valid retrieved date before they can be verified (releases carry one mandatory source)
 *   - origin import can never be verified; it must become a NEW version (origin human or ai_assisted) that a different person verifies; ai_assisted rows are verified only by a human review
 *   - deterministic second-reviewer sampling: the first 25 items that ever receive an accept are always sampled, afterwards 20% by crc32(type:id)
 *   - no verified row is ever changed except to become superseded by a newly verified version; sources and parents of a verified row are frozen
 *   - duplicates (same entity lineage + normalised date + normalised title) are FLAGGED, never merged
 * Every function takes a PDO and the acting user id and returns [bool ok, mixed result|string errorCode]. Prepared statements only: no value is ever concatenated into SQL
 * (table names come from a fixed whitelist, LIMIT/OFFSET values are bound integers).
 * Public reads: WHERE state = 'verified' AND is_current = 1 for the row AND for every entity it references. They never select proposer, reviewer, review notes, origin or edge notes.
 */
require_once __DIR__ . '/crm_core.php';

const UNI_SAMPLE_FIRST = 25;        // the first N items that receive an accept are always second-reviewed
const UNI_SAMPLE_PERCENT = 20;      // afterwards this share (deterministic, by crc32 of "type:id")
const UNI_MAX_PER_PAGE = 100;
const UNI_MAX_PAGE = 10000;

function uni_table(string $type): ?string { return ['entity' => 'uni_entity', 'event' => 'uni_event', 'edge' => 'uni_edge', 'release' => 'uni_release'][$type] ?? null; }
function uni_event_types(): array { return ['model_release', 'product_release', 'major_update', 'integration', 'pricing_change', 'launch', 'acquisition', 'announcement']; }
function uni_edge_types(): array { return ['developed_by', 'version_of', 'derived_from', 'successor_of', 'renamed_from', 'acquired_by', 'integrates_with', 'built_on', 'hosted_on']; }
function uni_entity_types(): array { return ['company', 'product', 'model', 'version']; }
function uni_origins(): array { return ['human', 'ai_assisted', 'import']; }

// ---- helpers ---------------------------------------------------------------------------------------------------------------------------
/** Single-line-or-more text: control characters removed, trimmed, valid UTF-8 only. null = invalid (non-scalar, bad encoding, empty when required, too long). Never truncates silently. */
function uni_str($v, int $max, bool $required = true, bool $multiline = false): ?string {
  if ($v === null) $v = '';
  if (!is_scalar($v) || is_bool($v)) return null;
  $s = (string)$v;
  if (!mb_check_encoding($s, 'UTF-8')) return null;
  $s = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $s);
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return $required ? null : '';
  return mb_strlen($s) > $max ? null : $s;
}
/** True when a string has visible content (Unicode whitespace and zero-width characters do not count). */
function uni_visible(string $s): bool { return preg_match('/[^\s\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00A0}]/u', $s) === 1; }
/** Normalised title for duplicate detection: lower case, accents folded, punctuation removed, whitespace collapsed. */
function uni_norm_title(string $t): string {
  $n = mb_strtolower($t, 'UTF-8');
  if (function_exists('iconv')) { $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n); if ($x !== false && $x !== '') $n = $x; }
  $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? '';
  return trim(preg_replace('/\s+/', ' ', $n) ?? '');
}
/** "YYYY", "YYYY-MM" or "YYYY-MM-DD" (1900..2100, a real date). $precision may force a coarser precision (then the finer parts must be 01). → [date 'Y-m-d', precision] or null. */
function uni_parse_date($s, ?string $precision = null): ?array {
  if (!is_string($s)) return null;
  $s = trim($s);
  if (!preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/D', $s, $m)) return null;
  $y = (int)$m[1]; $mo = isset($m[2]) ? (int)$m[2] : 1; $d = isset($m[3]) ? (int)$m[3] : 1;
  $implied = isset($m[3]) ? 'day' : (isset($m[2]) ? 'month' : 'year');
  if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) return null;
  $p = $precision ?? $implied;
  if (!in_array($p, ['day', 'month', 'year'], true)) return null;
  $rank = ['year' => 0, 'month' => 1, 'day' => 2];
  if ($rank[$p] > $rank[$implied]) return null;                          // "2024" cannot claim day precision
  if ($p === 'month' && $d !== 1) return null;
  if ($p === 'year' && ($mo !== 1 || $d !== 1)) return null;
  return [sprintf('%04d-%02d-%02d', $y, $mo, $d), $p];
}
function uni_parse_datetime($s): ?string {   // source retrieval time: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM[:SS]", not in the future (1 day tolerance), not before 1990
  if (!is_string($s)) return null;
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D', trim($s), $m)) return null;
  if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 1990) return null;
  $h = (int)($m[4] ?? 0); $i = (int)($m[5] ?? 0); $sec = (int)($m[6] ?? 0); if ($h > 23 || $i > 59 || $sec > 59) return null;
  $ts = gmmktime($h, $i, $sec, (int)$m[2], (int)$m[3], (int)$m[1]);
  return $ts > time() + 86400 ? null : gmdate('Y-m-d H:i:s', $ts);
}
function uni_lineage_key(): string { return bin2hex(random_bytes(16)); }
function uni_url_ok(string $u): bool {
  if (strlen($u) > 2048 || !preg_match('#^https?://#i', $u) || preg_match('/[\s<>"\'\\\\]/', $u)) return false;
  $h = parse_url($u, PHP_URL_HOST); return is_string($h) && $h !== '' && strpos($h, '.') !== false;
}
/** Active user row from the database (never from a session or caller-supplied role). null for unknown, inactive or id <= 0 (the shared admin session has no id). */
function uni_actor(PDO $pdo, $id): ?array {
  if (!is_int($id) || $id <= 0) return null;
  $s = $pdo->prepare('SELECT id, username, display_name, role, active FROM crm_users WHERE id = ?'); $s->execute([$id]);
  $u = $s->fetch(PDO::FETCH_ASSOC);
  return ($u && (int)$u['active'] === 1) ? $u : null;
}
function uni_can_propose(?array $actor): bool { return $actor !== null && in_array($actor['role'], ['editor', 'owner'], true) && crm_can($actor['role'], 'uni_propose'); }
function uni_can_review(?array $actor): bool { return $actor !== null && $actor['role'] === 'verifier' && crm_can($actor['role'], 'uni_review'); }   // owner is deliberately NOT a reviewer: the triggers demand the verifier role
function uni_audit(PDO $pdo, ?int $actor, string $action, string $type = '', ?int $id = null, string $summary = '', array $detail = []): void {
  $pdo->prepare('INSERT INTO uni_audit_log (at, actor_id, action, target_type, target_id, summary, detail) VALUES (?,?,?,?,?,?,?)')
      ->execute([gmdate('Y-m-d H:i:s') . '.' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT), $actor, substr($action, 0, 40), substr($type, 0, 12), $id, mb_substr(crm_scrub($summary), 0, 255), $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
}
/** Runs $fn in a transaction (joins one already open). $fn returns [ok, result]; ok=false or an exception rolls back. Database rule violations map to stable codes. */
function uni_tx(PDO $pdo, callable $fn): array {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $own = !$pdo->inTransaction();
  try {
    if ($own) $pdo->beginTransaction();
    $r = $fn();
    if ($own) { if ($r[0]) $pdo->commit(); else $pdo->rollBack(); }
    return $r;
  } catch (Throwable $e) {
    if ($own && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
      $code = (int)($e->errorInfo[1] ?? 0); $st = (string)($e->errorInfo[0] ?? '');
      if ($code === 1062) return [false, 'duplicate_current'];
      if ($code === 3819 || $code === 4025) return [false, 'db_constraint'];
      if ($st === '45000') return [false, 'db_rule'];
      if ($code === 1452 || $code === 1451) return [false, 'reference_invalid'];
    }
    sec_log_exception($e, 'uni_tx');
    return [false, 'error'];
  }
}
/** Locks and returns one row of a uni table ($type whitelisted), or null. */
function uni_row(PDO $pdo, string $type, int $id, bool $lock = false): ?array {
  $t = uni_table($type); if ($t === null || $id <= 0) return null;
  $s = $pdo->prepare("SELECT * FROM $t WHERE id = ?" . ($lock ? ' FOR UPDATE' : '')); $s->execute([$id]);
  $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}
function uni_pick_origin($v): ?string { $o = $v === null || $v === '' ? 'human' : $v; return is_string($o) && in_array($o, uni_origins(), true) ? $o : null; }
function uni_new_row_base(array $actor, string $origin): array { return ['state' => 'proposed', 'origin' => $origin, 'proposed_by' => (int)$actor['id'], 'proposed_at' => crm_now()]; }
function uni_insert(PDO $pdo, string $table, array $row): int {
  $cols = array_keys($row);
  $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
  return (int)$pdo->lastInsertId();
}
/** Current usable row of an entity lineage by row id (any row of the lineage resolves to the live one), for dependency checks. */
function uni_entity_live(PDO $pdo, int $entityId): ?array {
  $s = $pdo->prepare('SELECT c.* FROM uni_entity e JOIN uni_entity c ON c.lineage_key = e.lineage_key AND c.is_current = 1 WHERE e.id = ?'); $s->execute([$entityId]);
  $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}

// ---- sources ---------------------------------------------------------------------------------------------------------------------------
/** @param array $in url, publisher, retrieved_at, quote, [origin], [content_hash] */
function uni_source_create(PDO $pdo, int $actorId, array $in): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $url = uni_str($in['url'] ?? null, 2048); $pub = uni_str($in['publisher'] ?? null, 190); $quote = uni_str($in['quote'] ?? null, 1000, true, true);
  $ret = uni_parse_datetime($in['retrieved_at'] ?? null); $origin = uni_pick_origin($in['origin'] ?? null);
  $hash = $in['content_hash'] ?? null;
  if ($url === null || !uni_url_ok($url)) return [false, 'url_invalid'];
  if ($pub === null || !uni_visible($pub)) return [false, 'publisher_required'];
  if ($quote === null || !uni_visible($quote)) return [false, 'quote_required'];
  if ($ret === null) return [false, 'retrieved_at_invalid'];
  if ($origin === null) return [false, 'origin_invalid'];
  if ($hash !== null && (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/D', $hash))) return [false, 'hash_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $url, $pub, $quote, $ret, $origin, $hash) {
    $id = uni_insert($pdo, 'uni_source', ['url' => $url, 'publisher' => $pub, 'retrieved_at' => $ret, 'quote' => $quote, 'content_hash' => $hash, 'origin' => $origin, 'created_by' => (int)$actor['id'], 'created_at' => crm_now()]);
    uni_audit($pdo, (int)$actor['id'], 'source_create', 'source', $id, 'source created');
    return [true, $id];
  });
}
/** A source counts only if it has a non-blank quote, a retrieval date and an http(s) URL (re-checked at submit and at every accept: the DB row could have come from elsewhere). */
function uni_source_valid(array $s): bool {
  return uni_visible((string)$s['quote']) && !empty($s['retrieved_at']) && $s['retrieved_at'] !== '0000-00-00 00:00:00' && uni_visible((string)$s['publisher']) && preg_match('#^https?://#i', (string)$s['url']) === 1;
}
function uni_valid_source_count(PDO $pdo, string $type, int $id): int {
  if ($type === 'release') {
    $s = $pdo->prepare('SELECT s.* FROM uni_release r JOIN uni_source s ON s.id = r.source_id WHERE r.id = ?');
  } elseif ($type === 'event' || $type === 'edge') {
    $s = $pdo->prepare($type === 'event' ? 'SELECT s.* FROM uni_event_source l JOIN uni_source s ON s.id = l.source_id WHERE l.event_id = ?' : 'SELECT s.* FROM uni_edge_source l JOIN uni_source s ON s.id = l.source_id WHERE l.edge_id = ?');
  } else return 0;
  $s->execute([$id]); $n = 0; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (uni_source_valid($r)) $n++;
  return $n;
}
/** Attaches an existing source id (int) or a new source (array, see uni_source_create) to an unverified event or edge. Sources of verified rows are frozen. */
function uni_attach_source(PDO $pdo, int $actorId, string $type, int $targetId, $source): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  if (!in_array($type, ['event', 'edge'], true)) return [false, 'type_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actorId, $type, $targetId, $source) {
    $row = uni_row($pdo, $type, $targetId, true);
    if (!$row) return [false, 'not_found'];
    if (!in_array($row['state'], ['proposed', 'in_review'], true)) return [false, 'target_locked'];
    if (is_array($source)) { [$ok, $sid] = uni_source_create($pdo, $actorId, $source); if (!$ok) return [false, $sid]; $sid = (int)$sid; }
    elseif (is_int($source) && $source > 0) $sid = $source;
    else return [false, 'source_invalid'];
    $q = $pdo->prepare('SELECT * FROM uni_source WHERE id = ?'); $q->execute([$sid]); $src = $q->fetch(PDO::FETCH_ASSOC);
    if (!$src || !uni_source_valid($src)) return [false, 'source_invalid'];
    $link = $type === 'event' ? ['uni_event_source', 'event_id'] : ['uni_edge_source', 'edge_id'];
    $dup = $pdo->prepare("SELECT 1 FROM {$link[0]} WHERE {$link[1]} = ? AND source_id = ?"); $dup->execute([$targetId, $sid]);
    if ($dup->fetchColumn()) return [false, 'already_attached'];
    uni_insert($pdo, $link[0], [$link[1] => $targetId, 'source_id' => $sid, 'created_at' => crm_now()]);
    uni_audit($pdo, (int)$actorId, 'source_attach', $type, $targetId, 'source attached', ['source_id' => $sid]);
    return [true, $sid];
  });
}

// ---- proposals -------------------------------------------------------------------------------------------------------------------------
function uni_propose_entity(PDO $pdo, int $actorId, array $in): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $type = $in['entity_type'] ?? null; $slugRaw = $in['slug'] ?? null; $origin = uni_pick_origin($in['origin'] ?? null);
  $name = uni_str($in['name'] ?? null, 190); $summary = uni_str($in['summary'] ?? '', 500, false);
  if (!is_string($type) || !in_array($type, uni_entity_types(), true)) return [false, 'entity_type_invalid'];
  if (!is_string($slugRaw) || $slugRaw === '' || strlen($slugRaw) > 120 || $slugRaw !== crm_slug($slugRaw, 120) || strlen($slugRaw) < 2) return [false, 'slug_invalid'];   // lower-case [a-z0-9._-] only, otherwise refused (not silently rewritten)
  if ($name === null || !uni_visible($name)) return [false, 'name_required'];
  if ($summary === null) return [false, 'summary_invalid'];
  if ($origin === null) return [false, 'origin_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $type, $slugRaw, $name, $summary, $origin) {
    $x = $pdo->prepare('SELECT 1 FROM uni_entity WHERE slug = ? AND is_current = 1'); $x->execute([$slugRaw]);
    if ($x->fetchColumn()) return [false, 'slug_taken'];
    $id = uni_insert($pdo, 'uni_entity', uni_new_row_base($actor, $origin) + ['entity_type' => $type, 'slug' => $slugRaw, 'name' => $name, 'summary' => $summary, 'lineage_key' => uni_lineage_key(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1]);
    uni_audit($pdo, (int)$actor['id'], 'propose', 'entity', $id, 'entity proposed');
    return [true, $id];
  });
}
/** Rows of the same entity lineage with the same date and normalised title (open or verified, not rejected/superseded). FLAGS only. */
function uni_find_duplicate_events(PDO $pdo, int $entityId, string $date, string $title, int $excludeId = 0): array {
  $s = $pdo->prepare('SELECT ev.id, ev.title, ev.state FROM uni_entity e JOIN uni_entity l ON l.lineage_key = e.lineage_key JOIN uni_event ev ON ev.entity_id = l.id
                      WHERE e.id = ? AND ev.event_date = ? AND ev.state IN (\'proposed\',\'in_review\',\'verified\') AND ev.is_current = 1 AND ev.id <> ?');
  $s->execute([$entityId, $date, $excludeId]); $n = uni_norm_title($title); $out = [];
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (uni_norm_title((string)$r['title']) === $n) $out[] = (int)$r['id'];
  return $out;
}
/** Same entity lineage and normalised title on a DIFFERENT date: a conflict candidate (recorded in the audit log, never resolved automatically). */
function uni_find_date_conflicts(PDO $pdo, int $entityId, string $date, string $title, int $excludeId = 0): array {
  $s = $pdo->prepare('SELECT ev.id, ev.title FROM uni_entity e JOIN uni_entity l ON l.lineage_key = e.lineage_key JOIN uni_event ev ON ev.entity_id = l.id
                      WHERE e.id = ? AND ev.event_date <> ? AND ev.state IN (\'proposed\',\'in_review\',\'verified\') AND ev.is_current = 1 AND ev.id <> ?');
  $s->execute([$entityId, $date, $excludeId]); $n = uni_norm_title($title); $out = [];
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (uni_norm_title((string)$r['title']) === $n) $out[] = (int)$r['id'];
  return $out;
}
/** @return array [true, ['id'=>int, 'duplicates'=>int[], 'date_conflicts'=>int[]]] */
function uni_propose_event(PDO $pdo, int $actorId, array $in): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $entityId = $in['entity_id'] ?? null; $etype = $in['event_type'] ?? null; $kind = $in['date_kind'] ?? 'announced'; $origin = uni_pick_origin($in['origin'] ?? null);
  $title = uni_str($in['title'] ?? null, 255); $summary = uni_str($in['summary'] ?? '', 1000, false, true);
  $pd = uni_parse_date($in['event_date'] ?? null, isset($in['date_precision']) && is_string($in['date_precision']) ? $in['date_precision'] : null);
  if (!is_int($entityId) || $entityId <= 0) return [false, 'entity_invalid'];
  if (!is_string($etype) || !in_array($etype, uni_event_types(), true)) return [false, 'event_type_invalid'];
  if (!is_string($kind) || !in_array($kind, ['announced', 'ga', 'preview'], true)) return [false, 'date_kind_invalid'];
  if ($title === null || !uni_visible($title)) return [false, 'title_required'];
  if ($summary === null) return [false, 'summary_invalid'];
  if ($pd === null) return [false, 'date_invalid'];
  if ($origin === null) return [false, 'origin_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $entityId, $etype, $kind, $title, $summary, $pd, $origin) {
    if (!uni_entity_live($pdo, $entityId)) return [false, 'entity_not_found'];
    $dups = uni_find_duplicate_events($pdo, $entityId, $pd[0], $title); $conf = uni_find_date_conflicts($pdo, $entityId, $pd[0], $title);
    $id = uni_insert($pdo, 'uni_event', uni_new_row_base($actor, $origin) + ['entity_id' => $entityId, 'event_type' => $etype, 'event_date' => $pd[0], 'date_precision' => $pd[1], 'date_kind' => $kind, 'title' => $title, 'summary' => $summary,
      'lineage_key' => uni_lineage_key(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1]);
    uni_audit($pdo, (int)$actor['id'], 'propose', 'event', $id, 'event proposed');
    if ($dups) uni_audit($pdo, (int)$actor['id'], 'duplicate_flag', 'event', $id, 'possible duplicate (flagged, not merged)', ['duplicates' => $dups]);
    if ($conf) uni_audit($pdo, (int)$actor['id'], 'conflict_recorded', 'event', $id, 'same title on another date', ['kind' => 'date_conflict', 'others' => $conf]);
    return [true, ['id' => $id, 'duplicates' => $dups, 'date_conflicts' => $conf]];
  });
}
function uni_propose_edge(PDO $pdo, int $actorId, array $in): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $from = $in['from_entity_id'] ?? null; $to = $in['to_entity_id'] ?? null; $etype = $in['edge_type'] ?? null; $origin = uni_pick_origin($in['origin'] ?? null);
  $notes = uni_str($in['notes'] ?? '', 500, false, true);
  $vf = ($in['valid_from'] ?? null) === null ? null : uni_parse_date($in['valid_from']); $vt = ($in['valid_to'] ?? null) === null ? null : uni_parse_date($in['valid_to']);
  if (!is_int($from) || !is_int($to) || $from <= 0 || $to <= 0) return [false, 'entity_invalid'];
  if ($from === $to) return [false, 'self_edge'];
  if (!is_string($etype) || !in_array($etype, uni_edge_types(), true)) return [false, 'edge_type_invalid'];
  if ($notes === null) return [false, 'notes_invalid'];
  if (($in['valid_from'] ?? null) !== null && $vf === null || ($in['valid_to'] ?? null) !== null && $vt === null) return [false, 'date_invalid'];
  if ($vf && $vt && $vt[0] < $vf[0]) return [false, 'validity_invalid'];
  if ($origin === null) return [false, 'origin_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $from, $to, $etype, $notes, $vf, $vt, $origin) {
    $a = uni_entity_live($pdo, $from); $b = uni_entity_live($pdo, $to);
    if (!$a || !$b) return [false, 'entity_not_found'];
    if ((int)$a['id'] === (int)$b['id']) return [false, 'self_edge'];
    $x = $pdo->prepare('SELECT 1 FROM uni_edge WHERE from_entity_id = ? AND to_entity_id = ? AND edge_type = ? AND is_current = 1 AND state <> \'rejected\''); $x->execute([$from, $to, $etype]);
    if ($x->fetchColumn()) return [false, 'edge_exists'];
    $id = uni_insert($pdo, 'uni_edge', uni_new_row_base($actor, $origin) + ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => $etype, 'valid_from' => $vf[0] ?? null, 'valid_to' => $vt[0] ?? null, 'notes' => $notes,
      'lineage_key' => uni_lineage_key(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1]);
    uni_audit($pdo, (int)$actor['id'], 'propose', 'edge', $id, 'edge proposed');
    return [true, $id];
  });
}
/** @param array $in entity_id, [event_id], version_label, release_date, [date_precision], source_id (existing source, mandatory), [change_set array], [origin], [parents: [[parent_release_id, source_id], ...]] */
function uni_propose_release(PDO $pdo, int $actorId, array $in): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $entityId = $in['entity_id'] ?? null; $eventId = $in['event_id'] ?? null; $sid = $in['source_id'] ?? null; $origin = uni_pick_origin($in['origin'] ?? null);
  $label = uni_str($in['version_label'] ?? null, 80); $cs = $in['change_set'] ?? null; $parents = $in['parents'] ?? [];
  $pd = uni_parse_date($in['release_date'] ?? null, isset($in['date_precision']) && is_string($in['date_precision']) ? $in['date_precision'] : null);
  if (!is_int($entityId) || $entityId <= 0) return [false, 'entity_invalid'];
  if ($eventId !== null && (!is_int($eventId) || $eventId <= 0)) return [false, 'event_invalid'];
  if (!is_int($sid) || $sid <= 0) return [false, 'source_required'];
  if ($label === null || !uni_visible($label)) return [false, 'label_required'];
  if ($pd === null) return [false, 'date_invalid'];
  if ($origin === null) return [false, 'origin_invalid'];
  if ($cs !== null && !is_array($cs)) return [false, 'change_set_invalid'];
  $csJson = $cs === null ? null : json_encode($cs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($cs !== null && ($csJson === false || strlen($csJson) > 65535)) return [false, 'change_set_invalid'];
  if (!is_array($parents) || count($parents) > 20) return [false, 'parents_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $entityId, $eventId, $sid, $label, $pd, $origin, $csJson, $parents) {
    if (!uni_entity_live($pdo, $entityId)) return [false, 'entity_not_found'];
    if ($eventId !== null && !uni_row($pdo, 'event', $eventId)) return [false, 'event_not_found'];
    $q = $pdo->prepare('SELECT * FROM uni_source WHERE id = ?'); $q->execute([$sid]); $src = $q->fetch(PDO::FETCH_ASSOC);
    if (!$src || !uni_source_valid($src)) return [false, 'source_invalid'];
    $id = uni_insert($pdo, 'uni_release', uni_new_row_base($actor, $origin) + ['entity_id' => $entityId, 'event_id' => $eventId, 'version_label' => $label, 'release_date' => $pd[0], 'date_precision' => $pd[1], 'source_id' => $sid, 'change_set' => $csJson,
      'lineage_key' => uni_lineage_key(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1]);
    foreach ($parents as $p) {
      if (!is_array($p) || !isset($p[0], $p[1]) || !is_int($p[0]) || !is_int($p[1])) return [false, 'parents_invalid'];
      [$ok, $why] = uni_release_link_parent($pdo, $id, $p[0], $p[1]);
      if (!$ok) return [false, $why];
    }
    uni_audit($pdo, (int)$actor['id'], 'propose', 'release', $id, 'release proposed');
    return [true, $id];
  });
}
/** Inner step (caller holds the transaction): parent must exist, be usable, have a STRICTLY earlier date (ties at coarse precision are refused), not be the release itself, and not create a cycle. */
function uni_release_link_parent(PDO $pdo, int $releaseId, int $parentId, int $sourceId): array {
  $c = uni_row($pdo, 'release', $releaseId); $p = uni_row($pdo, 'release', $parentId);
  if (!$c || !$p) return [false, 'parent_not_found'];
  if ($releaseId === $parentId || $c['lineage_key'] === $p['lineage_key']) return [false, 'parent_self'];
  if (in_array($p['state'], ['rejected', 'superseded'], true)) return [false, 'parent_unusable'];
  if (!in_array($c['state'], ['proposed', 'in_review'], true)) return [false, 'target_locked'];
  if ($p['release_date'] >= $c['release_date']) return [false, 'parent_not_earlier'];
  $seen = [$parentId]; $stack = [$parentId];                                  // walk up from the parent: the child must not be among its ancestors
  while ($stack) {
    $cur = array_pop($stack); $r = $pdo->prepare('SELECT parent_release_id FROM uni_release_parent WHERE release_id = ?'); $r->execute([$cur]);
    foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $up) { $up = (int)$up; if ($up === $releaseId) return [false, 'parent_cycle']; if (!in_array($up, $seen, true)) { $seen[] = $up; $stack[] = $up; } if (count($seen) > 500) return [false, 'parent_cycle']; }
  }
  $q = $pdo->prepare('SELECT * FROM uni_source WHERE id = ?'); $q->execute([$sourceId]); $src = $q->fetch(PDO::FETCH_ASSOC);
  if (!$src || !uni_source_valid($src)) return [false, 'source_invalid'];
  $d = $pdo->prepare('SELECT 1 FROM uni_release_parent WHERE release_id = ? AND parent_release_id = ?'); $d->execute([$releaseId, $parentId]);
  if ($d->fetchColumn()) return [false, 'parent_exists'];
  uni_insert($pdo, 'uni_release_parent', ['release_id' => $releaseId, 'parent_release_id' => $parentId, 'source_id' => $sourceId, 'created_at' => crm_now()]);
  return [true, 'ok'];
}
function uni_release_add_parent(PDO $pdo, int $actorId, int $releaseId, int $parentId, int $sourceId): array {
  if (!uni_can_propose(uni_actor($pdo, $actorId))) return [false, 'forbidden'];
  return uni_tx($pdo, function () use ($pdo, $actorId, $releaseId, $parentId, $sourceId) {
    $r = uni_row($pdo, 'release', $releaseId, true); if (!$r) return [false, 'not_found'];
    [$ok, $why] = uni_release_link_parent($pdo, $releaseId, $parentId, $sourceId); if (!$ok) return [false, $why];
    uni_audit($pdo, $actorId, 'parent_attach', 'release', $releaseId, 'parent linked', ['parent' => $parentId]);
    return [true, 'ok'];
  });
}

// ---- submit and review -----------------------------------------------------------------------------------------------------------------
function uni_submit_for_review(PDO $pdo, int $actorId, string $type, int $id): array {
  if (!uni_can_propose(uni_actor($pdo, $actorId))) return [false, 'forbidden'];
  if (uni_table($type) === null) return [false, 'type_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actorId, $type, $id) {
    $row = uni_row($pdo, $type, $id, true); if (!$row) return [false, 'not_found'];
    if ($row['state'] !== 'proposed') return [false, 'not_proposed'];
    if ($row['origin'] === 'import') return [false, 'import_needs_new_version'];
    if ($type !== 'entity' && uni_valid_source_count($pdo, $type, $id) < 1) return [false, 'source_required'];
    $u = $pdo->prepare("UPDATE " . uni_table($type) . " SET state = 'in_review' WHERE id = ? AND state = 'proposed'"); $u->execute([$id]);
    if ($u->rowCount() !== 1) return [false, 'not_proposed'];
    uni_audit($pdo, $actorId, 'submit', $type, $id, 'submitted for review');
    return [true, 'in_review'];
  });
}
/** Deterministic sampling decision for one target, stable over time: rank = position of the target's first accept among all first accepts. */
function uni_second_review_required(PDO $pdo, string $type, int $id): bool {
  $f = $pdo->prepare('SELECT MIN(id) FROM uni_review WHERE target_type = ? AND target_id = ? AND decision = \'accept\''); $f->execute([$type, $id]); $first = $f->fetchColumn();
  if ($first === false || $first === null) return false;
  $r = $pdo->prepare('SELECT COUNT(*) FROM (SELECT target_type, target_id FROM uni_review WHERE decision = \'accept\' GROUP BY target_type, target_id HAVING MIN(id) <= ?) x'); $r->execute([(int)$first]);
  return (int)$r->fetchColumn() <= UNI_SAMPLE_FIRST || (crc32($type . ':' . $id) % 100) < UNI_SAMPLE_PERCENT;
}
/** Checks that must hold at the moment of verification (re-read from the database inside the transaction). → null or error code */
function uni_verify_preconditions(PDO $pdo, string $type, array $row): ?string {
  if ($row['origin'] === 'import') return 'import_cannot_be_verified';
  if ($type !== 'entity' && uni_valid_source_count($pdo, $type, (int)$row['id']) < 1) return 'source_required';
  $live = function (int $entityId) use ($pdo): bool { $e = uni_entity_live($pdo, $entityId); return $e !== null && $e['state'] === 'verified'; };
  if ($type === 'event' || $type === 'release') { if (!$live((int)$row['entity_id'])) return 'entity_not_verified'; }
  if ($type === 'edge') { if (!$live((int)$row['from_entity_id']) || !$live((int)$row['to_entity_id'])) return 'entity_not_verified'; }
  if ($type === 'release') {
    if ($row['event_id'] !== null) { $ev = uni_row($pdo, 'event', (int)$row['event_id']); if (!$ev || $ev['state'] !== 'verified') return 'event_not_verified'; }
    $p = $pdo->prepare('SELECT p.release_date FROM uni_release_parent l JOIN uni_release p ON p.id = l.parent_release_id WHERE l.release_id = ?'); $p->execute([(int)$row['id']]);
    foreach ($p->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d >= $row['release_date']) return 'parent_not_earlier';
  }
  return null;
}
/**
 * One human review. $decision: accept | reject | needs_second_source. The reviewer must be an active user with role `verifier` and must not be the proposer.
 * accept → verified immediately, or (when the sampling rule picks the item) stays in_review until a SECOND distinct verifier also accepts.
 * @return array [true, ['state'=>..., 'awaiting_second_review'=>bool, 'review_id'=>int]]
 */
function uni_review(PDO $pdo, int $reviewerId, string $type, int $id, string $decision, string $note = ''): array {
  $reviewer = uni_actor($pdo, $reviewerId);
  if (!uni_can_review($reviewer)) return [false, 'forbidden'];
  if (uni_table($type) === null) return [false, 'type_invalid'];
  if (!in_array($decision, ['accept', 'reject', 'needs_second_source'], true)) return [false, 'decision_invalid'];
  $noteS = uni_str($note, 1000, false, true); if ($noteS === null) return [false, 'note_invalid'];
  return uni_tx($pdo, function () use ($pdo, $reviewer, $type, $id, $decision, $noteS) {
    $row = uni_row($pdo, $type, $id, true); if (!$row) return [false, 'not_found'];
    if ($row['state'] !== 'in_review') return [false, 'not_in_review'];
    if ((int)$row['proposed_by'] === (int)$reviewer['id']) return [false, 'maker_checker'];
    $dup = $pdo->prepare('SELECT 1 FROM uni_review WHERE target_type = ? AND target_id = ? AND reviewer_id = ?'); $dup->execute([$type, $id, $reviewer['id']]);
    if ($dup->fetchColumn()) return [false, 'already_reviewed'];
    if ($decision === 'accept') { $bad = uni_verify_preconditions($pdo, $type, $row); if ($bad !== null) return [false, $bad]; }
    $rid = uni_insert($pdo, 'uni_review', ['target_type' => $type, 'target_id' => $id, 'reviewer_id' => (int)$reviewer['id'], 'proposed_by' => (int)$row['proposed_by'], 'decision' => $decision, 'note' => $noteS, 'decided_at' => crm_now()]);
    $t = uni_table($type);
    if ($decision === 'reject') {
      $u = $pdo->prepare("UPDATE $t SET state = 'rejected', is_current = 0 WHERE id = ? AND state = 'in_review'"); $u->execute([$id]);
      uni_audit($pdo, (int)$reviewer['id'], 'review_reject', $type, $id, 'rejected');
      return [true, ['state' => 'rejected', 'awaiting_second_review' => false, 'review_id' => $rid]];
    }
    if ($decision === 'needs_second_source') {
      uni_audit($pdo, (int)$reviewer['id'], 'review_needs_source', $type, $id, 'needs a second source');
      return [true, ['state' => 'in_review', 'awaiting_second_review' => false, 'review_id' => $rid]];
    }
    $a = $pdo->prepare('SELECT COUNT(*) FROM uni_review WHERE target_type = ? AND target_id = ? AND decision = \'accept\''); $a->execute([$type, $id]);
    $accepts = (int)$a->fetchColumn(); $need = uni_second_review_required($pdo, $type, $id) ? 2 : 1;
    if ($accepts < $need) {
      uni_audit($pdo, (int)$reviewer['id'], 'review_accept_first', $type, $id, 'first accept, second reviewer required');
      return [true, ['state' => 'in_review', 'awaiting_second_review' => true, 'review_id' => $rid]];
    }
    // finalize: retire the live version of the lineage FIRST, then publish this one (UNIQUE current_lineage / slug / edge triple)
    if ((int)$row['version_no'] > 1 || $row['supersedes_id'] !== null) {
      $old = $pdo->prepare("UPDATE $t SET state = 'superseded', is_current = 0 WHERE lineage_key = ? AND is_current = 1 AND id <> ?"); $old->execute([$row['lineage_key'], $id]);
    }
    $u = $pdo->prepare("UPDATE $t SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ?, is_current = 1 WHERE id = ? AND state = 'in_review'");
    $u->execute([(int)$reviewer['id'], crm_now(), $rid, $id]);
    if ($u->rowCount() !== 1) return [false, 'not_in_review'];
    uni_audit($pdo, (int)$reviewer['id'], 'verified', $type, $id, 'verified', ['second_review' => $need === 2]);
    return [true, ['state' => 'verified', 'awaiting_second_review' => false, 'review_id' => $rid]];
  });
}

// ---- corrections (new version, supersede) ----------------------------------------------------------------------------------------------
/**
 * Proposes a NEW VERSION of a verified item (or of an unverified import lead: the only way an import becomes verifiable). The old row is untouched until the new
 * version is verified, then it becomes superseded in the same transaction. Sources / parents are copied; add more with uni_attach_source. $changes may hold
 * the same fields as the propose function; origin must not be 'import'. Returns [true, newRowId].
 */
function uni_correct(PDO $pdo, int $actorId, string $type, int $id, array $changes): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  if (uni_table($type) === null) return [false, 'type_invalid'];
  $allowed = ['entity' => ['name', 'summary'], 'event' => ['event_type', 'event_date', 'date_precision', 'date_kind', 'title', 'summary'], 'edge' => ['edge_type', 'valid_from', 'valid_to', 'notes'], 'release' => ['version_label', 'release_date', 'date_precision', 'change_set']][$type];
  foreach (array_keys($changes) as $k) if ($k !== 'origin' && !in_array($k, $allowed, true)) return [false, 'field_not_correctable'];
  $origin = uni_pick_origin($changes['origin'] ?? null); if ($origin === null || $origin === 'import') return [false, 'origin_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $type, $id, $changes, $origin, $allowed) {
    $old = uni_row($pdo, $type, $id, true); if (!$old) return [false, 'not_found'];
    $importLead = $old['origin'] === 'import' && in_array($old['state'], ['proposed', 'in_review'], true);
    if ($old['state'] !== 'verified' && !$importLead) return [false, 'not_correctable'];
    if ((int)$old['is_current'] !== 1) return [false, 'not_current'];
    $o = $pdo->prepare('SELECT id, version_no FROM ' . uni_table($type) . ' WHERE lineage_key = ? ORDER BY version_no DESC LIMIT 1 FOR UPDATE'); $o->execute([$old['lineage_key']]); $last = $o->fetch(PDO::FETCH_ASSOC);
    $open = $pdo->prepare('SELECT 1 FROM ' . uni_table($type) . " WHERE lineage_key = ? AND state IN ('proposed','in_review') AND is_current = 0 LIMIT 1"); $open->execute([$old['lineage_key']]);
    if ($open->fetchColumn()) return [false, 'correction_pending'];
    $new = $old; unset($new['id'], $new['current_lineage'], $new['current_slug'], $new['current_edge']);
    foreach (['state' => 'proposed', 'origin' => $origin, 'proposed_by' => (int)$actor['id'], 'proposed_at' => crm_now(), 'verified_by' => null, 'verified_at' => null, 'review_id' => null,
              'version_no' => (int)$last['version_no'] + 1, 'supersedes_id' => (int)$last['id'], 'is_current' => 0] as $k => $v) $new[$k] = $v;
    $v = function (string $k, $default) use ($changes) { return array_key_exists($k, $changes) ? $changes[$k] : $default; };
    if ($type === 'entity') {
      $nm = uni_str($v('name', $old['name']), 190); $sm = uni_str($v('summary', $old['summary']), 500, false);
      if ($nm === null || !uni_visible($nm)) return [false, 'name_required']; if ($sm === null) return [false, 'summary_invalid'];
      $new['name'] = $nm; $new['summary'] = $sm;
    } elseif ($type === 'event') {
      $et = $v('event_type', $old['event_type']); $dk = $v('date_kind', $old['date_kind']); $ti = uni_str($v('title', $old['title']), 255); $sm = uni_str($v('summary', $old['summary']), 1000, false, true);
      $pd = uni_parse_date($v('event_date', $old['event_date']), array_key_exists('date_precision', $changes) ? $changes['date_precision'] : ((array_key_exists('event_date', $changes) && is_string($changes['event_date']) && strlen($changes['event_date']) <= 7) ? null : $old['date_precision']));
      if (!is_string($et) || !in_array($et, uni_event_types(), true)) return [false, 'event_type_invalid']; if (!is_string($dk) || !in_array($dk, ['announced', 'ga', 'preview'], true)) return [false, 'date_kind_invalid'];
      if ($ti === null || !uni_visible($ti)) return [false, 'title_required']; if ($sm === null) return [false, 'summary_invalid']; if ($pd === null) return [false, 'date_invalid'];
      $new['event_type'] = $et; $new['date_kind'] = $dk; $new['title'] = $ti; $new['summary'] = $sm; $new['event_date'] = $pd[0]; $new['date_precision'] = $pd[1];
    } elseif ($type === 'edge') {
      $et = $v('edge_type', $old['edge_type']); $nt = uni_str($v('notes', $old['notes']), 500, false, true);
      $vf = $v('valid_from', $old['valid_from']); $vt = $v('valid_to', $old['valid_to']);
      $pf = $vf === null ? null : uni_parse_date($vf); $pt = $vt === null ? null : uni_parse_date($vt);
      if (!is_string($et) || !in_array($et, uni_edge_types(), true)) return [false, 'edge_type_invalid']; if ($nt === null) return [false, 'notes_invalid'];
      if ($vf !== null && $pf === null || $vt !== null && $pt === null) return [false, 'date_invalid']; if ($pf && $pt && $pt[0] < $pf[0]) return [false, 'validity_invalid'];
      $new['edge_type'] = $et; $new['notes'] = $nt; $new['valid_from'] = $pf[0] ?? null; $new['valid_to'] = $pt[0] ?? null;
    } else {
      $lb = uni_str($v('version_label', $old['version_label']), 80); $cs = array_key_exists('change_set', $changes) ? $changes['change_set'] : ($old['change_set'] === null ? null : json_decode((string)$old['change_set'], true));
      $pd = uni_parse_date($v('release_date', $old['release_date']), array_key_exists('date_precision', $changes) ? $changes['date_precision'] : ((array_key_exists('release_date', $changes) && is_string($changes['release_date']) && strlen($changes['release_date']) <= 7) ? null : $old['date_precision']));
      if ($lb === null || !uni_visible($lb)) return [false, 'label_required']; if ($pd === null) return [false, 'date_invalid']; if ($cs !== null && !is_array($cs)) return [false, 'change_set_invalid'];
      $new['version_label'] = $lb; $new['release_date'] = $pd[0]; $new['date_precision'] = $pd[1]; $new['change_set'] = $cs === null ? null : json_encode($cs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $nid = uni_insert($pdo, uni_table($type), $new);
    if ($type === 'event' || $type === 'edge') {
      [$lt, $col] = $type === 'event' ? ['uni_event_source', 'event_id'] : ['uni_edge_source', 'edge_id'];
      $pdo->prepare("INSERT INTO $lt ($col, source_id, created_at) SELECT ?, source_id, ? FROM $lt WHERE $col = ?")->execute([$nid, crm_now(), $id]);
    }
    if ($type === 'release') {
      $pdo->prepare('INSERT INTO uni_release_parent (release_id, parent_release_id, source_id, created_at) SELECT ?, parent_release_id, source_id, ? FROM uni_release_parent WHERE release_id = ?')->execute([$nid, crm_now(), $id]);
      $bad = $pdo->prepare('SELECT 1 FROM uni_release_parent l JOIN uni_release p ON p.id = l.parent_release_id WHERE l.release_id = ? AND p.release_date >= ?'); $bad->execute([$nid, $new['release_date']]);
      if ($bad->fetchColumn()) return [false, 'parent_not_earlier'];
    }
    uni_audit($pdo, (int)$actor['id'], 'correct', $type, $nid, 'new version proposed', ['supersedes' => (int)$last['id']]);
    return [true, $nid];
  });
}

// ---- state queries and conflicts (staff side) --------------------------------------------------------------------------------------------
/** Publication state of one item: is it public right now, how many valid sources, accepts, and whether a second review is still needed. Staff-side (shows counts, never identities). */
function uni_publish_state(PDO $pdo, string $type, int $id): ?array {
  $row = uni_row($pdo, $type, $id); if (!$row) return null;
  $a = $pdo->prepare('SELECT COUNT(*) FROM uni_review WHERE target_type = ? AND target_id = ? AND decision = \'accept\''); $a->execute([$type, $id]); $accepts = (int)$a->fetchColumn();
  return ['state' => $row['state'], 'is_current' => (int)$row['is_current'] === 1, 'public' => $row['state'] === 'verified' && (int)$row['is_current'] === 1, 'version_no' => (int)$row['version_no'],
          'valid_sources' => uni_valid_source_count($pdo, $type, $id), 'accepts' => $accepts, 'second_review_required' => $accepts > 0 ? uni_second_review_required($pdo, $type, $id) : null];
}
/** Review queue for verifiers: items in_review that this verifier did not propose and has not reviewed yet. Bounded. */
function uni_review_queue(PDO $pdo, int $reviewerId, string $type, int $limit = 25): array {
  if (!uni_can_review(uni_actor($pdo, $reviewerId)) || ($t = uni_table($type)) === null) return [false, 'forbidden'];
  $s = $pdo->prepare("SELECT x.id, x.proposed_at FROM $t x WHERE x.state = 'in_review' AND x.proposed_by <> ? AND NOT EXISTS (SELECT 1 FROM uni_review r WHERE r.target_type = ? AND r.target_id = x.id AND r.reviewer_id = ?) ORDER BY x.id LIMIT ?");
  $s->bindValue(1, $reviewerId, PDO::PARAM_INT); $s->bindValue(2, $type); $s->bindValue(3, $reviewerId, PDO::PARAM_INT); $s->bindValue(4, max(1, min(100, $limit)), PDO::PARAM_INT); $s->execute();
  return [true, $s->fetchAll(PDO::FETCH_ASSOC)];
}
/** Records a human-noticed conflict between two items (or an item and a note) in the append-only audit log. Nothing is resolved or merged automatically. */
function uni_record_conflict(PDO $pdo, int $actorId, string $type, int $id, ?int $otherId, string $note): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor) && !uni_can_review($actor)) return [false, 'forbidden'];
  if (uni_table($type) === null) return [false, 'type_invalid'];
  $n = uni_str($note, 500, true, true); if ($n === null) return [false, 'note_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actorId, $type, $id, $otherId, $n) {
    if (!uni_row($pdo, $type, $id) || ($otherId !== null && !uni_row($pdo, $type, $otherId))) return [false, 'not_found'];
    uni_audit($pdo, $actorId, 'conflict_recorded', $type, $id, 'conflict recorded', ['kind' => 'manual', 'other' => $otherId, 'note' => $n]);
    return [true, 'ok'];
  });
}
function uni_conflicts(PDO $pdo, string $type, int $id): array {
  $s = $pdo->prepare("SELECT at, actor_id, summary, detail FROM uni_audit_log WHERE action = 'conflict_recorded' AND target_type = ? AND target_id = ? ORDER BY id"); $s->execute([$type, $id]);
  return array_map(function ($r) { $r['detail'] = json_decode((string)$r['detail'], true) ?: []; return $r; }, $s->fetchAll(PDO::FETCH_ASSOC));
}
function uni_duplicates_of_event(PDO $pdo, int $eventId): array {
  $e = uni_row($pdo, 'event', $eventId); return $e ? uni_find_duplicate_events($pdo, (int)$e['entity_id'], (string)$e['event_date'], (string)$e['title'], $eventId) : [];
}

// ---- PUBLIC READS: verified, current rows only; no identities, notes or origin ----------------------------------------------------------------
/** @return array [page, per_page, offset] with hard bounds; garbage input falls back to defaults. */
function uni_page($page, $perPage, int $defaultPer = 25): array {
  $p = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => UNI_MAX_PAGE]]); $pp = filter_var($perPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  $p = $p === false ? 1 : $p; $pp = $pp === false ? $defaultPer : min($pp, UNI_MAX_PER_PAGE);
  return [$p, $pp, ($p - 1) * $pp];
}
function uni_pub_envelope(array $items, int $total, int $page, int $per): array { return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total, 'has_more' => $page * $per < $total]; }
/** SQL fragment: joins row alias $a's entity column $col to the LIVE VERIFIED version of that entity's lineage (alias $c). Entities superseded by a correction still resolve. */
function uni_live_entity_join(string $a, string $col, string $c): string {
  return "JOIN uni_entity {$c}_o ON {$c}_o.id = $a.$col JOIN uni_entity $c ON $c.lineage_key = {$c}_o.lineage_key AND $c.is_current = 1 AND $c.state = 'verified'";
}
function uni_pub_sources(PDO $pdo, string $linkTable, string $col, array $ids): array {
  if (!$ids) return [];
  if (!in_array($linkTable, ['uni_event_source', 'uni_edge_source'], true) || !in_array($col, ['event_id', 'edge_id'], true)) return [];
  $s = $pdo->prepare("SELECT l.$col AS k, s.url, s.publisher, s.retrieved_at, s.quote FROM $linkTable l JOIN uni_source s ON s.id = l.source_id WHERE l.$col IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.id');
  $s->execute(array_values($ids)); $out = [];
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['k']; unset($r['k']); $out[$k][] = $r; }
  return $out;
}
function uni_public_entities(PDO $pdo, array $filter = [], $page = 1, $perPage = 25): array {
  [$p, $pp, $off] = uni_page($page, $perPage); $w = "state = 'verified' AND is_current = 1"; $args = [];
  if (isset($filter['type']) && is_string($filter['type']) && in_array($filter['type'], uni_entity_types(), true)) { $w .= ' AND entity_type = ?'; $args[] = $filter['type']; }
  if (isset($filter['q']) && is_string($filter['q']) && ($q = uni_str($filter['q'], 80, false)) !== null && $q !== '') { $w .= ' AND name LIKE ?'; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
  $c = $pdo->prepare("SELECT COUNT(*) FROM uni_entity WHERE $w"); $c->execute($args);
  $s = $pdo->prepare("SELECT lineage_key AS ref, entity_type, slug, name, summary, verified_at FROM uni_entity WHERE $w ORDER BY name, id LIMIT ? OFFSET ?");
  foreach ($args as $i => $a) $s->bindValue($i + 1, $a); $s->bindValue(count($args) + 1, $pp, PDO::PARAM_INT); $s->bindValue(count($args) + 2, $off, PDO::PARAM_INT); $s->execute();
  return uni_pub_envelope($s->fetchAll(PDO::FETCH_ASSOC), (int)$c->fetchColumn(), $p, $pp);
}
function uni_public_entity(PDO $pdo, $slug): ?array {
  if (!is_string($slug) || $slug === '' || strlen($slug) > 120) return null;
  $s = $pdo->prepare("SELECT lineage_key AS ref, entity_type, slug, name, summary, verified_at FROM uni_entity WHERE slug = ? AND state = 'verified' AND is_current = 1"); $s->execute([$slug]);
  $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}
/** @param array $filter entity_slug, event_type, from (date), to (date) */
function uni_public_events(PDO $pdo, array $filter = [], $page = 1, $perPage = 25): array {
  [$p, $pp, $off] = uni_page($page, $perPage);
  $from = "FROM uni_event ev " . uni_live_entity_join('ev', 'entity_id', 'en') . " WHERE ev.state = 'verified' AND ev.is_current = 1"; $args = [];
  if (isset($filter['entity_slug']) && is_string($filter['entity_slug'])) { $from .= ' AND en.slug = ?'; $args[] = $filter['entity_slug']; }
  if (isset($filter['event_type']) && is_string($filter['event_type']) && in_array($filter['event_type'], uni_event_types(), true)) { $from .= ' AND ev.event_type = ?'; $args[] = $filter['event_type']; }
  if (isset($filter['from']) && ($d = uni_parse_date($filter['from'])) !== null) { $from .= ' AND ev.event_date >= ?'; $args[] = $d[0]; }
  if (isset($filter['to']) && ($d = uni_parse_date($filter['to'])) !== null) { $from .= ' AND ev.event_date <= ?'; $args[] = $d[0]; }
  $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($args);
  $s = $pdo->prepare("SELECT ev.id AS _id, ev.lineage_key AS ref, ev.event_type, ev.event_date, ev.date_precision, ev.date_kind, ev.title, ev.summary, ev.verified_at, en.slug AS entity_slug, en.name AS entity_name $from ORDER BY ev.event_date DESC, ev.id DESC LIMIT ? OFFSET ?");
  foreach ($args as $i => $a) $s->bindValue($i + 1, $a); $s->bindValue(count($args) + 1, $pp, PDO::PARAM_INT); $s->bindValue(count($args) + 2, $off, PDO::PARAM_INT); $s->execute();
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); $src = uni_pub_sources($pdo, 'uni_event_source', 'event_id', array_map(fn($r) => (int)$r['_id'], $rows));
  foreach ($rows as &$r) { $r['sources'] = $src[(int)$r['_id']] ?? []; unset($r['_id']); } unset($r);
  return uni_pub_envelope($rows, (int)$c->fetchColumn(), $p, $pp);
}
/** Heatmap counts. $grain 'day' counts only events with day precision; 'month' counts day and month precision (year-only events never invent a month). */
function uni_public_event_counts(PDO $pdo, string $grain = 'month', ?string $from = null, ?string $to = null): array {
  $grain = $grain === 'day' ? 'day' : 'month';
  $f = $from === null ? ['1900-01-01'] : uni_parse_date($from); $t = $to === null ? ['2100-12-31'] : uni_parse_date($to);
  if ($f === null || $t === null) return [];
  $fmt = $grain === 'day' ? '%Y-%m-%d' : '%Y-%m'; $prec = $grain === 'day' ? "('day')" : "('day','month')";
  $s = $pdo->prepare("SELECT DATE_FORMAT(ev.event_date, '$fmt') AS period, COUNT(*) AS n FROM uni_event ev " . uni_live_entity_join('ev', 'entity_id', 'en') .
    " WHERE ev.state = 'verified' AND ev.is_current = 1 AND ev.date_precision IN $prec AND ev.event_date BETWEEN ? AND ? GROUP BY period ORDER BY period LIMIT 6000");
  $s->execute([$f[0], $t[0]]);
  return array_map(fn($r) => ['period' => $r['period'], 'count' => (int)$r['n']], $s->fetchAll(PDO::FETCH_ASSOC));
}
function uni_public_edges(PDO $pdo, array $filter = [], $page = 1, $perPage = 25): array {
  [$p, $pp, $off] = uni_page($page, $perPage);
  $from = 'FROM uni_edge ed ' . uni_live_entity_join('ed', 'from_entity_id', 'fe') . ' ' . uni_live_entity_join('ed', 'to_entity_id', 'te') . " WHERE ed.state = 'verified' AND ed.is_current = 1"; $args = [];
  if (isset($filter['edge_type']) && is_string($filter['edge_type']) && in_array($filter['edge_type'], uni_edge_types(), true)) { $from .= ' AND ed.edge_type = ?'; $args[] = $filter['edge_type']; }
  if (isset($filter['entity_slug']) && is_string($filter['entity_slug'])) { $from .= ' AND (fe.slug = ? OR te.slug = ?)'; $args[] = $filter['entity_slug']; $args[] = $filter['entity_slug']; }
  $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($args);
  $s = $pdo->prepare("SELECT ed.id AS _id, ed.lineage_key AS ref, ed.edge_type, ed.valid_from, ed.valid_to, ed.verified_at, fe.slug AS from_slug, fe.name AS from_name, te.slug AS to_slug, te.name AS to_name $from ORDER BY ed.id LIMIT ? OFFSET ?");
  foreach ($args as $i => $a) $s->bindValue($i + 1, $a); $s->bindValue(count($args) + 1, $pp, PDO::PARAM_INT); $s->bindValue(count($args) + 2, $off, PDO::PARAM_INT); $s->execute();
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); $src = uni_pub_sources($pdo, 'uni_edge_source', 'edge_id', array_map(fn($r) => (int)$r['_id'], $rows));
  foreach ($rows as &$r) { $r['sources'] = $src[(int)$r['_id']] ?? []; unset($r['_id']); } unset($r);
  return uni_pub_envelope($rows, (int)$c->fetchColumn(), $p, $pp);
}
/** Releases with their parents (only parents that are themselves verified and current; each link shows its own source). */
function uni_public_releases(PDO $pdo, array $filter = [], $page = 1, $perPage = 25): array {
  [$p, $pp, $off] = uni_page($page, $perPage);
  $from = 'FROM uni_release rl ' . uni_live_entity_join('rl', 'entity_id', 'en') . " JOIN uni_source so ON so.id = rl.source_id WHERE rl.state = 'verified' AND rl.is_current = 1"; $args = [];
  if (isset($filter['entity_slug']) && is_string($filter['entity_slug'])) { $from .= ' AND en.slug = ?'; $args[] = $filter['entity_slug']; }
  $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($args);
  $s = $pdo->prepare("SELECT rl.id AS _id, rl.lineage_key AS ref, rl.version_label, rl.release_date, rl.date_precision, rl.change_set, rl.verified_at, en.slug AS entity_slug, en.name AS entity_name, so.url AS source_url, so.publisher AS source_publisher, so.retrieved_at AS source_retrieved_at, so.quote AS source_quote
                      $from ORDER BY rl.release_date DESC, rl.id DESC LIMIT ? OFFSET ?");
  foreach ($args as $i => $a) $s->bindValue($i + 1, $a); $s->bindValue(count($args) + 1, $pp, PDO::PARAM_INT); $s->bindValue(count($args) + 2, $off, PDO::PARAM_INT); $s->execute();
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); $par = [];
  if ($rows) {
    $ids = array_map(fn($r) => (int)$r['_id'], $rows);
    $q = $pdo->prepare('SELECT l.release_id AS k, pc.lineage_key AS ref, pc.version_label, pc.release_date, pc.date_precision, ps.url AS source_url, ps.publisher AS source_publisher FROM uni_release_parent l
      JOIN uni_release po ON po.id = l.parent_release_id JOIN uni_release pc ON pc.lineage_key = po.lineage_key AND pc.is_current = 1 AND pc.state = \'verified\' JOIN uni_source ps ON ps.id = l.source_id
      WHERE l.release_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY pc.release_date, pc.id');
    $q->execute($ids); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['k']; unset($r['k']); $par[$k][] = $r; }
  }
  foreach ($rows as &$r) { $r['parents'] = $par[(int)$r['_id']] ?? []; $r['change_set'] = $r['change_set'] === null ? null : json_decode((string)$r['change_set'], true); unset($r['_id']); } unset($r);
  return uni_pub_envelope($rows, (int)$c->fetchColumn(), $p, $pp);
}
/** 1-hop neighbourhood of a verified entity for the graph: the centre, its verified neighbours and the verified edges between the centre and them. Capped. */
function uni_public_neighbourhood(PDO $pdo, $slug, $limit = 100): array {
  $center = uni_public_entity($pdo, $slug); if (!$center) return ['center' => null, 'nodes' => [], 'edges' => [], 'truncated' => false];
  $lim = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); $lim = $lim === false ? 100 : min($lim, 200);
  $s = $pdo->prepare('SELECT ed.lineage_key AS ref, ed.edge_type, ed.valid_from, ed.valid_to, fe.slug AS from_slug, te.slug AS to_slug, fe.lineage_key AS from_ref, fe.entity_type AS from_type, fe.name AS from_name, te.lineage_key AS to_ref, te.entity_type AS to_type, te.name AS to_name
    FROM uni_edge ed ' . uni_live_entity_join('ed', 'from_entity_id', 'fe') . ' ' . uni_live_entity_join('ed', 'to_entity_id', 'te') . " WHERE ed.state = 'verified' AND ed.is_current = 1 AND (fe.slug = ? OR te.slug = ?) ORDER BY ed.id LIMIT ?");
  $s->bindValue(1, $center['slug']); $s->bindValue(2, $center['slug']); $s->bindValue(3, $lim + 1, PDO::PARAM_INT); $s->execute();
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); $trunc = count($rows) > $lim; $rows = array_slice($rows, 0, $lim);
  $nodes = [$center['slug'] => ['ref' => $center['ref'], 'slug' => $center['slug'], 'name' => $center['name'], 'entity_type' => $center['entity_type']]]; $edges = [];
  foreach ($rows as $r) {
    $nodes[$r['from_slug']] = ['ref' => $r['from_ref'], 'slug' => $r['from_slug'], 'name' => $r['from_name'], 'entity_type' => $r['from_type']];
    $nodes[$r['to_slug']] = ['ref' => $r['to_ref'], 'slug' => $r['to_slug'], 'name' => $r['to_name'], 'entity_type' => $r['to_type']];
    $edges[] = ['ref' => $r['ref'], 'edge_type' => $r['edge_type'], 'from' => $r['from_slug'], 'to' => $r['to_slug'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to']];
  }
  return ['center' => $center, 'nodes' => array_values($nodes), 'edges' => $edges, 'truncated' => $trunc];
}
