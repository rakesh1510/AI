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

function uni_table(string $type): ?string { return ['entity' => 'uni_entity', 'event' => 'uni_event', 'edge' => 'uni_edge', 'release' => 'uni_release', 'fact' => 'uni_fact'][$type] ?? null; }
/** Review ledger of a type: facts have their own append-only table (database/phase7_facts.sql) so that the original uni_review CHECK never changes. Whitelisted, never user input. */
function uni_review_table(string $type): string { return $type === 'fact' ? 'uni_fact_review' : 'uni_review'; }
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
const UNI_TX_ATTEMPTS = 4;          // a transaction that InnoDB picked as a deadlock victim (error 1213) is re-run this many times before giving up
/** Runs $fn in a transaction (joins one already open). $fn returns [ok, result]; ok=false or an exception rolls back. Database rule violations map to stable codes.
 *  Deadlock victims (1213) are retried from the start with a short random back-off ($fn must therefore only touch the database), then reported as 'deadlock'; lock wait timeouts as 'busy'. */
function uni_tx(PDO $pdo, callable $fn): array {
  static $depth = 0;                                                    // >0 while inside one of OUR transactions (nested calls rethrow retryable errors to the outermost)
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $own = !$pdo->inTransaction();
  for ($attempt = 1; ; $attempt++) {
    $depth++;
    try {
      if ($own) $pdo->beginTransaction();
      $r = $fn();
      if ($own) { if ($r[0]) $pdo->commit(); else $pdo->rollBack(); }
      return $r;
    } catch (Throwable $e) {
      if ($own && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) { /* connection state already reset by the server */ } }
      if ($e instanceof PDOException) {
        $code = (int)($e->errorInfo[1] ?? 0); $st = (string)($e->errorInfo[0] ?? '');
        if ($code === 1213 || $code === 1205) {
          if (!$own && $depth > 1) throw $e;                            // nested in our own transaction: the whole outer transaction is gone, let it retry
          if ($code === 1213 && $own && $attempt < UNI_TX_ATTEMPTS) { usleep(random_int(5000, 30000) * $attempt); continue; }
          return [false, $code === 1213 ? 'deadlock' : 'busy'];
        }
        if ($code === 1062) {
          $m = $e->getMessage();
          if (strpos($m, 'uq_uni_entity_current_slug') !== false) return [false, 'slug_taken'];
          if (strpos($m, 'uq_uni_edge_current_triple') !== false) return [false, 'edge_exists'];
          return [false, 'duplicate_current'];
        }
        if ($code === 3819 || $code === 4025) return [false, 'db_constraint'];
        if ($st === '45000') return [false, 'db_rule'];
        if ($code === 1452 || $code === 1451) return [false, 'reference_invalid'];
      }
      sec_log_exception($e, 'uni_tx');
      return [false, 'error'];
    } finally { $depth--; }
  }
}
/** Locks and returns one row of a uni table ($type whitelisted), or null. */
function uni_row(PDO $pdo, string $type, int $id, bool $lock = false): ?array {
  $t = uni_table($type); if ($t === null || $id <= 0) return null;
  if ($type === 'fact' && !uni_facts_installed($pdo)) return null;
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
  } elseif ($type === 'event' || $type === 'edge' || $type === 'entity') {
    $s = $pdo->prepare($type === 'event' ? 'SELECT s.* FROM uni_event_source l JOIN uni_source s ON s.id = l.source_id WHERE l.event_id = ?' : ($type === 'edge' ? 'SELECT s.* FROM uni_edge_source l JOIN uni_source s ON s.id = l.source_id WHERE l.edge_id = ?' : 'SELECT s.* FROM uni_entity_source l JOIN uni_source s ON s.id = l.source_id WHERE l.entity_id = ?'));
  } elseif ($type === 'fact') {
    $s = $pdo->prepare('SELECT s.* FROM uni_fact_source l JOIN uni_source s ON s.id = l.source_id WHERE l.fact_id = ?');
  } else return 0;
  $s->execute([$id]); $n = 0; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (uni_source_valid($r)) $n++;
  return $n;
}
/** Attaches an existing source id (int) or a new source (array, see uni_source_create) to an unverified event or edge. Sources of verified rows are frozen. */
function uni_attach_source(PDO $pdo, int $actorId, string $type, int $targetId, $source): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  if ($type === 'fact') return uni_fact_attach_source($pdo, $actorId, $targetId, $source);   // wrapper in the facts section at the end of this file
  if (!in_array($type, ['event', 'edge', 'entity'], true)) return [false, 'type_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actorId, $type, $targetId, $source) {
    $row = uni_row($pdo, $type, $targetId, true);
    if (!$row) return [false, 'not_found'];
    if (!in_array($row['state'], ['proposed', 'in_review'], true)) return [false, 'target_locked'];
    if (is_array($source)) { [$ok, $sid] = uni_source_create($pdo, $actorId, $source); if (!$ok) return [false, $sid]; $sid = (int)$sid; }
    elseif (is_int($source) && $source > 0) $sid = $source;
    else return [false, 'source_invalid'];
    $q = $pdo->prepare('SELECT * FROM uni_source WHERE id = ?'); $q->execute([$sid]); $src = $q->fetch(PDO::FETCH_ASSOC);
    if (!$src || !uni_source_valid($src)) return [false, 'source_invalid'];
    $link = $type === 'event' ? ['uni_event_source', 'event_id'] : ($type === 'edge' ? ['uni_edge_source', 'edge_id'] : ['uni_entity_source', 'entity_id']);
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
    if (uni_valid_source_count($pdo, $type, $id) < 1) return [false, 'source_required'];
    $u = $pdo->prepare("UPDATE " . uni_table($type) . " SET state = 'in_review' WHERE id = ? AND state = 'proposed'"); $u->execute([$id]);
    if ($u->rowCount() !== 1) return [false, 'not_proposed'];
    uni_audit($pdo, $actorId, 'submit', $type, $id, 'submitted for review');
    return [true, 'in_review'];
  });
}
/** Deterministic sampling decision for one target, stable over time: rank = position of the target's first accept among all first accepts. */
function uni_second_review_required(PDO $pdo, string $type, int $id): bool {
  $f = $pdo->prepare('SELECT MIN(id) FROM ' . uni_review_table($type) . ' WHERE target_type = ? AND target_id = ? AND decision = \'accept\''); $f->execute([$type, $id]); $first = $f->fetchColumn();
  if ($first === false || $first === null) return false;
  $r = $pdo->prepare('SELECT COUNT(*) FROM (SELECT target_type, target_id FROM ' . uni_review_table($type) . ' WHERE decision = \'accept\' GROUP BY target_type, target_id HAVING MIN(id) <= ?) x'); $r->execute([(int)$first]);
  return (int)$r->fetchColumn() <= UNI_SAMPLE_FIRST || (crc32($type . ':' . $id) % 100) < UNI_SAMPLE_PERCENT;
}
/** Checks that must hold at the moment of verification (re-read from the database inside the transaction). → null or error code */
function uni_verify_preconditions(PDO $pdo, string $type, array $row): ?string {
  if ($row['origin'] === 'import') return 'import_cannot_be_verified';
  if (uni_valid_source_count($pdo, $type, (int)$row['id']) < 1) return 'source_required';
  $live = function (int $entityId) use ($pdo): bool { $e = uni_entity_live($pdo, $entityId); return $e !== null && $e['state'] === 'verified'; };
  if ($type === 'event' || $type === 'release') { if (!$live((int)$row['entity_id'])) return 'entity_not_verified'; }
  if ($type === 'edge') { if (!$live((int)$row['from_entity_id']) || !$live((int)$row['to_entity_id'])) return 'entity_not_verified'; }
  if ($type === 'release') {
    if ($row['event_id'] !== null) { $ev = uni_row($pdo, 'event', (int)$row['event_id']); if (!$ev || $ev['state'] !== 'verified') return 'event_not_verified'; }
    $p = $pdo->prepare('SELECT p.release_date FROM uni_release_parent l JOIN uni_release p ON p.id = l.parent_release_id WHERE l.release_id = ?'); $p->execute([(int)$row['id']]);
    foreach ($p->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d >= $row['release_date']) return 'parent_not_earlier';
  }
  if ($type === 'fact') return uni_fact_verify_preconditions($pdo, $row);
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
    $dup = $pdo->prepare('SELECT 1 FROM ' . uni_review_table($type) . ' WHERE target_type = ? AND target_id = ? AND reviewer_id = ?'); $dup->execute([$type, $id, $reviewer['id']]);
    if ($dup->fetchColumn()) return [false, 'already_reviewed'];
    if ($decision === 'accept') { $bad = uni_verify_preconditions($pdo, $type, $row); if ($bad !== null) return [false, $bad]; }
    $rid = uni_insert($pdo, uni_review_table($type), ['target_type' => $type, 'target_id' => $id, 'reviewer_id' => (int)$reviewer['id'], 'proposed_by' => (int)$row['proposed_by'], 'decision' => $decision, 'note' => $noteS, 'decided_at' => crm_now()]);
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
    $a = $pdo->prepare('SELECT COUNT(*) FROM ' . uni_review_table($type) . ' WHERE target_type = ? AND target_id = ? AND decision = \'accept\''); $a->execute([$type, $id]);
    $accepts = (int)$a->fetchColumn(); $need = uni_second_review_required($pdo, $type, $id) ? 2 : 1;
    if ($accepts < $need) {
      uni_audit($pdo, (int)$reviewer['id'], 'review_accept_first', $type, $id, 'first accept, second reviewer required');
      return [true, ['state' => 'in_review', 'awaiting_second_review' => true, 'review_id' => $rid]];
    }
    // finalize. A successor version is verified FIRST (is_current stays 0), then the live version is retired (the database refuses to retire a
    // verified row that has no verified successor), then the successor becomes current (UNIQUE current_lineage / slug / edge triple).
    if ($type === 'edge') {   // an endpoint entity may have been superseded since the edge was proposed: verify the edge against the LIVE rows of both lineages
      $ea = uni_entity_live($pdo, (int)$row['from_entity_id']); $eb = uni_entity_live($pdo, (int)$row['to_entity_id']);
      if ($ea === null || $eb === null || $ea['state'] !== 'verified' || $eb['state'] !== 'verified') return [false, 'entity_not_verified'];
      if ((int)$ea['id'] !== (int)$row['from_entity_id'] || (int)$eb['id'] !== (int)$row['to_entity_id']) {
        $x = $pdo->prepare("SELECT 1 FROM uni_edge WHERE from_entity_id = ? AND to_entity_id = ? AND edge_type = ? AND is_current = 1 AND state <> 'rejected' AND id <> ?"); $x->execute([(int)$ea['id'], (int)$eb['id'], $row['edge_type'], $id]);
        if ($x->fetchColumn()) return [false, 'edge_exists'];
        $pdo->prepare("UPDATE uni_edge SET from_entity_id = ?, to_entity_id = ? WHERE id = ? AND state = 'in_review'")->execute([(int)$ea['id'], (int)$eb['id'], $id]);
      }
    }
    $isSuccessor = (int)$row['version_no'] > 1 || $row['supersedes_id'] !== null;
    $u = $pdo->prepare("UPDATE $t SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ?, is_current = " . ($isSuccessor ? '0' : '1') . " WHERE id = ? AND state = 'in_review'");
    $u->execute([(int)$reviewer['id'], crm_now(), $rid, $id]);
    if ($u->rowCount() !== 1) return [false, 'not_in_review'];
    if ($isSuccessor) {
      $old = $pdo->prepare("UPDATE $t SET state = 'superseded', is_current = 0 WHERE lineage_key = ? AND is_current = 1 AND id <> ?"); $old->execute([$row['lineage_key'], $id]);
      $pdo->prepare("UPDATE $t SET is_current = 1 WHERE id = ?")->execute([$id]);
    }
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
  if ($type === 'fact') return uni_fact_correct($pdo, $actorId, $id, $changes);   // facts section at the end of this file
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
      // Re-point the endpoints at the LIVE rows of both entity lineages: the old rows may have been superseded since, and the trigger that guards
      // verification (phase7_triggers.sql) demands that the endpoint rows themselves are verified (the service check above is lineage based).
      $ea = uni_entity_live($pdo, (int)$old['from_entity_id']); $eb = uni_entity_live($pdo, (int)$old['to_entity_id']);
      if (!$ea || !$eb) return [false, 'entity_not_found'];
      $new['from_entity_id'] = (int)$ea['id']; $new['to_entity_id'] = (int)$eb['id'];
    } else {
      $lb = uni_str($v('version_label', $old['version_label']), 80); $cs = array_key_exists('change_set', $changes) ? $changes['change_set'] : ($old['change_set'] === null ? null : json_decode((string)$old['change_set'], true));
      $pd = uni_parse_date($v('release_date', $old['release_date']), array_key_exists('date_precision', $changes) ? $changes['date_precision'] : ((array_key_exists('release_date', $changes) && is_string($changes['release_date']) && strlen($changes['release_date']) <= 7) ? null : $old['date_precision']));
      if ($lb === null || !uni_visible($lb)) return [false, 'label_required']; if ($pd === null) return [false, 'date_invalid']; if ($cs !== null && !is_array($cs)) return [false, 'change_set_invalid'];
      $new['version_label'] = $lb; $new['release_date'] = $pd[0]; $new['date_precision'] = $pd[1]; $new['change_set'] = $cs === null ? null : json_encode($cs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $nid = uni_insert($pdo, uni_table($type), $new);
    if ($type === 'event' || $type === 'edge' || $type === 'entity') {
      [$lt, $col] = $type === 'event' ? ['uni_event_source', 'event_id'] : ($type === 'edge' ? ['uni_edge_source', 'edge_id'] : ['uni_entity_source', 'entity_id']);
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
  $a = $pdo->prepare('SELECT COUNT(*) FROM ' . uni_review_table($type) . ' WHERE target_type = ? AND target_id = ? AND decision = \'accept\''); $a->execute([$type, $id]); $accepts = (int)$a->fetchColumn();
  return ['state' => $row['state'], 'is_current' => (int)$row['is_current'] === 1, 'public' => $row['state'] === 'verified' && (int)$row['is_current'] === 1, 'version_no' => (int)$row['version_no'],
          'valid_sources' => uni_valid_source_count($pdo, $type, $id), 'accepts' => $accepts, 'second_review_required' => $accepts > 0 ? uni_second_review_required($pdo, $type, $id) : null];
}
/** Review queue for verifiers: items in_review that this verifier did not propose and has not reviewed yet. Bounded. */
function uni_review_queue(PDO $pdo, int $reviewerId, string $type, int $limit = 25): array {
  if (!uni_can_review(uni_actor($pdo, $reviewerId)) || ($t = uni_table($type)) === null) return [false, 'forbidden'];
  if ($type === 'fact' && !uni_facts_installed($pdo)) return [true, []];
  $s = $pdo->prepare("SELECT x.id, x.proposed_at FROM $t x WHERE x.state = 'in_review' AND x.proposed_by <> ? AND NOT EXISTS (SELECT 1 FROM " . uni_review_table($type) . " r WHERE r.target_type = ? AND r.target_id = x.id AND r.reviewer_id = ?) ORDER BY x.id LIMIT ?");
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
  if (!in_array($linkTable, ['uni_event_source', 'uni_edge_source', 'uni_entity_source'], true) || !in_array($col, ['event_id', 'edge_id', 'entity_id'], true)) return [];
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

// ---- PUBLIC READS for the HTTP API (universe-api.php): same rule, verified + current rows and verified + current entities only --------------
/** Edge types that may be drawn as lineage, mapped to their lineage kind. Everything else (developed_by, integrates_with, hosted_on, acquired_by, version_of) is never lineage. */
function uni_lineage_edge_kinds(): array { return ['derived_from' => 'technical_ancestry', 'built_on' => 'technical_ancestry', 'successor_of' => 'product_succession', 'renamed_from' => 'product_succession']; }
function uni_unavailable(string $reason = 'no_verified_data'): array { return ['available' => false, 'reason' => $reason]; }
function uni_available($value): array { return ['available' => true, 'value' => $value]; }
/** Source rows reduced to what the graph and lineage views need (no quote). */
function uni_pub_source_brief(array $rows): array { return array_map(fn($r) => ['url' => $r['url'], 'publisher' => $r['publisher'], 'retrieved_at' => $r['retrieved_at']], $rows); }
function uni_pub_entity_node(array $r, string $p = ''): array { return ['ref' => $r[$p . 'ref'], 'slug' => $r[$p . 'slug'], 'type' => $r[$p . 'type'], 'name' => $r[$p . 'name']]; }

/** One verified, current entity with its sources, or null. */
function uni_public_entity_detail(PDO $pdo, $slug): ?array {
  if (!is_string($slug) || $slug === '' || strlen($slug) > 120) return null;
  $s = $pdo->prepare("SELECT id AS _id, lineage_key AS ref, entity_type, slug, name, summary, verified_at FROM uni_entity WHERE slug = ? AND state = 'verified' AND is_current = 1"); $s->execute([$slug]);
  $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
  $src = uni_pub_sources($pdo, 'uni_entity_source', 'entity_id', [(int)$r['_id']]); $r['sources'] = $src[(int)$r['_id']] ?? []; unset($r['_id']);
  return $r;
}
/** Heatmap buckets of VERIFIED events. grain day|week (week starts Monday) count day-precision events only; month counts day and month precision. Year-only events never invent a finer bucket
 *  and are reported in excluded_coarser_precision. $entitySlug and $eventType are optional filters. @return array|null null when the range or a filter is invalid. */
function uni_public_heatmap(PDO $pdo, string $grain, string $from, string $to, ?string $entitySlug = null, ?string $eventType = null): ?array {
  $exprs = ['day' => "DATE_FORMAT(ev.event_date, '%Y-%m-%d')", 'week' => "DATE_FORMAT(DATE_SUB(ev.event_date, INTERVAL WEEKDAY(ev.event_date) DAY), '%Y-%m-%d')", 'month' => "DATE_FORMAT(ev.event_date, '%Y-%m')"];
  if (!isset($exprs[$grain]) || uni_parse_date($from) === null || uni_parse_date($to) === null || $from > $to) return null;
  if ($eventType !== null && !in_array($eventType, uni_event_types(), true)) return null;
  $prec = $grain === 'month' ? "('day','month')" : "('day')";
  $base = 'FROM uni_event ev ' . uni_live_entity_join('ev', 'entity_id', 'en') . " WHERE ev.state = 'verified' AND ev.is_current = 1 AND ev.event_date BETWEEN ? AND ?"; $args = [$from, $to];
  if ($entitySlug !== null) { $base .= ' AND en.slug = ?'; $args[] = $entitySlug; }
  if ($eventType !== null) { $base .= ' AND ev.event_type = ?'; $args[] = $eventType; }
  $s = $pdo->prepare('SELECT ' . $exprs[$grain] . " AS period, COUNT(*) AS n $base AND ev.date_precision IN $prec GROUP BY period ORDER BY period LIMIT 4000"); $s->execute($args);
  $buckets = array_map(fn($r) => ['period' => $r['period'], 'count' => (int)$r['n']], $s->fetchAll(PDO::FETCH_ASSOC));
  $x = $pdo->prepare("SELECT COUNT(*) $base AND ev.date_precision NOT IN $prec"); $x->execute($args);
  return ['grain' => $grain, 'buckets' => $buckets, 'total' => array_sum(array_column($buckets, 'count')), 'excluded_coarser_precision' => (int)$x->fetchColumn()];
}
/** Day drill-down: the verified events behind one day bucket (day precision only, exactly the rows the day/week heatmap counts) with their sources. Capped at 100. */
function uni_public_day_events(PDO $pdo, string $day, ?string $entitySlug = null, ?string $eventType = null): ?array {
  $d = uni_parse_date($day, 'day'); if ($d === null) return null;
  if ($eventType !== null && !in_array($eventType, uni_event_types(), true)) return null;
  $from = 'FROM uni_event ev ' . uni_live_entity_join('ev', 'entity_id', 'en') . " WHERE ev.state = 'verified' AND ev.is_current = 1 AND ev.date_precision = 'day' AND ev.event_date = ?"; $args = [$d[0]];
  if ($entitySlug !== null) { $from .= ' AND en.slug = ?'; $args[] = $entitySlug; }
  if ($eventType !== null) { $from .= ' AND ev.event_type = ?'; $args[] = $eventType; }
  $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($args);
  $s = $pdo->prepare("SELECT ev.id AS _id, ev.lineage_key AS ref, ev.event_type, ev.event_date, ev.date_precision, ev.date_kind, ev.title, ev.summary, ev.verified_at, en.slug AS entity_slug, en.name AS entity_name $from ORDER BY ev.title, ev.id LIMIT 100");
  $s->execute($args); $rows = $s->fetchAll(PDO::FETCH_ASSOC); $src = uni_pub_sources($pdo, 'uni_event_source', 'event_id', array_map(fn($r) => (int)$r['_id'], $rows));
  foreach ($rows as &$r) { $r['sources'] = $src[(int)$r['_id']] ?? []; unset($r['_id']); } unset($r);
  return ['day' => $d[0], 'count' => (int)$c->fetchColumn(), 'items' => $rows];
}
/** Progressive 1-hop graph: edges of the centre ordered stably, $limit (1..200) edges from $offset, nodes = the centre plus the neighbours of the returned edges (so at most limit + 1). Each edge keeps its own type and sources. */
function uni_public_graph(PDO $pdo, $slug, $limit = 50, $offset = 0, ?string $edgeType = null): ?array {
  $center = uni_public_entity($pdo, $slug); if (!$center) return null;
  $lim = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]); $lim = $lim === false ? 50 : $lim;
  $off = filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => UNI_MAX_PAGE * UNI_MAX_PER_PAGE]]); $off = $off === false ? 0 : $off;
  if ($edgeType !== null && !in_array($edgeType, uni_edge_types(), true)) return null;
  $from = 'FROM uni_edge ed ' . uni_live_entity_join('ed', 'from_entity_id', 'fe') . ' ' . uni_live_entity_join('ed', 'to_entity_id', 'te') . " WHERE ed.state = 'verified' AND ed.is_current = 1 AND (fe.slug = ? OR te.slug = ?)";
  $args = [$center['slug'], $center['slug']]; if ($edgeType !== null) { $from .= ' AND ed.edge_type = ?'; $args[] = $edgeType; }
  $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($args); $total = (int)$c->fetchColumn();
  $s = $pdo->prepare("SELECT ed.id AS _id, ed.edge_type, ed.valid_from, ed.valid_to, fe.lineage_key AS f_ref, fe.slug AS f_slug, fe.entity_type AS f_type, fe.name AS f_name, te.lineage_key AS t_ref, te.slug AS t_slug, te.entity_type AS t_type, te.name AS t_name $from ORDER BY ed.edge_type, ed.id LIMIT ? OFFSET ?");
  foreach ($args as $i => $a) $s->bindValue($i + 1, $a); $s->bindValue(count($args) + 1, $lim, PDO::PARAM_INT); $s->bindValue(count($args) + 2, $off, PDO::PARAM_INT); $s->execute();
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); $src = uni_pub_sources($pdo, 'uni_edge_source', 'edge_id', array_map(fn($r) => (int)$r['_id'], $rows));
  $nodes = [$center['ref'] => uni_pub_entity_node(['ref' => $center['ref'], 'slug' => $center['slug'], 'type' => $center['entity_type'], 'name' => $center['name']])]; $edges = [];
  foreach ($rows as $r) {
    $nodes[$r['f_ref']] = uni_pub_entity_node($r, 'f_'); $nodes[$r['t_ref']] = uni_pub_entity_node($r, 't_');
    $edges[] = ['from' => $r['f_ref'], 'to' => $r['t_ref'], 'type' => $r['edge_type'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'sources' => uni_pub_source_brief($src[(int)$r['_id']] ?? [])];
  }
  $next = $off + count($rows);
  return ['center' => $center['ref'], 'nodes' => array_values($nodes), 'edges' => $edges, 'limit' => $lim, 'offset' => $off, 'total_edges' => $total, 'has_more' => $next < $total, 'next_offset' => $next < $total ? $next : null];
}
/** Release graph of an entity (model family): its verified releases, the documented parent links (each with its own source) reached within 4 hops in either direction (node cap $maxNodes),
 *  plus entity-level edges of lineage types only. @return array|null null for an unknown entity */
function uni_public_lineage(PDO $pdo, $slug, $maxNodes = 100): ?array {
  $ent = uni_public_entity($pdo, $slug); if (!$ent) return null;
  $cap = filter_var($maxNodes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]); $cap = $cap === false ? 100 : $cap;
  $live = fn(string $a, string $c) => uni_live_entity_join($a, 'entity_id', $c);
  $s = $pdo->prepare('SELECT rl.lineage_key FROM uni_release rl ' . $live('rl', 'en') . " WHERE rl.state = 'verified' AND rl.is_current = 1 AND en.slug = ? ORDER BY rl.release_date, rl.id LIMIT ?");
  $s->bindValue(1, $ent['slug']); $s->bindValue(2, $cap + 1, PDO::PARAM_INT); $s->execute();
  $seed = $s->fetchAll(PDO::FETCH_COLUMN); $truncated = count($seed) > $cap; $keys = array_fill_keys(array_slice($seed, 0, $cap), true); $links = [];
  for ($round = 0; $round < 4 && $keys; $round++) {
    $list = array_keys($keys); $ph = implode(',', array_fill(0, count($list), '?')); $before = count($keys);
    $q = $pdo->prepare('SELECT c.lineage_key AS child_ref, pc.lineage_key AS parent_ref, ps.url, ps.publisher, ps.retrieved_at FROM uni_release_parent l
      JOIN uni_release c ON c.id = l.release_id AND c.is_current = 1 AND c.state = \'verified\' ' . $live('c', 'ce') . '
      JOIN uni_release po ON po.id = l.parent_release_id JOIN uni_release pc ON pc.lineage_key = po.lineage_key AND pc.is_current = 1 AND pc.state = \'verified\' ' . $live('pc', 'pe') . "
      JOIN uni_source ps ON ps.id = l.source_id WHERE c.lineage_key IN ($ph) OR pc.lineage_key IN ($ph) ORDER BY c.release_date, c.id, pc.release_date, pc.id LIMIT 1000");
    $q->execute(array_merge($list, $list));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      foreach ([$r['child_ref'], $r['parent_ref']] as $k) if (!isset($keys[$k])) { if (count($keys) >= $cap) { $truncated = true; continue 2; } $keys[$k] = true; }
      $links[$r['parent_ref'] . '|' . $r['child_ref']] = ['from' => $r['parent_ref'], 'to' => $r['child_ref'], 'type' => 'release_parent', 'source' => uni_pub_source_brief([$r])[0]];
    }
    if (count($keys) === $before) break;
  }
  $nodes = [];
  if ($keys) {
    $list = array_keys($keys);
    $q = $pdo->prepare('SELECT rl.lineage_key AS ref, rl.version_label, rl.release_date, rl.date_precision, en.slug AS entity_slug, en.name AS entity_name, so.url, so.publisher, so.retrieved_at FROM uni_release rl ' . $live('rl', 'en') .
      " JOIN uni_source so ON so.id = rl.source_id WHERE rl.state = 'verified' AND rl.is_current = 1 AND rl.lineage_key IN (" . implode(',', array_fill(0, count($list), '?')) . ') ORDER BY rl.release_date, rl.id');
    $q->execute($list);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $src = uni_pub_source_brief([$r])[0]; unset($r['url'], $r['publisher'], $r['retrieved_at']); $nodes[$r['ref']] = $r + ['source' => $src]; }
  }
  $links = array_values(array_filter($links, fn($l) => isset($nodes[$l['from']], $nodes[$l['to']])));
  // entity-level lineage edges (derived_from, built_on, successor_of, renamed_from only), each tagged with its lineage kind
  $kinds = uni_lineage_edge_kinds(); $types = array_keys($kinds);
  $e = $pdo->prepare('SELECT ed.id AS _id, ed.edge_type, ed.valid_from, ed.valid_to, fe.lineage_key AS f_ref, fe.slug AS f_slug, fe.entity_type AS f_type, fe.name AS f_name, te.lineage_key AS t_ref, te.slug AS t_slug, te.entity_type AS t_type, te.name AS t_name
    FROM uni_edge ed ' . uni_live_entity_join('ed', 'from_entity_id', 'fe') . ' ' . uni_live_entity_join('ed', 'to_entity_id', 'te') . " WHERE ed.state = 'verified' AND ed.is_current = 1 AND (fe.slug = ? OR te.slug = ?) AND ed.edge_type IN (" . implode(',', array_fill(0, count($types), '?')) . ') ORDER BY ed.edge_type, ed.id LIMIT 100');
  $e->execute(array_merge([$ent['slug'], $ent['slug']], $types)); $erows = $e->fetchAll(PDO::FETCH_ASSOC);
  $esrc = uni_pub_sources($pdo, 'uni_edge_source', 'edge_id', array_map(fn($r) => (int)$r['_id'], $erows));
  $enodes = [$ent['ref'] => uni_pub_entity_node(['ref' => $ent['ref'], 'slug' => $ent['slug'], 'type' => $ent['entity_type'], 'name' => $ent['name']])]; $elinks = [];
  foreach ($erows as $r) {
    $enodes[$r['f_ref']] = uni_pub_entity_node($r, 'f_'); $enodes[$r['t_ref']] = uni_pub_entity_node($r, 't_');
    $elinks[] = ['from' => $r['f_ref'], 'to' => $r['t_ref'], 'type' => $kinds[$r['edge_type']], 'edge_type' => $r['edge_type'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'sources' => uni_pub_source_brief($esrc[(int)$r['_id']] ?? [])];
  }
  return ['entity' => $ent['ref'], 'nodes' => array_values($nodes), 'edges' => $links, 'entities' => array_values($enodes), 'entity_edges' => $elinks, 'truncated' => $truncated, 'node_cap' => $cap,
    'allowed_lineage_types' => ['technical_ancestry', 'product_succession', 'release_parent']];
}
/** Two verified, current releases of ONE entity, resolved by lineage ref (32 hex) or version label. @return array ['error' => code] or the diff */
function uni_public_release_diff(PDO $pdo, $slug, $a, $b): array {
  $ent = uni_public_entity($pdo, $slug); if (!$ent) return ['error' => 'entity_not_found'];
  $find = function ($id) use ($pdo, $ent) {
    if (!is_string($id) || $id === '' || mb_strlen($id) > 80) return ['error' => 'release_not_found'];
    $col = preg_match('/^[0-9a-f]{32}$/D', $id) ? 'rl.lineage_key' : 'rl.version_label';
    $s = $pdo->prepare('SELECT rl.id AS _id, rl.lineage_key AS ref, rl.version_label, rl.release_date, rl.date_precision, rl.change_set FROM uni_release rl ' . uni_live_entity_join('rl', 'entity_id', 'en') . " WHERE rl.state = 'verified' AND rl.is_current = 1 AND en.slug = ? AND $col = ? ORDER BY rl.id LIMIT 2");
    $s->execute([$ent['slug'], $id]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['error' => 'release_not_found']; if (count($rows) > 1) return ['error' => 'release_ambiguous'];
    return $rows[0];
  };
  $ra = $find($a); if (isset($ra['error'])) return $ra; $rb = $find($b); if (isset($rb['error'])) return $rb;
  if ($ra['ref'] === $rb['ref']) return ['error' => 'same_release'];
  $swapped = $ra['release_date'] > $rb['release_date']; if ($swapped) [$ra, $rb] = [$rb, $ra];
  $cs = fn($r) => $r['change_set'] === null ? null : json_decode((string)$r['change_set'], true);
  $ca = $cs($ra); $cb = $cs($rb); $isMap = fn($v) => is_array($v) && ($v === [] || !array_is_list($v));
  $change = uni_unavailable('change_set_missing');
  if ($isMap($ca) && $isMap($cb)) {
    $added = []; $removed = []; $changed = []; $same = 0;
    foreach ($cb as $k => $v) { $k = (string)$k; if (!array_key_exists($k, $ca)) $added[] = $k; elseif (json_encode($ca[$k]) !== json_encode($v)) $changed[] = ['key' => $k, 'from' => $ca[$k], 'to' => $v]; else $same++; }
    foreach ($ca as $k => $v) if (!array_key_exists((string)$k, $cb)) $removed[] = (string)$k;
    sort($added); sort($removed);
    $change = ['available' => true, 'added' => $added, 'removed' => $removed, 'changed' => $changed, 'unchanged_count' => $same];
  }
  $days = (int)round((strtotime($rb['release_date'] . ' UTC') - strtotime($ra['release_date'] . ' UTC')) / 86400);
  $facet = function (string $key, string $eventType) use ($pdo, $ent, $ra, $rb, $ca, $cb, $isMap) {
    $out = ['available' => false, 'reason' => 'no_verified_data'];
    $inA = $isMap($ca) && array_key_exists($key, $ca); $inB = $isMap($cb) && array_key_exists($key, $cb);
    if ($inA || $inB) { $out = ['available' => true, 'change_set' => ['from' => $inA ? $ca[$key] : null, 'to' => $inB ? $cb[$key] : null, 'status' => !$inA ? 'added' : (!$inB ? 'removed' : (json_encode($ca[$key]) === json_encode($cb[$key]) ? 'unchanged' : 'changed'))]]; }
    $s = $pdo->prepare('SELECT ev.id AS _id, ev.lineage_key AS ref, ev.event_type, ev.event_date, ev.date_precision, ev.title, ev.summary FROM uni_event ev ' . uni_live_entity_join('ev', 'entity_id', 'en') .
      " WHERE ev.state = 'verified' AND ev.is_current = 1 AND en.slug = ? AND ev.event_type = ? AND ev.event_date > ? AND ev.event_date <= ? ORDER BY ev.event_date, ev.id LIMIT 20");
    $s->execute([$ent['slug'], $eventType, $ra['release_date'], $rb['release_date']]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) { $src = uni_pub_sources($pdo, 'uni_event_source', 'event_id', array_map(fn($r) => (int)$r['_id'], $rows)); foreach ($rows as &$r) { $r['sources'] = uni_pub_source_brief($src[(int)$r['_id']] ?? []); unset($r['_id']); } unset($r); unset($out['reason']); $out['available'] = true; $out['events'] = $rows; }
    return $out;
  };
  $brief = fn($r) => ['ref' => $r['ref'], 'version_label' => $r['version_label'], 'release_date' => $r['release_date'], 'date_precision' => $r['date_precision']];
  return ['entity' => ['ref' => $ent['ref'], 'slug' => $ent['slug'], 'name' => $ent['name']], 'from' => $brief($ra), 'to' => $brief($rb), 'swapped_to_chronological_order' => $swapped, 'days_between' => $days,
    'change_set' => $change, 'pricing' => $facet('pricing', 'pricing_change'), 'integrations' => $facet('integrations', 'integration')];
}
/** 2 to 4 verified entities side by side. Every field is {available: true, value} or {available: false, reason}: missing data is never guessed. @return array ['error' => code] or ['entities' => [...]] */
function uni_public_compare(PDO $pdo, array $slugs): array {
  $slugs = array_values(array_unique(array_filter($slugs, 'is_string')));
  if (count($slugs) < 2 || count($slugs) > 4) return ['error' => 'count_invalid'];
  $out = []; $factsBy = [];
  foreach ($slugs as $slug) {
    $e = uni_public_entity_detail($pdo, $slug); if (!$e) return ['error' => 'entity_not_found'];
    $factsBy[$slug] = uni_public_facts($pdo, $slug);   // verified + current facts only (empty when the facts migration is not installed)
    $rel = uni_public_releases($pdo, ['entity_slug' => $slug], 1, 1); $evs = uni_public_events($pdo, ['entity_slug' => $slug], 1, 1); $price = uni_public_events($pdo, ['entity_slug' => $slug, 'event_type' => 'pricing_change'], 1, 1);
    $dev = uni_public_edges($pdo, ['entity_slug' => $slug, 'edge_type' => 'developed_by'], 1, 20); $int = uni_public_edges($pdo, ['entity_slug' => $slug, 'edge_type' => 'integrates_with'], 1, 20);
    $latest = $rel['items'][0] ?? null; $other = fn($ed) => $ed['from_slug'] === $slug ? ['slug' => $ed['to_slug'], 'name' => $ed['to_name']] : ['slug' => $ed['from_slug'], 'name' => $ed['from_name']];
    $devs = array_values(array_map(fn($ed) => ['slug' => $ed['to_slug'], 'name' => $ed['to_name']], array_filter($dev['items'], fn($ed) => $ed['from_slug'] === $slug)));
    $pricing = uni_unavailable();
    if ($latest && is_array($latest['change_set']) && array_key_exists('pricing', $latest['change_set'])) $pricing = uni_available(['from' => 'latest_release_change_set', 'value' => $latest['change_set']['pricing']]);
    elseif ($price['items']) { $p = $price['items'][0]; $pricing = uni_available(['from' => 'pricing_change_event', 'date' => $p['event_date'], 'title' => $p['title'], 'sources' => uni_pub_source_brief($p['sources'])]); }
    $out[] = ['entity' => ['ref' => $e['ref'], 'slug' => $e['slug'], 'type' => $e['entity_type'], 'name' => $e['name']], 'fields' => [
      'summary' => $e['summary'] !== '' ? uni_available($e['summary']) : uni_unavailable(),
      'sources' => $e['sources'] ? uni_available(uni_pub_source_brief($e['sources'])) : uni_unavailable(),
      'developer' => $devs ? uni_available($devs) : uni_unavailable(),
      'release_count' => $rel['total'] > 0 ? uni_available($rel['total']) : uni_unavailable(),
      'latest_release' => $latest ? uni_available(['version_label' => $latest['version_label'], 'release_date' => $latest['release_date'], 'date_precision' => $latest['date_precision'], 'source' => ['url' => $latest['source_url'], 'publisher' => $latest['source_publisher'], 'retrieved_at' => $latest['source_retrieved_at']]]) : uni_unavailable(),
      'event_count' => $evs['total'] > 0 ? uni_available($evs['total']) : uni_unavailable(),
      'latest_event' => $evs['items'] ? uni_available(['event_type' => $evs['items'][0]['event_type'], 'event_date' => $evs['items'][0]['event_date'], 'title' => $evs['items'][0]['title'], 'sources' => uni_pub_source_brief($evs['items'][0]['sources'])]) : uni_unavailable(),
      'pricing' => $pricing,
      'integrations' => $int['items'] ? uni_available(array_map($other, $int['items'])) : uni_unavailable()],
      'facts' => $factsBy[$slug] ? uni_available($factsBy[$slug]) : uni_unavailable()];   // verified comparison facts: beside "fields" so that the field list stays as it was
  }
  return ['entities' => $out, 'fact_matrix' => uni_fact_matrix($factsBy),
    'fact_notes' => ['No score, rank or winner is computed. A difference is only a difference.', 'Numbers reported under different benchmark versions or conditions are not comparable.', 'A fact marked unavailable has no verified data. Nothing is guessed.']];
}

// ======================================================================================================================================================
// ---- FACTS (database/phase7_facts.sql): source-backed, human-verified comparison facts for the Battle Arena. APPEND-ONLY SECTION. ---------------------
// A fact is one claim about one entity (feature, platform, integration, pricing, benchmark, limit) with a text OR numeric value, an observed date and at
// least one quoted source. It uses the generic workflow above (uni_submit_for_review, uni_review, uni_row, uni_publish_state, uni_record_conflict) with
// type 'fact' (table uni_fact, review ledger uni_fact_review) and has its own propose / attach / correct wrappers. There is NO score, rank or winner.
// ======================================================================================================================================================
function uni_fact_types(): array { return ['feature', 'platform', 'integration', 'pricing', 'benchmark', 'limit']; }
/** True when the facts migration is installed (all three tables answer). Facts are optional: when absent, every fact read is empty and every fact write is refused. */
function uni_facts_installed(PDO $pdo): bool {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  try { $q = $pdo->prepare('SELECT 1 FROM uni_fact f LEFT JOIN uni_fact_source s ON 1 = 0 LEFT JOIN uni_fact_review r ON 1 = 0 LIMIT 1'); $q->execute(); $q->closeCursor(); return true; }
  catch (PDOException $e) { if (in_array((string)$e->getCode(), ['42S02', '42S22'], true)) return false; throw $e; }
}
/** A stored DECIMAL(24,6) string as the shortest exact text ("20.000000" -> "20", "0.500000" -> "0.5"). */
function uni_fact_num_text($n): ?string {
  if ($n === null || $n === '') return null;
  $s = (string)$n; if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
  return $s === '' || $s === '-' || $s === '-0' ? '0' : $s;
}
/** Validates and normalises the value fields of a fact. @return array [normalised fields, null] or [null, error code] */
function uni_fact_normalise(array $in): array {
  $type = $in['fact_type'] ?? null; $key = uni_str($in['fact_key'] ?? null, 120); $vt = uni_str($in['value_text'] ?? '', 1000, false, true); $unit = uni_str($in['unit'] ?? '', 40, false);
  $bn = uni_str($in['benchmark_name'] ?? '', 120, false); $bm = uni_str($in['benchmark_metric'] ?? '', 120, false); $bv = uni_str($in['benchmark_version'] ?? '', 80, false); $cond = uni_str($in['conditions'] ?? '', 1000, false, true);
  if (!is_string($type) || !in_array($type, uni_fact_types(), true)) return [null, 'fact_type_invalid'];
  if ($key === null || !uni_visible($key)) return [null, 'fact_key_required'];
  if ($vt === null) return [null, 'value_invalid'];
  if ($unit === null || $bn === null || $bm === null || $bv === null || $cond === null) return [null, 'field_invalid'];
  $raw = $in['value_num'] ?? null; $num = null;
  if ($raw !== null && $raw !== '') {
    if (is_int($raw)) $raw = (string)$raw;
    elseif (is_float($raw)) { if (!is_finite($raw) || abs($raw) >= 1e17) return [null, 'value_num_invalid']; $raw = rtrim(rtrim(sprintf('%.6F', $raw), '0'), '.'); }
    if (!is_string($raw) || !preg_match('/^-?\d{1,17}(\.\d{1,6})?$/D', trim($raw))) return [null, 'value_num_invalid'];
    $num = trim($raw);
  }
  $hasText = uni_visible($vt); if (!$hasText) $vt = '';
  if ($num !== null && $hasText) return [null, 'value_conflict'];                       // a text value OR a number, never both
  if ($num === null && !$hasText) return [null, 'value_required'];
  if ($num === null && $unit !== '') return [null, 'unit_without_number'];
  if ($num !== null && $unit === '') return [null, 'unit_required'];                    // a number without a unit or currency is meaningless
  $benchSet = $bn !== '' || $bm !== '' || $bv !== '';
  if (in_array($type, ['feature', 'platform', 'integration'], true) && $num !== null) return [null, 'value_num_not_allowed'];
  if ($type === 'pricing' && ($num === null || (float)$num < 0)) return [null, 'pricing_needs_number'];
  if ($type === 'benchmark') {
    if ($num === null) return [null, 'benchmark_needs_number'];
    if ($bn === '') return [null, 'benchmark_name_required']; if ($bm === '') return [null, 'benchmark_metric_required']; if ($bv === '') return [null, 'benchmark_version_required'];
  } elseif ($benchSet) return [null, 'benchmark_fields_not_allowed'];
  $pd = uni_parse_date($in['observed_on'] ?? null, isset($in['date_precision']) && is_string($in['date_precision']) ? $in['date_precision'] : null);
  if ($pd === null || $pd[0] > gmdate('Y-m-d', time() + 86400)) return [null, 'date_invalid'];
  return [['fact_type' => $type, 'fact_key' => $key, 'value_text' => $vt, 'value_num' => $num, 'unit' => $unit, 'benchmark_name' => $bn, 'benchmark_metric' => $bm, 'benchmark_version' => $bv, 'conditions' => $cond, 'observed_on' => $pd[0], 'date_precision' => $pd[1]], null];
}
/** Comparable identity of what a fact claims (value + unit), used for duplicate and difference detection. */
function uni_fact_signature(array $f): string {
  return $f['value_num'] !== null && $f['value_num'] !== '' ? 'n:' . uni_fact_num_text($f['value_num']) . '|' . mb_strtolower((string)$f['unit'], 'UTF-8') : 't:' . uni_norm_title((string)$f['value_text']);
}
/** Subject of a fact: type + key (+ benchmark name, metric, version, conditions). Two facts with the same subject but another signature are a conflict candidate; the same signature is a duplicate. */
function uni_fact_subject(array $f): string {
  return implode('|', [$f['fact_type'], uni_norm_title((string)$f['fact_key']), uni_norm_title((string)$f['benchmark_name']), uni_norm_title((string)$f['benchmark_metric']), uni_norm_title((string)$f['benchmark_version']), uni_norm_title((string)$f['conditions'])]);
}
/** Open or verified current facts of the same entity lineage and type: dup = same subject and value, conf = same subject, different value. FLAGS only, nothing is merged or resolved. @return array [dup ids, conflict ids] */
function uni_find_fact_flags(PDO $pdo, int $entityId, array $f, int $excludeId = 0): array {
  $s = $pdo->prepare('SELECT x.* FROM uni_entity e JOIN uni_entity l ON l.lineage_key = e.lineage_key JOIN uni_fact x ON x.entity_id = l.id
                      WHERE e.id = ? AND x.fact_type = ? AND x.state IN (\'proposed\',\'in_review\',\'verified\') AND x.is_current = 1 AND x.id <> ?');
  $s->execute([$entityId, $f['fact_type'], $excludeId]); $dup = []; $conf = []; $subj = uni_fact_subject($f); $sig = uni_fact_signature($f);
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { if (uni_fact_subject($r) !== $subj) continue; if (uni_fact_signature($r) === $sig) $dup[] = (int)$r['id']; else $conf[] = (int)$r['id']; }
  return [$dup, $conf];
}
function uni_duplicates_of_fact(PDO $pdo, int $factId): array { $r = uni_row($pdo, 'fact', $factId); return $r ? uni_find_fact_flags($pdo, (int)$r['entity_id'], $r, $factId)[0] : []; }
/**
 * @param array $in entity_id, [release_id], fact_type, fact_key, value_text XOR value_num (+ unit), [benchmark_name, benchmark_metric, benchmark_version], [conditions], observed_on, [date_precision], [origin]
 * @return array [true, ['id'=>int, 'duplicates'=>int[], 'value_conflicts'=>int[]]]. The fact stays a draft until it has a quoted source (uni_fact_attach_source), is submitted and a different verifier accepts it.
 */
function uni_propose_fact(PDO $pdo, int $actorId, array $in): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $entityId = $in['entity_id'] ?? null; $releaseId = $in['release_id'] ?? null; $origin = uni_pick_origin($in['origin'] ?? null);
  if (!is_int($entityId) || $entityId <= 0) return [false, 'entity_invalid'];
  if ($releaseId !== null && (!is_int($releaseId) || $releaseId <= 0)) return [false, 'release_invalid'];
  [$f, $err] = uni_fact_normalise($in); if ($f === null) return [false, $err];
  if ($origin === null) return [false, 'origin_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $entityId, $releaseId, $f, $origin) {
    if (!uni_facts_installed($pdo)) return [false, 'facts_not_installed'];
    $ent = uni_entity_live($pdo, $entityId); if (!$ent) return [false, 'entity_not_found'];
    if ($releaseId !== null) {
      $rel = uni_row($pdo, 'release', $releaseId); if (!$rel) return [false, 'release_not_found'];
      $re = uni_entity_live($pdo, (int)$rel['entity_id']); if (!$re || (int)$re['id'] !== (int)$ent['id']) return [false, 'release_entity_mismatch'];
    }
    [$dups, $conf] = uni_find_fact_flags($pdo, $entityId, $f);
    $id = uni_insert($pdo, 'uni_fact', uni_new_row_base($actor, $origin) + $f + ['entity_id' => $entityId, 'release_id' => $releaseId, 'lineage_key' => uni_lineage_key(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1]);
    uni_audit($pdo, (int)$actor['id'], 'propose', 'fact', $id, 'fact proposed');
    if ($dups) uni_audit($pdo, (int)$actor['id'], 'duplicate_flag', 'fact', $id, 'possible duplicate (flagged, not merged)', ['duplicates' => $dups]);
    if ($conf) uni_audit($pdo, (int)$actor['id'], 'conflict_recorded', 'fact', $id, 'same subject, different value', ['kind' => 'value_conflict', 'others' => $conf]);
    return [true, ['id' => $id, 'duplicates' => $dups, 'value_conflicts' => $conf]];
  });
}
/** Attaches an existing source id (int) or a new source (array, see uni_source_create) to an unverified fact. Sources of verified facts are frozen. Called by uni_attach_source for type 'fact'. */
function uni_fact_attach_source(PDO $pdo, int $actorId, int $factId, $source): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  return uni_tx($pdo, function () use ($pdo, $actorId, $factId, $source) {
    $row = uni_row($pdo, 'fact', $factId, true);
    if (!$row) return [false, 'not_found'];
    if (!in_array($row['state'], ['proposed', 'in_review'], true)) return [false, 'target_locked'];
    if (is_array($source)) { [$ok, $sid] = uni_source_create($pdo, $actorId, $source); if (!$ok) return [false, $sid]; $sid = (int)$sid; }
    elseif (is_int($source) && $source > 0) $sid = $source;
    else return [false, 'source_invalid'];
    $q = $pdo->prepare('SELECT * FROM uni_source WHERE id = ?'); $q->execute([$sid]); $src = $q->fetch(PDO::FETCH_ASSOC);
    if (!$src || !uni_source_valid($src)) return [false, 'source_invalid'];
    $dup = $pdo->prepare('SELECT 1 FROM uni_fact_source WHERE fact_id = ? AND source_id = ?'); $dup->execute([$factId, $sid]);
    if ($dup->fetchColumn()) return [false, 'already_attached'];
    uni_insert($pdo, 'uni_fact_source', ['fact_id' => $factId, 'source_id' => $sid, 'created_at' => crm_now()]);
    uni_audit($pdo, (int)$actorId, 'source_attach', 'fact', $factId, 'source attached', ['source_id' => $sid]);
    return [true, $sid];
  });
}
/** Extra checks at the moment of verification (called from uni_verify_preconditions, inside the review transaction): the entity and a linked release must already be verified. null or error code */
function uni_fact_verify_preconditions(PDO $pdo, array $row): ?string {
  $e = uni_entity_live($pdo, (int)$row['entity_id']); if ($e === null || $e['state'] !== 'verified') return 'entity_not_verified';
  if ($row['release_id'] !== null) { $r = uni_row($pdo, 'release', (int)$row['release_id']); if (!$r || $r['state'] !== 'verified') return 'release_not_verified'; }
  return null;
}
/** New VERSION of a verified fact (or of an unverified import lead), same rules as uni_correct: the old row stays untouched and public until the new one is verified by someone else. Sources are copied. @return array [true, newId] */
function uni_fact_correct(PDO $pdo, int $actorId, int $id, array $changes): array {
  $actor = uni_actor($pdo, $actorId);
  if (!uni_can_propose($actor)) return [false, 'forbidden'];
  $allowed = ['value_text', 'value_num', 'unit', 'benchmark_name', 'benchmark_metric', 'benchmark_version', 'conditions', 'observed_on', 'date_precision'];
  foreach (array_keys($changes) as $k) if ($k !== 'origin' && !in_array($k, $allowed, true)) return [false, 'field_not_correctable'];
  $origin = uni_pick_origin($changes['origin'] ?? null); if ($origin === null || $origin === 'import') return [false, 'origin_invalid'];
  return uni_tx($pdo, function () use ($pdo, $actor, $id, $changes, $origin) {
    $old = uni_row($pdo, 'fact', $id, true); if (!$old) return [false, 'not_found'];
    $importLead = $old['origin'] === 'import' && in_array($old['state'], ['proposed', 'in_review'], true);
    if ($old['state'] !== 'verified' && !$importLead) return [false, 'not_correctable'];
    if ((int)$old['is_current'] !== 1) return [false, 'not_current'];
    $o = $pdo->prepare('SELECT id, version_no FROM uni_fact WHERE lineage_key = ? ORDER BY version_no DESC LIMIT 1 FOR UPDATE'); $o->execute([$old['lineage_key']]); $last = $o->fetch(PDO::FETCH_ASSOC);
    $open = $pdo->prepare("SELECT 1 FROM uni_fact WHERE lineage_key = ? AND state IN ('proposed','in_review') AND is_current = 0 LIMIT 1"); $open->execute([$old['lineage_key']]);
    if ($open->fetchColumn()) return [false, 'correction_pending'];
    $merge = $changes + ['fact_type' => $old['fact_type'], 'fact_key' => $old['fact_key']];
    foreach (['value_text', 'value_num', 'unit', 'benchmark_name', 'benchmark_metric', 'benchmark_version', 'conditions'] as $k) if (!array_key_exists($k, $merge)) $merge[$k] = $old[$k];
    $numSet = array_key_exists('value_num', $changes) && $changes['value_num'] !== null && $changes['value_num'] !== ''; $textSet = array_key_exists('value_text', $changes) && trim((string)$changes['value_text']) !== '';
    if ($numSet && !array_key_exists('value_text', $changes)) $merge['value_text'] = '';                                                   // switching a text fact to a number clears the text (and the reverse)
    if ($textSet && !array_key_exists('value_num', $changes)) { $merge['value_num'] = null; if (!array_key_exists('unit', $changes)) $merge['unit'] = ''; }
    $merge['observed_on'] = $changes['observed_on'] ?? $old['observed_on'];
    $merge['date_precision'] = array_key_exists('date_precision', $changes) ? $changes['date_precision'] : ((array_key_exists('observed_on', $changes) && is_string($changes['observed_on']) && strlen($changes['observed_on']) <= 7) ? null : $old['date_precision']);
    [$f, $err] = uni_fact_normalise($merge); if ($f === null) return [false, $err];
    $new = $old; unset($new['id'], $new['current_lineage']);
    foreach ($f as $k => $v) $new[$k] = $v;
    foreach (['state' => 'proposed', 'origin' => $origin, 'proposed_by' => (int)$actor['id'], 'proposed_at' => crm_now(), 'verified_by' => null, 'verified_at' => null, 'review_id' => null,
              'version_no' => (int)$last['version_no'] + 1, 'supersedes_id' => (int)$last['id'], 'is_current' => 0] as $k => $v) $new[$k] = $v;
    $nid = uni_insert($pdo, 'uni_fact', $new);
    $pdo->prepare('INSERT INTO uni_fact_source (fact_id, source_id, created_at) SELECT ?, source_id, ? FROM uni_fact_source WHERE fact_id = ?')->execute([$nid, crm_now(), $id]);
    uni_audit($pdo, (int)$actor['id'], 'correct', 'fact', $nid, 'new version proposed', ['supersedes' => (int)$last['id']]);
    return [true, $nid];
  });
}

// ---- public fact reads (verified + current facts of verified + current entities only; no identities, origin or review notes) ---------------------------
/** Verified, current facts of one verified entity, each with its sources (url, publisher, retrieved_at, quote) and observation date. $type optionally narrows. Capped at $cap. */
function uni_public_facts(PDO $pdo, $slug, ?string $type = null, int $cap = 300): array {
  if (!is_string($slug) || $slug === '' || strlen($slug) > 120 || ($type !== null && !in_array($type, uni_fact_types(), true)) || !uni_facts_installed($pdo)) return [];
  $cap = max(1, min(500, $cap)); $args = [$slug];
  $w = "f.state = 'verified' AND f.is_current = 1 AND en.slug = ?"; if ($type !== null) { $w .= ' AND f.fact_type = ?'; $args[] = $type; }
  $s = $pdo->prepare('SELECT f.id AS _id, f.lineage_key AS ref, f.fact_type, f.fact_key, f.value_text, f.value_num, f.unit, f.benchmark_name, f.benchmark_metric, f.benchmark_version, f.conditions, f.observed_on, f.date_precision, f.verified_at, rc.version_label AS release_label
    FROM uni_fact f ' . uni_live_entity_join('f', 'entity_id', 'en') . ' LEFT JOIN uni_release ro ON ro.id = f.release_id LEFT JOIN uni_release rc ON rc.lineage_key = ro.lineage_key AND rc.is_current = 1 AND rc.state = \'verified\'' . " WHERE $w ORDER BY f.fact_type, f.fact_key, f.id LIMIT ?");
  foreach ($args as $i => $a) $s->bindValue($i + 1, $a); $s->bindValue(count($args) + 1, $cap, PDO::PARAM_INT); $s->execute();
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); if (!$rows) return [];
  $ids = array_map(fn($r) => (int)$r['_id'], $rows); $src = [];
  $q = $pdo->prepare('SELECT l.fact_id AS k, s.url, s.publisher, s.retrieved_at, s.quote FROM uni_fact_source l JOIN uni_source s ON s.id = l.source_id WHERE l.fact_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.id');
  $q->execute($ids); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['k']; unset($r['k']); $src[$k][] = $r; }
  $out = [];
  foreach ($rows as $r) {
    $bench = $r['fact_type'] === 'benchmark' ? ['name' => $r['benchmark_name'], 'metric' => $r['benchmark_metric'], 'version' => $r['benchmark_version']] : null;
    $out[] = ['ref' => $r['ref'], 'fact_type' => $r['fact_type'], 'fact_key' => $r['fact_key'], 'value_text' => $r['value_text'] !== '' ? $r['value_text'] : null, 'value_num' => uni_fact_num_text($r['value_num']), 'unit' => $r['unit'] !== '' ? $r['unit'] : null,
      'benchmark' => $bench, 'conditions' => $r['conditions'] !== '' ? $r['conditions'] : null, 'observed_on' => $r['observed_on'], 'date_precision' => $r['date_precision'], 'release' => $r['release_label'], 'verified_at' => $r['verified_at'], 'sources' => $src[(int)$r['_id']] ?? []];
  }
  return $out;
}
function uni_pub_fact_signature(array $f): string { return uni_fact_signature(['value_num' => $f['value_num'], 'unit' => (string)$f['unit'], 'value_text' => (string)$f['value_text']]); }
/**
 * Per fact type, the rows (one per subject: key, or benchmark name + metric) of a side-by-side table: cells[slug] = the verified facts of that entity for that row ([] = unavailable, never filled in).
 * differs: at least two entities have data and their reported values differ (a plain flag: nobody wins). comparable (benchmarks only): false when versions or conditions differ,
 * in which case differs stays false and the numbers must not be compared. complete: every compared entity has data. No score, rank or winner is computed here or anywhere.
 * @param array $factsBySlug slug => list of uni_public_facts rows
 */
function uni_fact_matrix(array $factsBySlug): array {
  $slugs = array_keys($factsBySlug); $out = [];
  foreach (uni_fact_types() as $type) {
    $rows = [];
    foreach ($factsBySlug as $slug => $facts) foreach ($facts as $f) {
      if ($f['fact_type'] !== $type) continue;
      $k = uni_norm_title((string)$f['fact_key']) . ($type === 'benchmark' ? '|' . uni_norm_title((string)$f['benchmark']['name']) . '|' . uni_norm_title((string)$f['benchmark']['metric']) : '');
      if (!isset($rows[$k])) { $rows[$k] = ['key' => $f['fact_key'], 'benchmark' => $f['benchmark'] ? ['name' => $f['benchmark']['name'], 'metric' => $f['benchmark']['metric']] : null, 'cells' => array_fill_keys($slugs, [])]; }
      $rows[$k]['cells'][$slug][] = $f;
    }
    $list = [];
    foreach ($rows as $r) {
      $with = array_filter($r['cells'], fn($c) => $c !== []); $sigs = [];
      foreach ($with as $c) { $x = array_map('uni_pub_fact_signature', $c); sort($x); $sigs[] = implode('||', $x); }
      $comparable = true;
      if ($type === 'benchmark') { $ctx = []; foreach ($with as $c) foreach ($c as $f) $ctx[uni_norm_title((string)$f['benchmark']['version']) . '|' . uni_norm_title((string)($f['conditions'] ?? ''))] = true; $comparable = count($ctx) <= 1; }
      $r['differs'] = $comparable && count($with) >= 2 && count(array_unique($sigs)) > 1; $r['comparable'] = $comparable; $r['complete'] = count($with) === count($slugs);
      $list[] = $r;
    }
    usort($list, fn($a, $b) => strcasecmp((string)$a['key'], (string)$b['key']) ?: strcmp((string)json_encode($a['benchmark']), (string)json_encode($b['benchmark'])));
    $out[$type] = ['rows' => $list];
  }
  return $out;
}
