<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * AI Universe public-source collector (CLI only). Turns PUBLIC API captures (Wikidata, Hugging Face Hub) into an `aihubpedia-import-v1` file of PROPOSED leads
 * plus a human review batch. It never writes to the database, never verifies anything and invents nothing: every claim carries a quote that is checked to be a
 * verbatim substring of the stored capture; a record whose quote is not found is dropped and reported.
 *
 *   php scripts/uni_collect_public.php --seed=data/uni_seed_orgs.json --out-dir=DIR [--max-models=3] [--replay] [--directory]
 *
 * --replay   rebuild the output from the captures already stored in DIR/captures (no network): deterministic, used by the tests.
 * --directory  also read the AIHubpedia directory (read-only SELECT on `tools`) and write directory_links.json / directory_leads.csv (leads only, nothing is changed).
 * Network: only the hosts in COL_HOSTS, GET only, no redirects, 2 MB cap, generic user agent, polite delay. Terms: Wikidata data is CC0; Hugging Face Hub API terms apply.
 * Output import file must then go through scripts/uni_import.php (dry run first) under a named editor user; verification is a different human's act.
 */
const COL_HOSTS = ['huggingface.co', 'www.wikidata.org'];
const COL_UA = 'AIHubpedia-research-bot/1.0 (generic; public-data research)';
const COL_MAX_BYTES = 2097152;

function col_arg(string $n, ?string $d = null): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return $d; }
function col_flag(string $n): bool { return in_array("--$n", $GLOBALS['argv'], true); }
function col_slug(string $s): string { $s = strtolower($s); $s = preg_replace('/[^a-z0-9._-]+/', '-', $s) ?? ''; return substr(trim($s, '-._'), 0, 120); }

/** @return array{0:int,1:string,2:array} [http status, body, capture meta] */
function col_get(string $url, string $dir, bool $replay): array {
  $p = parse_url($url); if (!$p || ($p['scheme'] ?? '') !== 'https' || !in_array($p['host'] ?? '', COL_HOSTS, true)) throw new RuntimeException('host_not_allowed');
  $file = "$dir/captures/" . sha1($url) . '.json';
  if ($replay) {
    if (!is_file($file)) return [0, '', []];
    $c = json_decode((string)file_get_contents($file), true); return [(int)$c['http'], (string)$c['body'], $c];
  }
  if (is_file($file)) { $c = json_decode((string)file_get_contents($file), true); return [(int)$c['http'], (string)$c['body'], $c]; }   // reuse a capture from this run
  $delay = $p['host'] === 'www.wikidata.org' ? 1300000 : 350000; $body = false; $code = 0;
  for ($try = 0; $try < 4; $try++) {   // polite retries: honour Retry-After on 429/503, otherwise back off 5, 15, 45 seconds
    usleep($delay); $retry = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => COL_UA, CURLOPT_MAXFILESIZE => COL_MAX_BYTES, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'],
      CURLOPT_HEADERFUNCTION => function ($c, string $h) use (&$retry) { if (stripos($h, 'retry-after:') === 0) $retry = (int)trim(substr($h, 12)); return strlen($h); }]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 429 && $code !== 503) break;
    $delay = (int)(1000000 * min(120, $retry > 0 ? $retry : [5, 15, 45][$try] ?? 45));
  }
  $body = is_string($body) ? $body : '';
  $meta = ['url' => $url, 'fetched_at' => gmdate('Y-m-d H:i:s'), 'http' => $code, 'sha256' => hash('sha256', $body), 'body' => $body];
  if ($code === 200 && $body !== '') { @mkdir("$dir/captures", 0775, true); file_put_contents($file, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
  return [$code, $body, $meta];
}

final class ColBuilder {
  public array $sources = [], $entities = [], $events = [], $edges = [], $review = [], $skipped = [];
  private array $srcIdx = [];
  public function skip(string $what, string $why): void { $this->skipped[] = ['what' => $what, 'why' => $why]; }
  /** Registers a source only when the quote is a verbatim substring of the captured body. @return string|null source id */
  public function source(array $meta, string $body, string $publisher, string $quote): ?string {
    if ($quote === '' || strpos($body, $quote) === false) return null;
    $k = $meta['url'] . '|' . $quote;
    if (!isset($this->srcIdx[$k])) { $id = 's' . (count($this->sources) + 1); $this->srcIdx[$k] = $id; $this->sources[] = ['id' => $id, 'url' => $meta['url'], 'publisher' => $publisher, 'retrieved_at' => $meta['fetched_at'], 'quote' => $quote, 'content_hash' => $meta['sha256']]; }
    return $this->srcIdx[$k];
  }
  public function entity(string $type, string $slug, string $name, string $summary, array $srcs): void {
    foreach ($this->entities as $e) if ($e['slug'] === $slug) return;
    $this->entities[] = ['entity_type' => $type, 'slug' => $slug, 'name' => mb_substr($name, 0, 190), 'summary' => mb_substr($summary, 0, 500), 'sources' => array_values(array_unique($srcs))];
  }
  public function hasEntity(string $slug): bool { foreach ($this->entities as $e) if ($e['slug'] === $slug) return true; return false; }
  public function edgeAdd(string $from, string $type, string $to, string $notes, array $srcs): void {
    foreach ($this->edges as $e) if ($e['from'] === $from && $e['to'] === $to && $e['edge_type'] === $type) return;
    $this->edges[] = ['from' => $from, 'to' => $to, 'edge_type' => $type, 'notes' => mb_substr($notes, 0, 500), 'sources' => array_values(array_unique($srcs))];
  }
}

$seedFile = col_arg('seed'); $out = col_arg('out-dir'); $maxModels = max(1, min(10, (int)col_arg('max-models', '3'))); $replay = col_flag('replay');
if (!$seedFile || !$out || !is_file($seedFile)) { fwrite(STDERR, "Usage: php scripts/uni_collect_public.php --seed=FILE --out-dir=DIR [--max-models=3] [--replay] [--directory]\n"); exit(2); }
@mkdir($out, 0775, true);
$seed = json_decode((string)file_get_contents($seedFile), true); if (!is_array($seed)) { fwrite(STDERR, "seed file is not valid JSON\n"); exit(2); }
$b = new ColBuilder(); $companies = []; /* hf namespace (lower) => company slug */ $companyWeb = [];

// ---- 1. companies: resolved through Wikidata, only when label matches exactly and the description looks like an organisation ----
foreach ($seed as $o) {
  $name = (string)($o['name'] ?? ''); $hf = (string)($o['hf'] ?? ''); if ($name === '' || $hf === '') continue;
  [$c1, $b1, $m1] = col_get('https://www.wikidata.org/w/api.php?action=wbsearchentities&search=' . rawurlencode($name) . '&language=en&type=item&limit=7&format=json', $out, $replay);
  if ($c1 !== 200) { $b->skip($name, "wikidata_search_http_$c1"); continue; }
  $hit = null; foreach ((json_decode($b1, true)['search'] ?? []) as $r) {
    if (mb_strtolower((string)($r['label'] ?? '')) === mb_strtolower($name) && preg_match('/company|organi[sz]ation|laborator|research|artificial intelligence|corporation|startup|institute|software|technology/i', (string)($r['description'] ?? ''))) { $hit = $r; break; }
  }
  if (!$hit) { $b->skip($name, 'no_exact_label_organisation_match_on_wikidata'); continue; }
  $qid = (string)$hit['id'];
  [$c2, $b2, $m2] = col_get("https://www.wikidata.org/wiki/Special:EntityData/$qid.json", $out, $replay);
  if ($c2 !== 200) { $b->skip($name, "wikidata_entity_http_$c2"); continue; }
  $ent = json_decode($b2, true)['entities'][$qid] ?? null; $desc = (string)($ent['descriptions']['en']['value'] ?? '');
  if ($desc === '') { $b->skip($name, 'no_english_description'); continue; }
  $sid = $b->source($m2, $b2, 'Wikidata (CC0)', $desc); if ($sid === null) { $b->skip($name, 'quote_not_in_capture'); continue; }
  $web = ''; foreach (($ent['claims']['P856'] ?? []) as $cl) { $v = $cl['mainsnak']['datavalue']['value'] ?? null; if (is_string($v) && preg_match('#^https?://[^\s<>"]+$#', $v)) { $web = $v; break; } }
  $country = ''; $cq = $ent['claims']['P17'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
  if (is_string($cq) && preg_match('/^Q\d+$/', $cq)) { [$c3, $b3] = col_get("https://www.wikidata.org/w/api.php?action=wbgetentities&ids=$cq&props=labels&languages=en&format=json", $out, $replay); if ($c3 === 200) $country = (string)(json_decode($b3, true)['entities'][$cq]['labels']['en']['value'] ?? ''); }
  $slug = col_slug($name);
  $sum = $desc . ' (Wikidata ' . $qid . ').' . ($web !== '' ? " Official website per Wikidata: $web." : '') . ($country !== '' ? " Country per Wikidata: $country." : '');
  $b->entity('company', $slug, (string)$hit['label'], $sum, [$sid]);
  $companies[strtolower($hf)] = $slug; if ($web !== '') $companyWeb[$slug] = $web;
  $b->review[] = ['kind' => 'entity', 'record' => $slug, 'claim' => "Organisation '{$hit['label']}' ($qid): $desc", 'source_url' => $m2['url'], 'quote' => $desc, 'check' => 'Open the Wikidata page for ' . $qid . ' and confirm it is the intended organisation (identity), the description text, and that the website/country shown in the summary match the Wikidata statements.'];
}

// ---- 2. models, release-like events, developer and derived-from edges from the Hugging Face Hub API ----
$modelEntity = function (string $id, string $body, array $meta) use ($b, &$companies): ?string {
  $m = json_decode($body, true); if (!is_array($m) || ($m['id'] ?? '') !== $id) return null;
  $sid = $b->source($meta, $body, 'Hugging Face Hub API', '"id":"' . $id . '"'); if ($sid === null) return null;
  $slug = col_slug(str_replace('/', '-', $id));
  if (!$b->hasEntity($slug)) { $b->entity('model', $slug, $id, '', [$sid]); $b->review[] = ['kind' => 'entity', 'record' => $slug, 'claim' => "Model repository '$id' exists on the Hugging Face Hub", 'source_url' => $meta['url'], 'quote' => '"id":"' . $id . '"', 'check' => 'Open the URL and confirm the repository id.']; }
  return $slug;
};
foreach ($seed as $o) {
  $hf = (string)($o['hf'] ?? ''); $company = $companies[strtolower($hf)] ?? null; if ($company === null) continue;
  [$cl, $bl] = col_get('https://huggingface.co/api/models?author=' . rawurlencode($hf) . '&sort=downloads&direction=-1&limit=' . $maxModels, $out, $replay);
  if ($cl !== 200) { $b->skip("models of $hf", "hf_list_http_$cl"); continue; }
  foreach ((json_decode($bl, true) ?: []) as $row) {
    $id = (string)($row['id'] ?? ''); if (!preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $id)) continue;
    [$cm, $bm, $mm] = col_get('https://huggingface.co/api/models/' . $id, $out, $replay);
    if ($cm !== 200) { $b->skip($id, "hf_model_http_$cm"); continue; }
    $m = json_decode($bm, true); $slug = $modelEntity($id, $bm, $mm);
    if ($slug === null) { $b->skip($id, 'model_quote_not_in_capture'); continue; }
    if (strcasecmp((string)($m['author'] ?? ''), $hf) === 0) {
      $sid = $b->source($mm, $bm, 'Hugging Face Hub API', '"author":"' . $m['author'] . '"');
      if ($sid !== null) { $b->edgeAdd($slug, 'developed_by', $company, "Hub namespace '$hf' is mapped to this company by the AIHubpedia seed list; the verifier must confirm the namespace belongs to the company (e.g. on the organisation's official site).", [$sid]);
        $b->review[] = ['kind' => 'edge', 'record' => "$slug developed_by $company", 'claim' => "Model published under the Hub namespace '$hf', mapped to the company", 'source_url' => $mm['url'], 'quote' => '"author":"' . $m['author'] . '"', 'check' => "Confirm that Hugging Face namespace '$hf' is operated by this company (official website or organisation page), then confirm the author field."]; }
    }
    $ca = (string)($m['createdAt'] ?? '');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T/', $ca, $dm)) {
      $sid = $b->source($mm, $bm, 'Hugging Face Hub API', '"createdAt":"' . $ca . '"');
      if ($sid !== null) {
        $b->events[] = ['entity' => $slug, 'event_type' => 'model_release', 'event_date' => $dm[1], 'date_precision' => 'day', 'date_kind' => 'preview', 'title' => 'Model repository created on the Hugging Face Hub', 'summary' => 'Date is when the repository was created on the Hub (API field createdAt). It may differ from the official announcement or public availability.', 'sources' => [$sid]];
        $b->review[] = ['kind' => 'event', 'record' => "$slug " . $dm[1], 'claim' => "Repository '$id' created on the Hub on {$dm[1]}", 'source_url' => $mm['url'], 'quote' => '"createdAt":"' . $ca . '"', 'check' => 'Confirm the createdAt value and that the event title and note do not overstate it (it is a Hub creation date, not an announcement).'];
      }
    }
    foreach (($m['tags'] ?? []) as $tag) {
      if (!is_string($tag) || !preg_match('#^base_model:finetune:([A-Za-z0-9._-]+/[A-Za-z0-9._-]+)$#', $tag, $bm2)) continue;
      $base = $bm2[1];
      [$cb, $bb, $mb] = col_get('https://huggingface.co/api/models/' . $base, $out, $replay);
      if ($cb !== 200) { $b->skip("$id base $base", "hf_base_http_$cb"); continue; }
      $bslug = $modelEntity($base, $bb, $mb); if ($bslug === null) { $b->skip("$id base $base", 'base_quote_not_in_capture'); continue; }
      $bauth = (string)(json_decode($bb, true)['author'] ?? '');
      if (isset($companies[strtolower($bauth)]) && ($sidA = $b->source($mb, $bb, 'Hugging Face Hub API', '"author":"' . $bauth . '"')) !== null) { $b->edgeAdd($bslug, 'developed_by', $companies[strtolower($bauth)], "Hub namespace '$bauth' is mapped to this company by the AIHubpedia seed list; the verifier must confirm it.", [$sidA]);
        $b->review[] = ['kind' => 'edge', 'record' => "$bslug developed_by " . $companies[strtolower($bauth)], 'claim' => "Base model published under the Hub namespace '$bauth', mapped to the company", 'source_url' => $mb['url'], 'quote' => '"author":"' . $bauth . '"', 'check' => "Confirm that Hugging Face namespace '$bauth' is operated by this company (official website or organisation page), then confirm the author field."]; }
      $sidT = $b->source($mm, $bm, 'Hugging Face Hub API', '"' . $tag . '"');
      if ($sidT !== null) { $b->edgeAdd($slug, 'derived_from', $bslug, 'Hugging Face model metadata tag base_model:finetune (fine-tuned from the base model).', [$sidT]);
        $b->review[] = ['kind' => 'edge', 'record' => "$slug derived_from $bslug", 'claim' => "'$id' is a fine-tune of '$base' according to the repository metadata", 'source_url' => $mm['url'], 'quote' => '"' . $tag . '"', 'check' => 'Open the model card on the Hub, confirm the base model statement (technical ancestry), and that the base repository id is correct.']; }
    }
  }
}

// ---- 3. optional: AIHubpedia directory as discovery leads (read-only SELECT, nothing is changed) ----
if (col_flag('directory')) {
  require_once __DIR__ . '/../helpers.php'; require_once __DIR__ . '/../db.php';
  $pdo = db(); $pdo->exec('SET SESSION TRANSACTION READ ONLY');
  $tools = $pdo->query("SELECT slug, name, website FROM tools WHERE status = 'published'")->fetchAll(PDO::FETCH_ASSOC);
  $hostOf = function (?string $u): string { $h = strtolower((string)parse_url((string)$u, PHP_URL_HOST)); return preg_replace('/^www\./', '', $h) ?? ''; };
  $links = []; $matched = [];
  foreach ($companyWeb as $cs => $w) { $d = $hostOf($w); if ($d === '') continue; foreach ($tools as $t) { $h = $hostOf($t['website']); if ($h !== '' && ($h === $d || substr($h, -strlen($d) - 1) === '.' . $d)) { $links[] = ['company' => $cs, 'directory_slug' => $t['slug'], 'directory_name' => $t['name'], 'directory_website' => $t['website']]; $matched[$t['slug']] = true; } } }
  file_put_contents("$out/directory_links.json", json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
  $fh = fopen("$out/directory_leads.csv", 'w'); fputcsv($fh, ['directory_slug', 'name', 'domain']); foreach ($tools as $t) if (!isset($matched[$t['slug']])) fputcsv($fh, [$t['slug'], $t['name'], $hostOf($t['website'])]); fclose($fh);
}

// ---- output ----
$doc = ['format' => 'aihubpedia-import-v1', 'sources' => $b->sources, 'entities' => $b->entities, 'events' => $b->events, 'edges' => $b->edges];
file_put_contents("$out/import.json", json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$seenRv = []; $b->review = array_values(array_filter($b->review, function ($r) use (&$seenRv) { $k = $r['kind'] . '|' . $r['record']; if (isset($seenRv[$k])) return false; $seenRv[$k] = true; return true; }));   /* one line per record */
$fh = fopen("$out/review_batch.csv", 'w'); fputcsv($fh, ['kind', 'record', 'claim', 'source_url', 'quote', 'what_the_verifier_must_check']); foreach ($b->review as $r) fputcsv($fh, [$r['kind'], $r['record'], $r['claim'], $r['source_url'], $r['quote'], $r['check']]); fclose($fh);
$rep = ['mode' => $replay ? 'replay' : 'live', 'organisations_in_seed' => count($seed), 'companies' => count(array_filter($b->entities, fn($e) => $e['entity_type'] === 'company')), 'models' => count(array_filter($b->entities, fn($e) => $e['entity_type'] === 'model')), 'events' => count($b->events), 'edges' => count($b->edges), 'sources' => count($b->sources), 'skipped' => $b->skipped];
file_put_contents("$out/run_report.json", json_encode($rep, JSON_PRETTY_PRINT) . "\n");
echo json_encode($rep, JSON_UNESCAPED_SLASHES) . "\n";
