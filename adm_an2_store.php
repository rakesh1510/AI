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
  public function lifetimeClicks(): ?array {
    try { if (!adma_table($this->pdo, 'tool_clicks')) return null; $o = []; foreach ($this->pdo->query('SELECT tool_slug, COUNT(*) AS total FROM tool_clicks GROUP BY tool_slug')->fetchAll(PDO::FETCH_NUM) as [$slug, $n]) $o[(string)$slug] = (int)$n; return $o; } catch (Throwable $e) { return null; }
  }
  public function unrolled(?string $sinceUtc): ?array {
    try {
      $o = ['page_views' => 0, 'outbound' => 0]; $any = false;
      if (adma_table($this->pdo, 'page_views')) { $any = true; $q = $this->pdo->prepare("SELECT COUNT(*) FROM page_views WHERE is_bot = 0 AND status < 400" . ($sinceUtc !== null ? ' AND viewed_at > ?' : '')); $q->execute($sinceUtc !== null ? [$sinceUtc] : []); $o['page_views'] = (int)$q->fetchColumn(); }
      if (adma_table($this->pdo, 'events')) { $any = true; $q = $this->pdo->prepare("SELECT COUNT(*) FROM events WHERE name = 'outbound_click' AND prop2 IN ('sponsored','organic')" . ($sinceUtc !== null ? ' AND occurred_at > ?' : '')); $q->execute($sinceUtc !== null ? [$sinceUtc] : []); $o['outbound'] = (int)$q->fetchColumn(); }
      return $any ? $o : null;
    } catch (Throwable $e) { return null; }
  }
  public function legacyClicks(string $from, string $to): ?array {
    try {
      if (!adma_table($this->pdo, 'tool_clicks')) return null;
      $prevTz = (string)$this->pdo->query('SELECT @@session.time_zone')->fetchColumn(); $this->pdo->query("SET time_zone = '+00:00'");   // days are UTC days; restored below
      $lo = $from . ' 00:00:00'; $hi = anz_add_days($to, 1) . ' 00:00:00'; $buf = anz_add_days($from, -1) . ' 00:00:00';
      try {
        $q = $this->pdo->prepare("SELECT d AS day, tool_slug, COUNT(*) records, SUM(dup) probable_duplicates FROM (SELECT DATE(created_at) d, created_at, tool_slug, CASE WHEN visitor_id <> '' AND TIMESTAMPDIFF(SECOND, LAG(created_at) OVER (PARTITION BY visitor_id, tool_slug ORDER BY created_at, id), created_at) <= 10 THEN 1 ELSE 0 END dup FROM tool_clicks WHERE created_at >= ? AND created_at < ?) t WHERE created_at >= ? GROUP BY d, tool_slug ORDER BY d, tool_slug");
        $q->execute([$buf, $hi, $lo]);
      } catch (Throwable $e) {   // no window functions: counts without the duplicate estimate (reported as zero probable duplicates is wrong, so mark with -1)
        $q = $this->pdo->prepare("SELECT DATE(created_at) day, tool_slug, COUNT(*) records, -1 probable_duplicates FROM tool_clicks WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at), tool_slug ORDER BY 1, 2"); $q->execute([$lo, $hi]);
      }
      $out = array_map(fn($r) => ['day' => (string)$r['day'], 'tool_slug' => (string)$r['tool_slug'], 'records' => (int)$r['records'], 'probable_duplicates' => max(0, (int)$r['probable_duplicates']), 'dup_known' => (int)$r['probable_duplicates'] >= 0], $q->fetchAll(PDO::FETCH_ASSOC));
      try { $this->pdo->query("SET time_zone = " . $this->pdo->quote($prevTz)); } catch (Throwable $e) {}
      return $out;
    } catch (Throwable $e) { try { if (isset($prevTz)) $this->pdo->query("SET time_zone = " . $this->pdo->quote($prevTz)); } catch (Throwable $e2) {} return null; }
  }
  public function placements(): ?array {
    try { if (!adma_table($this->pdo, 'adm_tool_feature')) return null; return $this->pdo->query('SELECT tool_slug, level, starts_on, ends_on, status FROM adm_tool_feature ORDER BY starts_on DESC, id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return null; }
  }
}
