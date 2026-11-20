# Final staging installation checklist (PREPARATION ONLY: none of this has been executed; tracking stays OFF)

Detailed commands are in `STAGING_INSTALL_AND_VERIFY.md` (section numbers below). Tick in order; stop at the first failure and report.

| # | Step | Section | Pass criterion |
|---|---|---|---|
| 1 | Written owner approval for staging installation; backup taken and verified restorable | 0 | Backup file exists off-server |
| 2 | Read-only preflight | 1 | MySQL 8.0+; raw tables present; five `adm_*` tables absent; row counts recorded |
| 3 | Schema compatibility | 1b | No UNIQUE index including `visitor_id` or `ip_hash` on `tool_clicks`; columns as expected |
| 4 | UTC check of PHP, database and cron host | 1c | Understood and recorded; collectors use UTC regardless |
| 5 | Phase 8B migration (administrator account) | 2 | Five tables exist; no existing table changed |
| 6 | Optional device column | 3 | Column exists, or skipped deliberately |
| 7 | Grants | 4 | App user cannot write `adm_*`; rollup user can; verified with `SHOW GRANTS` |
| 8 | Dashboard with tracking OFF | 5 | "No tracking data yet ... not enabled"; rollup exits 0 |
| 9 | Enable tracking on staging only (separate approval): `AIHUB_ANALYTICS=1`, 32+ char salt | 6 | No IP or user agent in rows; Do-Not-Track gives empty hash and device |
| 10 | One click, one double click, one bot-like request, one prefetch-like request | 6 | Exactly one `events` row and one `tool_clicks` row for the real click; none for the others |
| 11 | Privacy-fix verification | 1d | New `tool_clicks` rows have empty identifiers; no `aih_visitor` cookie after an outbound click |
| 12 | UTC date verification on live rows | 1c | `viewed_at` equals UTC within seconds; `day` is the UTC date |
| 13 | Rollup schedule installed by the administrator; first runs `ok` | 7 | `adm_rollup_run` shows `ok` |
| 14 | Dashboard reconciliation (page views, daily visitors, outbound clicks vs SQL for one day; exports vs screen) | 9 | Numbers equal |
| 15 | Accessibility spot-check on a phone and a desktop; screenshots saved | 11 | No broken layout |
| 16 | Rollback rehearsal on a copy | 10 | Tables drop cleanly; site unaffected |
| 17 | Sign-off note: results, deviations, open risks | | Owner approval before any production step |

Not part of this checklist (separate approvals): retention cleanup, Search Console import, listing-impression tracking, production.
