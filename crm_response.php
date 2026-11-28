<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * CRM lead response management (Phase 7B): business-hours engine, transparent priority score, the single follow-up task per enquiry, first HUMAN response
 * tracking, consultations and the owner-only digest/alerts. Reuses the existing CRM tables (crm_leads, crm_tasks, crm_activities, crm_proposals) and adds only
 * database/phase7b_lead_management.sql. Everything degrades gracefully while that migration is not applied (enquiries are still stored exactly as before).
 *
 * Rules: an automatic acknowledgement is NOT a response (it is sent by the system and never logged as a person's activity); only a signed-in user logging a
 * reply, call or meeting, or scheduling/holding a consultation, counts. Nothing here sends anything to a customer: the only e-mail is to the site owner.
 */
require_once __DIR__ . '/crm_core.php';

const CRM_RESPONSE_TARGET_MIN = 240;      // four business hours
const CRM_BH_OPEN = 540;                  // 09:00, minutes after midnight (Europe/Berlin)
const CRM_BH_CLOSE = 1020;                // 17:00
const CRM_STALE_PROPOSAL_DAYS = 7;
const CRM_RESPONSE_KINDS = ['reply', 'call', 'meeting', 'consultation'];

// ---- business hours (pure) ------------------------------------------------------------------------------------------------------------
function crm_bh_tz(): DateTimeZone { static $z = null; return $z ??= new DateTimeZone('Europe/Berlin'); }
function crm_bh_at(int $ts): DateTimeImmutable { return (new DateTimeImmutable('@' . $ts))->setTimezone(crm_bh_tz()); }
/** Easter Sunday (month, day) by the Meeus/Jones/Butcher algorithm. */
function crm_bh_easter(int $y): array {
  $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
  $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451); $n = $h + $l - 7 * $m + 114;
  return [intdiv($n, 31), ($n % 31) + 1];
}
/** Extra non-working dates the owner configures (e.g. a one-off public holiday): AIHUB_CRM_EXTRA_HOLIDAYS="2026-12-24,2026-12-31". Invalid entries are ignored. */
function crm_bh_extra_holidays(): array {
  $raw = function_exists('sec_setting') ? (string)sec_setting('AIHUB_CRM_EXTRA_HOLIDAYS', 'crm_extra_holidays') : ''; $o = [];
  foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $d) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) $o[$d] = 'Owner-configured non-working day';
  return $o;
}
/** Region whose public holidays apply: AIHUB_CRM_HOLIDAY_REGION (BW default = Baden-Wuerttemberg). Supported: DE (nationwide only), BW, BY, BE. Unknown values fall back to BW. */
function crm_bh_region(): string { $r = strtoupper(function_exists('sec_setting') ? trim(sec_setting('AIHUB_CRM_HOLIDAY_REGION', 'crm_holiday_region')) : ''); return in_array($r, ['DE', 'BW', 'BY', 'BE'], true) ? $r : 'BW'; }
/** Public holidays for $year: the nationwide German holidays plus those of the region (default Baden-Wuerttemberg), plus owner-configured extra dates. Time zone stays Europe/Berlin. */
function crm_bh_holidays(int $year, ?array $extra = null, ?string $region = null): array {
  [$em, $ed] = crm_bh_easter($year); $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $em, $ed), crm_bh_tz()); $region = $region ?? crm_bh_region();
  $o = [sprintf('%04d-01-01', $year) => 'New Year', $easter->modify('-2 days')->format('Y-m-d') => 'Good Friday', $easter->modify('+1 day')->format('Y-m-d') => 'Easter Monday', sprintf('%04d-05-01', $year) => 'Labour Day',
    $easter->modify('+39 days')->format('Y-m-d') => 'Ascension Day', $easter->modify('+50 days')->format('Y-m-d') => 'Whit Monday', sprintf('%04d-10-03', $year) => 'German Unity Day', sprintf('%04d-12-25', $year) => 'Christmas Day', sprintf('%04d-12-26', $year) => 'Second Christmas Day'];
  if (in_array($region, ['BW', 'BY'], true)) { $o[sprintf('%04d-01-06', $year)] = 'Epiphany'; $o[$easter->modify('+60 days')->format('Y-m-d')] = 'Corpus Christi'; $o[sprintf('%04d-11-01', $year)] = 'All Saints\' Day'; }
  if ($region === 'BE' && $year >= 2019) $o[sprintf('%04d-03-08', $year)] = 'International Women\'s Day (Berlin)';
  return $o + ($extra ?? crm_bh_extra_holidays());
}
function crm_bh_is_workday(DateTimeImmutable $d, ?array $extra = null): bool { return (int)$d->format('N') < 6 && !isset(crm_bh_holidays((int)$d->format('Y'), $extra)[$d->format('Y-m-d')]); }
function crm_bh_minute_of_day(DateTimeImmutable $d): int { return (int)$d->format('G') * 60 + (int)$d->format('i'); }
/** First instant at or after $t that lies inside business hours. */
function crm_bh_open_at(DateTimeImmutable $t, ?array $extra = null): DateTimeImmutable {
  for ($guard = 0; $guard < 400; $guard++) {
    if (crm_bh_is_workday($t, $extra)) { $m = crm_bh_minute_of_day($t); if ($m < CRM_BH_OPEN) return $t->setTime(9, 0); if ($m < CRM_BH_CLOSE) return $t; }
    $t = $t->modify('+1 day')->setTime(0, 0);
  }
  return $t;
}
/** $start plus $minutes of business time. The clock stops outside Mon-Fri 09:00-17:00 and on holidays; a deadline that falls exactly on 17:00 stays at 17:00. */
function crm_bh_add(DateTimeImmutable $start, int $minutes, ?array $extra = null): DateTimeImmutable {
  $t = crm_bh_open_at($start->setTimezone(crm_bh_tz()), $extra); $left = max(0, $minutes);
  for ($guard = 0; $guard < 800; $guard++) {
    $avail = CRM_BH_CLOSE - crm_bh_minute_of_day($t);
    if ($left <= $avail) return $t->modify('+' . $left . ' minutes');
    $left -= $avail; $t = crm_bh_open_at($t->modify('+1 day')->setTime(0, 0), $extra);
  }
  return $t;
}
/** Business minutes elapsed between two instants (0 when $to is not after $from). */
function crm_bh_minutes(DateTimeImmutable $from, DateTimeImmutable $to, ?array $extra = null): int {
  $from = $from->setTimezone(crm_bh_tz()); $to = $to->setTimezone(crm_bh_tz()); if ($to <= $from) return 0;
  $total = 0; $day = $from->setTime(0, 0);
  for ($guard = 0; $guard < 800 && $day <= $to; $guard++, $day = $day->modify('+1 day')->setTime(0, 0)) {
    if (!crm_bh_is_workday($day, $extra)) continue;
    $s = max($from, $day->setTime(9, 0)); $e = min($to, $day->setTime(17, 0)); if ($e > $s) $total += intdiv($e->getTimestamp() - $s->getTimestamp(), 60);
  }
  return $total;
}
/** Deadline in UTC seconds, floored to the minute (task due times have minute precision). */
function crm_response_due_ts(int $createdTs): int { return intdiv(crm_bh_add(crm_bh_at($createdTs), CRM_RESPONSE_TARGET_MIN)->getTimestamp(), 60) * 60; }

// ---- transparent priority score (pure) ------------------------------------------------------------------------------------------------
/**
 * Priority 0-100 from what the enquirer typed. Every point has a stated reason; the weights are planning assumptions (not measured win rates) and are shown
 * next to the score. Bands: high >= 60, medium 35-59, low < 35. $in: kind, email, company, website, budget, timeline, message.
 * @return array{score:int,band:string,reasons:array<int,array{0:string,1:int}>}
 */
function crm_lead_priority(array $in): array {
  $r = []; $add = function (string $why, int $pts) use (&$r) { if ($pts > 0) $r[] = [$why, $pts]; };
  $add('TrustNova project enquiry', ['development' => 30, 'advertising' => 20, 'partnership' => 15][(string)($in['kind'] ?? '')] ?? 0);
  $add('Budget stated: ' . ($in['budget'] ?? ''), ['over-50k' => 25, '20k-50k' => 25, '5k-20k' => 20, 'under-5k' => 8][(string)($in['budget'] ?? '')] ?? 0);
  $add('Timeline: ' . ($in['timeline'] ?? ''), ['asap' => 15, '1-3-months' => 10, '3-6-months' => 5, 'exploring' => 2][(string)($in['timeline'] ?? '')] ?? 0);
  $email = strtolower(trim((string)($in['email'] ?? ''))); $dom = (string)substr(strrchr($email, '@') ?: '', 1);
  if ($dom !== '' && function_exists('crm_is_free_mail_domain') && !crm_is_free_mail_domain($dom)) $add('Business e-mail domain', 10);
  $co = trim((string)($in['company'] ?? '')); if ($co !== '' && stripos($co, '(individual)') === false) $add('Company named', 5);
  if (trim((string)($in['website'] ?? '')) !== '') $add('Website given', 5);
  $len = mb_strlen(trim((string)($in['message'] ?? ''))); if ($len >= 200) $add('Detailed message (200+ characters)', 5); elseif ($len >= 80) $add('Message with context (80+ characters)', 3);
  $score = min(100, array_sum(array_column($r, 1))); $band = $score >= 60 ? 'high' : ($score >= 35 ? 'medium' : 'low');
  return ['score' => $score, 'band' => $band, 'reasons' => $r];
}
function crm_priority_label(string $band): string { return ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'][$band] ?? 'Low'; }
/** State of one response row at $nowTs: answered | open | overdue, with business minutes used against the 240-minute target. */
function crm_response_state(array $row, int $nowTs): array {
  $created = crm_bh_at((int)strtotime($row['created_at'] . ' UTC'));
  if (!empty($row['first_response_at'])) { $m = crm_bh_minutes($created, crm_bh_at((int)strtotime($row['first_response_at'] . ' UTC'))); return ['state' => 'answered', 'minutes' => $m, 'within_target' => $m <= CRM_RESPONSE_TARGET_MIN]; }
  $due = (int)strtotime($row['due_at'] . ' UTC'); $used = crm_bh_minutes($created, crm_bh_at($nowTs));
  return ['state' => $nowTs > $due ? 'overdue' : 'open', 'minutes' => $used, 'due_ts' => $due];
}
function crm_fmt_berlin(string $utc, string $fmt = 'D j M, H:i'): string { $t = strtotime($utc . ' UTC'); return $t ? crm_bh_at($t)->format($fmt) : ''; }

// ---- storage ---------------------------------------------------------------------------------------------------------------------------
function crm_response_ready(PDO $pdo): bool {
  static $memo = []; $k = spl_object_id($pdo); if (isset($memo[$k])) return $memo[$k];
  try { $pdo->query('SELECT 1 FROM crm_lead_response LIMIT 1'); $pdo->query('SELECT 1 FROM crm_consultations LIMIT 1'); $pdo->query('SELECT 1 FROM crm_notification_log LIMIT 1'); return $memo[$k] = true; } catch (Throwable $e) { return false; }
}
/**
 * Opens the response tracking for one enquiry: exactly one row (primary key = lead id) and exactly one follow-up task. Safe to call again for the same lead:
 * INSERT IGNORE inserts nothing the second time, so no second task is created. Returns [created, taskId|null].
 */
function crm_response_open(PDO $pdo, int $leadId, array $in, ?string $createdUtc = null): array {
  $createdUtc = $createdUtc ?? crm_now(); $ts = (int)strtotime($createdUtc . ' UTC'); $due = crm_response_due_ts($ts); $pri = crm_lead_priority($in);
  $s = $pdo->prepare('INSERT IGNORE INTO crm_lead_response (lead_id, due_at, priority_score, priority_band, priority_reasons, created_at) VALUES (?,?,?,?,?,?)');
  $s->execute([$leadId, crm_now($due), $pri['score'], $pri['band'], json_encode($pri['reasons'], JSON_UNESCAPED_UNICODE), $createdUtc]);
  if ($s->rowCount() !== 1) return [false, null];
  $own = false; if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $own = true; }
  try {
    [$ok, $tid] = crm_task_add($pdo, null, 'lead', $leadId, 'Reply to enquiry #' . $leadId . ' (target: 4 business hours)', substr(crm_now($due), 0, 16));
    $taskId = $ok ? (int)$tid : null; if ($taskId) $pdo->prepare('UPDATE crm_lead_response SET task_id = ? WHERE lead_id = ?')->execute([$taskId, $leadId]);
    if ($own) $pdo->commit(); return [true, $taskId];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_response_open'); return [true, null]; }
}
/** Records the first human response (idempotent: only the first call for a lead changes anything) and completes the auto task. Automatic mail never calls this. */
function crm_response_mark(PDO $pdo, int $leadId, ?array $user, string $kind, ?string $whenUtc = null): bool {
  if ($user === null || empty($user['id']) || !in_array($kind, CRM_RESPONSE_KINDS, true) || !crm_response_ready($pdo)) return false;
  $s = $pdo->prepare('UPDATE crm_lead_response SET first_response_at = ?, first_response_by = ?, first_response_kind = ? WHERE lead_id = ? AND first_response_at IS NULL');
  $s->execute([$whenUtc ?? crm_now(), (int)$user['id'], $kind, $leadId]); if ($s->rowCount() !== 1) return false;
  $t = $pdo->prepare('SELECT task_id FROM crm_lead_response WHERE lead_id = ?'); $t->execute([$leadId]); $tid = (int)$t->fetchColumn(); if ($tid) crm_task_done($pdo, $tid);
  return true;
}
/** Safety net for enquiries stored before the migration (or while it was missing): opens tracking for open, real leads of the last $days days. Idempotent. */
function crm_response_sync(PDO $pdo, int $days = 14): int {
  if (!crm_response_ready($pdo)) return 0; $n = 0;
  $s = $pdo->prepare("SELECT l.id, l.kind, l.message, l.budget_range, l.timeline, l.created_at, c.name AS company, c.website AS website, k.email AS email FROM crm_leads l LEFT JOIN crm_lead_response r ON r.lead_id = l.id LEFT JOIN crm_companies c ON c.id = l.company_id LEFT JOIN crm_contacts k ON k.id = l.contact_id WHERE r.lead_id IS NULL AND l.is_synthetic = 0 AND l.status IN ('new','qualified') AND l.created_at >= ? ORDER BY l.id LIMIT 500");
  $s->execute([crm_now(time() - $days * 86400)]);
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) { [$created] = crm_response_open($pdo, (int)$l['id'], ['kind' => $l['kind'], 'email' => $l['email'], 'company' => $l['company'], 'website' => $l['website'], 'budget' => $l['budget_range'], 'timeline' => $l['timeline'], 'message' => $l['message']], $l['created_at']); if ($created) $n++; }
  return $n;
}
function crm_response_get(PDO $pdo, int $leadId): ?array {
  if (!crm_response_ready($pdo)) return null;
  $s = $pdo->prepare('SELECT r.*, l.created_at AS lead_created_at, u.display_name AS responder FROM crm_lead_response r JOIN crm_leads l ON l.id = r.lead_id LEFT JOIN crm_users u ON u.id = r.first_response_by WHERE r.lead_id = ?'); $s->execute([$leadId]);
  $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) return null; $r['created_at'] = $r['lead_created_at']; $r['reasons'] = (array)json_decode((string)$r['priority_reasons'], true); return $r;
}

// ---- consultations ---------------------------------------------------------------------------------------------------------------------
function crm_consult_statuses(): array { return ['scheduled' => 'Scheduled', 'held' => 'Held', 'cancelled' => 'Cancelled', 'no_show' => 'No-show']; }
/** $local is "YYYY-MM-DD HH:MM" in Europe/Berlin. Scheduling alone is NOT a response (nothing has happened yet): only marking it held, or logging a reply/call/meeting, counts. */
function crm_consult_schedule(PDO $pdo, ?array $user, int $leadId, string $local, int $minutes = 30, string $note = '', ?int $nowTs = null): array {
  if ($user === null || !crm_can($user['role'] ?? '', 'write')) return [false, 'forbidden']; if (!crm_response_ready($pdo)) return [false, 'not_migrated'];
  $nowTs = $nowTs ?? time(); $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', str_replace('T', ' ', trim($local)), crm_bh_tz()); $err = DateTimeImmutable::getLastErrors();
  if (!$d || ($err && ($err['warning_count'] || $err['error_count']))) return [false, 'time_invalid']; $ts = $d->getTimestamp();
  if ($ts < $nowTs - 300 || $ts > $nowTs + 400 * 86400) return [false, 'time_out_of_range']; $minutes = max(15, min(240, $minutes));
  $q = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?'); $q->execute([$leadId]); if (!$q->fetchColumn()) return [false, 'lead_missing'];
  $pdo->prepare('INSERT INTO crm_consultations (lead_id, scheduled_at, duration_min, status, note, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$leadId, crm_now($ts), $minutes, 'scheduled', crm_line($note, 250), $user['id'], crm_now($nowTs), crm_now($nowTs)]);
  $id = (int)$pdo->lastInsertId(); crm_activity_add($pdo, $user, 'lead', $leadId, 'note', 'Consultation scheduled for ' . $d->format('Y-m-d H:i') . ' (Europe/Berlin), ' . $minutes . ' minutes.');
  crm_audit($pdo, $user, 'consultation_schedule', 'lead', $leadId, 'consultation #' . $id); return [true, (string)$id];
}
function crm_consult_set_status(PDO $pdo, ?array $user, int $id, string $to): array {
  if ($user === null || !crm_can($user['role'] ?? '', 'write')) return [false, 'forbidden']; if (!crm_response_ready($pdo) || !isset(crm_consult_statuses()[$to]) || $to === 'scheduled') return [false, 'status_invalid'];
  if ($to === 'held') { $c = $pdo->prepare('SELECT scheduled_at FROM crm_consultations WHERE id = ?'); $c->execute([$id]); $at = $c->fetchColumn(); if ($at !== false && strtotime($at . ' UTC') > time() + 900) return [false, 'not_yet_held']; }   // cannot be held before it starts
  $s = $pdo->prepare("UPDATE crm_consultations SET status = ?, updated_at = ? WHERE id = ? AND status = 'scheduled'"); $s->execute([$to, crm_now(), $id]); if ($s->rowCount() !== 1) return [false, 'not_scheduled'];
  $q = $pdo->prepare('SELECT lead_id FROM crm_consultations WHERE id = ?'); $q->execute([$id]); $lead = (int)$q->fetchColumn();
  crm_activity_add($pdo, $user, 'lead', $lead, 'note', 'Consultation #' . $id . ' marked ' . crm_consult_statuses()[$to] . '.'); if ($to === 'held') crm_response_mark($pdo, $lead, $user, 'consultation');
  crm_audit($pdo, $user, 'consultation_' . $to, 'lead', $lead, 'consultation #' . $id); return [true, 'ok'];
}
function crm_consults_for_lead(PDO $pdo, int $leadId): array { if (!crm_response_ready($pdo)) return []; $s = $pdo->prepare('SELECT * FROM crm_consultations WHERE lead_id = ? ORDER BY scheduled_at DESC LIMIT 20'); $s->execute([$leadId]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function crm_consults_upcoming(PDO $pdo, int $nowTs, int $days = 14, int $limit = 20): array {
  if (!crm_response_ready($pdo)) return [];
  $s = $pdo->prepare("SELECT x.id, x.lead_id, x.scheduled_at, x.duration_min, c.name AS company FROM crm_consultations x JOIN crm_leads l ON l.id = x.lead_id LEFT JOIN crm_companies c ON c.id = l.company_id WHERE x.status = 'scheduled' AND l.is_synthetic = 0 AND x.scheduled_at >= ? AND x.scheduled_at <= ? ORDER BY x.scheduled_at LIMIT " . max(1, min(100, $limit)));
  $s->execute([crm_now($nowTs - 3600), crm_now($nowTs + $days * 86400)]); return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ---- dashboard figures (counts from stored records only; nothing estimated) -------------------------------------------------------------
function crm_unanswered_leads(PDO $pdo, int $nowTs, int $limit = 50): array {
  if (!crm_response_ready($pdo)) return [];
  $s = $pdo->prepare("SELECT l.id, l.kind, l.created_at, r.due_at, r.priority_score, r.priority_band, c.name AS company FROM crm_lead_response r JOIN crm_leads l ON l.id = r.lead_id LEFT JOIN crm_companies c ON c.id = l.company_id WHERE r.first_response_at IS NULL AND l.is_synthetic = 0 AND l.status IN ('new','qualified') ORDER BY (r.due_at < ?) DESC, FIELD(r.priority_band, 'high', 'medium', 'low'), r.due_at LIMIT " . max(1, min(200, $limit)));
  $s->execute([crm_now($nowTs)]); $o = [];
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['state'] = crm_response_state($r + ['first_response_at' => null], $nowTs); $o[] = $r; } return $o;
}
function crm_stale_proposals(PDO $pdo, int $nowTs, int $days = CRM_STALE_PROPOSAL_DAYS): array {
  try {
    $s = $pdo->prepare("SELECT p.id, p.number, p.status, p.updated_at, c.name AS company FROM crm_proposals p JOIN crm_opportunities o ON o.id = p.opportunity_id LEFT JOIN crm_companies c ON c.id = o.company_id WHERE p.status IN ('pending_approval','approved') AND o.is_synthetic = 0 AND p.updated_at < ? ORDER BY p.updated_at LIMIT 50");
    $s->execute([crm_now($nowTs - $days * 86400)]); return $s->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}
function crm_pending_proposal_count(PDO $pdo): ?int {
  try { return (int)$pdo->query("SELECT COUNT(*) FROM crm_proposals p JOIN crm_opportunities o ON o.id = p.opportunity_id WHERE p.status IN ('pending_approval','approved') AND o.is_synthetic = 0")->fetchColumn(); } catch (Throwable $e) { return null; }
}
/** Everything the Overview and the digest need. 'ready' = false while the Phase 7B migration is not applied (callers then say so instead of showing zeros). */
function crm_response_overview(PDO $pdo, ?int $nowTs = null): array {
  $nowTs = $nowTs ?? time(); $o = ['ready' => crm_response_ready($pdo)];
  try { $o['new_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE is_synthetic = 0 AND created_at >= '" . crm_now($nowTs - 7 * 86400) . "'")->fetchColumn(); } catch (Throwable $e) { $o['new_7d'] = null; }
  $o['pending_proposals'] = crm_pending_proposal_count($pdo); $o['stale_proposals'] = count(crm_stale_proposals($pdo, $nowTs));
  try { $o['tasks_overdue'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE done_at IS NULL AND due_at < '" . crm_now($nowTs) . "'")->fetchColumn(); } catch (Throwable $e) { $o['tasks_overdue'] = null; }
  if (!$o['ready']) return $o + ['unanswered' => null, 'overdue' => null, 'high_unanswered' => null, 'avg_minutes' => null, 'within_target_pct' => null, 'answered_30d' => 0, 'consultations' => [], 'unanswered_list' => []];
  $list = crm_unanswered_leads($pdo, $nowTs, 200); $o['unanswered_list'] = array_slice($list, 0, 10); $o['unanswered'] = count($list);
  $o['overdue'] = count(array_filter($list, fn($r) => $r['state']['state'] === 'overdue')); $o['high_unanswered'] = count(array_filter($list, fn($r) => $r['priority_band'] === 'high'));
  $s = $pdo->prepare("SELECT l.created_at, r.first_response_at FROM crm_lead_response r JOIN crm_leads l ON l.id = r.lead_id WHERE r.first_response_at IS NOT NULL AND l.is_synthetic = 0 AND r.first_response_at >= ? LIMIT 500"); $s->execute([crm_now($nowTs - 30 * 86400)]);
  $mins = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $mins[] = crm_response_state($r + ['due_at' => ''], $nowTs)['minutes'];
  $o['answered_30d'] = count($mins); $o['avg_minutes'] = $mins ? (int)round(array_sum($mins) / count($mins)) : null; $o['within_target_pct'] = $mins ? round(count(array_filter($mins, fn($m) => $m <= CRM_RESPONSE_TARGET_MIN)) / count($mins) * 100, 1) : null;
  $o['consultations'] = crm_consults_upcoming($pdo, $nowTs, 14, 10); return $o;
}

// ---- owner-only notifications ----------------------------------------------------------------------------------------------------------
/** True only the first time this (kind, entity, period) is recorded: the unique key makes a duplicate impossible even with concurrent runs. */
function crm_notify_once(PDO $pdo, string $kind, string $etype, int $eid, string $period, ?int $nowTs = null): bool {
  $s = $pdo->prepare('INSERT IGNORE INTO crm_notification_log (kind, entity_type, entity_id, period_key, sent_at) VALUES (?,?,?,?,?)'); $s->execute([$kind, $etype, $eid, $period, crm_now($nowTs)]); return $s->rowCount() === 1;
}
function crm_notify_forget(PDO $pdo, string $kind, string $etype, int $eid, string $period): void { $pdo->prepare('DELETE FROM crm_notification_log WHERE kind = ? AND entity_type = ? AND entity_id = ? AND period_key = ?')->execute([$kind, $etype, $eid, $period]); }
function crm_owner_url(string $path): string { return function_exists('site_url') ? site_url($path) : $path; }
/** Data for the digest: only counts, company names, kinds, ids and times. No message text and no contact details are put into e-mail. */
function crm_digest_data(PDO $pdo, int $nowTs): array {
  $d = ['unanswered' => crm_unanswered_leads($pdo, $nowTs, 30), 'stale' => crm_stale_proposals($pdo, $nowTs), 'consults' => crm_consults_upcoming($pdo, $nowTs, 3, 10), 'tasks' => [], 'new_yesterday' => 0];
  try { $s = $pdo->prepare("SELECT t.id, t.entity_type, t.entity_id, t.title, t.due_at FROM crm_tasks t WHERE t.done_at IS NULL AND t.due_at < ? ORDER BY t.due_at LIMIT 30"); $s->execute([crm_now($nowTs)]); $d['tasks'] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  $b = crm_bh_at($nowTs)->setTime(0, 0); $from = $b->modify('-1 day')->getTimestamp(); $to = $b->getTimestamp();
  try { $s = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE is_synthetic = 0 AND created_at >= ? AND created_at < ?'); $s->execute([crm_now($from), crm_now($to)]); $d['new_yesterday'] = (int)$s->fetchColumn(); } catch (Throwable $e) {}
  return $d;
}
function crm_digest_is_empty(array $d): bool { return !$d['unanswered'] && !$d['stale'] && !$d['consults'] && !$d['tasks']; }
/** @return array{subject:string,text:string} Plain text; no customer messages or contact details. */
function crm_digest_compose(array $d, int $nowTs): array {
  $day = crm_bh_at($nowTs)->format('D j M Y'); $L = ["TrustNova / AIHubpedia CRM digest, $day (Europe/Berlin)", 'Owner only. Nothing was sent to any customer.', ''];
  $over = count(array_filter($d['unanswered'], fn($r) => $r['state']['state'] === 'overdue'));
  $L[] = 'Enquiries without a human reply: ' . count($d['unanswered']) . ($over ? " ($over past the 4-business-hour target)" : '') . '; new yesterday: ' . (int)$d['new_yesterday'];
  foreach ($d['unanswered'] as $r) $L[] = sprintf('  - #%d %s, %s priority (%d), %s, due %s  %s', $r['id'], $r['company'] ?: 'individual', crm_priority_label($r['priority_band']), $r['priority_score'], $r['state']['state'] === 'overdue' ? 'OVERDUE' : 'open', crm_fmt_berlin($r['due_at']), crm_owner_url('/crm.php?v=lead&id=' . $r['id']));
  if ($d['tasks']) { $L[] = ''; $L[] = 'Overdue follow-ups: ' . count($d['tasks']); foreach ($d['tasks'] as $t) $L[] = sprintf('  - %s (due %s)  %s', $t['title'], crm_fmt_berlin($t['due_at']), crm_owner_url('/crm.php?v=tasks')); }
  if ($d['stale']) { $L[] = ''; $L[] = 'Proposals waiting more than ' . CRM_STALE_PROPOSAL_DAYS . ' days: ' . count($d['stale']); foreach ($d['stale'] as $p) $L[] = sprintf('  - %s, %s, last change %s  %s', $p['number'], $p['company'] ?: 'company', crm_fmt_berlin($p['updated_at'], 'j M'), crm_owner_url('/crm.php?v=proposal&id=' . $p['id'])); }
  if ($d['consults']) { $L[] = ''; $L[] = 'Consultations in the next 3 days:'; foreach ($d['consults'] as $c) $L[] = sprintf('  - %s, %s (%d min), lead #%d', crm_fmt_berlin($c['scheduled_at'], 'D j M, H:i'), $c['company'] ?: 'company', $c['duration_min'], $c['lead_id']); }
  $L[] = ''; $L[] = 'Priority scores are transparent planning aids (see the lead page for the reasons), not predictions. Reply or log a call in the CRM to stop an enquiry counting as unanswered.';
  return ['subject' => '[AIHubpedia CRM] ' . count($d['unanswered']) . ' unanswered' . ($over ? ", $over overdue" : '') . ' - daily digest ' . crm_bh_at($nowTs)->format('Y-m-d'), 'text' => implode("\n", $L)];
}
/** Sends the daily digest to the site owner on working days only, once per Berlin date. Returns a status word (sent, dry-run, empty, not_workday, already_sent, no_owner, failed). */
function crm_send_digest(PDO $pdo, int $nowTs, bool $dry = false, bool $force = false): string {
  $now = crm_bh_at($nowTs); if (!$force && !crm_bh_is_workday($now)) return 'not_workday'; $to = function_exists('site_owner_email') ? site_owner_email() : ''; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'no_owner';
  crm_response_sync($pdo); $d = crm_digest_data($pdo, $nowTs); if (crm_digest_is_empty($d)) return 'empty'; $m = crm_digest_compose($d, $nowTs); if ($dry) { echo $m['subject'] . "\n\n" . $m['text'] . "\n"; return 'dry-run'; }
  $key = $now->format('Y-m-d'); if (!crm_notify_once($pdo, 'digest', 'owner', 0, $key, $nowTs)) return 'already_sent';
  $ok = send_mail_reliable($to, $m['subject'], '<pre style="font:14px/1.5 monospace;white-space:pre-wrap">' . htmlspecialchars($m['text'], ENT_QUOTES) . '</pre>', $m['text'], '', [], ['kind' => 'crm_digest', 'ref' => $key]);
  if (!$ok && function_exists('mail_last_error') && mail_last_error() === 'invalid_recipient') { crm_notify_forget($pdo, 'digest', 'owner', 0, $key); return 'failed'; }
  return 'sent';   // a failed first attempt is spooled and retried by scripts/mail_spool_flush.php; the log row stays so the digest is not sent twice
}
/** One alert e-mail per run listing enquiries that have JUST passed their target and were never alerted before (dedupe: one alert per lead, ever). Working hours only. */
/** Runs $fn only if no other process holds the named database lock (parallel cron runs: the loser returns 'busy' and its work is picked up by the next run). */
function crm_with_lock(PDO $pdo, string $name, callable $fn): string {
  $g = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $g->execute(['aihub_crm_' . $name]); if ((int)$g->fetchColumn() !== 1) return 'busy';
  try { return $fn(); } finally { $r = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $r->execute(['aihub_crm_' . $name]); }
}
function crm_send_alerts(PDO $pdo, int $nowTs, bool $dry = false): string { return crm_with_lock($pdo, 'alerts', fn() => crm_send_alerts_locked($pdo, $nowTs, $dry)); }
function crm_send_alerts_locked(PDO $pdo, int $nowTs, bool $dry): string {
  $now = crm_bh_at($nowTs); if (!crm_bh_is_workday($now) || crm_bh_minute_of_day($now) < CRM_BH_OPEN || crm_bh_minute_of_day($now) > CRM_BH_CLOSE) return 'outside_hours';
  $to = function_exists('site_owner_email') ? site_owner_email() : ''; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'no_owner'; crm_response_sync($pdo);
  $new = array_filter(crm_unanswered_leads($pdo, $nowTs, 100), fn($r) => $r['state']['state'] === 'overdue'); if (!$new) return 'none';
  $fresh = []; foreach ($new as $r) { if ($dry) { $fresh[] = $r; continue; } if (crm_notify_once($pdo, 'lead_overdue', 'lead', (int)$r['id'], 'v1', $nowTs)) $fresh[] = $r; }
  if (!$fresh) return 'none'; $L = ['These enquiries have passed the 4-business-hour response target without a human reply. Owner only; nothing was sent to a customer.', '']; foreach ($fresh as $r) $L[] = sprintf('  - #%d %s, %s priority, due %s  %s', $r['id'], $r['company'] ?: 'individual', crm_priority_label($r['priority_band']), crm_fmt_berlin($r['due_at']), crm_owner_url('/crm.php?v=lead&id=' . $r['id']));
  $subject = '[AIHubpedia CRM] ' . count($fresh) . ' enquiry(ies) past the response target'; if ($dry) { echo $subject . "\n" . implode("\n", $L) . "\n"; return 'dry-run'; }
  send_mail_reliable($to, $subject, '<pre style="font:14px/1.5 monospace;white-space:pre-wrap">' . htmlspecialchars(implode("\n", $L), ENT_QUOTES) . '</pre>', implode("\n", $L), '', [], ['kind' => 'crm_alert', 'ref' => (string)count($fresh)]); return 'sent';
}

// ---- admin UI fragments (HTML; every dynamic value goes through crm_h) -----------------------------------------------------------------
function crm_priority_badge(?string $band, $score = null): string {
  if ($band === null || $band === '') return '<span class="small">—</span>';
  $cls = ['high' => 'badge3 badge3--warn', 'medium' => 'badge3', 'low' => 'badge3'][$band] ?? 'badge3';
  return '<span class="' . $cls . '" title="Score ' . (int)$score . '/100">' . crm_h(crm_priority_label($band)) . ' ' . (int)$score . '</span>';
}
/** $r needs created_at, and when tracked: due_at, first_response_at (columns absent/NULL = not tracked or unanswered). */
function crm_response_badge(array $r): string {
  if (!array_key_exists('due_at', $r) || $r['due_at'] === null) return '<span class="small">not tracked</span>';
  $st = crm_response_state($r, time());
  if ($st['state'] === 'answered') return '<span class="badge3 badge3--ok">Answered ' . (int)$st['minutes'] . ' min</span>';
  return $st['state'] === 'overdue' ? '<span class="badge3 badge3--warn">Overdue, was due ' . crm_h(crm_fmt_berlin($r['due_at'])) . '</span>' : '<span class="badge3">Due ' . crm_h(crm_fmt_berlin($r['due_at'])) . '</span>';
}
function crm_response_panel(PDO $pdo, array $u, int $leadId, bool $canW): string {
  $r = crm_response_get($pdo, $leadId);
  if (!$r) return '<div class="panel"><h3>Response tracking</h3><p class="small">' . (crm_response_ready($pdo) ? 'Not tracked (older enquiry).' : 'Not available until the Phase 7B database migration is applied.') . '</p></div>';
  $st = crm_response_state($r, time()); $o = '<div class="panel"><h3>Response tracking</h3><p>' . crm_priority_badge($r['priority_band'], $r['priority_score']) . ' ' . crm_response_badge($r) . '</p>';
  $o .= '<p class="small">Target: first human reply within 4 business hours (Mon-Fri 09:00-17:00 Europe/Berlin, public holidays excluded). Received ' . crm_h(crm_fmt_berlin($r['created_at'], 'D j M Y, H:i')) . ', due ' . crm_h(crm_fmt_berlin($r['due_at'], 'D j M Y, H:i')) . '. Automatic acknowledgements do not count.';
  if ($st['state'] === 'answered') $o .= ' First response: ' . crm_h(crm_fmt_berlin($r['first_response_at'], 'D j M, H:i')) . ' by ' . crm_h($r['responder'] ?: 'user') . ' (' . crm_h($r['first_response_kind']) . '), ' . (int)$st['minutes'] . ' business minutes.';
  $o .= '</p><details><summary class="small">Why this priority (planning weights, not a prediction)</summary><ul class="small">';
  foreach ($r['reasons'] as [$why, $pts]) $o .= '<li>+' . (int)$pts . ' ' . crm_h($why) . '</li>';
  $o .= '<li><strong>Total ' . (int)$r['priority_score'] . '/100</strong> (high 60+, medium 35-59, low below 35)</li></ul></details>';
  $c = crm_consults_for_lead($pdo, $leadId);
  if ($c) { $o .= '<h4>Consultations</h4><ul class="small">'; foreach ($c as $x) { $o .= '<li>' . crm_h(crm_fmt_berlin($x['scheduled_at'], 'D j M Y, H:i')) . ' (' . (int)$x['duration_min'] . ' min) · ' . crm_h(crm_consult_statuses()[$x['status']] ?? $x['status']) . ($x['note'] !== '' ? ' · ' . crm_h($x['note']) : '');
      if ($canW && $x['status'] === 'scheduled') foreach (['held' => 'Held', 'no_show' => 'No-show', 'cancelled' => 'Cancel'] as $k => $lab) $o .= ' ' . crm_form('consult_status', ['id' => $x['id'], 'eid' => $leadId, 'to' => $k], '', $lab, 'inline'); $o .= '</li>'; } $o .= '</ul>'; }
  if ($canW) $o .= crm_form('consult_schedule', ['eid' => $leadId], crm_f_input('when', 'Consultation (Europe/Berlin, YYYY-MM-DD HH:MM)', '', 'text', ' placeholder="2026-12-01 10:00"') . crm_f_input('minutes', 'Minutes', '30') . crm_f_input('note', 'Note (optional)'), 'Schedule consultation');
  return $o . '</div>';
}

/** Weekly reminder for overdue follow-up tasks and stale proposals: each task/proposal is mentioned at most once per 7-day window (dedupe key), working days and hours only. */
function crm_send_reminders(PDO $pdo, int $nowTs, bool $dry = false): string { return crm_with_lock($pdo, 'reminders', fn() => crm_send_reminders_locked($pdo, $nowTs, $dry)); }
function crm_send_reminders_locked(PDO $pdo, int $nowTs, bool $dry): string {
  $now = crm_bh_at($nowTs); if (!crm_bh_is_workday($now) || crm_bh_minute_of_day($now) < CRM_BH_OPEN || crm_bh_minute_of_day($now) > CRM_BH_CLOSE) return 'outside_hours';
  $to = function_exists('site_owner_email') ? site_owner_email() : ''; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'no_owner';
  $win = 'w' . intdiv($nowTs, 7 * 86400); $d = crm_digest_data($pdo, $nowTs); $fresh = ['tasks' => [], 'stale' => []];
  foreach ($d['tasks'] as $t) if ($dry || crm_notify_once($pdo, 'task_overdue', 'task', (int)$t['id'], $win, $nowTs)) $fresh['tasks'][] = $t;
  foreach ($d['stale'] as $p) if ($dry || crm_notify_once($pdo, 'proposal_stale', 'proposal', (int)$p['id'], $win, $nowTs)) $fresh['stale'][] = $p;
  if (!$fresh['tasks'] && !$fresh['stale']) return 'none'; $L = ['Reminders for the owner only. Nothing was sent to any customer.', ''];
  if ($fresh['tasks']) { $L[] = 'Overdue follow-ups:'; foreach ($fresh['tasks'] as $t) $L[] = sprintf('  - %s (due %s)  %s', $t['title'], crm_fmt_berlin($t['due_at']), crm_owner_url('/crm.php?v=tasks')); }
  if ($fresh['stale']) { $L[] = ''; $L[] = 'Proposals with no change for more than ' . CRM_STALE_PROPOSAL_DAYS . ' days:'; foreach ($fresh['stale'] as $p) $L[] = sprintf('  - %s, %s, last change %s  %s', $p['number'], $p['company'] ?: 'company', crm_fmt_berlin($p['updated_at'], 'j M'), crm_owner_url('/crm.php?v=proposal&id=' . $p['id'])); }
  $subject = '[AIHubpedia CRM] reminders: ' . count($fresh['tasks']) . ' task(s), ' . count($fresh['stale']) . ' proposal(s)'; if ($dry) { echo $subject . "\n" . implode("\n", $L) . "\n"; return 'dry-run'; }
  send_mail_reliable($to, $subject, '<pre style="font:14px/1.5 monospace;white-space:pre-wrap">' . htmlspecialchars(implode("\n", $L), ENT_QUOTES) . '</pre>', implode("\n", $L), '', [], ['kind' => 'crm_reminder', 'ref' => $win]); return 'sent';
}
