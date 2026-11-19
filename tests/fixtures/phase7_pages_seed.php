<?php
/**
 * SYNTHETIC TEST DATA ONLY. Seeds a scratch AI Universe ledger THROUGH THE uni_core.php SERVICE LAYER for the public page tests
 * (tests/phase7_pages_test.php and the browser fixture tests/phase7_pages_server.php). Nothing here is real: names, dates, sources and figures are invented,
 * every source is on example.test, and every text marked LEAK must never appear on a public page. Callers must already have required uni_core.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function p7s_statements(string $file): array {
  $delim = ';'; $buf = ''; $out = [];
  foreach (preg_split('/\R/', (string)file_get_contents($file)) as $line) {
    $t = trim($line);
    if ($buf === '' && ($t === '' || str_starts_with($t, '--'))) continue;
    if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) { $delim = $m[1]; continue; }
    $buf .= $line . "\n";
    if (str_ends_with(rtrim($buf), $delim)) { $out[] = substr(rtrim($buf), 0, -strlen($delim)); $buf = ''; }
  }
  return $out;
}
function p7s_schema(PDO $pdo, string $root): void { foreach (['phase6_crm.sql', 'phase7_universe.sql'] as $f) foreach (p7s_statements("$root/database/$f") as $s) $pdo->exec($s); }
function p7s_must($r, string $what) { if (!is_array($r) || $r[0] !== true) throw new RuntimeException("seed failed: $what " . json_encode($r)); return $r[1]; }

/**
 * Seeds the ledger. @return array ground truth: ['entities' => visible slugs, 'day_counts' => [date => n] (verified, day precision), 'leaks' => strings that must never be public,
 * 'identities' => usernames and display names of the seeding accounts]
 */
function p7_seed(PDO $pdo): array {
  $H = password_hash('test-only-password', PASSWORD_DEFAULT);
  $mk = function (string $name, string $role) use ($pdo, $H): int { [$ok, $id] = crm_user_create($pdo, $name, ucfirst($name) . 'Display', $role, $H); if (!$ok) throw new RuntimeException("user $name: $id"); return (int)$id; };
  $ed = $mk('pgseeded', 'editor'); $v1 = $mk('pgseedv1', 'verifier'); $v2 = $mk('pgseedv2', 'verifier'); $v3 = $mk('pgseedv3', 'verifier');
  $src = fn(array $o = []) => $o + ['url' => 'https://example.test/synthetic/' . bin2hex(random_bytes(4)), 'publisher' => 'Synthetic Test Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote that supports the claim'];
  $publish = function (string $type, int $id) use ($pdo, $ed, $v1, $v2, $v3): void {
    p7s_must(uni_submit_for_review($pdo, $ed, $type, $id), "submit $type $id");
    foreach ([$v1, $v2, $v3] as $v) { $r = p7s_must(uni_review($pdo, $v, $type, $id, 'accept'), "review $type $id"); if ($r['state'] === 'verified') return; }
    throw new RuntimeException("not verified $type $id");
  };
  $entity = function (string $slug, string $type, string $name, string $summary = 'Synthetic test data: a made-up entity.') use ($pdo, $ed, $src): int {
    $id = (int)p7s_must(uni_propose_entity($pdo, $ed, ['entity_type' => $type, 'slug' => $slug, 'name' => $name, 'summary' => $summary]), "entity $slug");
    p7s_must(uni_attach_source($pdo, $ed, 'entity', $id, $src()), "entity source $slug"); return $id;
  };
  $vEntity = function (string $slug, string $type, string $name, string $summary = 'Synthetic test data: a made-up entity.') use ($entity, $publish): int { $id = $entity($slug, $type, $name, $summary); $publish('entity', $id); return $id; };
  $event = function (int $ent, string $type, string $date, string $title) use ($pdo, $ed, $src): int {
    $r = p7s_must(uni_propose_event($pdo, $ed, ['entity_id' => $ent, 'event_type' => $type, 'event_date' => $date, 'title' => $title, 'summary' => 'Synthetic test data: a made-up event.']), "event $title");
    $id = (int)$r['id']; p7s_must(uni_attach_source($pdo, $ed, 'event', $id, $src()), "event source $title"); return $id;
  };
  $vEvent = function (int $ent, string $type, string $date, string $title) use ($event, $publish): int { $id = $event($ent, $type, $date, $title); $publish('event', $id); return $id; };
  $edge = function (int $from, int $to, string $type, string $notes = '') use ($pdo, $ed, $src): int {
    $id = (int)p7s_must(uni_propose_edge($pdo, $ed, ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => $type, 'notes' => $notes]), "edge $type");
    p7s_must(uni_attach_source($pdo, $ed, 'edge', $id, $src()), "edge source $type"); return $id;
  };
  $vEdge = function (int $from, int $to, string $type) use ($edge, $publish): int { $id = $edge($from, $to, $type); $publish('edge', $id); return $id; };
  $release = function (int $ent, string $label, string $date, ?array $cs, array $parents = []) use ($pdo, $ed, $src): int {
    $sid = (int)p7s_must(uni_source_create($pdo, $ed, $src()), 'release source');
    return (int)p7s_must(uni_propose_release($pdo, $ed, ['entity_id' => $ent, 'version_label' => $label, 'release_date' => $date, 'source_id' => $sid, 'change_set' => $cs, 'parents' => array_map(fn($p) => [$p, $sid], $parents)]), "release $label");
  };
  $vRelease = function (int $ent, string $label, string $date, ?array $cs, array $parents = []) use ($release, $publish): int { $id = $release($ent, $label, $date, $cs, $parents); $publish('release', $id); return $id; };
  $force = function (string $table, int $id) use ($pdo, $v1): void {
    $rid = (int)$pdo->query("SELECT id FROM uni_review WHERE decision = 'accept' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE $table SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?")->execute([$v1, gmdate('Y-m-d H:i:s'), $rid, $id]);
  };

  $acme = $vEntity('acme', 'company', 'Acme Labs'); $mx = $vEntity('modelx', 'model', 'Model X'); $my = $vEntity('modely', 'model', 'Model Y'); $mz = $vEntity('modelz', 'model', 'Model Z'); $mw = $vEntity('modelw', 'model', 'Model W');
  $pz = $vEntity('prodz', 'product', 'Prod Z'); $lonely = $vEntity('lonely', 'model', 'Lonely Model');
  $evil = $vEntity('evil-co', 'company', 'Evil <b>Co</b> "quoted" & friends', 'Summary with <script>alert(9)</script> markup & "quotes".');
  $ren = $vEntity('renamed-co', 'company', 'OLDNAMELEAK Co'); $renNew = (int)p7s_must(uni_correct($pdo, $ed, 'entity', $ren, ['name' => 'Renamed Co']), 'correct entity'); $publish('entity', $renNew);
  $gp = $entity('leak-ghost-proposed', 'model', 'LEAK Ghost Proposed'); $gr = $entity('leak-ghost-review', 'model', 'LEAK Ghost Review'); p7s_must(uni_submit_for_review($pdo, $ed, 'entity', $gr), 'submit gr');
  $gx = $entity('leak-ghost-rejected', 'model', 'LEAK Ghost Rejected'); p7s_must(uni_submit_for_review($pdo, $ed, 'entity', $gx), 'submit gx'); p7s_must(uni_review($pdo, $v1, 'entity', $gx, 'reject', 'LEAKNOTE reviewer note'), 'reject gx');

  $vEdge($mx, $acme, 'developed_by'); $vEdge($my, $acme, 'developed_by'); $vEdge($my, $mx, 'derived_from'); $vEdge($mz, $my, 'successor_of'); $vEdge($mw, $my, 'renamed_from'); $vEdge($pz, $mx, 'built_on');
  $vEdge($mx, $pz, 'integrates_with'); $vEdge($mx, $acme, 'hosted_on'); $vEdge($acme, $evil, 'acquired_by');
  $e1 = $edge($acme, $mx, 'derived_from', 'LEAKNOTE edge note'); $e2 = $edge($acme, $my, 'derived_from', 'LEAKNOTE'); p7s_must(uni_submit_for_review($pdo, $ed, 'edge', $e2), 'submit e2');
  $e3 = $edge($lonely, $mx, 'built_on', 'LEAKNOTE'); p7s_must(uni_submit_for_review($pdo, $ed, 'edge', $e3), 'submit e3'); p7s_must(uni_review($pdo, $v1, 'edge', $e3, 'reject', 'LEAKNOTE'), 'reject e3');
  $e4 = $edge($gp, $acme, 'developed_by', 'LEAKNOTE'); $force('uni_edge', $e4);

  $truth = []; $dayCounts = [];
  $add = function (int $ent, string $type, string $date, string $title) use ($vEvent, &$dayCounts): void { $vEvent($ent, $type, $date, $title); if (strlen($date) === 10) $dayCounts[$date] = ($dayCounts[$date] ?? 0) + 1; };
  $add($mx, 'launch', '2024-03-15', 'Model X launches'); $add($acme, 'announcement', '2024-03-15', 'Acme announces'); $add($pz, 'product_release', '2024-03-15', 'Prod Z ships');
  $add($my, 'model_release', '2024-03-18', 'Model Y released'); $add($mx, 'pricing_change', '2024-04-02', 'Model X pricing update'); $add($mx, 'integration', '2024-04-20', 'Model X integration');
  $add($my, 'major_update', '2024-06-10', 'Model Y big update'); $add($mz, 'model_release', '2024-06-10', 'Model Z released'); $add($acme, 'acquisition', '2024-12-05', 'Acme acquires Evil Co');
  $add($mx, 'major_update', '2024-05', 'Model X May update'); $add($acme, 'acquisition', '2023', 'Acme acquires something');
  $old = $vEvent($acme, 'launch', '2024-03-16', 'OLDTITLELEAK launch'); $new = (int)p7s_must(uni_correct($pdo, $ed, 'event', $old, ['title' => 'Acme Day corrected']), 'correct event'); $publish('event', $new); $dayCounts['2024-03-16'] = 1;
  $event($mx, 'launch', '2024-03-15', 'LEAK proposed event');
  $h2 = $event($mx, 'launch', '2024-03-15', 'LEAK in review event'); p7s_must(uni_submit_for_review($pdo, $ed, 'event', $h2), 'submit h2');
  $h3 = $event($mx, 'launch', '2024-03-15', 'LEAK rejected event'); p7s_must(uni_submit_for_review($pdo, $ed, 'event', $h3), 'submit h3'); p7s_must(uni_review($pdo, $v1, 'event', $h3, 'reject', 'LEAKNOTE'), 'reject h3');
  $h4 = $event($gp, 'launch', '2024-03-15', 'LEAK verified event of unverified entity'); $force('uni_event', $h4);

  $r1 = $vRelease($mx, 'v1.0', '2024-01-10', ['description' => 'Initial public release of Model X.', 'context_window' => '8k', 'features' => ['a'], 'legacy_flag' => true, 'pricing' => ['input' => 1.0], 'platforms' => ['web'], 'same_key' => 'same']);
  $r2 = $vRelease($mx, 'v2.0', '2024-06-01', ['description' => 'Second release with a larger context window.', 'context_window' => '32k', 'features' => ['a', 'b'], 'new_key' => 'z', 'pricing' => ['input' => 0.5], 'platforms' => ['web', 'api'], 'benchmarks' => ['synthetic_bench' => '70.5 (synthetic)'], 'same_key' => 'same'], [$r1]);
  $y1 = $vRelease($my, 'Y1', '2024-07-01', ['note_free' => 'one'], [$r2]);
  $y2o = $vRelease($my, 'Y2-OLDLEAK', '2024-09-01', null, [$y1]); $y2 = (int)p7s_must(uni_correct($pdo, $ed, 'release', $y2o, ['version_label' => 'Y2']), 'correct release'); $publish('release', $y2);
  $vRelease($mz, 'Z1', '2023-11-02', ['description' => 'First release of Model Z.']);
  $release($mx, 'LEAK-REL-PROPOSED', '2024-08-01', null);
  $rl2 = $release($mx, 'LEAK-REL-REVIEW', '2024-08-02', null); p7s_must(uni_submit_for_review($pdo, $ed, 'release', $rl2), 'submit rl2');
  $rl4 = $release($gp, 'LEAK-REL-GHOST', '2024-08-04', null); $force('uni_release', $rl4);

  return ['entities' => ['acme', 'modelx', 'modely', 'modelz', 'modelw', 'prodz', 'lonely', 'evil-co', 'renamed-co'], 'day_counts' => $dayCounts,
    'leaks' => ['LEAK', 'OLDTITLELEAK', 'OLDNAMELEAK', 'Y2-OLDLEAK', 'LEAKNOTE', 'leak-ghost'], 'identities' => ['pgseeded', 'PgseededDisplay', 'pgseedv1', 'PgseedV1Display', 'Pgseedv1Display', 'Pgseedv2Display', 'Pgseedv3Display', 'pgseedv2', 'pgseedv3']];
}
