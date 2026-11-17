<?php
/**
 * Seeds SYNTHETIC leads into an isolated TEST database to exercise the lead admin prototype.
 * Usage: php scripts/seed_synthetic_leads.php [--count=24]
 * Refuses to run unless the database name contains "test". Every row is flagged is_synthetic=1, uses @example.test addresses and
 * a fixed pseudo-random sequence, so nothing here resembles a real person or a real business outcome.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$count = 24; foreach ($argv as $a) if (strpos($a, '--count=') === 0) $count = max(1, min(500, (int)substr($a, 8)));
$name = sec_setting('AIHUB_DB_NAME', 'db.name');
if (stripos($name, 'test') === false) { fwrite(STDERR, "Refusing: database name must contain 'test' (got '$name').\n"); exit(1); }
db_connection_available();
$pdo = db();
if (!db_column_exists($pdo, 'leads', 'is_synthetic')) { fwrite(STDERR, "Apply database/phase4_business.sql first.\n"); exit(1); }
mt_srand(20261117);
$types = array_keys(lead_types()); $pk = array_keys(array_filter(lead_package_options(), fn($l, $k) => $k !== '', ARRAY_FILTER_USE_BOTH)); $goals = array_keys(lead_goal_options());
$statusPool = ['new', 'new', 'new', 'contacted', 'qualified', 'proposal_sent', 'won', 'lost', 'spam']; $sources = ['', 'newsletter', 'partner-x', 'directory-y']; $pages = ['/advertise.php', '/business.php', '/tool.php'];
for ($i = 1; $i <= $count; $i++) {
  $t = $types[mt_rand(0, count($types) - 1)];
  $clean = ['lead_type' => $t, 'name' => "Synthetic Person $i", 'email' => "synthetic$i@example.test", 'company' => "Synthetic Co $i", 'website' => '', 'budget' => '', 'timeline' => '',
    'message' => "[SYNTHETIC] Generated test message $i. Not a real inquiry.", 'source_page' => $pages[mt_rand(0, 2)],
    'package_interest' => in_array($t, ['advertiser', 'affiliate_partner', 'newsletter_sponsor'], true) ? $pk[mt_rand(0, count($pk) - 1)] : '', 'campaign_goal' => $goals[mt_rand(0, count($goals) - 1)],
    'attr_source' => $sources[mt_rand(0, 3)], 'attr_medium' => '', 'attr_campaign' => '', 'ref_tool' => ''];
  $id = lead_store($pdo, $clean, 'synthetic0000000', true);
  $st = $statusPool[mt_rand(0, count($statusPool) - 1)];
  $pdo->prepare('UPDATE leads SET status = ?, created_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?')->execute([$st, mt_rand(0, 60), $id]);
}
echo "Seeded $count synthetic leads into '$name'.\n";
