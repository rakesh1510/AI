# Database compatibility and migrations (Phase 6)

## Status of the sanitized schema: received and checked
The owner supplied an export of the live "aihub" database after development had started. **The file is not schema-only: it also contains data rows** (about 315 `INSERT` statements covering tools, clicks, votes, posts, submissions, settings and comparison data). It was **not committed, not opened in full and not printed**. Only its `CREATE TABLE` and `ALTER TABLE` statements (12 tables, about 260 lines) were extracted into a scratch directory **outside the repository**, and every test below ran on a throw-away local database built from that DDL with **synthetic** rows. No connection to any live system was made. Because the file holds real rows (including site settings and submissions), the owner should treat the original as sensitive: delete it from anywhere it is not needed and do not share it again; rotating credentials remains urgent regardless (SECURITY doc).

### What the live schema looks like (summary, no DDL reproduced)
* MySQL 8.4 server (the export header), 12 tables: `categories`, `tools`, `posts`, `use_cases`, `site_settings`, `submissions`, `tool_clicks`, `tool_votes`, `compare_tool_profiles`, `compare_tool_points`, `compare_tool_sources`, and **`tool_live_data`** (not known to the repository code; it is never read or written by any code here and is left alone).
* Mixed collations (`utf8mb4_general_ci`, `utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`).
* **Missing live:** `leads`, `lead_status_history`, `subscribers`, `events`, `page_views`, `contact_messages`. The Phase 2, 3 and 4 SQL files were never applied to the live database, so on the live site today the lead, newsletter and statistics features report "temporarily unavailable" or record nothing.
* Good news: `tool_clicks` and `tool_votes` already have the **current** shape (`visitor_id`, `ip_hash`, `vote_type` enum, unique vote key), which the Phase 4 reports had flagged as a risk. The nine tables the code needs all match what it expects.

### Comparison result (`scripts/db_preflight.php` on the live-shaped scratch database)
| Check | Result |
|---|---|
| Required features broken | **0** |
| Existing tables compatible with the code | 11 of 11 (all eight core tables and the three comparison tables report `ok`) |
| Missing, optional (degrade gracefully) | 6 earlier-phase tables + the 18 new CRM tables |
| Naming conflicts with the 18 `crm_*` tables | **none** |
| Foreign keys from CRM tables to legacy tables | **none** (the CRM stores plain ids, so mixed collations cannot cause "illegal mix of collations" errors; it never joins a legacy table) |
| Charset and engine of the CRM tables | InnoDB, utf8mb4 (collation is the server default; irrelevant as above) |

### Incompatibilities found and fixed
1. **The Phase 6 public forms depended on the missing `leads` table**, so on the live schema every advertising, project and partnership form would have reported "could not save". Fixed: a shared submit path (`crm_public_submit`) stores in `leads` when it exists and otherwise **in the CRM only**; the per-network limit falls back to the file limiter; a failed owner notification is then recorded as a CRM activity on the lead; and if neither `leads` nor the CRM tables exist the form shows the existing "temporarily unavailable" page instead of crashing. Tested in all three states.
2. **Documentation gap:** the required order on the live database is now explicit (below), because the earlier migrations were never applied.

### Test evidence (`tests/live_schema_compat_test.php`, 61 checks; needs the owner's DDL file via `AIHUB_LIVE_SCHEMA_FILE`, otherwise reports SKIPPED; the file is never committed)
* Synthetic rows are inserted into every live table, a fingerprint (checksum, column definitions, indexes) is taken, then:
  * **Stage A, live as is:** every public page renders; `/crm.php` says "not set up"; a project inquiry degrades to a clear error; nothing changes.
  * **Stage B, Phase 6 only:** `phase6_crm.sql` applies without errors; every live table is identical; inquiries (project, advertising, partnership) are accepted CRM-only; the limit still works; the partner applicant is not shown publicly.
  * **Stage C, Phase 2, 3, 4 then 6:** all apply without errors; every live table is still **byte-identical** (synthetic rows, definitions and indexes preserved); one inquiry creates the public lead and the CRM lead linked by id; every page renders; no PHP fatal or uncaught error in the log.
* Re-running the migrations is harmless except that `phase4_business.sql` (an `ALTER ... ADD COLUMN`, from Phase 4) reports duplicate columns on a second run; run it once.

## What was built
`database/phase6_crm.sql`: 18 tables, every statement `CREATE TABLE IF NOT EXISTS`, no `ALTER`, no `DROP`, no change to any existing table. Safe to run twice.

| Group | Tables |
|---|---|
| Identity and audit | `crm_users`, `crm_audit_log` |
| Sales | `crm_companies`, `crm_contacts`, `crm_leads`, `crm_opportunities`, `crm_stage_history`, `crm_activities`, `crm_tasks` |
| Money | `crm_proposals`, `crm_proposal_versions`, `crm_proposal_items`, `crm_contracts`, `crm_revenue_entries` |
| Growth | `crm_campaigns`, `crm_partners`, `crm_prospects`, `crm_outreach_drafts` |

Design rules: InnoDB, utf8mb4; money in `BIGINT` minor units with a 3-letter currency code and rates in integer basis points (no float or decimal); unique keys on `crm_users.username`, `crm_contacts.email_norm`, `crm_proposals.number`, `crm_campaigns.attribution_code`, `(proposal, version)`; indexes on status, kind, company, assignee, due date and campaign code; foreign keys that protect history (`RESTRICT` for companies with opportunities, contracts and campaigns; `SET NULL` for optional links; `CASCADE` only for a child that has no meaning without its parent). The public `leads` table is not altered; the CRM keeps its own lead record and stores the public lead's id.

## Compatibility with the existing tables
| Existing table | Relationship |
|---|---|
| `leads` (Phase 3/4) | Unchanged. Every public inquiry is still stored there first (existing notifications, status workflow and reports keep working). The CRM copy is best effort and carries `public_lead_id` |
| `subscribers`, `events`, `tools`, `categories`, `posts`, … | Untouched; not referenced |
| Tool and editorial data (JSON and DB) | Untouched; the CRM never reads or writes it, and ranking code never reads the CRM (tested) |

## Evidence (`tests/phase6_migration_test.php`, isolated throw-away database; run it to reproduce)
Verified on **MySQL 8.0.46** (local isolated server and GitHub Actions) and MariaDB 10.11: the migration applies with **no output and exit status 0**, twice, and the rollback runs cleanly. (An earlier CI failure was a test-harness problem, not the SQL: see TEST_RESULTS.md, run #32.)
* Baseline built from the repository's Phase 1 to 4 SQL; fingerprint taken of every existing table (checksum, column definitions, row count).
* After applying the migration: 18 tables exist; **every pre-existing table is identical**; applying it again changes nothing.
* Foreign-key behaviour proven: duplicate contact email refused; a company with opportunities cannot be deleted; deleting a contact keeps the lead; deleting an opportunity removes only its stage history; an orphan opportunity is refused.
* All money and rate columns are integer types; all CRM tables are InnoDB utf8mb4; the expected unique and secondary indexes exist.
* **Scale:** 20,000 companies, contacts and leads plus 5,000 opportunities seeded; list, search, dashboard and duplicate check measured (numbers in TEST_RESULTS.md) and bounded by thresholds in the test.
* **Rollback** (`database/phase6_rollback.sql`) removes only the `crm_*` tables, leaves everything else byte-identical, and the migration can be re-applied afterwards.
* The read-only preflight (`scripts/db_preflight.php`) now knows the 18 tables: on a Phase 4 database it reports them as missing and **optional** (verdict not "broken"), prints a plan that points to `phase6_crm.sql` and contains no destructive statement; after migration it reports them ok.

## Graceful degradation (no CRM tables)
If the migration has not been applied: `/crm.php` shows a "not set up" page (HTTP 503), public pages and forms keep working, the CRM mirror is skipped silently (logged), `/partners.php` shows its empty state.

## Safe validation procedure for the owner (do not run on the live database first)
0. **Required order on the live database** (all additive; each after a verified backup, on a copy first): `database/phase3_business.sql` (leads, subscribers, events), then `database/phase4_business.sql` (adds columns to `leads`, creates `lead_status_history`; run once), optionally `database/phase2_analytics.sql` (only if you will enable analytics), then `database/phase6_crm.sql`. `contact_messages` still has no SQL file in the repository (a Phase 4.1 finding): create it from `tests/fixtures/interactions_current.sql` if the contact form is wanted.
1. For any new check, give the team a **truly schema-only** export (no rows), or run `php scripts/db_preflight.php --plan` yourself on a restored **copy**.
2. On a copy or a new empty staging database: apply the existing SQL, then `database/phase6_crm.sql`, then run `php tests/phase6_migration_test.php` with a throw-away root user.
3. Compare the preflight output on the copy with `database/expected_schema.json`; resolve any differences before any live change.
4. On production, only after a verified backup and a maintenance decision: apply `phase6_crm.sql` (additive), then create the first owner with `php scripts/crm_user.php`. Keep `phase6_rollback.sql` and a backup ready. Never run the rollback if real CRM records exist unless you exported them.

## Recovery
Restore from the backup taken before the change; or, if only the CRM is unwanted and holds no real data, run the rollback script. Existing data is never modified by the migration, so no data restoration is needed for it.

## Next time a schema is supplied
Add its tables to the comparison in `tests/phase6_migration_test.php` baseline (or run the preflight against a copy), check name collisions (`crm_*`), engine and collation (the CRM needs utf8mb4 InnoDB), and MySQL/MariaDB version compatibility (the migration uses only portable DDL; tested on MariaDB locally and MySQL 8 in CI).
