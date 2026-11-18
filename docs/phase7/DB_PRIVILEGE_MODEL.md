# Phase 7 database privilege model

Status: tested on MySQL 8.0.46 (local, binary logging on, `log_bin_trust_function_creators=0`) by `tests/phase7_privilege_test.php`.
**MySQL 8.4 was NOT run locally.** CI runs the same test on 8.0 and 8.4; treat 8.4 as unverified until that job is green.

Why this exists: the cross-table rules of the provenance ledger are enforced by the PHP service layer (`uni_core.php`); the triggers in
`database/phase7_triggers.sql` are optional defence in depth. Grants are the third layer and the only one that survives a bug in PHP.

## Findings (all verified empirically)

1. **TRUNCATE requires the DROP privilege.** An account with SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER on a table is refused
   (error 1142). An account holding only DROP on the table can TRUNCATE it (and cannot DELETE). So "no DELETE" does not prevent TRUNCATE; "no DROP" does.
2. TRUNCATE also fails with error 1701 on a table referenced by a foreign key, even for the migrator. That protects the parent tables (`uni_entity`, `uni_event`,
   `uni_edge`, `uni_release`, `uni_source`, `uni_review`) but NOT leaf tables such as `uni_audit_log`: the migrator (which holds DROP) can empty the audit log. Keep the
   migrator out of application configuration.
3. **Triggers fire for an account without SUPER and without the TRIGGER privilege.** Trigger bodies run with the DEFINER's rights (the account that installed them),
   not the caller's. A raw `UPDATE` of a verified row by the application account is refused by the trigger (SQLSTATE 45000). Tested with triggers installed by the admin
   account (DEFINER `root@localhost`) and by the migrator account.
4. **Installing triggers needs SUPER when binary logging is on.** The non-SUPER migrator (ALL PRIVILEGES on the one database) got **error 1419** here
   (`log_bin=1`, `log_bin_trust_function_creators=0`; MySQL 8.0 default is binary logging on). The installer is documented to treat this as non-fatal, which means
   a host like this silently runs without triggers. Options: install as a SUPER-holding account (the test does `GRANT SUPER ON *.*` to the migrator for the install
   and revokes it afterwards), or set `log_bin_trust_function_creators=1` (NOT tested here, server-wide setting).
5. **DEFINER hazard.** Triggers keep the installing account as DEFINER. When that account is dropped, every INSERT/UPDATE touching a trigger table fails with
   **error 1449** ("The user specified as a definer does not exist"); the service layer returns the clean generic code `error` (no PHP fatal) and the site cannot write
   until the triggers are reinstalled (`phase7_triggers.sql` is idempotent: DROP IF EXISTS + CREATE). Install triggers with a long-lived dedicated migrator account, never
   with a personal or temporary one, and never drop that account without reinstalling.
6. **Without triggers the database does not stop the application account from tampering.** With the recommended grants, a raw `UPDATE uni_event SET title=...` on a verified
   row succeeds when no triggers are installed (the PHP layer is the only guard). Grants still make `uni_review`, `uni_audit_log` and `uni_source` append-only for the
   application, and still forbid DELETE, TRUNCATE, DROP and ALTER, but they cannot express "verified rows are immutable". That is exactly why triggers should be installed
   wherever the host allows it.
7. **"INSERT-only importer" is not enough on its own.** A table-level INSERT grant lets the importer insert rows that are already `state='verified'` (referencing any existing
   review id) when triggers are absent. Fix, tested: a **column-level INSERT grant** that omits `state`, `verified_by`, `verified_at`, `review_id`, `is_current`, `version_no`,
   `supersedes_id`. Those columns then take their defaults (`proposed`, NULL, 1, 1, NULL): imported rows can only be fresh version-1 proposals. The importer can still choose
   `origin` (a column-level grant cannot restrict values), so importer code must set `origin='import'`; the service layer and the CHECK constraints refuse to verify an `import` row.
8. The wider reading of the comment in `phase7_universe.sql` ("SELECT, INSERT, UPDATE on uni_*, only SELECT, INSERT on uni_audit_log and uni_review") also passes the whole service
   test (profile `appw`). The tight profile below is stricter and also passes; prefer it.
9. Foreign-key checks need no REFERENCES or SELECT privilege on the referenced table (the INSERT-only importer inserts rows pointing at `crm_users` and `uni_review`).
10. Error 1419 beats error 1142 for `CREATE TRIGGER` by the application account on a binary-logging server: it is refused either way and no trigger is created.

## Accounts and exact GRANT statements

Replace `uni_db`, the host part and the passwords. Table-level grants are NOT removed when a table is dropped, so re-run the GRANTs after a rollback and re-create.

```sql
-- application account (the PHP service layer, uni_core.php): least privilege
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_entity`  TO 'uni_app'@'%';
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_event`   TO 'uni_app'@'%';
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_edge`    TO 'uni_app'@'%';
GRANT SELECT, INSERT, UPDATE ON `uni_db`.`uni_release` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_source`         TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_entity_source`  TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_event_source`   TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_edge_source`    TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_release_parent` TO 'uni_app'@'%';
GRANT SELECT, INSERT ON `uni_db`.`uni_review`         TO 'uni_app'@'%';   -- append-only
GRANT SELECT, INSERT ON `uni_db`.`uni_audit_log`      TO 'uni_app'@'%';   -- append-only
GRANT SELECT ON `uni_db`.`uni_correction`             TO 'uni_app'@'%';
GRANT SELECT ON `uni_db`.`crm_users`                  TO 'uni_app'@'%';   -- actor lookup; the app can never create or promote users
-- never: DELETE, DROP, ALTER, CREATE, INDEX, TRIGGER, REFERENCES, CREATE VIEW, LOCK TABLES, FILE, SUPER, GRANT OPTION

-- read-only public account (public pages that read the ledger directly; no review, audit or user tables)
GRANT SELECT ON `uni_db`.`uni_entity`         TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_event`          TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_edge`           TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_release`        TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_source`         TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_entity_source`  TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_event_source`   TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_edge_source`    TO 'uni_public'@'%';
GRANT SELECT ON `uni_db`.`uni_release_parent` TO 'uni_public'@'%';

-- importer account: INSERT only, column-limited on the versioned tables (finding 7)
GRANT INSERT ON `uni_db`.`uni_source`         TO 'uni_import'@'%';
GRANT INSERT ON `uni_db`.`uni_entity_source`  TO 'uni_import'@'%';
GRANT INSERT ON `uni_db`.`uni_event_source`   TO 'uni_import'@'%';
GRANT INSERT ON `uni_db`.`uni_edge_source`    TO 'uni_import'@'%';
GRANT INSERT ON `uni_db`.`uni_release_parent` TO 'uni_import'@'%';
GRANT INSERT (`id`, `entity_type`, `slug`, `name`, `summary`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`) ON `uni_db`.`uni_entity` TO 'uni_import'@'%';
GRANT INSERT (`id`, `entity_id`, `event_type`, `event_date`, `date_precision`, `date_kind`, `title`, `summary`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`) ON `uni_db`.`uni_event` TO 'uni_import'@'%';
GRANT INSERT (`id`, `from_entity_id`, `to_entity_id`, `edge_type`, `valid_from`, `valid_to`, `notes`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`) ON `uni_db`.`uni_edge` TO 'uni_import'@'%';
GRANT INSERT (`id`, `entity_id`, `event_id`, `version_label`, `release_date`, `date_precision`, `source_id`, `change_set`, `origin`, `proposed_by`, `proposed_at`, `lineage_key`) ON `uni_db`.`uni_release` TO 'uni_import'@'%';

-- admin / migrator: runs phase7_universe.sql, phase7_triggers.sql, phase7_rollback.sql. Used from a shell or CI job, never from the web app.
GRANT ALL PRIVILEGES ON `uni_db`.* TO 'uni_migrator'@'%';
-- only when binary logging is on and log_bin_trust_function_creators=0 (finding 4): GRANT SUPER ON *.* for the duration of the trigger install, REVOKE afterwards
```

## What the test proves (`tests/phase7_privilege_test.php`, 405 checks locally)

- TRUNCATE/DROP/DELETE matrix on a scratch table (finding 1).
- Application account: TRUNCATE and DELETE refused on every uni_* table; DROP TABLE, ALTER, CREATE INDEX, CREATE TABLE, RENAME, CREATE TRIGGER, DROP TRIGGER, UPDATE of
  `uni_review`/`uni_audit_log`/`uni_source`, writes to `crm_users`, reads of `mysql.user`, SET GLOBAL, INTO OUTFILE, GRANT, CREATE/DROP DATABASE and reads of other tables all refused. `SHOW GRANTS` contains none of DELETE, DROP, ALTER, TRIGGER, REFERENCES, CREATE, INDEX, SUPER, ALL, GRANT OPTION, FILE, EXECUTE.
- Service layer end to end as the application account (propose entity/event/edge/release, attach source, submit, review with sampling, publish, correct entity-version chain for event and release, reject, conflict, staff reads, public reads): without triggers, with triggers installed by the admin account, with triggers installed by the migrator account; for both the tight and the wider profile.
- Public account: public read functions return the same totals as the application account; every write refused; review, audit and user tables unreadable.
- Importer: inserts import-origin rows as `proposed`; cannot SELECT, UPDATE, DELETE, TRUNCATE, write reviews or audit rows, cannot set `state` or verification columns.
- DEFINER hazard and its repair (finding 5).

## NOT tested

- MySQL 8.4 (CI matrix covers it; not run locally), MariaDB 10.11, MySQL 9.x, Percona, Aurora, and any managed host's privilege model (shared hosting often has no CREATE USER, no TRIGGER, no per-table grants).
- `log_bin_trust_function_creators=1` and the `SET_USER_ID` dynamic privilege as alternatives to SUPER (finding 4).
- Replication, `sql_require_primary_key`, `SQL SECURITY` of views, roles (`CREATE ROLE`), password plugins/TLS requirements, `REQUIRE SSL`, host-restricted accounts (the test uses host `%`).
- Column-level SELECT or views to hide unverified rows and proposer ids from a raw-SQL public account: the public account can read unverified rows and `proposed_by` through raw SQL, because grants are table-level. The service functions hide them; use the service layer or views if the public account is ever exposed to anything that runs arbitrary SQL.
- A SQL-injection or compromised-application scenario beyond the grant surface above (an attacker with the application account can still INSERT proposals, UPDATE unverified rows and, without triggers, UPDATE verified rows).
- Accounts or grants on the other application tables (`tools`, `crm_*` besides `crm_users` SELECT); only the Universe surface was modelled.
