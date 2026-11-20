# Analytics: production readiness report (verification, blockers and the approval needed)

Branch `feat/aihubpedia-analytics`. **Nothing was run against production or against your staging database** (`aihub`, `aihub_phase6_test`): this environment cannot reach them and none of them was contacted. No migration, tracking activation, cron, merge or deployment was done; no tracking record was deleted, truncated, overwritten or reset.

## 1. Query compatibility: every query that touches `tool_clicks` versus the real columns (`id, tool_slug, visitor_id, ip_hash, created_at`)
| Code | Query | Columns used | Compatible |
|---|---|---|---|
| `record_click` (`helpers.php`) | `INSERT (tool_slug, visitor_id, ip_hash, created_at) VALUES (?, '', '', CURRENT_TIMESTAMP)`; `SELECT COUNT(*) WHERE tool_slug` | all four | Yes (NOT NULL-safe: empty strings). **Risk: fails if a UNIQUE index involves `visitor_id`/`ip_hash`** (checked by preflight) |
| `all_click_counts` | `SELECT tool_slug, COUNT(*) GROUP BY tool_slug` | tool_slug | Yes |
| `interaction_tables_exist` | existence of `visitor_id`, `ip_hash` | both | Yes (both exist; removing them would disable counting) |
| `adma_lifetime_clicks` | `SELECT tool_slug, COUNT(*) ... ORDER BY LIMIT` | tool_slug | Yes |
| `AdmAnPdoStore::legacyClicks` (new, read-only) | `DATE(created_at)`, `LAG() OVER (PARTITION BY visitor_id, tool_slug ...)`, `TIMESTAMPDIFF` | tool_slug, visitor_id, created_at, id | Yes on MySQL 8.x (window functions); degrades to totals without the duplicate estimate on older servers |
| `analytics_retention_*` | `COUNT`/`UPDATE ... SET visitor_id='', ip_hash=''` guarded by `created_at` | visitor_id, ip_hash, created_at | Yes; not run |
| `scripts/business_report.php` | `COUNT(*) WHERE created_at >= ?` | created_at | Yes |
| `scripts/analytics_verify.php` (new, read-only) | fingerprint `CRC32(CONCAT_WS(...))` over all five columns | all five | Yes |
The dashboard's date-filtered reports (visitors, tools, categories, placements) read **only** the seven new tables, never `tool_clicks`.

## 2. The seven tables (additive, idempotent; `database/analytics_install/`)
`01_page_views.sql` (= `phase2_analytics.sql`), `02_events.sql` (the `events` table only, extracted verbatim; `phase3_business.sql` is **not** run and would also create `leads` and `subscribers`), `phase8b_analytics.sql` (five reporting tables). Plus read-only `00_preflight_readonly.sql` and `90_verify_readonly.sql`, and two rollback files (`99_rollback_reporting.sql` safe; `99_rollback_raw_tables.sql` only while the raw tables hold nothing to keep). None contains DELETE, TRUNCATE, UPDATE or ALTER against `tool_clicks`; the optional device column is a separate file.

## 3. Rehearsal on an isolated disposable database (`tests/analytics_install_db_test.php`, **66 checks, 0 failed**)
Run on a local **MariaDB 10.11.14** scratch database, **not MySQL 8.4.10 and not your staging**. It built `tool_clicks` with the production columns and **71,502 synthetic rows** (16,540 planted double writes), then verified: both install runs (twice, idempotent) clean; exactly the seven tables created and `leads`/`subscribers` not created; **historical rows byte-identical after the migrations** (count, max id, CRC fingerprint) and after every later step including rollback; the read-only scripts change nothing; `record_click` on the production shape stores empty identifiers; the real collectors write raw rows without user agent or IP columns; rollup totals equal hand-counted rows (views, visitors, bots separated, outbound clicks counted once, old browser-style events excluded, affiliate and sponsored split correct) and are idempotent; all dashboard views and 9 exports run on the real tables; the legacy view equals direct SQL (71,503 incl. one new click) and its duplicate estimate equals the planted count; the optional device column applies, is rolled up and detected by a fresh process; the retention dry run counts identifiers and deletes nothing; rollback part 1 and part 2 work and the reporting tables can be rebuilt from raw rows; **a UNIQUE `(tool_slug, visitor_id)` index makes the second anonymous click fail and the documented preflight query finds it** (risk confirmed, so the preflight gate matters).
Also: `scripts/analytics_verify.php` pre and post phases exercised on a scratch database (including refusal on a wrong database name).

## 4. Database-free regression (current)
Analytics privacy 68, A1 49, A2-A5 106 (now includes legacy view and export); Unified Admin U1 155, U2 52, U3 42, U4 57, U5 39, U6 23; Finance 160; tools 115 and 180; categories 54; security 147; security inventory 521; data quality 279; i18n 35; content 27; secret-init 10; salt 10; dashboard browser checks 408 (17 synthetic pages x 3 sizes x light and dark); PHP lint clean; built assets current.

## 5. What is verified, what is not
| Item | Status |
|---|---|
| Migrations (idempotent, additive, history preserved), rollups, dashboard queries, legacy view, exports, device column, rollbacks | **Verified on a disposable MariaDB 10.11 rehearsal** |
| Same on MySQL 8.4.10 | **Not verified** (needs your staging `aihub_phase6_test`; follow the runbook there first) |
| Actual `tool_clicks` indexes, column types (is `created_at` TIMESTAMP or DATETIME?), nullability, row fingerprint | **Unknown**: preflight step 1 reads them |
| Production application version and whether the privacy and single-count fixes are deployed | **Unknown**: production runs older code; `analytics_verify.php --phase=pre` reports it; deployment is a separate approval |
| Production database connection, grants, tracking configuration, salt | **Unknown**: read-only commands in the runbook (steps 1, 4) |
| UTC behaviour of PHP, cron host and database | **Unknown** until step 6; collectors/rollup/dashboard are UTC by code |
| Staging (`aihub_phase6_test`) install | **Not performed** (not approved; no access from here) |

## 6. Blockers
1. Production code still old (identifiers and double writes continue until the branch chain is deployed; PR #8 must merge first because `main` auto-deploys). 2. Preflight facts not yet collected (unique index, column types, MySQL grants). 3. Staging dry run of the runbook on MySQL. 4. No approval yet for any gate. 5. Privacy items awaiting your decisions (identifier blanking, archive table, retention validation).

## 7. Exact approvals needed to proceed (in order; each separate)
1. **Staging:** "Approve running `PRODUCTION_INSTALL_RUNBOOK.md` steps 1 to 6 on `aihub_phase6_test` (backup first), with tracking OFF."
2. **Production code release (GATE 0):** approve deploying the reviewed branch chain, after PR #8, and name the window.
3. **Production preflight:** approve running the read-only steps 1 and 6 on `aihub` (SELECT/SHOW only).
4. **Production installation (GATE 1 and 2):** "Approve backup and running `01_page_views.sql`, `02_events.sql` and `phase8b_analytics.sql` on database `aihub`", plus grants (step 4).
5. Later and separate: enable tracking (GATE 3), install cron (GATE 4), legacy identifier archive and blanking, retention cleanup, Search Console, impressions.
Until step 4, the Control Center keeps saying "Analytics storage is not installed" on production, and nothing is recorded.
