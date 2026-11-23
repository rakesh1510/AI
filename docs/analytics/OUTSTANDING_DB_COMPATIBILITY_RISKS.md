# Outstanding database compatibility risks (unverified; no MySQL test was run or authorised)

| # | Risk | Why it matters | Detection (read-only) | Mitigation |
|---|---|---|---|---|
| 1 | A UNIQUE index on `tool_clicks` involving `visitor_id`/`ip_hash` | Empty identifiers would make every later click of a tool fail to insert (silent under-count; the visitor is still redirected) | `SHOW INDEX FROM tool_clicks` (checklist step 3) | Stop; an approved migration drops or changes the index. No such index appears in any repository SQL file, but the live table was created outside them |
| 2 | `tool_clicks` created from the older install file (`ip_address`, `user_agent`) | The application already treats it as "interactions unavailable"; counts would be zero | column list (step 3), `scripts/db_preflight.php` | Approved migration to the current shape, staging first |
| 3 | MySQL older than 8.0 | Rollup uses `LAG` and `ROW_NUMBER`; the device column uses `ALGORITHM=INSTANT` | `SELECT VERSION()` | Upgrade first or do not install |
| 4 | `page_views`/`events` shape differs from the repository files | Collectors fail silently (by design) and nothing is recorded | column list (step 3) | Apply the missing migration after review |
| 5 | `ALTER TABLE page_views` locking on a very large table | Brief stall | `SELECT COUNT(*)` first; INSTANT needs 8.0.12+ | Run off-peak, or skip the device column |
| 6 | Timezone of PHP, database and cron host | Wrong days if any writer used local time (fixed in code for analytics; old rows if any) | step 4 and 12 | Correction procedure in `STAGING_INSTALL_AND_VERIFY.md` 1c, owner approval first |
| 7 | Character set or collation differences between `adm_*` tables and raw tables | Joins use no string joins across tables; low risk | `SELECT @@collation_database` | None expected |
| 8 | Rollup performance on large `page_views` | The hourly run reads one day at a time with indexes on `day` | `EXPLAIN` on the day query after data exists | Index review (existing `idx_pv_day_bot`) |
| 9 | Grants too broad or too narrow | Writes to wrong tables, or rollup failing | `SHOW GRANTS` (step 7) | Least-privilege grants in the guide |
| 10 | Real `AdmAnPdoStore` queries and the rollup have not run on MySQL | Syntax or performance surprises | steps 8 and 14 on staging | Reconcile against SQL; fix before production |
| 11 | Phase 8B MySQL test not run | Existing coverage of the rollup on a real database is not refreshed | Needs separate authorisation for a disposable database | Ask for approval when ready |
