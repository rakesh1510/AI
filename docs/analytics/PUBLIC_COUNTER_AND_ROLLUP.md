# Public click counter and rollup freshness: investigation, fix and staging procedure

Branch `feat/aihubpedia-analytics`, builds on `f79291a` (the new commit is named in the report). **Live staging is NOT verified from here** (no access to `srv1424046` or https://staging.aihubpedia.com). Items marked LOCAL were proven on a disposable MariaDB 10.11 with the real application over HTTP; items marked PENDING need the VPS administrator.

## 1. The trace: Visit site, saved click, public counter, admin reporting
1. **Visit site**: links point to `/redirect.php?id=<tool>` (`tool.php`, cards, compare, recommend). The browser no longer posts anything (single-count fix).
2. **Redirect**: `redirect.php` calls `outbound_click_record()` once: bots, prefetches, HEAD, a repeat within 5 seconds and over 20 per 10 minutes are not counted; otherwise it writes **one `tool_clicks` row** (`visitor_id` and `ip_hash` empty) and **one `events` row** (`outbound_click`, sponsored/organic), then redirects.
3. **Public 👁 counter**: `render_vote_buttons()` prints `click_count($tool)`, which reads `all_click_counts()`.
4. **Admin reporting**: *Legacy click records* and the new *Public lifetime total* read `tool_clicks` directly (live). The date-filtered tables (Overview, AI tool clicks, Categories) read the **rollup** (`adm_tool_daily`, built from `events`), so they lag until a rollup runs.

## 2. Root cause of the stuck public counter (verified in code, reproduced by test)
`all_click_counts()` took its totals from `tool_clicks` **only if `interaction_tables_exist()` was true**, and that function requires `tool_votes` as well as `tool_clicks` (columns `visitor_id`, `ip_hash`, and a `vote_type` that is not the old ENUM). Staging has no `tool_votes`, so the function returned false and the counter **silently fell back to the old `clicks.json`** (static legacy numbers), while the click itself was saved correctly (the write path was already fixed in `f79291a`). That is exactly why Admin showed new records and the public number did not move. Reading a total needs nothing from the voting table.

## 3. Fixes
- `helpers.php`: new `tool_clicks_readable()` (database reachable, table present, `tool_slug` column present; independent of `tool_votes` and of the identifier columns) and `click_counts_source()`; `all_click_counts()` now reads `tool_clicks` through it. `clicks.json` is used only when no readable `tool_clicks` exists.
- **Server-confirmed refresh** (answers "should returning from the external tab refresh the count?": yes, from the server only): new read-only `click-count.php` (GET, no-store, no cookie, rate limited, not a page view, up to 30 valid ids) and a small block in `assets/js/interactions.js`. When the visitor returns to the tab (visibility/focus), restores the page with Back (bfcache or a cached copy), the totals are re-read and written into the counter. **The script never adds 1 by itself**; a click the server suppressed leaves the server total unchanged, so the number does not change; if the request fails the old number stays and the click stays pending for the next attempt. (Browser cache: tool pages send no caching headers; the Back-button case is covered by the refresh.)
- `scripts/analytics_config_check.php`: it now loads `db.php` itself. Cause of "FAIL database connection Error": `db()` lives in `db.php`, which the web path loads lazily through `db_connection_available()`; a CLI script calling `db()` directly (the checker) hit an undefined function. No `auto_prepend_file` is needed. The checker also reports the public counter's source (database or the old JSON file) and totals, and prints a connection error with the class and a redacted message.
- Dashboard: **freshness banner** on every Analytics view (last rollup time and age; "out of date" alert after 90 minutes; the number of page views and outbound clicks recorded since the last rollup and not yet in the reports); the AI tool clicks table gets a **Public lifetime total (all dates)** column with an explanation that it is a different number from the date-filtered columns; the Legacy view shows the lifetime total; the tools CSV has the same column.

## 4. LOCAL verification (all executed)
- `tests/analytics_e2e_http_test.php`: **66 passed, 0 failed** (real PHP over HTTP, empty environment, config file only), including: public total equals the database total (5 legacy rows + clicks) while `tool_votes` is missing and `clicks.json` says 777; one eligible click raises both the record count and the public total by exactly one; a double click, the old `tracked=1` link, a bot and a prefetch do not change it; `click-count.php` returns only valid tools, is no-store, sets no cookie, refuses POST and never changes data; an incompatible old-shape `tool_votes` still gives the database total and still records a click; events carry the 3 eligible clicks (not the legacy rows); the dashboard reports activity "not yet included" after the last rollup, a manual rollup brings the date-filtered clicks to 4 and the pending count to 0, and the public total, database total and dashboard lifetime column agree (9); the checker passes with no auto_prepend_file and prints no password.
- `tests/browser_click_counter_test.js`: **11 passed** (Chromium): no local increment on click; server total shown on return; other tools untouched; suppressed click leaves the number; failure leaves the old number and keeps the click pending; Back/bfcache refresh; invalid server values ignored; no local increment in the code.
- `tests/analytics_a2_a5_test.php` **115**, `tests/analytics_config_test.php` **195**, privacy 68, A1 49, installer static 41, security 147, security inventory 537, Unified Admin, Finance, tools, data quality: all passing; lint and built assets clean.
- Not run: any MySQL 8.4 check, any live check.

## 5. The stale rollup (separate finding)
The screenshot's last rollup, 2026-11-20 15:20:47 UTC, is the **manual run** made during the setup verification. **No scheduled job exists**: cron was deliberately not installed (it needs your approval), so nothing refreshes the rollup tables. Date-filtered reports therefore contain only what existed at that moment, while the public counter and the Legacy view read live tables. This is expected behaviour, not a malfunction, and is now visible on screen (banner).

### Recovery (staging only; run as the web user so file and database rights are the real ones)
```bash
cd /var/www/html/AI-PHASE3-TEST
sudo -u www-data php scripts/adm_rollup.php --since-first          # back-fills from real rows only; idempotent; staging database only
mysql aihub_phase6_test -e "SELECT id, job, day_from, day_to, started_at, finished_at, rows_written, status FROM adm_rollup_run ORDER BY id DESC LIMIT 3; SELECT day, views_human, visitors FROM adm_traffic_daily ORDER BY day DESC LIMIT 5;"
```
Then reload the dashboard: the banner should say "Reports are current" and "Nothing has been recorded since". Compare: *AI tool clicks* (date-filtered, from events) versus *Public lifetime total* (all rows): they differ by design.

### Scheduling (PROPOSAL, requires your approval; nothing was installed)
```bash
# /etc/cron.d/aihub-analytics-staging  (root-owned, 0644). One line per job; flock prevents overlapping runs; no secrets; the private config file supplies settings.
7 * * * *  www-data  cd /var/www/html/AI-PHASE3-TEST && /usr/bin/flock -n /tmp/aihub-rollup-staging.lock /usr/bin/php scripts/adm_rollup.php >> /var/log/aihub-rollup-staging.log 2>&1
37 2 * * * www-data  cd /var/www/html/AI-PHASE3-TEST && /usr/bin/flock -n /tmp/aihub-rollup-staging.lock /usr/bin/php scripts/adm_rollup.php --days=3 >> /var/log/aihub-rollup-staging.log 2>&1
```
Preconditions: the staging database account used by the application can DELETE/INSERT/UPDATE the five `adm_*` tables; the log file exists and is writable by `www-data` (`touch /var/log/aihub-rollup-staging.log && chown www-data /var/log/aihub-rollup-staging.log`). Verify after the first hour: `adm_rollup_run` shows a new `ok` row and the banner says "Reports are current". Remove with `rm /etc/cron.d/aihub-analytics-staging`. The production directory `/var/www/html/AI` must never get this file.

## 6. Staging update and rollback (staging directory only; no service restart except an FPM reload that you approve)
```bash
cd /var/www/html/AI-PHASE3-TEST
git rev-parse --short HEAD                     # note the current commit (expected f79291a) for rollback
git fetch origin feat/aihubpedia-analytics && git checkout feat/aihubpedia-analytics && git pull --ff-only
git rev-parse --short HEAD                     # must equal the commit in the report
sudo -u www-data php scripts/analytics_config_check.php --expect-db=aihub_phase6_test --expect-env=staging     # expect ALL PASS; "public click counter reads tool_clicks" must PASS
systemctl reload php*-fpm                      # only with your approval (shared service); opcache then serves the new code
# public counter check: open a tool page, note the 👁 number, click "Visit site" ONCE, return to the tab (it refreshes by itself) or reload:
mysql aihub_phase6_test -e "SELECT COUNT(*) FROM tool_clicks WHERE tool_slug='<tool id>';"      # equals the 👁 number
curl -s "https://staging.aihubpedia.com/click-count.php?ids=<tool id>"                          # {"counts":{"<tool id>":N},"source":"database"}
```
Rollback: `git -C /var/www/html/AI-PHASE3-TEST checkout f79291a` then the same FPM reload. No data, table, credential or production path is touched by the update or the rollback. If `source` prints `json`, the click table is not readable by the web account: send me the checker output.
