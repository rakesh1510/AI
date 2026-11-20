<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Analytics A1: the ONE place an outbound AI-tool click is counted. redirect.php calls it once per request; nothing else writes a click
 * (track-click.php is read-only, the browser no longer sends an outbound_click event or posts to track-click.php).
 *
 * A request counts once, writing at most one lifetime row (tool_clicks) and one dated event (outbound_click), unless it is:
 *   bot         - classified as a crawler or automation (these are not visitors; tool_clicks used to include them)
 *   prefetch    - a browser prefetch or prerender, not a click
 *   head        - a HEAD request
 *   duplicate   - a second request for the same tool from the same network within 5 seconds (double click)
 *   rate_limited- more than 20 per 10 minutes per tool and network (inflation guard, unchanged)
 *   unavailable - tracking storage not writable (the visitor is still redirected)
 * The `tracked=1` flag that older scripts appended is ignored: it can neither add nor suppress a count.
 *
 * @param array $server  $_SERVER
 * @param array $io      injectable for tests: limit(bucket,max,window):bool (true = over the limit), click(id), event(id,affiliate,placement), writable():bool
 * @return string        counted | bot | prefetch | head | duplicate | rate_limited | unavailable
 */
function outbound_click_record(string $id, array $tool, array $server, array $io = []): string {
  $limit = $io['limit'] ?? 'sec_rate_limited'; $writable = $io['writable'] ?? 'tool_clicks_writable';
  $click = $io['click'] ?? 'record_click';
  $event = $io['event'] ?? fn(string $i, string $aff, string $place) => event_record_server('outbound_click', $i, $aff, $place);
  if (strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') return 'head';
  $purpose = strtolower((string)($server['HTTP_SEC_PURPOSE'] ?? ($server['HTTP_PURPOSE'] ?? ($server['HTTP_X_PURPOSE'] ?? ''))));
  if ($purpose !== '' && (strpos($purpose, 'prefetch') !== false || strpos($purpose, 'prerender') !== false || $purpose === 'preview')) return 'prefetch';
  if (analytics_classify_agent((string)($server['HTTP_USER_AGENT'] ?? ''))['is_bot']) return 'bot';
  if ($limit('click:' . $id, 20, 600)) return 'rate_limited';
  if ($limit('clickdup:' . $id, 1, 5)) return 'duplicate';
  $did = false;
  try {
    if ($writable()) { $click($id); $did = true; }
    $event($id, function_exists('is_affiliate_link') && is_affiliate_link($tool) ? 'yes' : 'no', function_exists('is_featured_tool') && is_featured_tool($tool) ? 'sponsored' : 'organic');
    $did = true;
  } catch (Throwable $e) { if (function_exists('sec_log_exception')) sec_log_exception($e, 'outbound_click'); }
  return $did ? 'counted' : 'unavailable';
}
