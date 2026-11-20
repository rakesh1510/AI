# Analytics: staging installation, verification and rollback (NOT EXECUTED, NOT APPROVED)

Decision D6: staging installation is **not approved yet**. This is the exact procedure for when it is. Nothing here has been run. Tracking stays **off** until a separate approval. STAGING only; production follows only after staging acceptance and a further approval. The administrator runs every command; Claude does not.

## 0. Preconditions
Owner approval in writing; a fresh database backup (`mysqldump --single-transaction --routines DBNAME > backup-YYYYMMDD.sql`, stored off the server); MySQL **8.0 or later** (the rollup uses window functions); `AIHUB_ENV_LABEL=staging` so the banner is amber.

## 1. Read-only preflight (no writes)
Run as the application's database user or a read-only user:
```sql
SELECT VERSION();                                                                   -- must be 8.0+
SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
  AND table_name IN ('page_views','events','tool_clicks','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run','adm_tool_feature');
SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tool_clicks';   -- expect visitor_id and ip_hash
SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'page_views' AND column_name = 'device';  -- empty before step 3
SELECT COUNT(*) FROM page_views;  SELECT COUNT(*) FROM events;  SELECT COUNT(*) FROM tool_clicks;
SHOW GRANTS FOR CURRENT_USER();
```
Expected before install: the five `adm_*` tables absent (that is why the dashboard says "Analytics storage is not installed"); `page_views` and `events` present (else apply `database/phase2_analytics.sql` and `database/phase3_business.sql` first, each after review); `tool_clicks` has `visitor_id` and `ip_hash`. Record the three row counts. Also: `php scripts/db_preflight.php` if it is part of your release routine (read-only).

## 2. Create the reporting tables (additive, idempotent)
```bash
mysql DBNAME < database/phase8b_analytics.sql      # five new tables; no existing table is touched
```
Run by an administrator account, not the application user.

## 3. Optional: device column (decision D3)
```bash
mysql DBNAME < database/analytics_a4_device.sql    # ALTER TABLE page_views ADD COLUMN device ... ALGORITHM=INSTANT
```
Skip it and everything still works; the dashboard then says device data is not collected. The user agent is never stored.

## 4. Database grants (least privilege)
```sql
GRANT SELECT, INSERT ON DBNAME.page_views TO 'app'@'host';        -- collection (existing)
GRANT SELECT, INSERT ON DBNAME.events     TO 'app'@'host';        -- collection (existing)
GRANT SELECT, INSERT ON DBNAME.tool_clicks TO 'app'@'host';       -- outbound counter (existing)
GRANT SELECT, INSERT, UPDATE, DELETE ON DBNAME.adm_traffic_daily, DBNAME.adm_traffic_dim_daily, DBNAME.adm_tool_daily, DBNAME.adm_event_daily, DBNAME.adm_rollup_run TO 'rollup'@'host';
GRANT SELECT ON DBNAME.adm_traffic_daily, DBNAME.adm_traffic_dim_daily, DBNAME.adm_tool_daily, DBNAME.adm_event_daily, DBNAME.adm_rollup_run, DBNAME.adm_tool_feature TO 'app'@'host';
```
The rollup account also needs SELECT on `page_views`, `events`. The retention script (section 8) additionally needs DELETE on `page_views` and `events` and UPDATE on `tool_clicks`, preferably on a separate administrator account used only for that job.

## 5. Verify with tracking OFF
1. Sign in as owner, open Control Center, Analytics: the message must change from "Analytics storage is not installed" to "No tracking data yet ... not enabled".
2. `php scripts/adm_rollup.php --days=2` must print `nothing_to_do` or zero rows and exit 0.
3. Open `AI tool clicks`, `Featured placements`: honest empty or unavailable states, no errors.

## 6. Enable tracking on staging (separate approval, test traffic only)
Set in the staging environment (not in the repository): `AIHUB_ANALYTICS=1`, `AIHUB_ANALYTICS_SALT=<32+ random characters>` (`openssl rand -hex 32`). Browse staging yourself on a phone, a tablet and a desktop browser, open a tool page, click a tool's outbound link **once**, double-click another, and visit with Do-Not-Track on.
Check in SQL:
```sql
SELECT day, path, page_type, device, visitor_hash <> '' AS has_hash FROM page_views ORDER BY id DESC LIMIT 20;   -- no IP, no user agent; device is one word
SELECT COUNT(*) FROM events WHERE name = 'outbound_click' AND day = CURDATE();                                 -- one per real click; the double click is one
SELECT COUNT(*) FROM tool_clicks WHERE created_at >= CURDATE();                                                -- same count as above, never double
```
Expect: Do-Not-Track visits have an empty hash and empty device; the single click produced exactly one `events` row and one `tool_clicks` row (this verifies the double-counting fix on a real server).

## 7. Rollup schedule
Administrator installs (the application never does): hourly `7 * * * * cd /path/to/app && php scripts/adm_rollup.php >> /var/log/aihub-rollup.log 2>&1` and a nightly safety run `37 2 * * * ... php scripts/adm_rollup.php --days=3`. One-time back-fill from real rows only: `php scripts/adm_rollup.php --since-first`. Check `SELECT * FROM adm_rollup_run ORDER BY id DESC LIMIT 5;` shows `ok`.

## 8. Retention (only after the owner decides the periods; see PRIVACY_AND_RETENTION.md)
Dry run first, always: `php scripts/analytics_purge.php --raw-days=N --id-days=M` (prints counts, changes nothing). `--apply` only after a backup and written approval of N and M. No default exists.

## 9. Reconcile
Pick one test day. Dashboard "page views" for that day must equal `SELECT COUNT(*) FROM page_views WHERE day = 'D' AND is_bot = 0 AND status < 400`; daily visitors must equal `SELECT COUNT(DISTINCT visitor_hash) ... AND visitor_hash <> ''`; outbound clicks must equal the `events` count with `prop2 IN ('sponsored','organic')`. Export each CSV and compare totals with the screen.

## 10. Rollback
1. Unset `AIHUB_ANALYTICS` (tracking stops at once; pages are unaffected). 2. Remove the cron lines. 3. `mysql DBNAME < database/analytics_a4_device_rollback.sql` (only if step 3 was applied). 4. `mysql DBNAME < database/phase8b_rollback.sql` (drops only the five rollup tables; raw tables untouched; rollups can be rebuilt with `--since-first`). 5. Restore the backup only if something unexpected changed.

## 11. Acceptance checklist (staging)
Sign-in and owner-only access confirmed; preflight outputs saved; migrations applied once; grants verified (a non-owner user and the application user cannot write to the `adm_*` tables); one click = one record; Do-Not-Track respected; reconciliation matches; exports download and open safely; rollback rehearsed on a copy; screenshots of each dashboard view attached.
