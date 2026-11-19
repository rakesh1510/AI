<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * AI Universe candidate importer (CLI only). Creates PROPOSED, origin='import' leads in the uni_* provenance ledger through the service layer (uni_core.php)
 * under a named editor-role crm user. It can never create a verified row, never names a verifier, never opens a URL and never merges or overwrites anything.
 *
 *   php scripts/uni_import.php --file=PATH --user=USERNAME [--apply] [--format=json|csv] [--kind=entities|events|edges] [--batch-size=N] [--json] [--base-dir=DIR]
 *
 * --dry-run is the DEFAULT (validate, look up duplicates, report; the database session is READ ONLY). Nothing is written without --apply.
 * Input format and rules: docs/phase7/IMPORTER.md. Privileges: docs/phase7/SECURITY_AND_PRIVILEGES.md.
 * Exit codes: 0 ok (skips are not errors), 1 input or database errors (nothing written for the failing batch), 2 usage or refused request.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../uni_core.php';

const IMP_FORMAT = 'aihubpedia-import-v1';
const IMP_MAX_BYTES = 2097152;           // 2 MiB
const IMP_MAX_DEPTH = 6;
const IMP_MAX_NODES = 200000;
const IMP_MAX_STRING_BYTES = 8000;       // any single JSON string, before the field-specific limits
const IMP_MAX_ERRORS = 50;
const IMP_LIMITS = ['sources' => 2000, 'entities' => 2000, 'events' => 5000, 'edges' => 5000];
const IMP_MAX_SOURCES_PER_RECORD = 10;
const IMP_LOCK = 'aihub_uni_import';

final class ImpParseError extends RuntimeException { public int $srcLine; public function __construct(string $m, int $line) { parent::__construct($m); $this->srcLine = $line; } }

/** Strict RFC 8259 JSON reader: duplicate object keys, depth, node count, string size, bad escapes and lone surrogates are errors; records get source line numbers. */
final class ImpJson {
  private string $s; private int $n; private int $i = 0; private int $line = 1; private int $nodes = 0; private string $mask;
  /** @var array<string, array<int,int>> list name => index => line of the record */
  public array $lines = [];
  public function __construct(string $s) { $this->s = $s; $this->n = strlen($s); $this->mask = '"\\' . implode('', array_map('chr', range(0, 31))); }
  public static function parse(string $s): array {
    $p = new self($s); $p->ws(); $v = $p->value(0, []); $p->ws();
    if ($p->i < $p->n) $p->fail('trailing_content');
    if (!is_array($v) || array_is_list($v) && $v !== []) $p->fail('top_level_must_be_object');
    return [$v, $p->lines];
  }
  private function fail(string $m): void { throw new ImpParseError($m, $this->line); }
  private function ws(): void {
    while ($this->i < $this->n) { $c = $this->s[$this->i]; if ($c === ' ' || $c === "\t" || $c === "\r") $this->i++; elseif ($c === "\n") { $this->line++; $this->i++; } else break; }
  }
  private function value(int $depth, array $path) {
    if (++$this->nodes > IMP_MAX_NODES) $this->fail('too_many_values');
    if ($this->i >= $this->n) $this->fail('unexpected_end');
    $c = $this->s[$this->i];
    if ($c === '{') return $this->object($depth + 1, $path);
    if ($c === '[') return $this->arr($depth + 1, $path);
    if ($c === '"') return $this->string();
    if (substr($this->s, $this->i, 4) === 'true') { $this->i += 4; return true; }
    if (substr($this->s, $this->i, 5) === 'false') { $this->i += 5; return false; }
    if (substr($this->s, $this->i, 4) === 'null') { $this->i += 4; return null; }
    if (preg_match('/\G-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/', $this->s, $m, 0, $this->i) && $m[0] !== '') {
      if (strlen($m[0]) > 30) $this->fail('number_too_long');
      $this->i += strlen($m[0]); return (isset($m[1]) && $m[1] !== '') || (isset($m[2]) && $m[2] !== '') ? (float)$m[0] : (int)$m[0];
    }
    $this->fail('unexpected_character');
  }
  private function object(int $depth, array $path): array {
    if ($depth > IMP_MAX_DEPTH) $this->fail('nesting_too_deep');
    $this->i++; $out = []; $this->ws();
    if ($this->i < $this->n && $this->s[$this->i] === '}') { $this->i++; return $out; }
    while (true) {
      $this->ws(); if ($this->i >= $this->n || $this->s[$this->i] !== '"') $this->fail('object_key_expected');
      $k = $this->string(); if (strlen($k) > 64) $this->fail('key_too_long');
      if (array_key_exists($k, $out)) $this->fail('duplicate_key');
      $this->ws(); if ($this->i >= $this->n || $this->s[$this->i] !== ':') $this->fail('colon_expected'); $this->i++; $this->ws();
      $out[$k] = $this->value($depth, array_merge($path, [$k])); $this->ws();
      if ($this->i >= $this->n) $this->fail('unexpected_end');
      $c = $this->s[$this->i++]; if ($c === '}') return $out; if ($c !== ',') $this->fail('comma_or_brace_expected');
    }
  }
  private function arr(int $depth, array $path): array {
    if ($depth > IMP_MAX_DEPTH) $this->fail('nesting_too_deep');
    $this->i++; $out = []; $this->ws();
    if ($this->i < $this->n && $this->s[$this->i] === ']') { $this->i++; return $out; }
    while (true) {
      $this->ws();
      if (count($path) === 1) $this->lines[$path[0]][count($out)] = $this->line;
      $out[] = $this->value($depth, array_merge($path, [count($out)])); $this->ws();
      if ($this->i >= $this->n) $this->fail('unexpected_end');
      $c = $this->s[$this->i++]; if ($c === ']') return $out; if ($c !== ',') $this->fail('comma_or_bracket_expected');
    }
  }
  private function string(): string {
    $this->i++; $out = '';
    while (true) {
      $len = strcspn($this->s, $this->mask, $this->i); $out .= substr($this->s, $this->i, $len); $this->i += $len;
      if (strlen($out) > IMP_MAX_STRING_BYTES) $this->fail('string_too_long');
      if ($this->i >= $this->n) $this->fail('unterminated_string');
      $c = $this->s[$this->i];
      if ($c === '"') { $this->i++; return $out; }
      if ($c !== '\\') $this->fail('control_character_in_string');
      $e = $this->s[$this->i + 1] ?? ''; $this->i += 2;
      switch ($e) {
        case '"': $out .= '"'; break; case '\\': $out .= '\\'; break; case '/': $out .= '/'; break;
        case 'b': $out .= "\x08"; break; case 'f': $out .= "\x0C"; break; case 'n': $out .= "\n"; break; case 'r': $out .= "\r"; break; case 't': $out .= "\t"; break;
        case 'u':
          $cp = $this->hex4();
          if ($cp >= 0xD800 && $cp <= 0xDBFF) { if (substr($this->s, $this->i, 2) !== '\\u') $this->fail('lone_surrogate'); $this->i += 2; $lo = $this->hex4(); if ($lo < 0xDC00 || $lo > 0xDFFF) $this->fail('lone_surrogate'); $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00); }
          elseif ($cp >= 0xDC00 && $cp <= 0xDFFF) $this->fail('lone_surrogate');
          if ($cp === 0) $this->fail('nul_character');
          $out .= mb_chr($cp, 'UTF-8'); break;
        default: $this->fail('bad_escape');
      }
    }
  }
  private function hex4(): int {
    $h = substr($this->s, $this->i, 4); if (!preg_match('/^[0-9a-fA-F]{4}$/D', $h)) $this->fail('bad_unicode_escape'); $this->i += 4; return (int)hexdec($h);
  }
}

// ---- CSV (optional) ---------------------------------------------------------------------------------------------------------------------
/** @return array{0: array<int, array<int,string>>, 1: array<int,int>} rows and the line each row starts on. Strict: unterminated quotes and stray quotes are errors. */
function imp_csv_rows(string $s): array {
  $rows = []; $lines = []; $row = []; $f = ''; $inq = false; $line = 1; $rowLine = 1; $n = strlen($s); $started = false; $quoted = false;
  for ($i = 0; $i < $n; $i++) {
    $c = $s[$i];
    if ($inq) {
      if ($c === '"') { if (($s[$i + 1] ?? '') === '"') { $f .= '"'; $i++; } else { $inq = false; } }
      else { if ($c === "\n") $line++; $f .= $c; }
      continue;
    }
    if ($c === '"') { if ($f !== '' || $quoted) throw new ImpParseError('csv_stray_quote', $line); $inq = true; $quoted = true; $started = true; continue; }
    if ($c === ',') { $row[] = $f; $f = ''; $quoted = false; $started = true; continue; }
    if ($c === "\r") { if (($s[$i + 1] ?? '') === "\n") continue; throw new ImpParseError('csv_bare_cr', $line); }
    if ($c === "\n") {
      if ($started || $f !== '') { $row[] = $f; $rows[] = $row; $lines[] = $rowLine; } else throw new ImpParseError('csv_blank_line', $line);
      $row = []; $f = ''; $started = false; $quoted = false; $line++; $rowLine = $line; continue;
    }
    if ($quoted) throw new ImpParseError('csv_text_after_quote', $line);
    $f .= $c; $started = true;
  }
  if ($inq) throw new ImpParseError('csv_unterminated_quote', $line);
  if ($started || $f !== '') { $row[] = $f; $rows[] = $row; $lines[] = $rowLine; }
  return [$rows, $lines];
}
/** Turns one CSV file (one record kind, one inline source per row) into the same document shape as the JSON format. → [doc, lines] */
function imp_csv_to_doc(string $s, string $kind): array {
  $fields = ['entities' => ['entity_type', 'slug', 'name', 'summary', 'key'], 'events' => ['entity', 'event_type', 'event_date', 'date_precision', 'date_kind', 'title', 'summary', 'key'], 'edges' => ['from', 'to', 'edge_type', 'valid_from', 'valid_to', 'notes', 'key']][$kind];
  $srcCols = ['source_url' => 'url', 'source_publisher' => 'publisher', 'source_retrieved_at' => 'retrieved_at', 'source_quote' => 'quote', 'source_content_hash' => 'content_hash'];
  [$rows, $rl] = imp_csv_rows($s);
  if (!$rows) throw new ImpParseError('csv_empty', 1);
  $head = $rows[0]; $allowed = array_merge($fields, array_keys($srcCols));
  if (count($head) !== count(array_unique($head))) throw new ImpParseError('csv_duplicate_column', 1);
  foreach ($head as $h) if (!in_array($h, $allowed, true)) throw new ImpParseError('csv_unknown_column', 1);
  foreach (['source_url', 'source_publisher', 'source_retrieved_at', 'source_quote'] as $must) if (!in_array($must, $head, true)) throw new ImpParseError('csv_missing_source_column', 1);
  $doc = ['format' => IMP_FORMAT, 'sources' => [], $kind => []]; $lines = []; $srcIndex = [];
  for ($r = 1; $r < count($rows); $r++) {
    if (count($rows[$r]) !== count($head)) throw new ImpParseError('csv_column_count', $rl[$r]);
    if ($r > IMP_LIMITS[$kind]) throw new ImpParseError('too_many_records', $rl[$r]);
    $rec = []; $src = [];
    foreach ($head as $ci => $h) { $v = $rows[$r][$ci]; if (isset($srcCols[$h])) { if ($v !== '') $src[$srcCols[$h]] = $v; } elseif ($v !== '') $rec[$h] = $v; }
    $sk = json_encode($src); if (!isset($srcIndex[$sk])) { $srcIndex[$sk] = 'csv' . (count($srcIndex) + 1); $doc['sources'][] = ['id' => $srcIndex[$sk]] + $src; $lines['sources'][count($doc['sources']) - 1] = $rl[$r]; }
    $rec['sources'] = [$srcIndex[$sk]]; $doc[$kind][] = $rec; $lines[$kind][count($doc[$kind]) - 1] = $rl[$r];
  }
  return [$doc, $lines];
}

// ---- validation -------------------------------------------------------------------------------------------------------------------------
function imp_err(array &$errs, string $where, ?int $line, string $field, string $code): void { if (count($errs) < IMP_MAX_ERRORS) $errs[] = ['where' => $where, 'line' => $line, 'field' => $field, 'code' => $code]; elseif (count($errs) === IMP_MAX_ERRORS) $errs[] = ['where' => '', 'line' => null, 'field' => '', 'code' => 'more_errors_not_shown']; }
/** One string field: strict (no silent control-character stripping or truncation). null = invalid, error recorded. '' for an absent optional field. */
function imp_str(array $rec, string $f, int $max, bool $required, bool $multiline, array &$errs, string $where, ?int $line): ?string {
  if (!array_key_exists($f, $rec) || $rec[$f] === null) { if ($required) { imp_err($errs, $where, $line, $f, 'missing'); return null; } return ''; }
  $v = $rec[$f];
  if (!is_string($v)) { imp_err($errs, $where, $line, $f, 'not_a_string'); return null; }
  if (preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $v)) { imp_err($errs, $where, $line, $f, 'control_characters'); return null; }
  if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}]/u', $v)) { imp_err($errs, $where, $line, $f, 'bidi_control_characters'); return null; }
  $v = trim($v);
  if ($v === '') { if ($required) { imp_err($errs, $where, $line, $f, 'empty'); return null; } return ''; }
  if (!uni_visible($v)) { imp_err($errs, $where, $line, $f, 'blank'); return null; }
  if (mb_strlen($v) > $max) { imp_err($errs, $where, $line, $f, 'too_long'); return null; }
  return $v;
}
function imp_unknown(array $rec, array $allowed, array &$errs, string $where, ?int $line): void { foreach (array_keys($rec) as $k) if (!in_array((string)$k, $allowed, true)) imp_err($errs, $where, $line, imp_clean((string)$k, 30), 'unknown_field'); }
function imp_clean(string $s, int $max = 80): string { $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? ''; return strlen($s) > $max ? substr($s, 0, $max) . '...' : $s; }
function imp_enum(array $rec, string $f, array $allowed, ?string $default, array &$errs, string $where, ?int $line): ?string {
  $v = $rec[$f] ?? $default;
  if (!is_string($v) || !in_array($v, $allowed, true)) { imp_err($errs, $where, $line, $f, array_key_exists($f, $rec) || $default === null ? 'invalid_value' : 'missing'); return null; }
  return $v;
}
function imp_key(array $rec, array &$errs, string $where, ?int $line): ?string {
  if (!array_key_exists('key', $rec)) return '';
  if (!is_string($rec['key']) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $rec['key'])) { imp_err($errs, $where, $line, 'key', 'key_invalid'); return null; }
  return $rec['key'];
}
function imp_srcrefs(array $rec, array $srcIds, array &$errs, string $where, ?int $line): ?array {
  $r = $rec['sources'] ?? null;
  if (!is_array($r) || !array_is_list($r) || count($r) < 1) { imp_err($errs, $where, $line, 'sources', 'source_required'); return null; }
  if (count($r) > IMP_MAX_SOURCES_PER_RECORD) { imp_err($errs, $where, $line, 'sources', 'too_many_sources'); return null; }
  foreach ($r as $x) if (!is_string($x) || !isset($srcIds[$x])) { imp_err($errs, $where, $line, 'sources', 'unknown_source_reference'); return null; }
  if (count($r) !== count(array_unique($r))) { imp_err($errs, $where, $line, 'sources', 'duplicate_source_reference'); return null; }
  return $r;
}
/** Whole-document validation. → [normalised records, errors]. Never touches the database. */
function imp_validate($doc, array $lines): array {
  $errs = []; $out = ['sources' => [], 'entities' => [], 'events' => [], 'edges' => []];
  if (!is_array($doc)) { imp_err($errs, 'file', null, '', 'top_level_must_be_object'); return [$out, $errs]; }
  imp_unknown($doc, ['format', 'description', 'synthetic', 'sources', 'entities', 'events', 'edges'], $errs, 'file', null);
  if (($doc['format'] ?? null) !== IMP_FORMAT) imp_err($errs, 'file', null, 'format', 'format_unsupported');
  if (isset($doc['description']) && (!is_string($doc['description']) || mb_strlen($doc['description']) > 500)) imp_err($errs, 'file', null, 'description', 'invalid_value');
  if (isset($doc['synthetic']) && !is_bool($doc['synthetic'])) imp_err($errs, 'file', null, 'synthetic', 'invalid_value');
  foreach (IMP_LIMITS as $list => $max) {
    if (!isset($doc[$list])) { if ($list === 'sources') imp_err($errs, 'file', null, 'sources', 'missing'); continue; }
    if (!is_array($doc[$list]) || !array_is_list($doc[$list])) { imp_err($errs, 'file', null, $list, 'must_be_a_list'); $doc[$list] = []; }
    elseif (count($doc[$list]) > $max) { imp_err($errs, 'file', null, $list, 'too_many_records'); $doc[$list] = []; }
  }
  if (!array_filter([$doc['entities'] ?? [], $doc['events'] ?? [], $doc['edges'] ?? []])) imp_err($errs, 'file', null, '', 'no_records');
  $srcIds = [];
  foreach (($doc['sources'] ?? []) as $i => $s) {
    $w = "sources[$i]"; $ln = $lines['sources'][$i] ?? null;
    if (!is_array($s) || array_is_list($s) && $s !== []) { imp_err($errs, $w, $ln, '', 'not_an_object'); continue; }
    imp_unknown($s, ['id', 'url', 'publisher', 'retrieved_at', 'quote', 'content_hash'], $errs, $w, $ln);
    $id = $s['id'] ?? null; if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/D', $id)) { imp_err($errs, $w, $ln, 'id', 'id_invalid'); continue; }
    if (isset($srcIds[$id])) { imp_err($errs, $w, $ln, 'id', 'duplicate_id'); continue; }
    $url = imp_str($s, 'url', 2048, true, false, $errs, $w, $ln); $pub = imp_str($s, 'publisher', 190, true, false, $errs, $w, $ln); $quote = imp_str($s, 'quote', 1000, true, true, $errs, $w, $ln);
    if ($url !== null && (!uni_url_ok($url) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) { imp_err($errs, $w, $ln, 'url', 'url_invalid'); $url = null; }
    $ret = isset($s['retrieved_at']) && is_string($s['retrieved_at']) ? uni_parse_datetime($s['retrieved_at']) : null; if ($ret === null) imp_err($errs, $w, $ln, 'retrieved_at', 'retrieved_at_invalid');
    $hash = $s['content_hash'] ?? null; if ($hash !== null && (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/D', $hash))) { imp_err($errs, $w, $ln, 'content_hash', 'hash_invalid'); }
    $srcIds[$id] = true;
    if ($url !== null && $pub !== null && $quote !== null && $ret !== null && ($hash === null || is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash))) $out['sources'][$id] = ['url' => $url, 'publisher' => $pub, 'retrieved_at' => $ret, 'quote' => $quote, 'content_hash' => $hash, 'origin' => 'import'];
  }
  $slugs = []; $keys = [];
  $dupKey = function (string $k, string $w, ?int $ln, array &$errs) use (&$keys): void { if ($k !== '') { if (isset($keys[$k])) imp_err($errs, $w, $ln, 'key', 'duplicate_key_in_file'); $keys[$k] = true; } };
  foreach (($doc['entities'] ?? []) as $i => $e) {
    $w = "entities[$i]"; $ln = $lines['entities'][$i] ?? null;
    if (!is_array($e) || array_is_list($e) && $e !== []) { imp_err($errs, $w, $ln, '', 'not_an_object'); continue; }
    imp_unknown($e, ['key', 'entity_type', 'slug', 'name', 'summary', 'sources'], $errs, $w, $ln);
    $n0 = count($errs);
    $type = imp_enum($e, 'entity_type', uni_entity_types(), null, $errs, $w, $ln); $name = imp_str($e, 'name', 190, true, false, $errs, $w, $ln); $sum = imp_str($e, 'summary', 500, false, false, $errs, $w, $ln);
    $slug = $e['slug'] ?? null; if (!is_string($slug) || strlen($slug) < 2 || strlen($slug) > 120 || $slug !== crm_slug($slug, 120)) { imp_err($errs, $w, $ln, 'slug', 'slug_invalid'); $slug = null; }
    $key = imp_key($e, $errs, $w, $ln); $refs = imp_srcrefs($e, $srcIds, $errs, $w, $ln);
    if ($slug !== null) { if (isset($slugs[$slug])) imp_err($errs, $w, $ln, 'slug', 'duplicate_slug_in_file'); $slugs[$slug] = true; }
    if ($key !== null) $dupKey($key === '' ? '' : 'entity:' . $key, $w, $ln, $errs);
    if (count($errs) === $n0) $out['entities'][] = ['i' => $i, 'line' => $ln, 'key' => $key !== '' ? 'entity:' . $key : 'entity:' . $slug, 'entity_type' => $type, 'slug' => $slug, 'name' => $name, 'summary' => $sum, 'sources' => $refs];
  }
  $evSeen = [];
  foreach (($doc['events'] ?? []) as $i => $e) {
    $w = "events[$i]"; $ln = $lines['events'][$i] ?? null;
    if (!is_array($e) || array_is_list($e) && $e !== []) { imp_err($errs, $w, $ln, '', 'not_an_object'); continue; }
    imp_unknown($e, ['key', 'entity', 'event_type', 'event_date', 'date_precision', 'date_kind', 'title', 'summary', 'sources'], $errs, $w, $ln);
    $n0 = count($errs);
    $ent = $e['entity'] ?? null; if (!is_string($ent) || strlen($ent) < 2 || strlen($ent) > 120 || $ent !== crm_slug($ent, 120)) { imp_err($errs, $w, $ln, 'entity', 'entity_reference_invalid'); $ent = null; }
    $type = imp_enum($e, 'event_type', uni_event_types(), null, $errs, $w, $ln); $kind = imp_enum($e, 'date_kind', ['announced', 'ga', 'preview'], 'announced', $errs, $w, $ln);
    $title = imp_str($e, 'title', 255, true, false, $errs, $w, $ln); $sum = imp_str($e, 'summary', 1000, false, true, $errs, $w, $ln);
    $prec = null; if (array_key_exists('date_precision', $e)) { if (!is_string($e['date_precision'])) imp_err($errs, $w, $ln, 'date_precision', 'invalid_value'); else $prec = $e['date_precision']; }
    $pd = isset($e['event_date']) && is_string($e['event_date']) ? uni_parse_date($e['event_date'], $prec) : null; if ($pd === null) imp_err($errs, $w, $ln, 'event_date', 'date_invalid');
    $key = imp_key($e, $errs, $w, $ln); $refs = imp_srcrefs($e, $srcIds, $errs, $w, $ln);
    if ($ent !== null && $title !== null && $pd !== null) { $d = $ent . '|' . $pd[0] . '|' . uni_norm_title($title); if (isset($evSeen[$d])) imp_err($errs, $w, $ln, 'title', 'duplicate_in_file'); $evSeen[$d] = true; }
    if ($key !== null) $dupKey($key === '' ? '' : 'event:' . $key, $w, $ln, $errs);
    if (count($errs) === $n0) $out['events'][] = ['i' => $i, 'line' => $ln, 'key' => $key !== '' ? 'event:' . $key : 'event:' . $ent . '|' . $pd[0] . '|' . uni_norm_title($title), 'entity' => $ent, 'event_type' => $type, 'event_date' => $pd[0], 'date_precision' => $pd[1], 'date_kind' => $kind, 'title' => $title, 'summary' => $sum, 'sources' => $refs];
  }
  $edSeen = [];
  foreach (($doc['edges'] ?? []) as $i => $e) {
    $w = "edges[$i]"; $ln = $lines['edges'][$i] ?? null;
    if (!is_array($e) || array_is_list($e) && $e !== []) { imp_err($errs, $w, $ln, '', 'not_an_object'); continue; }
    imp_unknown($e, ['key', 'from', 'to', 'edge_type', 'valid_from', 'valid_to', 'notes', 'sources'], $errs, $w, $ln);
    $n0 = count($errs);
    $refsE = [];
    foreach (['from', 'to'] as $f) { $v = $e[$f] ?? null; if (!is_string($v) || strlen($v) < 2 || strlen($v) > 120 || $v !== crm_slug($v, 120)) { imp_err($errs, $w, $ln, $f, 'entity_reference_invalid'); $v = null; } $refsE[$f] = $v; }
    if ($refsE['from'] !== null && $refsE['from'] === $refsE['to']) imp_err($errs, $w, $ln, 'to', 'self_edge');
    $type = imp_enum($e, 'edge_type', uni_edge_types(), null, $errs, $w, $ln); $notes = imp_str($e, 'notes', 500, false, true, $errs, $w, $ln);
    $vd = [];
    foreach (['valid_from', 'valid_to'] as $f) { if (!array_key_exists($f, $e) || $e[$f] === null) { $vd[$f] = null; continue; } $p = is_string($e[$f]) ? uni_parse_date($e[$f]) : null; if ($p === null) imp_err($errs, $w, $ln, $f, 'date_invalid'); $vd[$f] = $p[0] ?? null; }
    if ($vd['valid_from'] !== null && $vd['valid_to'] !== null && $vd['valid_to'] < $vd['valid_from']) imp_err($errs, $w, $ln, 'valid_to', 'validity_invalid');
    $key = imp_key($e, $errs, $w, $ln); $refs = imp_srcrefs($e, $srcIds, $errs, $w, $ln);
    if ($refsE['from'] !== null && $refsE['to'] !== null && $type !== null) { $d = $refsE['from'] . '|' . $type . '|' . $refsE['to']; if (isset($edSeen[$d])) imp_err($errs, $w, $ln, 'edge_type', 'duplicate_in_file'); $edSeen[$d] = true; }
    if ($key !== null) $dupKey($key === '' ? '' : 'edge:' . $key, $w, $ln, $errs);
    if (count($errs) === $n0) $out['edges'][] = ['i' => $i, 'line' => $ln, 'key' => $key !== '' ? 'edge:' . $key : 'edge:' . $refsE['from'] . '|' . $type . '|' . $refsE['to'], 'from' => $refsE['from'], 'to' => $refsE['to'], 'edge_type' => $type, 'valid_from' => $vd['valid_from'], 'valid_to' => $vd['valid_to'], 'notes' => $notes, 'sources' => $refs];
  }
  return [$out, $errs];
}

// ---- file access ------------------------------------------------------------------------------------------------------------------------
/** Safe read of one regular, non-symlink file. → [content, null] or [null, error code]. */
function imp_read_file(string $path, int $max, ?string $baseDir): array {
  if ($path === '' || strlen($path) > 4096 || strpos($path, "\0") !== false) return [null, 'path_invalid'];
  foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $seg) if ($seg === '..') return [null, 'path_traversal_refused'];
  $l = @lstat($path); if ($l === false) return [null, 'file_not_found'];
  if (($l['mode'] & 0170000) === 0120000) return [null, 'symlink_refused'];
  if (($l['mode'] & 0170000) !== 0100000) return [null, 'not_a_regular_file'];
  if ($l['size'] > $max) return [null, 'file_too_large'];
  if ($baseDir !== null) { $b = realpath($baseDir); $r = realpath($path); if ($b === false || $r === false || strpos($r . '/', rtrim($b, '/') . '/') !== 0) return [null, 'outside_base_dir']; }
  $h = @fopen($path, 'rb'); if ($h === false) return [null, 'file_unreadable'];
  $f = fstat($h);
  if ($f === false || $f['ino'] !== $l['ino'] || $f['dev'] !== $l['dev'] || ($f['mode'] & 0170000) !== 0100000) { fclose($h); return [null, 'file_changed_during_open']; }   // lstat and fstat must describe the same inode (no swap to a symlink between the checks)
  $c = stream_get_contents($h, $max + 1); fclose($h);
  if ($c === false) return [null, 'file_unreadable'];
  if (strlen($c) > $max) return [null, 'file_too_large'];
  return [$c, null];
}

// ---- database stage ---------------------------------------------------------------------------------------------------------------------
function imp_hash(string $k): string { return 'import:' . hash('sha256', 'uni-import-v1|' . $k); }
/** @return array<string,int> audit summary => target id for every given key hash already imported */
function imp_known_keys(PDO $pdo, array $hashes): array {
  $out = [];
  foreach (array_chunk(array_values(array_unique($hashes)), 400) as $chunk) {
    $s = $pdo->prepare("SELECT summary, target_id FROM uni_audit_log WHERE action = 'import_record' AND summary IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'); $s->execute($chunk);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['summary']] = (int)$r['target_id'];
  }
  return $out;
}
function imp_link_source(PDO $pdo, int $actorId, string $type, int $id, int $sid): void {
  // Lock-free equivalent of uni_attach_source for a row this same transaction just inserted: uni_attach_source locks the target with SELECT ... FOR UPDATE, which needs UPDATE rights.
  [$t, $c] = ['entity' => ['uni_entity_source', 'entity_id'], 'event' => ['uni_event_source', 'event_id'], 'edge' => ['uni_edge_source', 'edge_id']][$type];
  uni_insert($pdo, $t, [$c => $id, 'source_id' => $sid, 'created_at' => crm_now()]);
  uni_audit($pdo, $actorId, 'source_attach', $type, $id, 'source attached (import)', ['source_id' => $sid]);
}
function imp_run(PDO $pdo, array $actor, array $rec, bool $apply, int $batch, string $fileHash): array {
  $aid = (int)$actor['id']; $res = []; $committed = 0; $batchStart = 0; $c = ['created' => 0, 'would_create' => 0, 'skipped_already_imported' => 0, 'skipped_duplicate' => 0, 'flagged' => 0, 'errors' => 0, 'batches' => 0];
  $add = function (string $kind, array $r, string $status, string $detail = '') use (&$res, &$c) { $res[] = ['kind' => $kind, 'index' => $r['i'], 'line' => $r['line'], 'ref' => imp_clean((string)($r['slug'] ?? $r['title'] ?? $r['key'])), 'status' => $status, 'detail' => $detail]; if (isset($c[$status])) $c[$status]++; };
  $abort = function (string $kind, array $r, string $code) use (&$res, &$c, &$committed, &$batchStart, $pdo, $apply) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    for ($k = $batchStart; $k < count($res); $k++) if ($res[$k]['status'] === 'created') $res[$k]['status'] = 'rolled_back';   // rows of the failing batch never reached the database
    $res[] = ['kind' => $kind, 'index' => $r['i'], 'line' => $r['line'], 'ref' => imp_clean((string)($r['slug'] ?? $r['title'] ?? $r['key'])), 'status' => 'errors', 'detail' => $code];
    $c['errors']++; if ($apply) $c['created'] = $committed;
    return [$res, $c, false];
  };
  $hashes = []; foreach (['entities', 'events', 'edges'] as $k) foreach ($rec[$k] as $r) $hashes[] = imp_hash($r['key']);
  if (!$apply) $pdo->exec('SET TRANSACTION READ ONLY');   // the dry-run session is read only: the database itself refuses any write
  $pdo->beginTransaction();
  $known = imp_known_keys($pdo, $hashes);
  $srcIds = []; $processed = 0;
  $tick = function () use ($pdo, $apply, $batch, &$processed, &$c, &$committed, &$batchStart, &$res) { $processed++; if ($apply && $batch > 0 && $processed % $batch === 0) { $pdo->commit(); $c['batches']++; $committed = $c['created']; $batchStart = count($res); $pdo->beginTransaction(); } };
  $source = function (string $ref) use ($pdo, $aid, $rec, &$srcIds) { if (!isset($srcIds[$ref])) { [$ok, $id] = uni_source_create($pdo, $aid, $rec['sources'][$ref]); if (!$ok) return [false, (string)$id]; $srcIds[$ref] = (int)$id; } return [true, $srcIds[$ref]]; };
  $slugCache = [];
  $findSlug = function (string $slug) use ($pdo, &$slugCache): ?int {
    if (array_key_exists($slug, $slugCache)) return $slugCache[$slug];
    $s = $pdo->prepare('SELECT id FROM uni_entity WHERE slug = ? AND is_current = 1'); $s->execute([$slug]); $v = $s->fetchColumn(); return $slugCache[$slug] = ($v === false ? null : (int)$v);
  };
  $names = []; $tooMany = false;
  $q = $pdo->query("SELECT entity_type, name FROM uni_entity WHERE is_current = 1 AND state <> 'rejected' LIMIT 100001");
  foreach ($q->fetchAll(PDO::FETCH_NUM) as $n) { if (count($names) >= 100000) { $tooMany = true; break; } $names[$n[0] . '|' . uni_norm_title((string)$n[1])] = true; }
  $stage = function (string $type, int $id, array $r, array $extra) use ($pdo, $aid, $fileHash, $source) {
    foreach ($r['sources'] as $ref) { [$ok, $sid] = $source($ref); if (!$ok) return [false, $sid]; imp_link_source($pdo, $aid, $type, $id, (int)$sid); }
    uni_audit($pdo, $aid, 'import_record', $type, $id, imp_hash($r['key']), ['file_sha256' => $fileHash, 'record' => $r['i']] + $extra);
    return [true, 'ok'];
  };
  foreach ($rec['entities'] as $r) {
    $h = imp_hash($r['key']);
    if (isset($known[$h])) { $slugCache[$r['slug']] = $findSlug($r['slug']); $add('entity', $r, 'skipped_already_imported'); $tick(); continue; }
    $ex = $findSlug($r['slug']);
    if ($ex !== null) { $add('entity', $r, 'skipped_duplicate', 'slug already exists as a current row (not merged)'); $tick(); continue; }
    $fuzzy = isset($names[$r['entity_type'] . '|' . uni_norm_title($r['name'])]) && uni_norm_title($r['name']) !== '';
    if (!$apply) { $slugCache[$r['slug']] = -1; $add('entity', $r, 'would_create', $fuzzy ? 'possible duplicate by name' : ''); if ($fuzzy) $c['flagged']++; $tick(); continue; }
    [$ok, $id] = uni_propose_entity($pdo, $aid, ['entity_type' => $r['entity_type'], 'slug' => $r['slug'], 'name' => $r['name'], 'summary' => $r['summary'], 'origin' => 'import']);
    if (!$ok) return $abort('entity', $r, (string)$id);
    [$ok, $why] = $stage('entity', (int)$id, $r, ['kind' => 'entity']); if (!$ok) return $abort('entity', $r, (string)$why);
    if ($fuzzy) uni_audit($pdo, $aid, 'duplicate_flag', 'entity', (int)$id, 'possible duplicate by name (flagged, not merged)', ['kind' => 'name']);
    $slugCache[$r['slug']] = (int)$id; $names[$r['entity_type'] . '|' . uni_norm_title($r['name'])] = true; $add('entity', $r, 'created', $fuzzy ? 'possible duplicate by name (flagged)' : ''); if ($fuzzy) $c['flagged']++; $tick();
  }
  foreach ($rec['events'] as $r) {
    $h = imp_hash($r['key']);
    if (isset($known[$h])) { $add('event', $r, 'skipped_already_imported'); $tick(); continue; }
    $eid = $findSlug($r['entity']); if ($eid === null) return $abort('event', $r, 'entity_not_found');
    if ($eid > 0) { $dups = uni_find_duplicate_events($pdo, $eid, $r['event_date'], $r['title']); if ($dups) { $add('event', $r, 'skipped_duplicate', 'same entity, date and title exist (not merged)'); $tick(); continue; } }
    if (!$apply) { $add('event', $r, 'would_create'); $tick(); continue; }
    [$ok, $out] = uni_propose_event($pdo, $aid, ['entity_id' => $eid, 'event_type' => $r['event_type'], 'event_date' => $r['event_date'], 'date_precision' => $r['date_precision'], 'date_kind' => $r['date_kind'], 'title' => $r['title'], 'summary' => $r['summary'], 'origin' => 'import']);
    if (!$ok) return $abort('event', $r, (string)$out);
    [$ok, $why] = $stage('event', (int)$out['id'], $r, ['kind' => 'event']); if (!$ok) return $abort('event', $r, (string)$why);
    $add('event', $r, 'created', $out['date_conflicts'] ? 'same title on another date recorded as a conflict candidate' : ''); $tick();
  }
  foreach ($rec['edges'] as $r) {
    $h = imp_hash($r['key']);
    if (isset($known[$h])) { $add('edge', $r, 'skipped_already_imported'); $tick(); continue; }
    $from = $findSlug($r['from']); $to = $findSlug($r['to']); if ($from === null || $to === null) return $abort('edge', $r, 'entity_not_found');
    if ($from > 0 && $to > 0) { $x = $pdo->prepare("SELECT 1 FROM uni_edge WHERE from_entity_id = ? AND to_entity_id = ? AND edge_type = ? AND is_current = 1 AND state <> 'rejected'"); $x->execute([$from, $to, $r['edge_type']]); if ($x->fetchColumn()) { $add('edge', $r, 'skipped_duplicate', 'same edge exists (not merged)'); $tick(); continue; } }
    if (!$apply) { $add('edge', $r, 'would_create'); $tick(); continue; }
    [$ok, $id] = uni_propose_edge($pdo, $aid, ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => $r['edge_type'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'notes' => $r['notes'], 'origin' => 'import']);
    if (!$ok) return $abort('edge', $r, (string)$id);
    [$ok, $why] = $stage('edge', (int)$id, $r, ['kind' => 'edge']); if (!$ok) return $abort('edge', $r, (string)$why);
    $add('edge', $r, 'created'); $tick();
  }
  if ($apply) {
    if ($c['created'] > 0) uni_audit($pdo, $aid, 'import_run', '', null, 'import run', ['file_sha256' => $fileHash, 'created' => $c['created'], 'skipped_already_imported' => $c['skipped_already_imported'], 'skipped_duplicate' => $c['skipped_duplicate']]);
    $pdo->commit(); $c['batches']++;
  } else $pdo->rollBack();
  if ($tooMany) $res[] = ['kind' => 'file', 'index' => 0, 'line' => null, 'ref' => '', 'status' => 'note', 'detail' => 'name-based duplicate flagging skipped (more than 100000 current entities)'];
  return [$res, $c, true];
}

// ---- command line -----------------------------------------------------------------------------------------------------------------------
function imp_usage(): string { return "Usage: php scripts/uni_import.php --file=PATH --user=EDITOR_USERNAME [--apply] [--format=json|csv] [--kind=entities|events|edges (csv)] [--batch-size=N] [--base-dir=DIR] [--json]\nDefault is a dry run: nothing is written without --apply.\n"; }
function imp_finish(array $report, bool $asJson, int $code): void {
  if ($asJson) echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
  else {
    echo "uni_import " . $report['mode'] . ($report['file'] !== '' ? ' file=' . $report['file'] : '') . ($report['user'] !== '' ? ' user=' . $report['user'] : '') . "\n";
    foreach ($report['errors'] as $e) echo '  ERROR ' . imp_clean((string)($e['where'] ?? '')) . ($e['line'] ? ' line ' . $e['line'] : '') . ($e['field'] !== '' ? ' field ' . imp_clean((string)$e['field'], 30) : '') . ': ' . imp_clean((string)$e['code'], 60) . "\n";
    foreach ($report['records'] as $r) if ($r['status'] !== 'created' && $r['status'] !== 'would_create' || $r['detail'] !== '') echo '  ' . $r['kind'] . ' #' . $r['index'] . ($r['line'] ? ' line ' . $r['line'] : '') . ' ' . $r['ref'] . ': ' . $r['status'] . ($r['detail'] !== '' ? ' (' . imp_clean($r['detail'], 100) . ')' : '') . "\n";
    $t = $report['totals'];
    echo "  totals: " . ($report['mode'] === 'apply' ? 'created=' . $t['created'] : 'would_create=' . $t['would_create']) . ' skipped_already_imported=' . $t['skipped_already_imported'] . ' skipped_duplicate=' . $t['skipped_duplicate'] . ' flagged=' . $t['flagged'] . ' errors=' . count($report['errors']) . "\n";
    if ($report['mode'] === 'dry-run' && !$report['errors']) echo "  dry run: nothing was written. Re-run with --apply to create the proposed import leads.\n";
    if ($report['rolled_back']) echo "  the failing batch was rolled back" . ($report['totals']['batches'] > 0 ? ' (earlier committed batches stay; re-running is safe)' : '; nothing was written') . ".\n";
  }
  exit($code);
}

$opts = getopt('', ['file:', 'user:', 'apply', 'dry-run', 'format:', 'kind:', 'batch-size:', 'base-dir:', 'json', 'help']);
$asJson = isset($opts['json']);
$report = ['mode' => 'dry-run', 'file' => '', 'user' => '', 'sha256' => '', 'errors' => [], 'records' => [], 'totals' => ['created' => 0, 'would_create' => 0, 'skipped_already_imported' => 0, 'skipped_duplicate' => 0, 'flagged' => 0, 'errors' => 0, 'batches' => 0], 'rolled_back' => false];
$refuse = function (string $code) use (&$report, $asJson) { $report['errors'][] = ['where' => 'request', 'line' => null, 'field' => '', 'code' => $code]; fwrite(STDERR, imp_usage()); imp_finish($report, $asJson, 2); };
if (isset($opts['help'])) { echo imp_usage(); exit(0); }
foreach (['file', 'user', 'format', 'kind', 'batch-size', 'base-dir'] as $o) if (isset($opts[$o]) && !is_string($opts[$o])) $refuse('option_repeated_or_invalid');
if (isset($opts['apply']) && isset($opts['dry-run'])) $refuse('apply_and_dry_run_conflict');
$apply = isset($opts['apply']); $report['mode'] = $apply ? 'apply' : 'dry-run';
$file = (string)($opts['file'] ?? ''); $userName = (string)($opts['user'] ?? '');
if ($file === '') $refuse('file_required');
if ($userName === '') $refuse('user_required');
if (strlen($userName) > 40 || $userName !== crm_slug($userName, 40)) $refuse('user_invalid');
$batch = isset($opts['batch-size']) ? (preg_match('/^\d{1,4}$/D', (string)$opts['batch-size']) ? (int)$opts['batch-size'] : -1) : 0; if ($batch < 0) $refuse('batch_size_invalid');
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)); $format = (string)($opts['format'] ?? $ext);
if (!in_array($format, ['json', 'csv'], true) || $ext !== $format) $refuse('format_invalid');
$kind = (string)($opts['kind'] ?? ''); if ($format === 'csv' && !in_array($kind, ['entities', 'events', 'edges'], true)) $refuse('csv_kind_required'); if ($format === 'json' && $kind !== '') $refuse('kind_only_for_csv');
$report['file'] = imp_clean(basename($file), 60); $report['user'] = $userName;

[$raw, $why] = imp_read_file($file, IMP_MAX_BYTES, isset($opts['base-dir']) ? (string)$opts['base-dir'] : null);
if ($raw === null) { $report['errors'][] = ['where' => 'file', 'line' => null, 'field' => '', 'code' => (string)$why]; imp_finish($report, $asJson, 1); }
if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
if (!mb_check_encoding($raw, 'UTF-8') || strpos($raw, "\0") !== false) { $report['errors'][] = ['where' => 'file', 'line' => null, 'field' => '', 'code' => 'invalid_utf8_or_nul']; imp_finish($report, $asJson, 1); }
$report['sha256'] = hash('sha256', $raw);
try { [$doc, $lines] = $format === 'csv' ? imp_csv_to_doc($raw, $kind) : ImpJson::parse($raw); }
catch (ImpParseError $e) { $report['errors'][] = ['where' => 'file', 'line' => $e->srcLine, 'field' => '', 'code' => $e->getMessage()]; imp_finish($report, $asJson, 1); }
[$rec, $errs] = imp_validate($doc, $lines);
if ($errs) { $report['errors'] = $errs; $report['totals']['errors'] = count($errs); imp_finish($report, $asJson, 1); }

if (($u = getenv('AIHUB_IMPORT_DB_USER')) !== false && $u !== '') { putenv('AIHUB_DB_USER=' . $u); if (($p = getenv('AIHUB_IMPORT_DB_PASS')) !== false) putenv('AIHUB_DB_PASS=' . $p); }   // optional dedicated least-privilege account
$pdo = null; $locked = false;
try {
  $pdo = crm_pdo();
  $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
  $pdo->query('SELECT 1 FROM uni_entity LIMIT 0')->closeCursor();
  $s = $pdo->prepare('SELECT id, username, role, active FROM crm_users WHERE username = ?'); $s->execute([$userName]); $row = $s->fetch(PDO::FETCH_ASSOC);
  $actor = $row ? uni_actor($pdo, (int)$row['id']) : null;
  if (!$actor || $actor['role'] !== 'editor' || !uni_can_propose($actor)) { $report['errors'][] = ['where' => 'request', 'line' => null, 'field' => 'user', 'code' => 'user_not_an_active_editor']; imp_finish($report, $asJson, 2); }
  if ((int)$pdo->query("SELECT GET_LOCK('" . IMP_LOCK . "', 0)")->fetchColumn() !== 1) { $report['errors'][] = ['where' => 'request', 'line' => null, 'field' => '', 'code' => 'another_import_is_running']; imp_finish($report, $asJson, 2); }
  $locked = true;
  [$res, $tot, $ok] = imp_run($pdo, $actor, $rec, $apply, $batch, $report['sha256']);
  $report['records'] = $res; $report['totals'] = $tot;
  if (!$ok) { $report['rolled_back'] = $apply; $report['errors'][] = ['where' => 'database', 'line' => null, 'field' => '', 'code' => 'record_rejected_see_records']; }
  try { $pdo->query("SELECT RELEASE_LOCK('" . IMP_LOCK . "')")->closeCursor(); } catch (Throwable $e) { /* the lock dies with the connection */ }
  imp_finish($report, $asJson, $ok ? 0 : 1);
} catch (Throwable $e) {
  if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) { /* connection already reset */ } }
  sec_log('uni_import_exception', ['class' => get_class($e), 'code' => ($e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0)]);   // class and error number only: never the message (it can carry the account name, host or file content)
  $report['errors'][] = ['where' => 'database', 'line' => null, 'field' => '', 'code' => 'database_error_' . imp_clean(get_class($e), 40)]; $report['rolled_back'] = $apply; imp_finish($report, $asJson, 1);
}
