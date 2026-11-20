# Phase 8A to 8D: staging deployment readiness and verification plan

Status: **PLANNING ONLY.** Nothing in this document has been executed. No deployment, migration, merge, staging change, production access or Hostinger access has happened or will happen without the owner's explicit approval at the checkpoints in section 12. Written from the repository state at commit `7c4b35f` (branch `feat/aihubpedia-finance-phase8d`).

## 1. Summary and verdict
- **Code:** Phases 8A (Control Center), 8B (Analytics), 8C (Tool Management with category safeguards) and 8D (Finance) are development-complete. Database-free CI is green. Phases 8A, 8B and 8D and the Phase 8C migration and store were also verified on **disposable** MySQL 8.0 (locally) and MySQL 8.4 (GitHub service containers). Nothing has been verified on the real staging server.
- **Verdict:** ready for a **controlled staging deployment** once the prerequisites in section 3 are met. **Not ready for production.** Production must wait for a clean staging acceptance run and a separate approval.
- **The single most dangerous fact:** on `main`, `.github/workflows/deploy.yml` **deploys to the production server on every push to `main`** (`git reset --hard origin/main`). A merge into `main` is therefore a production deployment. Deploy protection (draft PR #8) is not merged. See sections 2 and 11.

## 2. Git branches, pull requests and recommended merge order
All branches form one linear chain; each contains the previous one (verified with `git merge-base --is-ancestor`):

`main (68eed92)` < `seo-performance-v1` < `premium-ux-v3` < `global-growth-phase4` < `content-authority-phase5` < `global-revenue-phase6` < `ai-universe-phase7` < `admin-center-phase8` (8A) < `analytics-phase8b` (8B) < `tool-management-phase8c` (8C) < `finance-phase8d` (8D tip `7c4b35f`).

| PR | Head to base | State |
|---|---|---|
| #8 | `chore/deploy-protection` to `main` | open, draft. **Prerequisite for any merge.** |
| #3, #4 | `seo-performance-v1` to `main`; `global-growth-v2` to `seo-performance-v1` | open, draft |
| #5, #6, #7 | Phase 4 to `premium-ux-v3`; Phase 5 to Phase 4; Phase 6 to Phase 5 | open, draft |
| #9 | Phase 7 to Phase 6 | open, draft |
| none | 8A and 8B branches have **no pull request** yet | to be opened (stacked, drafts) |
| #10, #11 | 8C to 8B; 8D to 8C | open, draft |

`main` has not moved since "Test AI deploy 3". The whole chain is about 97 commits ahead of it, so production currently runs none of Phases 3 to 8.

**Recommended order**
1. **Never merge anything into `main` first.** Staging tests use a checkout of the branch tip, not `main`.
2. After the owner approves, merge **#8 (deploy protection) first, alone**. Its own workflow file removes the push trigger, so the merge does not deploy. Complete its one-time setup (protected `production` environment with required reviewer, secrets moved to environment secrets, key rotation, branch ruleset). Until that setup is done nothing should merge to `main`.
3. After staging acceptance, merge the work to `main` as **one release pull request** from the 8D tip (`release/phase3-8d`, a branch created at that commit), using a merge commit so history is kept. This avoids merging eleven stacked pull requests one by one and retargeting each base. Close the stacked draft PRs as "included in the release pull request" only after the owner confirms.
4. Do **not** squash: reviewers rely on the per-phase commits.
5. Deploy to production only through the approval-gated manual workflow, with the exact commit SHA, after a production backup (section 4) and a separate owner approval.

## 3. Prerequisites (before anything touches staging)
| # | Prerequisite | Owner |
|---|---|---|
| P1 | Owner approves this plan and checkpoint A1 | Owner |
| P2 | **Rotate credentials that remain in Git history** (database, SMTP, admin) per `docs/phase4/MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md`, and rotate any credential ever pasted into chat | Owner and administrator |
| P3 | Confirm the staging host, checkout path (`/var/www/html/AI-PHASE3-TEST` from earlier work), vhost, HTTPS, HTTP basic auth, and that the staging database is `aihub_phase6_test` (MySQL 8.4.x reported earlier). Confirm production is **not** reachable from the staging configuration | Administrator |
| P4 | Staging is **noindex** and not linked from anywhere public (`X-Robots-Tag` or basic auth) | Administrator |
| P5 | A named administrator account with shell access, and a person to approve each checkpoint | Owner |
| P6 | Apache or PHP-FPM environment-variable method known (for `AIHUB_*` settings); PHP 8.3 with `pdo_mysql`, `mbstring`, `curl`, `dom` | Administrator |
| P7 | Enough disk space for a dump and a restore rehearsal (twice the database size) | Administrator |

## 4. Backup and recovery (mandatory before the first migration)
All commands run as the deploy user on the **staging** server, with passwords supplied through mode-600 option files created with `read -rs` (never on a command line, in history or in Git). No secrets are written here.

1. **Safe shell:** `set +o history; umask 077; set -euo pipefail`; `DB=aihub_phase6_test`; `test "$DB" != "<production database name from the administrator>"`.
2. **Save the code state:** `git rev-parse HEAD > ~/.aihub/prev_commit.txt`; confirm the working tree is clean (`git status --porcelain` empty), otherwise stop.
3. **Dump:** `mysqldump --defaults-extra-file=~/.aihub/migrator.cnf --single-transaction --routines --triggers --events --default-character-set=utf8mb4 "$DB" | gzip > "$F.part"`; `gzip -t`; confirm "Dump completed" and that the table count in the dump equals the live table count; only then rename to the final name; `sha256sum` the **final** file and `sha256sum -c` it. Store outside the web root.
4. **Restore rehearsal (required):** restore the dump into a **scratch** database whose name contains `test` (for example `aihub_restore_check_test`), compare table list and a `CHECKSUM TABLE` of the key tables, then drop the scratch database. A backup that has never been restored is not a backup.
5. **File backup:** copy the checkout's untracked configuration (environment files, vhost include) to a mode-600 archive.
6. **Recovery paths:** (a) **code only:** `git checkout --detach "$(cat ~/.aihub/prev_commit.txt)"`; (b) **migration undone:** run the rollback files in reverse order (section 5); (c) **full restore:** restore the dump into a new database, point the staging environment at it, verify, then retire the damaged one. Never restore over the only copy.
7. **Targets:** recovery point is the moment of the dump; recovery time target for staging is under 1 hour. State these to the owner before approving.

## 5. Migration order, privileges and rollback
Idempotent additive files. Each creates tables only; none alters an existing table. **Run order** (earlier items may already exist on staging: step 5.1 finds out):

| # | File | Creates | Needs | Rollback (reverse order) |
|---|---|---|---|---|
| 0 | `database/full_mysql_schema.sql`, `phase2_analytics.sql`, `phase3_business.sql`, `phase4_business.sql`, `install_compare_tables.sql` | core content, analytics and business tables | CREATE | not rolled back by this plan (older phases) |
| 1 | `database/phase6_crm.sql` | `crm_*` tables | CREATE | `phase6_rollback.sql` |
| 2 | `phase7_universe.sql`, `phase7_triggers.sql`, `phase7_facts.sql`, `phase7_facts_triggers.sql` | `uni_*` ledger, facts, triggers | CREATE, TRIGGER | `phase7_facts_rollback.sql`, `phase7_rollback.sql` |
| 3 | `phase8b_analytics.sql` | `adm_traffic_daily`, `adm_traffic_dim_daily`, `adm_tool_daily`, `adm_event_daily`, `adm_rollup_run` | CREATE | `phase8b_rollback.sql` |
| 4 | `phase8c_tools.sql` | `adm_tool_revision`, `adm_tool_feature`, `adm_submission_review` | CREATE | `phase8c_rollback.sql` |
| 5 | `phase8d_finance.sql` | `fin_expense_entry`, `fin_target`, `fin_adjustment` | CREATE | `phase8d_rollback.sql` |

**Rules**
- **5.1 Read-only preflight first** (checkpoint A2): run `php scripts/db_preflight.php --json` against staging with a **read-only** user, and the SELECT-only queries in `docs/staging/READ_ONLY_PREFLIGHT.sql`. Output tells which of steps 0 to 5 are already applied, the real engine and version, the content source, and schema drift.
- Only run files whose tables are missing. Take the backup (section 4) first. After each file, run its verification query and stop at the first surprise.
- **Existing tables must be byte-identical afterwards** (`CHECKSUM TABLE` before and after, as in the disposable verification).
- **Never run `import-json-to-mysql.php` after step 4.** The Phase 8C guard makes it insert-only, but the owner must still decide whether staging needs it at all (item D3).
- **Rollback order is the reverse:** 5, 4, 3, 2, 1. Rolling back a later step does not require rolling back an earlier one. The Phase 7 rollback needs DDL rights that the application user must not have (use the migrator account). Export any real `uni_*`, `crm_*` or `fin_*` data before dropping.
- Database changes are never applied by any workflow and are not undone by a file rollback.

**Least-privilege accounts**
| Account | Purpose | Grants |
|---|---|---|
| migrator | Run the migration files, then locked or used only for approved changes | CREATE, TRIGGER (Phase 7), ALTER/DROP only for rollback windows |
| web app | Runtime | `crm_*` and `uni_*` as in `docs/phase7/DB_PRIVILEGE_MODEL.md`; `page_views`, `events`, `tool_clicks` SELECT, INSERT; `tools` SELECT, INSERT, UPDATE; `categories` SELECT, INSERT, UPDATE, DELETE (empty unprotected labels only); `submissions` SELECT, INSERT, UPDATE; `adm_tool_revision` SELECT, INSERT; `adm_tool_feature` and `adm_submission_review` SELECT, INSERT, UPDATE; `adm_traffic_*`, `adm_tool_daily`, `adm_event_daily`, `adm_rollup_run` SELECT; `fin_*` SELECT, INSERT, UPDATE; no DELETE on `fin_*`, `crm_revenue_entries` or `tools`; no DDL, no TRUNCATE, no DROP |
| rollup (8B) | Cron job only | SELECT on `page_views`, `events`; SELECT, INSERT, UPDATE, DELETE on the five `adm_traffic_*`, `adm_tool_daily`, `adm_event_daily`, `adm_rollup_run` tables; one GRANT per table; password generated, never typed |
| importer (Phase 7) | CLI only | as in the Phase 7 privilege model |
The finance and Phase 8C grants were exercised on the disposable MySQL 8.0 verification (allowed operations worked, writes outside the grants were denied by the server).

## 6. Authentication, authorization and CSRF verification (staging)
- Accounts are created by CLI only: `php scripts/crm_user.php add <name> "<Display Name>" <role>` with the password read from standard input. Create one account per role: owner, sales, viewer, editor, verifier. No defaults, no passwords in chat.
- Required results (full table in `ACCEPTANCE_TEST_SCRIPT.md`): anonymous sees only the sign-in form; wrong password and inactive accounts fail; sign-in limiter (5 per 15 minutes) works; session cookie is `Secure`, `HttpOnly`, `SameSite=Strict` over HTTPS; idle and maximum session timeouts end the session; the role matrix holds (owner everything; sales CRM and advertising; viewer CRM read; editor Universe and tool management without publish, delete, restore, bulk, featured or moderation; verifier Universe; Finance and Analytics owner only) with 403 and an audit row for every denial.
- **CSRF and origin:** every POST without a token, with a wrong token, with another session's token, or with the right token from another origin is rejected (403) and changes nothing; a GET never changes data; other methods return 405.
- Admin pages are `noindex`, sent with no-store headers, and `/robots.txt` disallows `/admin-center.php`.

## 7. Module acceptance (staging)
- **Control Center (8A):** overview tiles come only from records, planned modules show "planned", system health shows the real engine, and no figure is invented.
- **Analytics (8B):** **tracking stays OFF** (`AIHUB_ANALYTICS` unset) unless the owner approves otherwise. Verify honest empty states; the rollup runs once, is idempotent and non-overlapping under `flock`; exports are formula-safe and audited. Enabling tracking is a separate approval and requires a random salt of at least 32 characters and the diagnostic printing `salt=set (strong)`.
- **Tool management (8C):** create, edit, publish, unpublish, trash and restore; unpublished and trashed tools return 404 by direct URL; bulk review then apply is all-or-nothing; category and tag rules and protected categories hold; submission approval creates a draft only; featured placements mirror and expire by date; classified and curated tools show read-only fields; reserved slugs are refused.
- **Finance (8D):** record an expense, a refund and a credit note, then void each; set a target; every CSV export; CRM tables unchanged; legacy payments shown separately and never added; empty states honest; every figure labelled as recorded manually and not bank-verified; VAT shown as not tracked.

## 8. SEO, sitemap, canonical and category regression
Capture a **baseline before deployment** and compare after (read-only GET requests against staging):
1. Fetch `/sitemap.xml` (and sub-sitemaps). Record the URL list and count. For every URL record status, `<title>`, canonical, robots meta, `X-Robots-Tag`, H1 and JSON-LD hash. Run `php tests/seo_test.php <staging url>` and `php scripts/seo_audit.php` (both read-only against a URL).
2. After deployment the same crawl must be **identical** for all existing URLs (509 tools, category pages, subcategories, guides, compare pages): same status, canonical, title, robots and JSON-LD. Differences are defects unless the owner approves them.
3. Category checks: every public category page returns 200; `SEO` redirects to `Marketing`; unknown names return 404; tool breadcrumbs link to a valid category.
4. Staging canonical and sitemap hosts must be correct for staging and the site must be `noindex`; confirm nothing on staging points search engines at itself.
5. `/admin-center.php`, `/crm.php`, finance exports and library files are not indexable and not publicly readable.
6. The 8C `find_tool` guard: a tool set to draft, unpublished or deleted is gone from the sitemap and returns 404.

## 9. Mobile, tablet and desktop testing
Desktop (1280 and 1920 px), tablet (768 px), phone (360 px) in light and dark; Chrome and Firefox on desktop; Safari on iPhone; Chrome on Android; iPad. For each module: navigation, forms, charts, tables, exports, keyboard-only use, visible focus, reduced motion, and screen-reader spot checks (landmarks, labels, chart descriptions, data tables). Record Lighthouse lab results for the home page, a tool page, a category page and the Control Center overview: report measured numbers only. Known item: on phones the Phase 8A sidebar sits above the content (about 960 px).

## 10. Deployment steps (summary, all gated)
1. A1 approval; prerequisites done. 2. Backup and restore rehearsal. 3. A2: read-only preflight; report. 4. A3 approval of exact commit SHA and migration list. 5. `git fetch`, `git checkout --detach <approved SHA>` in the staging checkout (never `main`). 6. Migrations in order with a verification query after each. 7. Create role accounts; set environment settings (tracking off). 8. Run the acceptance script. 9. SEO regression and device checks. 10. A4: owner reviews results and decides go or no-go for production planning.

## 11. Risks
| Risk | Severity | Mitigation |
|---|---|---|
| Merging to `main` deploys to production automatically | **Critical** | Merge deploy protection (#8) first; configure the protected environment; nothing else merges until then |
| Credentials remain in Git history | **High** | Rotate before staging (P2) |
| 97 commits of unreviewed change reaching production in one step | High | Staging acceptance first; single release PR; production backup; manual gated deploy |
| Migration on real data; wrong engine assumptions (MySQL 8.4 versus MariaDB) | High | Read-only preflight; backup and restore rehearsal; stop at first surprise; engine confirmed before running |
| Content source unknown (MySQL or JSON) so 8C could manage a database the site does not read | High | Preflight reports `mysql_tables_available_for_content()` inputs; decision D2 |
| `import-json-to-mysql.php` overwriting managed tools | High | Not run after step 4; insert-only guard exists |
| Rollback needs DDL rights that the app must not have | Medium | Use the migrator account in a rollback window |
| Tracking enabled without a strong salt | Medium | Guarded in code; keep off; diagnostic first |
| Cache or SEO regressions | Medium | Baseline and diff crawl (section 8) |
| Admin exposed publicly | Medium | HTTPS, basic auth in front on staging, IP restriction where possible, `noindex` |
| Disposable tests do not replace staging | Medium | Run the full acceptance script on staging |
| Finance shows empty or approximate data | Low | By design; labelled |
| Founder or administrator availability during the window | Low | Schedule with a named person on call |

## 12. Owner approval checkpoints (each is a stop)
| # | Checkpoint | What you approve | What I will present |
|---|---|---|---|
| A0 | This plan | The approach and order | This document |
| A1 | Prerequisites complete | Starting staging work; credentials rotated; basic auth and noindex confirmed | Checklist with evidence |
| A2 | Read-only preflight | Running the read-only engine, schema and content-source checks on staging | Query list; results report |
| A3 | Backup done, migration list | The exact commit SHA and the exact migration files and order | Backup checksum and restore rehearsal result; diff of what will run |
| A4 | After migrations | Continuing to acceptance tests | Verification query results; unchanged-table checksums |
| A5 | After acceptance | Go or no-go for production planning | Acceptance, SEO diff and device results; defect list |
| A6 | Deploy protection | Merging #8 and configuring the protected environment | Settings checklist |
| A7 | Release pull request | Opening and merging the single release PR | Final diff summary and CI |
| A8 | Production deployment | Production backup, SHA and window | Separate production plan; not part of this document |
| A9 | Analytics tracking | Enabling visitor tracking on any server | Salt and diagnostic evidence |

## 13. Go and no-go criteria for staging acceptance
Go only if: every item in `ACCEPTANCE_TEST_SCRIPT.md` passes or has an owner-accepted exception; no existing table changed; the SEO crawl is identical; no PHP error appears in the logs; role and CSRF checks are all rejected correctly; the restore rehearsal succeeded; and Finance and Tool management create no visible change on public pages for existing tools. Any critical defect is a no-go.

## 14. Decisions needed from the owner
- **D1** Approve the merge strategy (deploy protection first, single release pull request, merge commit).
- **D2** Authorize the future read-only preflight (A2) to establish the real engine and content source.
- **D3** Whether staging needs `import-json-to-mysql.php` at all (staging already has data?).
- **D4** Staging window and who is the named administrator.
- **D5** Whether to open the missing 8A and 8B draft pull requests now, for traceability.
- **D6** Confirm credential rotation is done (P2).
