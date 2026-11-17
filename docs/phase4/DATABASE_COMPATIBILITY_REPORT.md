# Database compatibility report (Phase 3 + Phase 4 SQL vs the existing "aihub" database)

**Method and limits.** Built only from the repository's SQL files, the application code and **synthetic, throw-away databases** (names contain `test`). The live `aihub` database was never contacted: its real schema is **unknown to this work**. Everything below tells you what to check and how, not what your database contains. The read-only tool `scripts/db_preflight.php` tells you for your own copy.

## 1. What the application needs
`database/expected_schema.json` lists every table and column the PHP code reads or writes (derived from all SQL statements in the code); Phase 2/3/4 and compare tables are read from their SQL files. 17 tables:
| Table | Needed for | If missing or incompatible |
|---|---|---|
| `categories`, `tools` (required) | database-backed catalogue | whole catalogue falls back to the JSON files |
| `tool_clicks`, `tool_votes` (required) | outbound click counts, likes/dislikes | writes return **503** ("temporarily unavailable"); reads fall back to JSON counts |
| `posts`, `use_cases`, `site_settings` | blog, use-case pages, configuration | fall back to JSON files; mail/price settings then come from env |
| `submissions`, `contact_messages` | tool submission form, contact form | form shows an error, nothing stored |
| `page_views`, `events` | optional statistics (off unless `AIHUB_ANALYTICS=1`) | nothing recorded |
| `leads`, `subscribers` | B2B inquiries, newsletter | friendly "temporarily unavailable" with email fallback |
| `lead_status_history` and `leads` extension columns (Phase 4) | status history, attribution, notification tracking | app works without them (answers appended to the message; "delivery not tracked yet") |
| `compare_tool_profiles/points/sources` | source-backed comparison data | comparison uses basic catalogue data |

## 2. Findings: the committed SQL does not match the code
| # | Finding | Impact if the live database was built from the committed files | Fix in this branch |
|---|---|---|---|
| 1 | `database/aihub_interactions.sql` and `full_mysql_schema.sql` create `tool_clicks(ip_address, user_agent)` and `tool_votes(ENUM('like','dislike'), ip_address, UNIQUE(tool_slug, ip_address))`. The code writes `visitor_id`, `ip_hash` and `up/down` | every vote or click **write fails**. The only committed migration (`migrate_tool_votes_to_visitor_id.sql`) adds `visitor_id` to votes but not `ip_hash`, does not convert the ENUM, and does not touch `tool_clicks` | The code now detects the old shape and treats interactions as unavailable (503 for writes, JSON for reads) instead of failing; `db_preflight.php --plan` prints the exact `ALTER`/`UPDATE` statements (section 5) |
| 2 | **No production SQL file creates `contact_messages`** (only the test fixture does) though `contact_send.php` inserts into it | contact form cannot save | `--plan` points to the fixture's `CREATE TABLE`; the form reports failure cleanly |
| 3 | `use_cases` needs `icon, description, hero_text, audience, popular_tasks, example_tools, benefits`; the committed file lacks them (they appear only in `tests/setup_test_db.sh`) | use-case pages fall back to JSON | `--plan` prints the `ALTER` |
| 4 | Phase 4 had a bug unrelated to schema: `/redirect.php` threw HTTP 500 when no database was configured | outbound links broke on a first deployment without DB settings | fixed (best effort click counting); regression-tested in "no database" mode |
| 5 | `phase4_business.sql` uses `ALTER TABLE ... ADD COLUMN` (MySQL 8 has no `IF NOT EXISTS`) | running it twice fails | the app tolerates "not applied"; apply once, guided by the preflight verdict `needs_phase4_migration` |
| 6 | Charset/collation of the live tables unknown | utf8mb4 needed for emoji/accents | preflight reports non-utf8mb4 collations |
Phase 2, 3 and 4 files themselves are consistent with the code (a fresh install of all files passes the preflight with 0 findings).

## 3. Scenario results (synthetic databases, automated: `tests/db_compat_test.php`, 252 checks)
Each scenario is created from repository SQL, populated with the JSON catalogue, checked by the preflight, then exercised by a private `php -S` instance (17 pages, outbound redirect, click/vote POST, lead, newsletter, admin lead page, PHP error log).
| Scenario | Preflight | Application behaviour |
|---|---|---|
| S1: only the committed install SQL | **broken**: `tool_clicks`, `tool_votes` incompatible; `leads`, `subscribers`, `events`, `contact_messages`, compare tables missing | all pages 200; redirect works; click/vote → 503; lead form → friendly 503 with email fallback; admin sees "leads table is not available" |
| S2: Phase 1/2 state (votes migrated, old clicks) | broken (clicks/votes incompatible); `page_views`, compare, `contact_messages` ok | same as S1 for interactions; contact form works |
| **S1/S2 after applying the generated plan** (synthetic copy) | required features compatible | votes and clicks work again (HTTP 200); plan applied cleanly |
| S3: Phase 3 applied, no Phase 4 | compatible; `leads` = `needs_phase4_migration`; `lead_status_history` missing | forms work; admin shows "delivery not tracked yet"; status changes work without history |
| S4: everything applied (control) | 0 findings | all features |
| S5: empty database | broken (everything missing) | all pages 200 using the JSON catalogue; writes 503/friendly |
| No database configured | n/a | all pages 200; outbound redirect 302; voting 503; no uncaught exceptions |
Caveat: these prove the code copes with the *shapes* modelled here. Your live database may be in a state no scenario covers; run the preflight on a copy.

## 4. Required changes to the existing "aihub" database (decision list)
Run the read-only preflight on a **restored backup** and apply only what it reports:
1. `tool_clicks` / `tool_votes` incompatible → apply the interaction plan (section 5). Without it votes/clicks stay unavailable (the site still works).
2. `contact_messages` missing → create from the fixture definition.
3. `use_cases` columns missing → add them (or leave: JSON fallback).
4. `leads`, `subscribers`, `events` missing → `database/phase3_business.sql`; `page_views` → `phase2_analytics.sql`; compare tables → `install_compare_tables.sql` (all `CREATE TABLE IF NOT EXISTS`).
5. `leads` missing Phase 4 columns / `lead_status_history` missing → `database/phase4_business.sql` **once**.
6. Never run `import-json-to-mysql.php` or the install scripts against production: they are CLI-only and meant for a fresh database.
Order: backup → preflight → (1)–(4) → Phase 3 → Phase 4 → preflight again → smoke tests. Rollback of Phase 4 columns is in ROLLBACK_PLAN.md.

## 5. Generated plan for the old install shape (text only; produced by `php scripts/db_preflight.php --plan`, verified on synthetic copies)
```sql
ALTER TABLE `tool_clicks` ADD COLUMN `visitor_id` VARCHAR(64) NULL;
ALTER TABLE `tool_clicks` ADD COLUMN `ip_hash` CHAR(64) NULL;
ALTER TABLE tool_votes MODIFY vote_type VARCHAR(10) NOT NULL;
UPDATE tool_votes SET vote_type = 'up' WHERE vote_type = 'like';
UPDATE tool_votes SET vote_type = 'down' WHERE vote_type = 'dislike';
ALTER TABLE `tool_votes` ADD COLUMN `visitor_id` CHAR(32) NULL;
ALTER TABLE `tool_votes` ADD COLUMN `ip_hash` CHAR(64) NULL;
UPDATE tool_votes SET visitor_id = MD5(CONCAT(COALESCE(ip_address, ''), ':', id)) WHERE visitor_id IS NULL OR visitor_id = '';
ALTER TABLE tool_votes DROP INDEX uniq_tool_vote_ip, ADD UNIQUE KEY uniq_tool_vote_visitor (tool_slug, visitor_id);
```
The legacy `ip_address`/`user_agent` columns are kept (they contain raw IP addresses: decide separately whether to null them for privacy). The plan only lists the statements the observed gaps need; review each before running, on a copy, with a backup.

## 6. How to validate the schema safely before deployment
1. Restore the latest production backup into a **separate database on a machine you control** (not the live server).
2. `AIHUB_DB_HOST=… AIHUB_DB_NAME=<copy> AIHUB_DB_USER=<read-only user> AIHUB_DB_PASS=… php scripts/db_preflight.php --plan` (it executes only `SELECT` on `information_schema`; it never reads application rows).
3. Apply the reviewed statements to the copy; re-run until "Required features broken: 0".
4. Point a staging instance at the copy and follow STAGING_DEPLOYMENT_INSTRUCTIONS.md.
5. Only then decide on production with its own backup and maintenance window. The repository's tests (`tests/db_compat_test.php`) can be run locally at any time; they create and drop only databases whose names contain `test`.
