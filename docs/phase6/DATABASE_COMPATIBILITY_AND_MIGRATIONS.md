# Database compatibility and migrations (Phase 6)

## Status of the sanitized schema
**The sanitized, schema-only export of the existing "aihub" database was not supplied**, and none is committed. Per the brief, the schema export must not be committed without approval and a full production dump must not be requested. Phase 6 therefore used the repository's own SQL (`database/*.sql`, `tests/fixtures/interactions_current.sql`, `database/expected_schema.json`) and **independent, additive** design. **The migration is tested but NOT finalized against your real database.** Verification against the real schema is blocked until you supply it.

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
1. Obtain a **schema-only** export of "aihub" (no rows) and give it to the development team, or run `php scripts/db_preflight.php --plan` yourself on a restored **copy**.
2. On a copy or a new empty staging database: apply the existing SQL, then `database/phase6_crm.sql`, then run `php tests/phase6_migration_test.php` with a throw-away root user.
3. Compare the preflight output on the copy with `database/expected_schema.json`; resolve any differences before any live change.
4. On production, only after a verified backup and a maintenance decision: apply `phase6_crm.sql` (additive), then create the first owner with `php scripts/crm_user.php`. Keep `phase6_rollback.sql` and a backup ready. Never run the rollback if real CRM records exist unless you exported them.

## Recovery
Restore from the backup taken before the change; or, if only the CRM is unwanted and holds no real data, run the rollback script. Existing data is never modified by the migration, so no data restoration is needed for it.

## When the sanitized schema arrives
Add its tables to the comparison in `tests/phase6_migration_test.php` baseline (or run the preflight against a copy), check name collisions (`crm_*`), engine and collation (the CRM needs utf8mb4 InnoDB), and MySQL/MariaDB version compatibility (the migration uses only portable DDL; tested on MariaDB locally and MySQL 8 in CI).
