<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Lead status workflow and aggregate reporting for the admin prototype (Phase 4, P2).
 * Reports show raw counts only. No conversion rates, revenue or benchmarks are computed or invented.
 */

function lead_statuses(): array {
  return ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'proposal_sent' => 'Proposal sent', 'won' => 'Won', 'lost' => 'Lost', 'spam' => 'Spam'];
}

/** Allowed moves. Anything can be marked spam; closed leads (won/lost/spam) can be reopened to "contacted". */
function lead_status_transitions(): array {
  return [
    'new' => ['contacted', 'qualified', 'lost', 'spam'],
    'contacted' => ['qualified', 'proposal_sent', 'lost', 'spam'],
    'qualified' => ['contacted', 'proposal_sent', 'won', 'lost', 'spam'],
    'proposal_sent' => ['qualified', 'won', 'lost', 'spam'],
    'won' => ['contacted'], 'lost' => ['contacted'], 'spam' => ['new'],
  ];
}

function lead_can_move(string $from, string $to): bool { return in_array($to, lead_status_transitions()[$from] ?? [], true); }

/** Change a lead's status with history. @return string 'ok' | 'not_found' | 'invalid_transition' | 'unknown_status' */
function lead_set_status(PDO $pdo, int $id, string $to): string {
  if (!isset(lead_statuses()[$to])) return 'unknown_status';
  $s = $pdo->prepare('SELECT status FROM leads WHERE id = ?'); $s->execute([$id]);
  $from = $s->fetchColumn();
  if ($from === false) return 'not_found';
  if (!lead_can_move((string)$from, $to)) return 'invalid_transition';
  $pdo->beginTransaction();
  try {
    $hasChanged = db_column_exists($pdo, 'leads', 'status_changed_at');
    $pdo->prepare('UPDATE leads SET status = ?' . ($hasChanged ? ', status_changed_at = NOW()' : '') . ' WHERE id = ?')->execute([$to, $id]);
    if (db_table_exists('lead_status_history')) $pdo->prepare('INSERT INTO lead_status_history (lead_id, old_status, new_status, changed_at) VALUES (?,?,?,NOW())')->execute([$id, (string)$from, $to]);
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  return 'ok';
}

/**
 * Sends the owner notification for a stored lead again (admin action) and records the outcome.
 * @return string 'sent' | 'failed' | 'not_found' | 'unsupported' (tracking columns missing)
 */
function lead_retry_notification(PDO $pdo, int $id): string {
  if (!db_column_exists($pdo, 'leads', 'notify_status')) return 'unsupported';
  $row = lead_get($pdo, $id);
  if (!$row) return 'not_found';
  $c = array_intersect_key($row, array_flip(['lead_type', 'name', 'email', 'company', 'website', 'budget', 'timeline', 'message', 'source_page', 'package_interest', 'campaign_goal', 'attr_source', 'attr_medium', 'attr_campaign', 'ref_tool']));
  $sent = false; try { $sent = lead_notify_owner($c, enquiry_reference('L', $id), $id, false); } catch (Throwable $e) { mail_last_error('smtp_other'); sec_log_exception($e, 'lead_retry'); }
  lead_record_notification($pdo, $id, $sent, mail_last_error());
  return $sent ? 'sent' : 'failed';
}

/** "ja***@example.com": the list view never shows a full address; the detail view does and is logged. */
function lead_mask_email(string $email): string {
  $p = strpos($email, '@');
  if ($p === false) return '***';
  return mb_substr($email, 0, min(2, max(1, $p))) . '***' . substr($email, $p);
}

/** @return array{rows:array,total:int} filters: type, status, days, q (company/name substring), page */
function leads_list(PDO $pdo, array $f, int $perPage = 25): array {
  $where = []; $args = [];
  if (!empty($f['type']) && isset(lead_types()[$f['type']])) { $where[] = 'lead_type = ?'; $args[] = $f['type']; }
  if (!empty($f['status']) && isset(lead_statuses()[$f['status']])) { $where[] = 'status = ?'; $args[] = $f['status']; }
  if (!empty($f['days'])) { $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$f['days'] . ' DAY)'; }
  if (!empty($f['q'])) { $where[] = '(company LIKE ? OR name LIKE ?)'; $like = '%' . addcslashes(mb_substr((string)$f['q'], 0, 60), '%_\\') . '%'; $args[] = $like; $args[] = $like; }
  $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
  $c = $pdo->prepare("SELECT COUNT(*) FROM leads$w"); $c->execute($args);
  $page = max(1, (int)($f['page'] ?? 1));
  $s = $pdo->prepare("SELECT id, created_at, lead_type, name, email, company, status, source_page" . (db_column_exists($pdo, 'leads', 'package_interest') ? ', package_interest, attr_source, attr_campaign, is_synthetic' : ", '' AS package_interest, '' AS attr_source, '' AS attr_campaign, 0 AS is_synthetic") . (db_column_exists($pdo, 'leads', 'notify_status') ? ', notify_status, notify_error, notify_attempts' : ", '' AS notify_status, '' AS notify_error, 0 AS notify_attempts") . " FROM leads$w ORDER BY created_at DESC, id DESC LIMIT " . (int)$perPage . ' OFFSET ' . (($page - 1) * (int)$perPage));
  $s->execute($args);
  return ['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$c->fetchColumn()];
}

function lead_get(PDO $pdo, int $id): ?array {
  $s = $pdo->prepare('SELECT * FROM leads WHERE id = ?'); $s->execute([$id]);
  $r = $s->fetch(PDO::FETCH_ASSOC);
  if (!$r) return null;
  $r['history'] = [];
  if (db_table_exists('lead_status_history')) { $h = $pdo->prepare('SELECT old_status, new_status, changed_at FROM lead_status_history WHERE lead_id = ? ORDER BY changed_at, id'); $h->execute([$id]); $r['history'] = $h->fetchAll(PDO::FETCH_ASSOC); }
  return $r;
}

/** Aggregate report: counts only, with the period stated. */
function leads_report(PDO $pdo, int $days = 90): array {
  $since = date('Y-m-d H:i:s', strtotime("-$days days"));
  $ext = db_column_exists($pdo, 'leads', 'attr_source');
  $q = function (string $sql) use ($pdo, $since) { $s = $pdo->prepare($sql); $s->execute([$since]); return $s->fetchAll(PDO::FETCH_ASSOC); };
  $r = [
    'days' => $days,
    'total' => (int)$q('SELECT COUNT(*) AS n FROM leads WHERE created_at >= ?')[0]['n'],
    'by_type' => $q('SELECT lead_type, COUNT(*) AS n FROM leads WHERE created_at >= ? GROUP BY lead_type ORDER BY n DESC'),
    'by_status' => $q('SELECT status, COUNT(*) AS n FROM leads WHERE created_at >= ? GROUP BY status ORDER BY n DESC'),
    'by_page' => $q("SELECT source_page, COUNT(*) AS n FROM leads WHERE created_at >= ? AND source_page <> '' GROUP BY source_page ORDER BY n DESC LIMIT 10"),
    'by_source' => $ext ? $q("SELECT attr_source, attr_campaign, COUNT(*) AS n FROM leads WHERE created_at >= ? AND (attr_source <> '' OR attr_campaign <> '') GROUP BY attr_source, attr_campaign ORDER BY n DESC LIMIT 10") : [],
    'by_package' => $ext ? $q("SELECT package_interest, COUNT(*) AS n FROM leads WHERE created_at >= ? AND package_interest <> '' GROUP BY package_interest ORDER BY n DESC") : [],
    'synthetic' => $ext ? (int)$q('SELECT COUNT(*) AS n FROM leads WHERE created_at >= ? AND is_synthetic = 1')[0]['n'] : 0,
    'tracks_notifications' => db_column_exists($pdo, 'leads', 'notify_status'),
    'notify_failed' => db_column_exists($pdo, 'leads', 'notify_status') ? (int)$q("SELECT COUNT(*) AS n FROM leads WHERE created_at >= ? AND notify_status = 'failed'")[0]['n'] : 0,
    'notify_failed_by_reason' => db_column_exists($pdo, 'leads', 'notify_status') ? $q("SELECT notify_error, COUNT(*) AS n FROM leads WHERE created_at >= ? AND notify_status = 'failed' GROUP BY notify_error ORDER BY n DESC") : [],
  ];
  // Stage counts: how many leads currently sit at or beyond each stage. Raw counts; no rates.
  $order = ['new', 'contacted', 'qualified', 'proposal_sent', 'won'];
  $by = array_column($r['by_status'], 'n', 'status');
  $r['stages'] = [];
  foreach ($order as $i => $st) { $n = 0; foreach (array_slice($order, $i) as $later) $n += (int)($by[$later] ?? 0); $r['stages'][$st] = $n; }
  return $r;
}
