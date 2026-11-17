# Rollback plan

Everything is on branch `feat/aihubpedia-global-growth-phase4`, which starts from Phase 3 (`50c6348`). Nothing was deployed or merged. Each commit is a self-contained group and can be reverted independently.

| Commit group | Revert with | Data/config effects to undo |
|---|---|---|
| P0 security | `git revert <sha>` | Re-create `config.local.php`/env only if reverting `db.php` (the old code needed the hard-coded password: do **not** restore it; keep env credentials). Admin returns to the insecure `?pass=` flow if reverted: strongly discouraged |
| P1 discovery/evidence | `git revert <sha>` | none (read-only features, new data files `capabilities.json`, `search_synonyms.json`, `solutions.json`) |
| P2 B2B | `git revert <sha>` | Migration below |
| P3/P5 wizard, change log, newsletter archive | `git revert <sha>` | none (no tables) |
| P4 SEO/GEO | `git revert <sha>` | regenerate `sitemap.xml`/`llms.txt` with `php scripts/build-seo-files.php` |

## Database migration (`database/phase4_business.sql`)
Additive only. To roll back after it was applied (take a backup first):
```sql
ALTER TABLE leads
  DROP COLUMN package_interest, DROP COLUMN campaign_goal, DROP COLUMN attr_source, DROP COLUMN attr_medium,
  DROP COLUMN attr_campaign, DROP COLUMN ref_tool, DROP COLUMN status_changed_at, DROP COLUMN is_synthetic,
  DROP KEY idx_leads_status;
DROP TABLE IF EXISTS lead_status_history;
```
Rolling the **code** back first is safe: Phase 3 code ignores the extra columns. Rolling the **database** back first is also safe: the Phase 4 code detects missing columns and keeps working (answers go into the message text; status history is skipped).

## Feature switches without a revert
- Admin lead page: unset `AIHUB_ADMIN_PASS_HASH` → admin pages show "disabled".
- Analytics/events: unset `AIHUB_ANALYTICS` (default off).
- Remove a problem page from indexing: delete its entry in `data/solutions.json`; remove the wizard: delete `recommend.php` link in `layout_top.php`.
- Hide the two inquiry-only packages: delete them from `data/sponsorship_packages.json`.
- Trusted-proxy handling: unset `AIHUB_TRUSTED_PROXIES` to ignore all forwarded headers (safe default).

## Verification after a rollback
`php tests/seo_test.php <url> --full-sitemap`, the browser regression suites and `php tests/security_http_test.php` (on staging only) must pass for the version you rolled back to; the Phase 4 tests will (correctly) fail on pre-Phase 4 code.

## Things that cannot be rolled back by code
Emails already sent, leads already collected, search-engine indexing of URLs that were live, and any secrets that were rotated (rotation is one-way and intended).
