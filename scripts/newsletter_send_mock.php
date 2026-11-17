<?php
/**
 * MOCK newsletter delivery. Renders an issue for every CONFIRMED subscriber and writes the messages to a local outbox directory.
 * Usage: AIHUB_MAIL_TRANSPORT=file AIHUB_MAIL_OUTBOX=/tmp/outbox php scripts/newsletter_send_mock.php --issue=<slug> [--file=draft.json] [--limit=500]
 * It refuses to run with the SMTP transport: this release does not implement real newsletter sending. Real delivery needs the owner's
 * approval, a verified sending domain (SPF/DKIM/DMARC), an email provider and a legal review. Pending and unsubscribed addresses are skipped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
if (mail_transport() !== 'file') { fwrite(STDERR, "Refusing: set AIHUB_MAIL_TRANSPORT=file and AIHUB_MAIL_OUTBOX. Real newsletter sending is not enabled.\n"); exit(1); }
$opt = function (string $k, $d = '') use ($argv) { foreach ($argv as $a) if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3); return $d; };
$slug = (string)$opt('issue'); $file = (string)$opt('file'); $limit = max(1, min(5000, (int)$opt('limit', 500)));
if ($file !== '') { $issue = json_decode((string)@file_get_contents($file), true) ?: null; if ($issue) $issue['slug'] = $issue['slug'] ?? 'file'; }
else { $issue = newsletter_issues_all()[$slug] ?? null; if ($issue) $issue['slug'] = $slug; }
if (!$issue) { fwrite(STDERR, "Issue not found.\n"); exit(1); }
$errs = newsletter_issue_errors($issue + ['published_on' => $issue['published_on'] ?: date('Y-m-d'), 'editor' => $issue['editor'] ?? '']);
if ($errs) { fwrite(STDERR, "Issue is not ready:\n - " . implode("\n - ", $errs) . "\n"); exit(1); }
db_connection_available();
$pdo = db();
if (!db_table_exists('subscribers')) { fwrite(STDERR, "No subscribers table.\n"); exit(1); }
$rows = $pdo->query("SELECT email, token FROM subscribers WHERE status = 'confirmed' ORDER BY id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
$sent = 0;
foreach ($rows as $r) {
  $unsub = site_url('/unsubscribe.php?token=' . $r['token']);
  $text = newsletter_render_text($issue, $unsub);
  $html = nl2br(htmlspecialchars($text, ENT_QUOTES));
  if (send_mail($r['email'], $issue['title'], $html, $text, '', ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'])) $sent++;
}
echo "MOCK delivery: wrote $sent message(s) to the outbox for " . count($rows) . " confirmed subscriber(s). Nothing was sent.\n";
