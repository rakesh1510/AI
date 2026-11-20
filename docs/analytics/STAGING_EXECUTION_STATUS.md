# Staging installation (aihub_phase6_test): execution status

> **Update:** the first run on staging stopped because `tool_clicks` does not exist there. The installer now supports that case (creates an empty five-column table, never copies production data). Use `STAGING_RERUN_COMMANDS.md`. The text below is the earlier status.

**Status: NOT EXECUTED. Blocked: this environment has no network path or credentials for your staging database.** No `AIHUB_DB_*` variable, no `config.local.php`, no MySQL client configuration and no reachable database host exist here, and I did not ask for or accept database credentials in chat. Therefore none of the following could be confirmed: that `aihub_phase6_test` is isolated from `aihub`, the exact MySQL version, the account's privileges, the existing tables, or migration compatibility. Nothing was installed on staging, nothing was touched on production, and no record anywhere was changed.

## What was prepared so the administrator can run steps 1 to 6 safely: `scripts/analytics_staging_install.sh`
Target database is hard-coded to `aihub_phase6_test` (it cannot be pointed at `aihub`). Password only from `MYSQL_PWD`. It stops (exit 2) with a one-line reason at the first unexpected finding.
| Phase | What it does | Stops if |
|---|---|---|
| Connection and version | Connects, prints version, hostname, port, user, database | cannot connect; connected database differs; version below 8.0 (notes MariaDB and any version other than 8.4.x) |
| Isolation | Checks `aihub` is not visible on the same server; reads the account's grants and tests every grant pattern (including `%` and `_` wildcards and global grants) against the name `aihub` | `aihub` is visible (unless the owner explicitly accepts shared-server isolation with `ALLOW_SHARED_SERVER=yes`); any grant can reach `aihub` |
| Preflight and compatibility | Saves `00_preflight_readonly.sql` output; requires `tool_clicks` columns exactly `id,tool_slug,visitor_id,ip_hash,created_at`; no UNIQUE index involving the identifiers; records the row-count and CRC fingerprint; lists existing target tables, `leads`, `subscribers`; runs `analytics_verify.php --phase=pre` | any of these differs |
| Backup | Full dump and `tool_clicks` dump (gzip, `--single-transaction`), SHA-256 file, integrity checks (gzip test, size, "Dump completed", rows present); optional `--restore-test` restores `tool_clicks` into a throw-away scratch database it creates and drops, and compares count, max id and fingerprint | any check fails or the restore differs |
| Stop point | Without `--apply` the run ends here: **no change made** | |
| Install (`--apply` plus `CONFIRM_INSTALL=aihub_phase6_test`) | Applies `01_page_views.sql`, `02_events.sql`, `phase8b_analytics.sql` (additive, idempotent); checks the seven tables; compares the historical `tool_clicks` fingerprint up to the preflight max id; runs `90_verify_readonly.sql` and `analytics_verify.php --phase=post`; prints the UTC checks | any table missing, any fingerprint difference, any FAIL |
It never enables tracking, installs cron, deploys, cleans identifiers, or runs a DROP, TRUNCATE, UPDATE or DELETE.

## Rehearsal of the script itself (local disposable MariaDB 10.11, decoy `aihub` plus `aihub_phase6_test`, not your staging)
Refused a broad account (global privileges and a visible `aihub`); with a staging-only account the dry run passed preflight, produced and integrity-checked both backups and passed the restore test; `--apply` without the confirmation variable was refused; `--apply` installed the seven tables, left `tool_clicks` byte-identical, and verified; a second run was a harmless no-op; a UNIQUE `(tool_slug, visitor_id)` index stopped the run before any change; the decoy production database was untouched. Scratch databases, users and files were removed afterwards.

## Exact commands (run on a host that can reach staging, from the application checkout of branch `feat/aihubpedia-analytics`)
```bash
export STAGING_DB_HOST=<staging host> STAGING_DB_PORT=3306 STAGING_DB_USER=<staging admin account> MYSQL_PWD='<password>'
scripts/analytics_staging_install.sh --restore-test          # preflight, isolation, backup, restore test; STOPS without changing anything
# review analytics-staging-<timestamp>/run.log, preflight.txt, grants.txt, verify-pre.txt; send them to the owner
CONFIRM_INSTALL=aihub_phase6_test scripts/analytics_staging_install.sh --apply
```
Use an account that has privileges on `aihub_phase6_test` only (plus CREATE on `aihub\_phase6\_test\_bkverify\_%` if you want the restore test). If staging and production share a server, the script will stop and say so.

## Next required approval
Either (a) the administrator runs the commands above and sends the evidence folder for my review, or (b) you explicitly approve a secure way for me to reach staging (for example a temporary staging-only account through the platform's credential form, never pasted in chat) and I run it. The next separate approvals after a clean install remain: code release (GATE 0), enabling tracking on staging (GATE 3), cron (GATE 4).
