<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
// Static TrustNova Tech services catalogue (data/trustnova_services.json). No database, no CRM data: safe for pages whose code must stay independent of CRM and campaign data.
function trustnova_catalog(): array { return catalog_json('trustnova_services.json', ['services' => [], 'process' => [], 'honest_notes' => []]); }
function trustnova_services(): array { return trustnova_catalog()['services'] ?? []; }
/** The four services shown on the homepage, with the visitor-facing names and the real deliverables from the services catalogue (data/trustnova_services.json). */
function trustnova_services_for_home(): array {
  $all = trustnova_services(); $out = [];
  foreach ([['api-integrations', 'API integrations', 'plug'], ['ai-workflow-automation', 'Workflow automation', 'flow'], ['backend-development', 'PHP/MySQL development', 'database'], ['custom-ai-agents', 'Custom AI solutions', 'sparkle']] as [$slug, $title, $icon]) {
    if (!isset($all[$slug])) continue; $s = $all[$slug];
    $out[] = ['slug' => $slug, 'title' => $title, 'icon' => $icon, 'summary' => (string)$s['summary'], 'deliverables' => array_slice((array)($s['deliverables'] ?? []), 0, 2)];
  }
  return $out;
}
