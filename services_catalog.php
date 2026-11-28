<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
// Static TrustNova Tech services catalogue (data/trustnova_services.json). No database, no CRM data: safe for pages whose code must stay independent of CRM and campaign data.
function trustnova_catalog(): array { return catalog_json('trustnova_services.json', ['services' => [], 'process' => [], 'honest_notes' => []]); }
function trustnova_services(): array { return trustnova_catalog()['services'] ?? []; }
/** The eight portfolio services shown on the homepage and the business page (visitor-facing titles and one-line benefits from data/trustnova_services.json; the first two outcomes). */
function trustnova_services_for_home(): array {
  $out = [];
  foreach (trustnova_services() as $slug => $s) {
    if ((int)($s['portfolio'] ?? 0) < 1) continue;
    $out[(int)$s['portfolio']] = ['slug' => $slug, 'title' => (string)$s['title'], 'icon' => (string)($s['icon'] ?? 'sparkle'), 'summary' => (string)($s['short'] ?? $s['summary']), 'deliverables' => array_slice((array)($s['outcomes'] ?? $s['deliverables'] ?? []), 0, 2)];
  }
  ksort($out); return array_values($out);
}
