# Phase 8C final acceptance checklist

Legend: DONE = verified by database-free checks in routine CI; OPEN = needs owner approval or verification that has not been run. Nothing in the OPEN items has been executed. No production, staging or Hostinger access was used.

## A. Development and routine CI (database-free)
- [x] DONE M1-M7 implemented per `TOOL_MANAGEMENT_PLAN.md`; M1-M3 preserved (commit `942be0d`, CI #93 green).
- [x] DONE Logic tests 115 + 173, synthetic screens 312 (axe, overflow, 44 px), security inventory 459, security regression 147.
- [x] DONE Static guards: no hard delete of tools, no ALTER or DROP, no `tools.json` writes, no email, payment or network calls, no AI Universe access, six tables only in the MySQL store.
- [x] DONE Category and overlay consistency safeguards (`CATEGORY_SAFEGUARDS_REPORT.md`): public-taxonomy categories, read-only classified and curated fields, protected categories, reserved slugs, 509-tool regression (54 checks). Database and staging verification of it remains under B and E.
- [ ] OPEN Final CI run on the final commit and review of draft PR #10 by the owner.

## B. Database verification (all OPEN; needs approval to run on staging only)
- [ ] MySQL store SQL: `all`, `get`, `create`, `update`, `bulkUpdate`, categories, submissions, placements, mirror; correct against the real schema (`category_id` join, JSON columns, `featured_until` NULL handling).
- [ ] Concurrency: two simultaneous edits or bulk actions, exactly one wins through the `(tool_slug, revision_no)` key; `FOR UPDATE` on submission and placement decisions; no deadlock.
- [ ] Transactions roll back completely on failure (bulk, approval creating a tool plus a review row, placement plus mirror).
- [ ] Character set and collation (utf8mb4) for names, tags and notes; case-insensitive uniqueness for categories.
- [ ] Performance: loading about 509 tools plus revisions for the list; list and detail under load.
- [ ] The manual suite `ci-database.yml` (MySQL 8.0 and 8.4) run once on the final commit, only on the owner's request.
- [ ] Existing public pages unchanged when `categories`, `tools` and the new tables exist (content switch from JSON to MySQL).

## C. Migration (OPEN; executed only by the owner's administrator after approval)
- [ ] `database/phase8c_tools.sql` reviewed: 3 `CREATE TABLE IF NOT EXISTS`, no ALTER (checked by test).
- [ ] Pre-checks on staging: database is `aihub_phase6_test`; `tools`, `categories`, `submissions` exist (the module requires them, and shows "storage not installed" otherwise); working tree clean and previous commit saved.
- [ ] Backup with checksum and read check before the migration (corrected pack from the 8B gate).
- [ ] Run, then verify: three new tables exist, existing table checksums and row counts unchanged.
- [ ] Rollback rehearsal on staging with `database/phase8c_rollback.sql` (drops only the three tables); site still works afterwards.
- [ ] JSON import guard confirmed: re-running `import-json-to-mysql.php` after migration adds missing records only and never overwrites managed ones.

## D. Security (OPEN until exercised on staging)
- [ ] Real sign-in, session and CSRF flow with real accounts for owner, editor, sales, viewer and verifier: editors blocked from every owner action over HTTP; cross-origin and missing-token POSTs rejected.
- [ ] Least-privilege database user for the web app (no schema rights). Needed on the new tables and existing ones: `tools` SELECT, INSERT, UPDATE; `categories` SELECT, INSERT, UPDATE, DELETE; `submissions` SELECT, UPDATE; `adm_tool_revision` SELECT, INSERT; `adm_tool_feature` SELECT, INSERT, UPDATE; `adm_submission_review` SELECT, INSERT, UPDATE. Confirm anything outside this is denied.
- [ ] Audit rows appear in `crm_audit_log` for every change and denial; no secrets or personal data in logs.
- [ ] Control Center stays `noindex` and unlinked from public pages; direct execution of library files returns 404.
- [ ] Hostile input through the real forms (markup, long text, unsafe URLs) stored safely and rendered escaped on public pages.
- [ ] No outbound email, payment or network call occurs from the module (static test is done; confirm in staging logs).

## E. SEO and editorial integrity (OPEN)
- [ ] Before and after comparison on staging of every public tool page, category page, sitemap and canonical: identical for the existing 509 tools.
- [ ] A new tool created in the module (public category enforced) appears on exactly one valid category page, with a working breadcrumb and a sensible thin-content treatment.
- [ ] On staging, edit a classified and a curated tool through the module (name only changes) and confirm their public pages are byte-identical apart from the name; confirm the category and curated fields shown to visitors do not change.
- [ ] On staging, confirm the MySQL store creates the missing database category row when the first tool uses a public category that has no row.
- [ ] Unpublish, trash and restore remove and return tool pages correctly (404 for unpublished by direct URL, `find_tool` guard).
- [ ] Curated and verified profiles unchanged and their review labels still shown; AI Universe pages and verification flow unchanged.
- [ ] Featured placement shows as sponsored, starts and ends on the right dates, and does not change ranking.

## F. Deployment prerequisites (OPEN)
- [ ] D1: administrator confirms whether production serves content from MySQL or JSON (not checked by the development team).
- [ ] Owner's written approvals, separately: merge of PR #10, staging deployment, migration, and later production.
- [ ] Staging functional test script (owner and editor): create, edit, publish, unpublish, trash, restore, bulk review and apply, tag and category actions, moderation (approve, reject, needs-info), featured add and cancel, stale-edit message.
- [ ] Monitoring and rollback plan agreed: revert checkout, rollback SQL, backup restore path.
- [ ] Known limits accepted: future-dated featured placements not included; selection per page; 100-tool bulk limit; restored never-published seed tools return as drafts; classification of existing tools changed only through editorial scripts.
- [ ] Analytics tracking, Phase 8B deployment items and Phase 8H remain separate and unapproved.
