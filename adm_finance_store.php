<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8D Finance store. The CRM and the legacy submissions table are READ ONLY here (SELECT only). Writes go to the three fin_* tables only, and nothing is deleted:
// a wrong record is voided with a reason. The MySQL store is NOT verified by database tests in this phase (owner decision).
require_once __DIR__ . '/adm_finance_core.php';

interface AdmFinanceStore {
  public function finReady(): bool;                       // the three fin_* tables exist (writes possible)
  public function contracts(): array;                     // CRM contracts + opp_kind + package (read only)
  public function revenueEntries(): array;                // CRM invoiced / received entries (read only)
  public function pipeline(): array;                      // open CRM opportunities (read only)
  public function campaigns(): array;                     // CRM campaigns (read only)
  public function legacyPayments(): array;                // public listing submissions with payment status (read only)
  public function expenses(): array;
  public function adjustments(): array;
  public function targets(): array;
  public function addExpense(array $e, string $actor): array;
  public function voidExpense(int $id, string $actor, string $reason): array;
  public function addAdjustment(array $a, string $actor): array;
  public function voidAdjustment(int $id, string $actor, string $reason): array;
  public function setTarget(string $month, string $metric, int $minor, string $label, string $actor): array;
}
/** Everything the report needs, in the shape admf_report() expects. */
function admf_load(AdmFinanceStore $s): array { return ['contracts' => $s->contracts(), 'revenue' => $s->revenueEntries(), 'adjustments' => $s->adjustments(), 'expenses' => $s->expenses(), 'legacy' => $s->legacyPayments(), 'pipeline' => $s->pipeline(), 'campaigns' => $s->campaigns()]; }

final class AdmFinanceMemoryStore implements AdmFinanceStore {
  public array $d; private int $seq = 0; public bool $ready = true; public int $clock = 1700000000;
  public function __construct(array $data = []) { $this->d = $data + ['contracts' => [], 'revenue' => [], 'pipeline' => [], 'campaigns' => [], 'legacy' => [], 'expenses' => [], 'adjustments' => [], 'targets' => []]; foreach (['expenses', 'adjustments', 'targets'] as $k) foreach ($this->d[$k] as $r) $this->seq = max($this->seq, (int)($r['id'] ?? 0)); }
  public function finReady(): bool { return $this->ready; }
  public function contracts(): array { return $this->d['contracts']; } public function revenueEntries(): array { return $this->d['revenue']; } public function pipeline(): array { return $this->d['pipeline']; }
  public function campaigns(): array { return $this->d['campaigns']; } public function legacyPayments(): array { return $this->d['legacy']; }
  public function expenses(): array { return array_reverse($this->d['expenses']); } public function adjustments(): array { return array_reverse($this->d['adjustments']); } public function targets(): array { return array_reverse($this->d['targets']); }
  private function now(): string { return gmdate('Y-m-d H:i:s', ++$this->clock); }
  public function addExpense(array $e, string $actor): array { $id = ++$this->seq; $this->d['expenses'][] = $e + ['id' => $id, 'created_by' => $actor, 'created_at' => $this->now(), 'voided_at' => null, 'voided_by' => null, 'void_reason' => '', 'is_synthetic' => 0]; return ['ok' => true, 'id' => $id]; }
  private function voidIn(string $k, int $id, string $actor, string $why): array { foreach ($this->d[$k] as $i => $r) if ((int)$r['id'] === $id) { if (!empty($r['voided_at'])) return ['ok' => false, 'error' => 'voided']; $this->d[$k][$i]['voided_at'] = $this->now(); $this->d[$k][$i]['voided_by'] = $actor; $this->d[$k][$i]['void_reason'] = $why; return ['ok' => true]; } return ['ok' => false, 'error' => 'missing']; }
  public function voidExpense(int $id, string $actor, string $reason): array { return $this->voidIn('expenses', $id, $actor, $reason); }
  public function addAdjustment(array $a, string $actor): array { $id = ++$this->seq; $this->d['adjustments'][] = $a + ['id' => $id, 'created_by' => $actor, 'created_at' => $this->now(), 'voided_at' => null, 'voided_by' => null, 'void_reason' => '', 'is_synthetic' => 0]; return ['ok' => true, 'id' => $id]; }
  public function voidAdjustment(int $id, string $actor, string $reason): array { return $this->voidIn('adjustments', $id, $actor, $reason); }
  public function setTarget(string $month, string $metric, int $minor, string $label, string $actor): array {
    foreach ($this->d['targets'] as $i => $t) if ($t['month'] === $month && $t['metric'] === $metric && empty($t['superseded_at'])) $this->d['targets'][$i]['superseded_at'] = $this->now();
    $this->d['targets'][] = ['id' => ++$this->seq, 'month' => $month, 'metric' => $metric, 'amount_minor' => $minor, 'currency' => ADMF_PRIMARY, 'label' => $label, 'created_by' => $actor, 'created_at' => $this->now(), 'superseded_at' => null]; return ['ok' => true];
  }
}

/** MySQL store. Reads CRM tables with SELECT only. A missing table reads as "no rows" so the reports show honest empty states; writes need finReady(). */
final class AdmFinancePdoStore implements AdmFinanceStore {
  public function __construct(private PDO $pdo) {}
  private function rows(string $sql): array { try { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } }
  public function finReady(): bool { foreach (['fin_expense_entry', 'fin_target', 'fin_adjustment'] as $t) { try { $this->pdo->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { return false; } } return true; }
  public function contracts(): array { return $this->rows("SELECT k.id, k.company_id, c.name AS company_name, k.status, k.start_date, k.end_date, k.billing_period, k.amount_minor, k.currency, k.renewal_date, k.cancelled_at, k.confirmed_at, k.opportunity_id, k.is_synthetic, o.kind AS opp_kind, (SELECT cp.package FROM crm_campaigns cp WHERE o.lead_id IS NOT NULL AND cp.lead_id = o.lead_id ORDER BY cp.id LIMIT 1) AS package FROM crm_contracts k LEFT JOIN crm_companies c ON c.id = k.company_id LEFT JOIN crm_opportunities o ON o.id = k.opportunity_id ORDER BY k.id"); }
  public function revenueEntries(): array { return $this->rows('SELECT id, contract_id, kind, amount_minor, currency, entry_date, reference, is_synthetic FROM crm_revenue_entries ORDER BY entry_date, id'); }
  public function pipeline(): array { return $this->rows("SELECT stage, kind, amount_minor, currency, is_synthetic FROM crm_opportunities WHERE stage NOT IN ('won','lost')"); }
  public function campaigns(): array { return $this->rows('SELECT id, package, status, quote_minor, quote_currency, start_date, end_date, is_synthetic FROM crm_campaigns'); }
  public function legacyPayments(): array { return $this->rows('SELECT id, name, plan, price, currency, status, payment_status, created_at FROM submissions ORDER BY id'); }
  public function expenses(): array { return $this->rows('SELECT id, entry_date, category, vendor, description, amount_minor, currency, recurrence, recurrence_end, reference, created_by, created_at, voided_at, voided_by, void_reason, is_synthetic FROM fin_expense_entry ORDER BY entry_date DESC, id DESC LIMIT 2000'); }
  public function adjustments(): array { return $this->rows('SELECT id, contract_id, kind, amount_minor, currency, entry_date, reference, note, created_by, created_at, voided_at, voided_by, void_reason, is_synthetic FROM fin_adjustment ORDER BY entry_date DESC, id DESC LIMIT 2000'); }
  public function targets(): array { return $this->rows('SELECT id, month, metric, amount_minor, currency, label, created_by, created_at, superseded_at FROM fin_target ORDER BY month DESC, id DESC LIMIT 500'); }
  private function tx(callable $f): array { try { $this->pdo->beginTransaction(); $r = $f(); if (!$r['ok']) { $this->pdo->rollBack(); return $r; } $this->pdo->commit(); return $r; } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); sec_log_exception($e, 'finance store'); return ['ok' => false, 'error' => 'failed']; } }
  public function addExpense(array $e, string $actor): array {
    return $this->tx(function () use ($e, $actor) { $this->pdo->prepare('INSERT INTO fin_expense_entry (entry_date, category, vendor, description, amount_minor, currency, recurrence, recurrence_end, reference, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$e['entry_date'], $e['category'], $e['vendor'], $e['description'], $e['amount_minor'], $e['currency'], $e['recurrence'], $e['recurrence_end'], $e['reference'], $this->uid($actor), gmdate('Y-m-d H:i:s')]); return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId()]; });
  }
  /** created_by columns hold the CRM user id when the actor is a known CRM account; the username is always in the audit log. */
  private function uid(string $actor): ?int { try { $q = $this->pdo->prepare('SELECT id FROM crm_users WHERE username = ?'); $q->execute([$actor]); $v = $q->fetchColumn(); return $v === false ? null : (int)$v; } catch (Throwable $e) { return null; } }
  private function voidIn(string $table, int $id, string $actor, string $why): array {
    return $this->tx(function () use ($table, $id, $actor, $why) { $q = $this->pdo->prepare("SELECT voided_at FROM `$table` WHERE id = ? FOR UPDATE"); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); if (!$r) return ['ok' => false, 'error' => 'missing']; if ($r['voided_at'] !== null) return ['ok' => false, 'error' => 'voided'];
      $this->pdo->prepare("UPDATE `$table` SET voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s'), $this->uid($actor), mb_substr($why, 0, 190), $id]); return ['ok' => true]; });
  }
  public function voidExpense(int $id, string $actor, string $reason): array { return $this->voidIn('fin_expense_entry', $id, $actor, $reason); }
  public function voidAdjustment(int $id, string $actor, string $reason): array { return $this->voidIn('fin_adjustment', $id, $actor, $reason); }
  public function addAdjustment(array $a, string $actor): array {
    return $this->tx(function () use ($a, $actor) { $this->pdo->prepare('INSERT INTO fin_adjustment (contract_id, kind, amount_minor, currency, entry_date, reference, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$a['contract_id'], $a['kind'], $a['amount_minor'], $a['currency'], $a['entry_date'], $a['reference'], $a['note'], $this->uid($actor), gmdate('Y-m-d H:i:s')]); return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId()]; });
  }
  public function setTarget(string $month, string $metric, int $minor, string $label, string $actor): array {
    return $this->tx(function () use ($month, $metric, $minor, $label, $actor) { $d = $month . '-01'; $now = gmdate('Y-m-d H:i:s');
      $this->pdo->prepare('UPDATE fin_target SET superseded_at = ? WHERE month = ? AND metric = ? AND superseded_at IS NULL')->execute([$now, $d, $metric]);
      $this->pdo->prepare('INSERT INTO fin_target (month, metric, amount_minor, currency, label, created_by, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$d, $metric, $minor, ADMF_PRIMARY, $label, $this->uid($actor), $now]); return ['ok' => true]; });
  }
}
