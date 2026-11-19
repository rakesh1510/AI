# Phase 7 security model and database privileges

This consolidates and extends `docs/phase7/DB_PRIVILEGE_MODEL.md` (kept as the detailed record of the privilege findings) and adds the importer account, the threat model and the deployment checklist.
Everything stated as tested was run by `tests/phase7_privilege_test.php` (404 checks), `tests/phase7_service_test.php` (731) and `tests/phase7_import_test.php` (235) on a local
**MariaDB 10.11.14**, binary logging off. The privilege model findings in `DB_PRIVILEGE_MODEL.md` were recorded on MySQL 8.0.46 (binary logging on); the same privilege test also passes here on MariaDB. See "NOT tested".

## Threat model

| threat | defence | layer |
|---|---|---|
| A web visitor reads unverified or reviewer data | public reads filter `state='verified' AND is_current=1` for the row and its entities; no proposer/reviewer/origin columns selected; public DB account is SELECT only | service, grants |
| A visitor or the shared admin password session writes | writes need a real active `crm_users` row with role editor/verifier, re-read per call; the admin session has no user id | service |
| An editor verifies their own work | maker != checker: service check, CHECK `chk_*_maker_checker`, trigger | service, DB |
| A bulk import slips unverified claims into the public ledger | importer writes only `proposed`/`import`; the service refuses to submit or accept `import` rows; CHECK `chk_*_import_not_verified`; the importer account cannot write the verification columns | service, DB, grants |
| A compromised app or importer account tampers with history | no DELETE/DROP/ALTER/TRUNCATE; review and audit tables append-only by grant; verified rows immutable by trigger (only if installed) | grants, triggers |
| Hostile input file (huge, malformed, hostile strings, traversal, symlink) | strict parser and limits, no network, safe path handling, prepared statements | importer |
| Credential exposure | no credentials in code, arguments or output; dedicated accounts per role; importer takes its password from the environment | importer, ops |
| An operator destroys evidence | the migrator (holds DROP) is never in application config; backups (below) | ops |

Out of scope: a compromised database administrator; SQL injection outside the service layer; denial of service by an editor with a valid account; the correctness of imported claims (that is what human verification is for).

## Roles (`crm_users.role`)

`editor`: propose, attach sources, submit, correct (`uni_propose`). Runs the importer. `verifier`: review only (`uni_review`); the owner is deliberately **not** a verifier. `owner`: can propose, cannot verify.
`sales`, `viewer`: no Universe access. There are no default users; create them from the shell with `scripts/crm_user.php`.

## Database accounts and exact GRANT statements

Replace `uni_db`, the host part and the passwords. Table and column grants survive `DROP TABLE`: re-issue them after a rollback/re-create. The application, public and migrator statements are tested in `phase7_privilege_test.php`; the
importer statements are tested in `phase7_import_test.php`.

```sql
-- application account (PHP service layer, uni_core.php; the web app and the editor/verifier UI)
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_entity`  TO 'uni_app'@'%';
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_event`   TO 'uni_app'@'%';
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_edge`    TO 'uni_app'@'%';
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_release` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_source` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_entity_source` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_event_source` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_edge_source` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_release_parent` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_review`    TO 'uni_app'@'%';    -- append-only
GRANT SELECT, INSERT ON `uni_db`.`uni_audit_log` TO 'uni_app'@'%';    -- append-only
GRANT SELECT ON `uni_db`.`uni_correction` TO 'uni_app'@'%';
GRANT SELECT ON `uni_db`.`crm_users`      TO 'uni_app'@'%';           -- actor lookup
-- never: DELETE, DROP, ALTER, CREATE, INDEX, TRIGGER, REFERENCES, CREATE VIEW, LOCK TABLES, FILE, SUPER, GRANT OPTION

-- public read-only account (only for code that reads the ledger directly; one GRANT per table)
GRANT SELECT ON `uni_db`.`uni_entity` TO 'uni_public'@'%';   -- repeat for uni_event, uni_edge, uni_release, uni_source, uni_entity_source, uni_event_source, uni_edge_source, uni_release_parent

-- importer account (scripts/uni_import.php via AIHUB_IMPORT_DB_USER / AIHUB_IMPORT_DB_PASS) -- see the section below for why each line is needed
GRANT INSERT (`id`, `entity_type`, `slug`, `name`, `summary`, `state`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`, `version_no`, `supersedes_id`, `is_current`) ON `uni_db`.`uni_entity` TO 'uni_import'@'%';
GRANT INSERT (`id`, `entity_id`, `event_type`, `event_date`, `date_precision`, `date_kind`, `title`, `summary`, `state`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`, `version_no`, `supersedes_id`, `is_current`) ON `uni_db`.`uni_event` TO 'uni_import'@'%';
GRANT INSERT (`id`, `from_entity_id`, `to_entity_id`, `edge_type`, `valid_from`, `valid_to`, `notes`, `state`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`, `version_no`, `supersedes_id`, `is_current`) ON `uni_db`.`uni_edge` TO 'uni_import'@'%';
-- Deliberately ABSENT: verified_by, verified_at, review_id (the generated columns need no grant).
GRANT SELECT ON `uni_db`.`uni_entity`    TO 'uni_import'@'%';   GRANT SELECT ON `uni_db`.`uni_event` TO 'uni_import'@'%';   GRANT SELECT ON `uni_db`.`uni_edge` TO 'uni_import'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_audit_log` TO 'uni_import'@'%';   -- SELECT: idempotency keys; INSERT: audit rows
GRANT INSERT ON `uni_db`.`uni_source`        TO 'uni_import'@'%';
GRANT INSERT ON `uni_db`.`uni_entity_source` TO 'uni_import'@'%';  GRANT INSERT ON `uni_db`.`uni_event_source` TO 'uni_import'@'%';  GRANT INSERT ON `uni_db`.`uni_edge_source` TO 'uni_import'@'%';
GRANT SELECT (`id`, `username`, `display_name`, `role`, `active`) ON `uni_db`.`crm_users` TO 'uni_import'@'%';   -- never password_hash

-- migrator (runs phase7_universe.sql, phase7_triggers.sql, phase7_rollback.sql from a shell or CI job; never in web or importer configuration)
GRANT ALL PRIVILEGES ON `uni_db`.* TO 'uni_migrator'@'%';
-- only when binary logging is on and log_bin_trust_function_creators=0: GRANT SUPER ON *.* for the trigger install, REVOKE afterwards (see below)
```

### Importer account: exactly what is needed, and what an INSERT-only mode cannot do

Earlier documentation recommended a table-level or column-limited **INSERT-only** importer. Wiring a real importer through the service layer shows what that means in practice:

* **SELECT is needed** on `crm_users` (columns above), `uni_entity`/`uni_event`/`uni_edge` (slug and duplicate checks, `uni_entity_live`) and `uni_audit_log` (idempotency keys). A pure INSERT-only account cannot run the service functions or detect duplicates.
* **INSERT columns include `state`, `version_no`, `supersedes_id`, `is_current`** because `uni_insert()` writes them explicitly (as `proposed`, 1, NULL, 1). Omitting them (the tightest profile in the privilege test, finding 7) would work for raw SQL but makes the service functions fail, and changing `uni_core.php` for it was out of scope.
  What stops a verified row instead is that the account has **no grant on `verified_by`, `verified_at`, `review_id`**: the CHECK constraints `chk_*_verified_fields` (verified needs all three) and `chk_*_import_not_verified` then make any verified insert fail. Tested: the account cannot name `verified_by` (error 1143) and a verified row without it is refused by the CHECK, for origin `human` and `import`, with and without triggers.
  This relies on **CHECK constraints being enforced** (MySQL 8.0.16+, MariaDB 10.2.1+). On older servers CHECKs parse but are ignored: do not use this importer there.
* **No UPDATE is needed.** The importer links sources itself instead of calling `uni_attach_source` (which takes `SELECT ... FOR UPDATE`, requiring UPDATE). Tested: under this account `uni_attach_source` and therefore `uni_submit_for_review`, `uni_review` and `uni_correct` fail. That is intended: submit, attach to existing rows, review and correct are human/editor-UI actions run by the application account.
* **Residual risk (cannot be expressed in grants):** a compromised importer account can still insert *proposed* rows with `origin='human'`, or `is_current=0` / a `version_no` > 1 / a `supersedes_id`, because a column grant cannot restrict values. Such rows are still only proposals; a human verifier must still accept them and the service layer's maker-checker and source rules apply. The code always writes `origin='import'`.
* The account has no access to reviews, releases, corrections, or other tables, and cannot DELETE, TRUNCATE, ALTER or write `crm_users`.

## TRUNCATE needs DROP, not DELETE

On MySQL (tested 8.0) an account with every table right except DROP cannot TRUNCATE (error 1142); an account with only DROP can. "No DELETE" does not prevent TRUNCATE: **withhold DROP**. TRUNCATE also fails with 1701 on tables referenced by a foreign key, even for the migrator, which protects the parent tables but **not** leaf tables such as `uni_audit_log`: the migrator can empty the audit log. Keep the migrator out of every application, importer and cron configuration.

## Triggers: requirements and implications

Triggers (`database/phase7_triggers.sql`) are optional defence in depth; `uni_core.php` enforces every rule without them (tested in both modes by all Phase 7 tests, including this importer test).
They add: verified rows immutable, append-only review/audit tables, reviewer role and review-target checks, parent date rules, endpoint verification of edges.

* **Privilege to create them:** TRIGGER privilege, and **SUPER when binary logging is on and `log_bin_trust_function_creators=0`** (MySQL error 1419; MySQL 8.0 default is binary logging on). A non-SUPER migrator silently gets no triggers if the installer treats 1419 as non-fatal. Options: install as a SUPER-holding account (grant SUPER for the install only), or set `log_bin_trust_function_creators=1` (server-wide; not tested). The same server variable on MariaDB is also `log_bin_trust_function_creators`; MariaDB here had binary logging off.
* **Triggers fire for accounts without SUPER or TRIGGER** (they run with the DEFINER's rights). The importer test verifies imports and the human verification flow with triggers installed by the admin account.
* **DEFINER hazard:** triggers keep the installing account as DEFINER. If that account is dropped, every write to a trigger table fails with error 1449 and the service returns the generic code `error` until `phase7_triggers.sql` (idempotent) is re-run. Install with a long-lived dedicated migrator account, never a personal one.
* **Edge endpoints:** the edge trigger requires the endpoint *rows* to be verified, while the service checks the entity *lineage*. A defect found while testing the import flow: correcting a verified edge after one of its entities had a newer verified version failed with `db_rule` in trigger mode, because the new edge version still pointed at the superseded entity row. Fixed in `uni_core.php` (`uni_correct` re-points edge endpoints at the live entity rows); regression test in `tests/phase7_import_test.php`. The remaining ordering case (an edge *proposed* before one of its entities is superseded and *reviewed* afterwards) is also fixed: at verification time `uni_review` verifies the edge against the LIVE entity rows of both lineages, re-points its endpoints to them (refusing with `edge_exists` if that would duplicate a current edge), then verifies; regression test in `tests/phase7_service_test.php` (both trigger modes). No table or data definition changed.

## What the database enforces, and what only the service enforces

| rule | CHECK (always, 8.0.16+/10.2.1+) | trigger (optional) | service only |
|---|---|---|---|
| state and origin vocabularies; verified needs verifier, time and review; open rows carry no verifier | yes | | yes |
| import rows never verified; maker != checker; date precision; no self edge/parent; non-blank quotes | yes | partly | yes |
| at least one valid source before verification; reviewer holds verifier role; review matches target | | yes | yes |
| verified rows immutable except becoming superseded by a verified successor; sources of verified rows frozen | | yes | yes |
| review and audit tables append-only | grants (no UPDATE/DELETE) | yes | |
| parent release strictly earlier, no cycles; second-reviewer sampling; duplicate flags; origin of a correction | | partly | yes |
| every actor is a real, active user with the right role, re-read per call | | | yes |
| importer writes origin `import` | | | yes (importer code) |

Without triggers the database does **not** stop the application account from updating a verified row. Install triggers wherever the host allows.

## Backup, restore and rollback

* Take a full logical backup (`mysqldump --single-transaction --routines --triggers`) before applying `phase7_universe.sql`, before installing triggers, and before every large `--apply`. The importer is additive and idempotent, but the audit log and the ledger are the product: they are not regenerable.
* Restore into a scratch database first and run `php tests/phase7_schema_test.php`-style checks (counts, `SELECT COUNT(*) ... WHERE state='verified' AND origin='import'` must be 0, orphaned links 0). Restore **triggers and grants** too: `mysqldump` includes triggers but not users; re-run the GRANT statements.
* `phase7_rollback.sql` is manual and destructive (drops the ledger). Never run it against a database holding verified data without a backup. Re-issue grants after it.
* To undo a bad import: nothing is deleted. Reject the imported rows through the review flow, or restore the backup taken before `--apply`. The audit log rows `import_run` (file SHA-256, counts) identify the batch.

## Deployment checklist

1. Backup; create the migrator account; apply `phase6_crm.sql` then `phase7_universe.sql`; install triggers as a long-lived account (SUPER or `log_bin_trust_function_creators` as discussed); confirm `SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_NAME LIKE 'trg_uni_%'`.
2. Create `uni_app`, `uni_public`, `uni_import` with the statements above; `SHOW GRANTS` must contain none of DELETE, DROP, ALTER, TRIGGER, SUPER, GRANT OPTION (the importer additionally nothing on `verified_by`, `verified_at`, `review_id`, `uni_review`).
3. Put credentials in environment/secret storage, not in files in the web root. The web app uses `uni_app`; the importer uses `AIHUB_IMPORT_DB_USER/PASS`; the migrator is used from a shell only.
4. Create named users with `scripts/crm_user.php` (editor for the importer, at least two verifiers). No shared or default accounts.
5. Dry-run every import first, read the report, then `--apply`; keep the input file and its SHA-256 (it is in the audit log).
6. Confirm the web server cannot execute `scripts/` (it returns 404 even if it could) and that `AIHUB_SECURITY_LOG` is not world-readable.
7. After every deployment run the Phase 7 tests against a scratch database, never against production.

## NOT tested

* **MySQL 8.0 and 8.4 for the importer**, and MySQL 8.4 for everything: the local server in this environment is MariaDB 10.11.14. CI runs the Phase 7 tests on MySQL; the importer test is added to the same job but its result is unknown until that job is green.
* MariaDB behaviour of trigger installation under binary logging (log_bin was OFF), MariaDB 11, MySQL 9.x, Percona, Aurora and any managed host's privilege model (shared hosting often has no CREATE USER, TRIGGER or per-column grants).
* `log_bin_trust_function_creators=1` and the `SET_USER_ID` privilege as alternatives to SUPER; replication; `sql_require_primary_key`; roles (`CREATE ROLE`); `REQUIRE SSL`; host-restricted accounts (the tests use host `%`).
* Column-level SELECT or views to hide unverified rows from a raw-SQL public account: the public account can read unverified rows through raw SQL because grants are table-level.
* An importer run on servers without enforced CHECK constraints (MySQL before 8.0.16); a very large file near the limits against a large production-size ledger (performance); concurrent importers on separate database servers; filesystem races beyond the `lstat`/`fstat` identity check; Windows paths.
* The security log content under an unexpected database error (see IMPORTER.md known limits).
