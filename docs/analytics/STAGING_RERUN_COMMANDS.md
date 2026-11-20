# Staging rerun: exact VPS commands (database `aihub_phase6_test` only) — PREPARED, NOT EXECUTED

## Why the first run stopped, and the decision
The installer stopped because `aihub_phase6_test.tool_clicks` does not exist: staging holds CRM and AI Universe tables but has never recorded outbound clicks, and the old installer treated a missing table as an error (correctly refusing to guess).
**Decision: support databases without historical click data, by creating an EMPTY `tool_clicks` in staging, additively.** Reasons: (1) the application and the Analytics code expect the table (`interaction_tables_exist()`, the lifetime counter, the legacy report and the privacy-fixed recorder all reference it) and cope with its absence only by silently degrading, which would hide staging problems; (2) an empty table with the production five-column shape lets staging exercise the real click path, so the "one click = one row, empty identifiers, no cookie" checks can be performed; (3) it is safe: `CREATE TABLE IF NOT EXISTS` never alters or empties an existing table; (4) no production data is copied or read: staging stays free of real click records and identifiers. The alternative (skip the table) was rejected because it leaves the click path untestable.
What changed: new `03_tool_clicks_if_missing.sql` (empty, five columns `id, tool_slug, visitor_id, ip_hash, created_at`, repository current definition, no UNIQUE index), a preflight variant that never queries a missing table, and an installer that records the row count of every existing table before, after and in the restored backup copy, so the CRM and AI Universe records are proven untouched. An existing `tool_clicks` is verified and never altered; a wrong shape or a unique index still stops the run. Optional `--create-tool-votes` creates an empty `tool_votes` (the app writes `tool_clicks` rows only when both interaction tables exist; without it outbound clicks are still counted in `events`).

## MySQL 8.4.10 compatibility (what was handled)
information_schema labels are upper case in MySQL 8 (the verifier now aliases and lower-cases them, tested); `mysqldump` options for 8.4 (`--no-tablespaces`, `--set-gtid-purged=OFF` when supported, routine-privilege fallback); no removed syntax in any SQL file (static test); `SHOW GRANTS` parsing for backtick-quoted and wildcard database names; window functions and `CRC32` are standard in 8.4; `ALGORITHM=INSTANT` for the optional device column is supported. **Executed against MariaDB 10.11 only** (the only server available to me); the first staging run on MySQL 8.4.10 is the real confirmation, and any difference is reported by the script as a STOP or FAIL, never silently applied.

## Commands (on the VPS, from the application checkout of branch `feat/aihubpedia-analytics`)
```bash
cd /path/to/app && git fetch origin && git checkout feat/aihubpedia-analytics && git pull --ff-only        # the checkout must be this branch; do NOT deploy it to the live web root for this step
export STAGING_DB_HOST=<staging db host> STAGING_DB_PORT=3306 STAGING_DB_USER=<staging-only account>
read -rs MYSQL_PWD && export MYSQL_PWD                                                                       # type the password; it is not echoed or stored in shell history

# 1. Preflight, isolation checks, backup and restore test. Changes NOTHING in aihub_phase6_test (the restore test creates and drops its own scratch database).
scripts/analytics_staging_install.sh --restore-test
#    Review analytics-staging-<timestamp>/: run.log, preflight.txt, grants.txt, verify-pre.txt, counts-before.txt, counts-restored.txt, backups.sha256, the two .sql.gz files.
#    Expected: "tool_clicks does NOT exist ... will create it EMPTY", "restore test passed", "DRY RUN COMPLETE". Send me the folder (no passwords are in it).

# 2. Installation: only after the owner confirms the dry run
CONFIRM_INSTALL=aihub_phase6_test scripts/analytics_staging_install.sh --apply
#    Optional, only if you want the full click path testable on staging:
#    CONFIRM_INSTALL=aihub_phase6_test scripts/analytics_staging_install.sh --apply --create-tool-votes

# 3. Extra read-only confirmation (tracking is still OFF; expected: 8 new tables, tool_clicks 0 rows, page_views 0, events 0)
mysql -h"$STAGING_DB_HOST" -P"$STAGING_DB_PORT" -u"$STAGING_DB_USER" aihub_phase6_test -e "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tool_clicks','page_views','events','adm_traffic_daily','adm_traffic_dim_daily','adm_tool_daily','adm_event_daily','adm_rollup_run') ORDER BY 1; SHOW CREATE TABLE tool_clicks\G"
AIHUB_DB_HOST="$STAGING_DB_HOST:$STAGING_DB_PORT" AIHUB_DB_NAME=aihub_phase6_test AIHUB_DB_USER="$STAGING_DB_USER" AIHUB_DB_PASS="$MYSQL_PWD" php scripts/analytics_verify.php --phase=post --expect-db=aihub_phase6_test

unset MYSQL_PWD
```
If the staging and production databases live on the same MySQL server the script stops with "isolation not proven". That is not a failure of the install; send me the message. Proceed only after the owner accepts database-level isolation (`ALLOW_SHARED_SERVER=yes`) and confirms the account has no privileges on `aihub` (the script checks that separately and has no override).

## What these commands never do
Touch `aihub`; copy data; enable tracking (`AIHUB_ANALYTICS` is not set by anything here); install cron; deploy code; clean identifiers; drop, truncate, update or delete any table or row (the only DROP is the installer's own scratch database in the restore test).

## After a clean run (each a separate approval)
Dashboard check as owner on staging (should say "No tracking data yet ... not enabled"); then GATE 3 (enable tracking on staging, test traffic), GATE 4 (cron), later the code release for production.
