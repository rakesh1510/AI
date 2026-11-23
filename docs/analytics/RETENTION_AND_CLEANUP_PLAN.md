# Retention and cleanup plan (separate from the code fix; NOTHING HERE HAS BEEN RUN)

Status: plan for approval. No deletion or blanking has been run against staging, production or any database. No historical record is removed silently: every step below is a dry run first and needs your written approval, a backup, and a validation record.

## Scope
Historical `tool_clicks` rows written before the privacy fix may hold `visitor_id` (value of the `aih_visitor` cookie) and `ip_hash`. Raw `page_views` and `events` do not exist until tracking is enabled. Rollups hold no personal data.

## Step 0: discover (read-only)
```sql
SELECT COUNT(*) total, SUM(visitor_id <> '') with_visitor_id, SUM(ip_hash <> '') with_ip_hash, MIN(created_at), MAX(created_at) FROM tool_clicks;
SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) FROM tool_clicks GROUP BY m ORDER BY m;
SELECT COUNT(*) FROM page_views; SELECT COUNT(*) FROM events;   -- expected 0 before tracking is enabled
```
Record the results in the approval note. Expect the identifiers to exist only for clicks made before the fix.

## Step 1: validate the periods (before any deletion)
Owner and counsel confirm: raw 90 days, rollups 13 months, identifiers 30 days (proposals), against (a) the privacy notice wording, (b) affiliate-programme agreements (some require click records for commission audits: if so, keep the click row and tool, not the identifier), (c) any legal hold, (d) backup rotation. Output: a dated written decision with the three numbers.

## Step 2: dry run on a COPY first
Restore the latest backup to a scratch database, then:
`php scripts/analytics_purge.php --raw-days=90 --id-days=30 --rollup-days=396` (no `--apply`) prints what would change. Compare with Step 0.

## Step 3: apply on staging (after approval)
Backup; `php scripts/analytics_purge.php --raw-days=90 --id-days=30 --rollup-days=396 --apply`; the script refuses if rollups are missing for old raw days. Verify: dashboard history unchanged (it reads rollups), `SELECT COUNT(*) FROM tool_clicks` unchanged, `SELECT COUNT(*) FROM tool_clicks WHERE created_at < NOW() - INTERVAL 30 DAY AND (visitor_id <> '' OR ip_hash <> '')` is 0.

## Step 4: production and schedule (separate approval)
Same steps on production after staging acceptance. If scheduled, the administrator installs a monthly cron; the application never runs it. Review the periods yearly.

## Step 5: individual requests
Because new clicks carry no identifier, requests concern only historical rows: match by the cookie value the person supplies, blank those rows. Keep a log.

## Rollback
Deleted raw rows cannot be restored except from backup; identifiers blanked are gone. Hence: backup first, dry run first, staging first. Rollups can be rebuilt only from raw rows still present.
