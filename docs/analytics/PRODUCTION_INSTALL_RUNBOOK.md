# Analytics: production installation runbook (PREPARED, NOT EXECUTED, NOT APPROVED)

Facts confirmed by the owner: database `aihub`, MySQL 8.4.10; `tool_clicks` has 71,502 rows from 2026-03-16 to 2026-11-20 with columns `id, tool_slug, visitor_id, ip_hash, created_at`; `page_views`, `events` and the five `adm_*` tables do not exist; staging is `aihub_phase6_test` and already runs the enhanced Analytics code; production runs older code.
**Hard rules:** nothing below runs without the owner's written approval of each GATE; tracking stays OFF; no cron; no deployment from here; no statement in `database/analytics_install/` deletes, truncates, updates or alters `tool_clicks` (asserted by the rehearsal). Run staging first with the same commands (`aihub_phase6_test`).
Notation: `DBH`, `DBU` are the host and an administrator user; the application user is separate. Replace `aihub` with `aihub_phase6_test` for the staging run.

> **Staging note (added after the first staging run):** `aihub_phase6_test` has no `tool_clicks` table (no historical clicks). The staging installer therefore creates it EMPTY with the five production columns (`03_tool_clicks_if_missing.sql`, additive and idempotent) and never copies production data. Production already has the table (71,502 rows) and **must not** be given this file: nothing in the production steps below creates or alters `tool_clicks`. See `STAGING_RERUN_COMMANDS.md` for the staging commands.

## GATE 0: code (separate approval; not part of the database work)
The privacy fix and single-count fix must be deployed before tracking is enabled, otherwise old code keeps writing identifiers and double counts. Deployment of the branch `feat/aihubpedia-analytics` (and its parents) is a separate approved release; this runbook does not deploy. After deployment, `php scripts/analytics_verify.php --phase=pre` confirms the code (steps below).

## STEP 1: read-only preflight (safe on production; SELECT and SHOW only)
```bash
mysql -hDBH -uDBU -p aihub < database/analytics_install/00_preflight_readonly.sql | tee preflight-$(date -u +%Y%m%dT%H%M%SZ).txt
php scripts/analytics_verify.php --phase=pre --expect-db=aihub | tee verify-pre.txt        # run from the application directory, with the application's environment
```
Save both outputs. **Stop conditions:** version below 8.0; a UNIQUE index involving `visitor_id` or `ip_hash` on `tool_clicks` (the verify line says FAIL); columns differ from the five expected; any of the seven tables already exists with unexpected content. Write down the fingerprint line `n,max_id,crc-sum` (expected `71502,<max id>,<number>`; clicks made after the preflight will change `n`, so the post check compares rows up to `max_id`).

## STEP 2: backup (GATE 1: owner approves the installation window; administrator performs)
```bash
mysqldump -hDBH -uDBU -p --single-transaction --routines --triggers aihub > aihub-full-$(date -u +%Y%m%dT%H%M%SZ).sql
mysqldump -hDBH -uDBU -p --single-transaction aihub tool_clicks > tool_clicks-$(date -u +%Y%m%dT%H%M%SZ).sql
sha256sum aihub-full-*.sql tool_clicks-*.sql > backups.sha256 ; ls -l aihub-full-*.sql tool_clicks-*.sql      # sizes must be non-trivial; store off the server
```
Restore test (recommended): load `tool_clicks-*.sql` into a scratch database and compare `SELECT COUNT(*)` to 71,502.

## STEP 3: migrations (GATE 2: explicit approval of "run the three SQL files on database aihub")
Three isolated files, additive, idempotent (`CREATE TABLE IF NOT EXISTS`). **`phase3_business.sql` is NOT run** (it also creates `leads` and `subscribers`).
```bash
mysql -hDBH -uDBU -p aihub < database/analytics_install/01_page_views.sql      # page_views
mysql -hDBH -uDBU -p aihub < database/analytics_install/02_events.sql          # events (only this table, extracted verbatim)
mysql -hDBH -uDBU -p aihub < database/phase8b_analytics.sql                    # adm_traffic_daily, adm_traffic_dim_daily, adm_tool_daily, adm_event_daily, adm_rollup_run
```
Each is safe to re-run. They contain no DROP, DELETE, TRUNCATE, UPDATE or ALTER (the rehearsal asserts the historical rows are byte-identical afterwards). Optional, separately approved: `database/analytics_a4_device.sql` (one additive column on `page_views`; INSTANT, empty table, no lock concern).

## STEP 4: grants (administrator; least privilege; adjust user and host names)
```sql
-- application user: collection and reading
GRANT SELECT, INSERT ON aihub.page_views   TO 'APPUSER'@'HOST';
GRANT SELECT, INSERT ON aihub.events       TO 'APPUSER'@'HOST';
GRANT SELECT, INSERT ON aihub.tool_clicks  TO 'APPUSER'@'HOST';      -- already present in production; keep INSERT, no UPDATE or DELETE
GRANT SELECT ON aihub.adm_traffic_daily, aihub.adm_traffic_dim_daily, aihub.adm_tool_daily, aihub.adm_event_daily, aihub.adm_rollup_run TO 'APPUSER'@'HOST';
-- rollup user (used only by the scheduled job)
GRANT SELECT ON aihub.page_views TO 'ROLLUPUSER'@'HOST';  GRANT SELECT ON aihub.events TO 'ROLLUPUSER'@'HOST';
GRANT SELECT, INSERT, UPDATE, DELETE ON aihub.adm_traffic_daily TO 'ROLLUPUSER'@'HOST';   -- repeat for adm_traffic_dim_daily, adm_tool_daily, adm_event_daily, adm_rollup_run
SHOW GRANTS FOR 'APPUSER'@'HOST'; SHOW GRANTS FOR 'ROLLUPUSER'@'HOST';
```
The dashboard also reads `adm_tool_feature` (Phase 8C; optional). If the application user cannot insert into the new tables, tracking silently records nothing (by design); the verification in step 7 catches it.

## STEP 5: post-migration verification (read-only)
```bash
mysql -hDBH -uDBU -p aihub < database/analytics_install/90_verify_readonly.sql      # edit SET @preflight_max_id first
php scripts/analytics_verify.php --phase=post --expect-db=aihub --fingerprint=N,MAXID,CRC | tee verify-post.txt      # values from step 1
```
Pass: seven tables present; `historical click records identical to the preflight fingerprint` PASS; zero FAIL. Then open `/admin-center.php?m=analytics` as owner: the page must change from "Analytics storage is not installed" to "No tracking data yet ... not enabled". The "Legacy click records" view already shows the 71,502 historical rows (read-only, labelled, with the duplicate estimate).

## STEP 6: UTC verification (read-only)
```bash
php -r 'echo date_default_timezone_get(), " local=", date("c"), " utc=", gmdate("c"), PHP_EOL;'   # on the web host and on the host that will run cron
mysql -hDBH -uDBU -p -e "SELECT @@global.time_zone, @@session.time_zone, NOW(), UTC_TIMESTAMP();"
mysql -hDBH -uDBU -p aihub -e "SELECT created_at, UNIX_TIMESTAMP(created_at), FROM_UNIXTIME(UNIX_TIMESTAMP(created_at)) FROM tool_clicks ORDER BY id DESC LIMIT 3;"
```
The collectors, rollup and dashboard use UTC (`gmdate`) regardless. `tool_clicks.created_at` is a `TIMESTAMP` set by the database; the legacy view reads it in UTC by setting the session time zone to `+00:00` for that query only (then restores it). If the column is `DATETIME` instead, its day boundaries follow the server's local time and the legacy view's day labels may be shifted by the server's offset: report, do not change data.

## GATE 3: enable tracking (separate approval; staging first, production later)
Set in the server environment (not in the repository): `AIHUB_ANALYTICS=1`, `AIHUB_ANALYTICS_SALT=<openssl rand -hex 32>`, `AIHUB_ENV_LABEL=production` (red banner). Then verify with test traffic: one real outbound click => exactly one `events` row (`prop2` sponsored or organic) and one new `tool_clicks` row with `visitor_id = ''` and `ip_hash = ''`; no `aih_visitor` cookie; Do-Not-Track visit => empty hash. Queries are in `STAGING_INSTALL_AND_VERIFY.md` section 6 and 1d.

## GATE 4: rollup schedule (separate approval; administrator installs, the application never does)
```cron
7 * * * *   cd /path/to/app && php scripts/adm_rollup.php >> /var/log/aihub-rollup.log 2>&1
37 2 * * *  cd /path/to/app && php scripts/adm_rollup.php --days=3 >> /var/log/aihub-rollup.log 2>&1
```
First run by hand: `php scripts/adm_rollup.php --days=2`; check `SELECT id, job, day_from, day_to, status, rows_written FROM adm_rollup_run ORDER BY id DESC LIMIT 5;` shows `ok`.

## STEP 7: dashboard reconciliation (after tracking has produced a test day)
For a chosen UTC day D: dashboard "page views" = `SELECT COUNT(*) FROM page_views WHERE day='D' AND is_bot=0 AND status<400`; daily visitors = `SELECT COUNT(DISTINCT visitor_hash) FROM page_views WHERE day='D' AND is_bot=0 AND status<400 AND visitor_hash<>''`; outbound clicks = `SELECT COUNT(*) FROM events WHERE day='D' AND name='outbound_click' AND prop2 IN ('sponsored','organic')`. Export each CSV and compare totals. The legacy view's total equals `SELECT COUNT(*) FROM tool_clicks WHERE created_at < 'D+1'`.

## ROLLBACK (any time; none touches tool_clicks)
1. Unset `AIHUB_ANALYTICS` (tracking stops immediately). 2. Remove the cron lines. 3. Reporting tables only: `mysql ... aihub < database/analytics_install/99_rollback_reporting.sql` (rebuildable with `adm_rollup.php --since-first`). 4. Raw tables only if they hold nothing you want: check counts, then `99_rollback_raw_tables.sql`. 5. If anything unexpected happened to data: restore from the step 2 backup into a scratch database first and compare before touching production.

## Decisions and approvals map
GATE 0 deploy code; GATE 1 window and backup; GATE 2 run the three files; optional device column; GATE 3 enable tracking; GATE 4 install cron; later and separate: retention cleanup, legacy identifier transition, Search Console, impressions.
