<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Analytics A2-A4: the database store. READ ONLY: every query is a SELECT with bound parameters over the Phase 8B rollup tables, plus one live count over page_views (active visitors)
// and the Phase 8C placement table. It never writes, never creates a table and never reads a raw user agent or IP (none is stored).
require_once __DIR__ . '/adm_an2_core.php';

final class AdmAnPdoStore implements AdmAnStore {
  public function __construct(private PDO $pdo) {}
  public function status(): array {
    $s = adma_status($this->pdo); $s['device_col'] = adma_column($this->pdo, 'page_views', 'device'); $s['last_day'] = null;
    if (!empty($s['storage'])) { try { $s['last_day'] = $this->pdo->query('SELECT MAX(day) FROM adm_traffic_daily')->fetchColumn() ?: null; } catch (Throwable $e) {} }
    return $s;
  }
  public function traffic(string $from, string $to): array {
    $q = $this->pdo->prepare('SELECT day, views_human, visitors, sessions, views_no_id FROM adm_traffic_daily WHERE day BETWEEN ? AND ? ORDER BY day'); $q->execute([$from, $to]); return $q->fetchAll(PDO::FETCH_ASSOC);
  }
  public function dim(string $dim, string $from, string $to, int $limit): array { return array_map(fn($r) => ['k' => (string)$r['k'], 'views' => (int)$r['views'], 'visitor_days' => (int)$r['visitor_days']], adma_dim($this->pdo, $dim, $from, $to, $limit)); }
  public function tools(string $from, string $to): array { return adma_tools($this->pdo, $from, $to, 5000); }
  public function toolDays(string $from, string $to, ?string $slug = null): array {
    $q = $this->pdo->prepare('SELECT day, SUM(tool_views) tool_views, SUM(outbound_clicks) outbound_clicks FROM adm_tool_daily WHERE day BETWEEN ? AND ?' . ($slug !== null ? ' AND tool_slug = ?' : '') . ' GROUP BY day ORDER BY day'); $q->execute($slug !== null ? [$from, $to, $slug] : [$from, $to]); return $q->fetchAll(PDO::FETCH_ASSOC);
  }
  public function activeNow(): ?array {
    try {
      if (!function_exists('analytics_enabled') || !analytics_enabled() || !adma_table($this->pdo, 'page_views')) return null;
      $last = $this->pdo->query("SELECT MAX(viewed_at) FROM page_views WHERE is_bot = 0 AND status < 400")->fetchColumn();
      if (!$last || strtotime((string)$last . ' UTC') < time() - 86400) return null;   // no recorded human activity in the last day: do not show a number
      $q = $this->pdo->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM page_views WHERE viewed_at >= ? AND is_bot = 0 AND status < 400 AND visitor_hash <> ''"); $q->execute([gmdate('Y-m-d H:i:s', time() - ANZ_ACTIVE_WINDOW_MIN * 60)]);
      return ['count' => (int)$q->fetchColumn(), 'last_view' => (string)$last];
    } catch (Throwable $e) { return null; }
  }
  public function placements(): ?array {
    try { if (!adma_table($this->pdo, 'adm_tool_feature')) return null; return $this->pdo->query('SELECT tool_slug, level, starts_on, ends_on, status FROM adm_tool_feature ORDER BY starts_on DESC, id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return null; }
  }
}
