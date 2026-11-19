<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * AI Universe lead adoption (CLI only). A HUMAN editor turns imported leads (origin 'import', state 'proposed') into human-origin proposals that a DIFFERENT verifier can then review.
 * It only calls the service layer (uni_correct with origin 'human' + uni_submit_for_review). It never verifies, never names a verifier and never edits an imported row.
 * Adopting is an editor's statement: "I opened the source of each record I adopt and the quote supports the claim" (see the review batch CSV). The verifier still checks independently.
 *
 *   php scripts/uni_adopt.php --user=EDITOR_USERNAME [--kind=entity|event|edge|all] [--limit=N] [--slug-prefix=P] [--apply]
 *
 * Dry run is the default (lists what would be adopted, read-only session). Order is entities, then events, then edges. Exit codes: 0 ok, 1 errors, 2 refused request.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../uni_core.php';

function ad_arg(string $n, ?string $d = null): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return $d; }
$user = ad_arg('user'); $kind = ad_arg('kind', 'all'); $limit = (int)ad_arg('limit', '1000'); $prefix = ad_arg('slug-prefix', ''); $apply = in_array('--apply', $argv, true);
if (!$user || !in_array($kind, ['entity', 'event', 'edge', 'all'], true) || $limit < 1 || $limit > 5000 || !preg_match('/^[a-z0-9._-]{0,60}$/', (string)$prefix)) { fwrite(STDERR, "Usage: php scripts/uni_adopt.php --user=EDITOR [--kind=entity|event|edge|all] [--limit=N] [--slug-prefix=P] [--apply]\n"); exit(2); }
$pdo = db();
$s = $pdo->prepare("SELECT id, role, active FROM crm_users WHERE username = ?"); $s->execute([$user]); $u = $s->fetch(PDO::FETCH_ASSOC);
if (!$u || (int)$u['active'] !== 1 || $u['role'] !== 'editor') { fwrite(STDERR, "refused: --user must be an active user with the editor role\n"); exit(2); }
$actor = (int)$u['id'];
if (!$apply) $pdo->exec('SET SESSION TRANSACTION READ ONLY');

$like = $prefix !== '' ? addcslashes((string)$prefix, '%_\\') . '%' : '%';
$q = [
  'entity' => "SELECT id, slug AS label FROM uni_entity WHERE origin = 'import' AND state = 'proposed' AND is_current = 1 AND slug LIKE ? ORDER BY id LIMIT ?",
  'event' => "SELECT ev.id, CONCAT(en.slug, ' ', ev.event_date) AS label FROM uni_event ev JOIN uni_entity en ON en.id = ev.entity_id WHERE ev.origin = 'import' AND ev.state = 'proposed' AND ev.is_current = 1 AND en.slug LIKE ? ORDER BY ev.id LIMIT ?",
  'edge' => "SELECT ed.id, CONCAT(f.slug, ' ', ed.edge_type, ' ', t.slug) AS label FROM uni_edge ed JOIN uni_entity f ON f.id = ed.from_entity_id JOIN uni_entity t ON t.id = ed.to_entity_id WHERE ed.origin = 'import' AND ed.state = 'proposed' AND ed.is_current = 1 AND f.slug LIKE ? ORDER BY ed.id LIMIT ?",
];
$res = ['mode' => $apply ? 'apply' : 'dry-run', 'adopted' => 0, 'would_adopt' => 0, 'errors' => []];
foreach (['entity', 'event', 'edge'] as $k) {
  if ($kind !== 'all' && $kind !== $k) continue;
  $st = $pdo->prepare($q[$k]); $st->bindValue(1, $like); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!$apply) { $res['would_adopt']++; continue; }
    $c = uni_correct($pdo, $actor, $k, (int)$r['id'], ['origin' => 'human']);
    if (!$c[0]) { $res['errors'][] = "$k {$r['label']}: {$c[1]}"; continue; }
    $sub = uni_submit_for_review($pdo, $actor, $k, (int)$c[1]);
    if (!$sub[0]) { $res['errors'][] = "$k {$r['label']}: submit {$sub[1]}"; continue; }
    $res['adopted']++;
  }
}
echo json_encode($res, JSON_UNESCAPED_SLASHES) . "\n";
exit($res['errors'] ? 1 : 0);
