# Phase 6 release readiness — QA-001 + QA-002

- **Author:** qa-testing (independent verifier). **To:** development. **Copy:** ai-coo.
- **Date:** 2026-11-18
- **Build under test:** `feat/aihubpedia-global-revenue-phase6` @ `452e5ea` (draft PR rakesh1510/AI#7), checked out as a detached worktree at `/home/user/qa-452e5ea`. No application code, test, SQL, config or CI file was changed.
- **Environment:** Ubuntu 24.04 container, PHP 8.3.6 CLI and built-in server (`tests/router.php`), **MariaDB 10.11.14** (local, throw-away; databases `aihub_test`, `aihub_p6mig_test`, `aihub_compat_*`, `qa_p6indep_test`; every name contains `test`), `tests/smtp_sink.py` mail sink on 127.0.0.1:2525, Playwright 1.63 with preinstalled Chromium, axe-core 4.10.2 (installed into a scratch folder, not the repo). Environment variables copied from `.github/workflows/ci.yml`. Synthetic data only.

## Verdict: **not ready**

This is not a release approval in any case; only the CEO approves merges and deployment.

The Phase 6 functionality works on MariaDB. Every suite I ran passed (4,542 checks, 0 failed), and the manual authorization checks found no hole. It is **not ready** for three reasons:

1. **GitHub CI is red on 452e5ea** (QA-BUG-001). `tests/phase6_migration_test.php` fails on MySQL 8.0. Because of this, CI steps 20–38 have **never run on MySQL 8** for this commit. Those steps cover the HTTP security tests, the CRM HTTP tests, all browser tests and the axe audit. The defect is in the test harness, not in the migration, but the release gate is still failing.
2. **QA-BUG-002 (medium):** the CRM merges unrelated inquirers into one company record. This happens when they share a webmail domain that is missing from the free-mail list (e.g. `web.de`), or when someone types another company's website into a public form.
3. I could not verify MySQL 8, Apache or the live schema (see "Not tested").

**What moves it to "ready for staging review":** CI green on MySQL 8 for the full workflow (all 38 steps), and QA-BUG-002 either fixed or explicitly accepted by the CEO.

## Answer to ai-coo: the CI failure in `phase6_migration_test.php`

| Question | Answer |
|---|---|
| Reproduces on local MariaDB 10.11? | **No.** `php tests/phase6_migration_test.php` → `PASSED: 33  FAILED: 0`. It covers apply ×2, the relationships, the 20,000-lead scale (filter 5 ms, search 52 ms, dashboard 34 ms, dedupe 3 ms) and rollback. |
| Test harness defect or migration defect? | **Test harness defect** (both failures). There is no evidence of a migration defect. |

- **Failures 1 and 2: "applies without errors" and "idempotent".** `tests/phase6_migration_test.php:17` (the `$file` helper) runs `mysql -p<password> … 2>&1`, so stderr is captured with stdout. The MySQL 8 client always writes `mysql: [Warning] Using a password on the command line interface can be insecure.` to stderr. The assertions at `:46` and `:49` then require `trim($out) === ''`, so they fail.
  - The MariaDB client prints no such warning, which is why the test passes locally.
  - In the CI log (job of run 44278605993), the only output from the first apply is that warning. No SQL error appears.
  - The same pattern recurs at `:78` (rollback) and `:80` (re-apply). Those lines would fail the same way once `:65` is fixed.
  - Note that the baseline build at `:21` already tolerates the warning, because it only fails on the string `ERROR`.
- **Failure 3: fatal `1137 Can't reopen table: 'a'`.** `tests/phase6_migration_test.php:65` builds `nums` with `FROM d10 a, d10 b, d10 c, d10 e, d10 f`, where `d10` is a `CREATE TEMPORARY TABLE`. MySQL cannot reference one TEMPORARY table more than once in a query (ER_CANT_REOPEN_TABLE). MariaDB ≥ 10.2 can. I confirmed the MariaDB behaviour locally: a self-join of a temporary table returns 4 rows.
- **Consequence:** on MySQL 8 the relationship (FK RESTRICT/CASCADE/SET NULL), scale and rollback assertions have never run, and every later CI step was skipped. The other DB suites did pass on MySQL 8 in CI: crm_logic 131/0 and db_compat 252/0.
- I did not fix anything; the implementation session owns the fix.

## Results by priority

"Verified" means I exercised the behaviour myself on this build: by running the suites against a real database and server, and/or by hand with curl and SQL. Documentation was not counted as evidence. All evidence is from MariaDB and Chromium on Linux.

| # | Priority | Status | Evidence |
|---|---|---|---|
| 1 | B2B advertising & sponsorship | **Verified** (local) | Manual: an advertiser inquiry posted to `/inquiry.php` gave a 303, and created a `crm_leads` row (kind `advertising`) plus a `crm_campaigns` row with status `inquiry`. `tool.php?id=otter-ai` shows the "Sponsored" label and `rel="sponsored"`. The advertise page states that sponsorship never affects facts. Campaign approval, disclosure and safe-URL gates: `crm_logic_test` "Advertising campaigns" section (131/0). Sales `campaign_move`/viewer writes are refused by role (code: `crm_growth.php` uses `crm_deny(...,'approve')`). |
| 2 | Internal CRM & pipeline | **Verified, with 1 defect** (QA-BUG-002) | `crm_logic_test` 131/0 and `crm_http_test` 106/0. Manual curl checks (section below): anonymous, viewer, sales and owner matrix; CSRF; origin; GET inertness; login rate limit; cookie flags; zero-user gate; stored-XSS escaping. Owner qualify → convert produced opportunity #2 (EUR 1,200). |
| 3 | TrustNova lead generation | **Verified** (local) | All 10 `services.php?s=<slug>` pages return 200; an unknown slug returns 404. A guided project inquiry gives a 303 to `inquiry.php?sent=dev_project` and the lead is mirrored into `crm_leads` (kind `development`). Missing, bogus or too-fast token → 422. Rate limit: the 6th valid submission within an hour → 429. The page says "We have not published client case studies or references", so there are no invented clients. |
| 4 | Partnership management | **Verified** (local) | Manual sequence: `partner-apply.php` → partner `applied`/`hidden` (not on `/partners.php`). Publish before approval → `partner_not_approved`. Viewer move → `forbidden`. Sales `applied→in_review` → ok. Sales `in_review→approved` → `forbidden`. Owner approve → ok. Publish with an `http://` evidence URL → `evidence_url_required`. With `https://` → listed and visible on `/partners.php`. Unpublish → no longer listed. |
| 5 | Proposals & revenue | **Verified** (local) | Proposal: 2 × 1,000.00 EUR, −10 % discount, +19 % tax → stored `subtotal_minor=200000`, `total_minor=214200`; hand calculation agrees (EUR 2,142.00). Sales can submit for approval but approve → `forbidden`. Owner `approved→accepted` → contract → confirm worked. Revenue: "received" before "invoiced" → `received_exceeds_invoiced`; future date → `date_invalid`; negative amount → `amount_invalid`; sales recording revenue → `forbidden`. Money columns are integers (migration test). |
| 6 | Business analytics dashboard | **Partially verified** | The dashboard rendered figures that match the DB (7 inquiries split 1/1/5, 1 open opportunity, pipeline 1,200) and states that forecast is not revenue and that currencies are never mixed. At 20,000 leads and 5,000 opportunities the counts were correct and it rendered in 34 ms. I did **not** hand-check each of the six revenue measures, or recognition across months, beyond the automated tests. |
| 7 | Ethical business development (drafts only) | **Verified** (local) | A draft without an opt-out line → `optout_line_required`. Setting a draft to `sent` → `status_invalid` (no such state exists). Viewer `outreach_draft` → `forbidden`. **0 e-mails** reached the sink from any CRM action (sink `To:` count was unchanged before and after the CRM workflow). Static grep of `crm*.php`: the only mail call is `crm_intake.php:129` `lead_notify_owner()`, the existing owner notification for public form submissions, and every message went to the owner address `inbox@example.test`. |
| 8 | UX, conversion & quality | **Partially verified** | Passed: `browser_phase6_test.js` 125/0 (desktop, mobile, no-JS, CRM keyboard path, axe on the CRM views), `browser_ux_test.js` 196/0, `browser_phase4_test.js` 100/0, `browser_test.js` 135/0. `axe_audit.js --strict` found 0 violations on 21 pages, including `/services.php` and `/partners.php`. `seo_test` 3186/0. The SEO audit found 0 non-200, 0 missing title/canonical/H1 on 630 URLs. Chromium only; no Firefox, WebKit, real device or screen-reader testing. |
| – | Database integration | **Partially verified** | On MariaDB: `phase6_crm.sql` creates exactly 18 `crm_*` tables and contains no ALTER, DROP or INSERT. My own run applied it twice, ran the rollback, ran the rollback again and re-applied; the details are under "Migration and rollback". MySQL 8 is unverified (QA-BUG-001). `live_schema_compat_test.php` was not run because no CEO schema was supplied. |

## Test totals (my runs on 452e5ea)

| Suite | Command (from the worktree, CI environment variables) | Result |
|---|---|---|
| Test DB build | `./tests/setup_test_db.sh` | ok (509 tools imported) |
| CRM logic | `php tests/crm_logic_test.php` | 131 / 0 |
| DB compatibility | `php tests/db_compat_test.php` | 252 / 0 |
| Phase 6 migration | `php tests/phase6_migration_test.php` | 33 / 0 (MariaDB) |
| Catalogue audit | `php scripts/audit_catalog.php` | exit 0 |
| SEO | `php tests/seo_test.php http://127.0.0.1:8002 --full-sitemap` | 3186 / 0 |
| Security HTTP | `php tests/security_http_test.php http://127.0.0.1:8002` | 75 / 0 |
| Negative security | `php tests/security_negative_http_test.php …` | 147 / 0 |
| Lead reliability | `php tests/lead_reliability_test.php …` | 56 / 0 |
| CRM HTTP | `php tests/crm_http_test.php …` | 106 / 0 |
| SEO audit gate | `php scripts/seo_audit.php …` | exit 0 (630 URLs) |
| Browser | `node tests/browser_test.js …` | 135 / 0 |
| Browser UX | `node tests/browser_ux_test.js …` | 196 / 0 |
| Browser Phase 4 | `node tests/browser_phase4_test.js …` | 100 / 0 |
| Browser Phase 6 | `node tests/browser_phase6_test.js …` | 125 / 0 (run twice, both passed) |
| axe audit | `node tests/axe_audit.js … --strict` | 0 violations, exit 0 |
| Business report | `php scripts/business_report.php --days=1` | exit 0 |
| **Total** | | **4,542 passed / 0 failed** |

**Run note:** on my first HTTP run I left out `AIHUB_SMTP_LOG`. The sink was writing to a different file from the one the tests read, so 4 mail-dependent checks failed: negative 1, lead reliability 2, CRM HTTP 1. That was my setup error, not a product defect. After rebuilding the DB and re-running the four suites in CI order with the correct variable, all passed.

I did not repeat the no-DB suites that development already ran (phase4_logic, security, security_inventory, etc.). They were green in CI on MySQL 8: phase4_logic 8683/0, security 147/0, security_inventory 386/0.

**GitHub CI** (read-only `gh api …/actions/runs?branch=feat/aihubpedia-global-revenue-phase6&per_page=3`):
- Runs 44278605993 and 44278565901 on `452e5ea`, and 44278498720 on `1272812`, all concluded **failure**.
- Steps 1–18 passed; step 19 (the Phase 6 migration test) failed; steps 20–38 were skipped.

## Migration and rollback (independent run, not the project's test)

I built a fresh `qa_p6indep_test` the same way as `setup_test_db.sh` (17 tables) and added a baseline `leads` row. The fingerprint is the CHECKSUM, SHOW CREATE TABLE and row count of every non-`crm_` table.

- apply 1 → exit 0, 35 tables, 18 `crm_*`, fingerprint unchanged
- inserted a `crm_companies` row; apply 2 → exit 0, still 18, row kept, fingerprint unchanged; `crm_users` = 0 (no default account)
- rollback → exit 0, 17 tables, 0 `crm_*`, fingerprint identical to baseline, `leads` row intact
- rollback again → exit 0; re-apply → 18 `crm_*`

**Conclusion:** the migration is additive and idempotent, and the rollback removes only `crm_*` tables (on MariaDB).

## Authorization spot checks (manual curl, server on 127.0.0.1:8002)

| Check | Result |
|---|---|
| CRM with no users | `GET /crm.php` → 503 "No CRM user exists… There are no default accounts." |
| No default accounts | `crm_users` was empty after migration and after the full test suites. The migration has no INSERT. `scripts/crm_user.php` is CLI-only, reads the password from stdin, refuses passwords under 12 characters and refuses role `admin` (`role_invalid`). The web UI has no user-create action (`act=user_create` → `unknown_action`). |
| Anonymous GET, 15 views (`dashboard`, `leads`, `lead&id`, `opportunities`, `proposals`, `contracts`, `revenue`, `campaigns`, `partners`, `prospects`, `outreach`, `tasks`, `companies`, `users`, `audit`) | Sign-in page only; no `example.test` data in any response |
| Anonymous POST write (`lead_qualify`) | Sign-in page; lead stayed `new` |
| Viewer, valid CSRF: `lead_qualify`, `lead_assign`, `lead_convert`, `note`, `task_add`, `prospect_add`, `outreach_draft`, `revenue_record`, `partner_move` | All `m=forbidden`; DB unchanged (status `new`, assignee null, 0 tasks, 0 prospects, 0 opportunities) |
| Sales: approve proposal / approve partner / publish partner / create contract / record revenue | All `forbidden` |
| Owner POST to the CRM (21 actions: `lead_qualify`, `lead_assign`, `lead_convert`, `opp_create`, `opp_move`, `proposal_create`, `proposal_status`, `contract_create`, `revenue_record`, `campaign_update`, `campaign_move`, `partner_move`, `partner_publish`, `prospect_add`, `outreach_draft`, `outreach_status`, `consent`, `unsubscribe`, `task_add`, `note`, `logout`) — without `_csrf`, with a wrong `_csrf`, and with a valid `_csrf` but `Origin: https://evil.example` | **403** for every action in all three cases |
| Owner GET `crm.php?act=lead_assign…` | Inert (assignee unchanged) |
| Login brute force | Attempts 1–5 → "Sign-in failed"; attempts 6–7 → **429**; the correct password while limited → 429 |
| Session cookie | `aihub_crm`; `HttpOnly; SameSite=Strict`; `Cache-Control: no-store` |
| Public forms CSRF | `project-inquiry.php` and `partner-apply.php` with no, bogus or too-fresh `form_token` → 422 |
| Public form rate limit | Submissions 1–5 → 303; 6–7 → **429** |
| Stored XSS | A company of `<script>alert(1)</script>"><img src=x onerror=alert(2)>` was not rendered raw in the CRM leads, lead, companies or dashboard views |
| Security manifest | 121 top-level PHP files, 121 in `tests/security_manifest.json`, none missing |

## Bugs

### QA-BUG-001 — Phase 6 migration test fails on MySQL 8; CI red; later CI steps never run (severity: high, test harness)
- **Environment:** GitHub Actions `ubuntu-24.04`, `mysql:8.0` service. Does **not** reproduce on MariaDB 10.11.
- **Steps to reproduce:**
  1. Push 452e5ea, or run `php tests/phase6_migration_test.php` against MySQL 8 with the CI variables.
- **Expected:** 33 checks pass.
- **Actual:**
  - `FAIL: the migration applies without errors: mysql: [Warning] Using a password on the command line interface can be insecure.`
  - `FAIL: applying it a second time is harmless (idempotent)`
  - `PHP Fatal error: … SQLSTATE[HY000]: General error: 1137 Can't reopen table: 'a' in tests/phase6_migration_test.php:65`
- **Evidence:** CI run 44278605993, step 19, log lines 667–671.
- **Suspected code:**
  - `tests/phase6_migration_test.php:17`: the `$file` helper uses `2>&1` with `-p`, and the assertions at `:46`, `:49`, `:78` and `:80` require empty output.
  - `tests/phase6_migration_test.php:65`: a TEMPORARY table is referenced 5 times in one query, which MySQL does not allow.
- **Impact:** the release gate is red. Steps 20–38 (HTTP security, CRM HTTP, browser tests, axe) and the FK/rollback assertions have not run on MySQL 8 for this commit.

### QA-BUG-002 — CRM attaches unrelated inquirers to the same company record (severity: medium, data integrity / privacy)
- **Environment:** local, MariaDB 10.11, 452e5ea.
- **Steps to reproduce:**
  1. Submit `/project-inquiry.php` with a valid aged `form_token`, `email=alpha.qa@web.de`, `company=Alpha Synthetic GmbH`, no website, `service=custom-ai-agents`, `consent=1` and a message of at least 40 characters.
  2. Submit again with `email=beta.qa@web.de` and `company=Beta Synthetic AG`.
  3. Run `SELECT l.id, ct.email, c.name, c.domain FROM crm_leads l JOIN crm_contacts ct ON ct.id=l.contact_id JOIN crm_companies c ON c.id=l.company_id ORDER BY l.id DESC LIMIT 2;`
- **Expected:** two separate companies, "Alpha Synthetic GmbH" and "Beta Synthetic AG".
- **Actual:** both leads point to company 9, "Alpha Synthetic GmbH", domain `web.de`. Beta's lead and contact are shown under Alpha's company, and the company name Beta typed is lost from the CRM company record.
- **Cause:**
  - `crm_sales.php:21-23` (`crm_company_find_or_create`) matches on the website domain. If no website is given, it falls back to the e-mail domain, and it ignores a differing company name.
  - The free-mail exclusion list at `crm_core.php:57` has 15 entries and misses common providers in the target markets: `web.de`, `gmx.net`, `t-online.de`, `yahoo.co.in`, `rediffmail.com`, `hotmail.co.uk`, `outlook.de`, `mail.ru`, and others.
  - Related: a public visitor can type any `website` (e.g. a real customer's domain) and their lead is attached to that customer's existing company record.
- **Suggested direction (for development):** do not merge when the entered company name differs (flag as a possible duplicate instead), and/or extend the free-mail list.

### QA-BUG-003 — CRM `X-Robots-Tag: noindex, nofollow` is overwritten with `noindex, follow` (severity: low)
- **Steps to reproduce:**
  1. Run `curl -sD - -o /dev/null http://127.0.0.1:8002/crm.php | grep -i x-robots`
- **Expected:** `noindex, nofollow`, as set in `crm_views.php:11` and `:16`.
- **Actual:** `X-Robots-Tag: noindex, follow`.
- **Cause:** `layout_top.php:34` replaces the header. The same happens on `/admin.php`, which is pre-existing. `crm.php` is also not in `robots.txt` `Disallow` (`admin.php` is). The impact is low because `noindex` still applies.

### Observations (not counted as bugs)
- **Report vs CI:** `docs/phase6/FINAL_PHASE6_IMPLEMENTATION_REPORT.md` says "all Phase 1–5 suites plus 131 + 106 + 33 + 125 new checks" passed. That was not true on CI (MySQL 8) for 452e5ea.
- **Demo user hash:** `data/users.json` holds one bcrypt-hashed demo user (`demo@…`). It predates Phase 6, is gated by `demo_login` (off by default), is served as 404 over HTTP and is not a CRM account. The CEO may want it removed.
- **Dev-server warnings:** the PHP built-in server logs `http_response_code(): headers already sent` warnings (`db.php:2`, `layout_bottom.php:2`) when the negative tests request include-only files directly. This is caused by the dev router rendering 404.php. Apache rules were not tested.
- **Owner notification e-mail:** public forms still send the existing owner notification e-mail via `lead_notify_owner()`; I saw no mail to prospects or customers. CRM actions themselves send none.

## What could not be tested, and why
- **MySQL 8.0 / 8.4 locally:** only MariaDB could be installed alongside the tools here, and CI stops at step 19. MySQL-specific behaviour (FK, rollback, HTTP and browser on MySQL) is unverified until CI goes green.
- **Apache / `.htaccess` blocking rules:** only the PHP built-in server with `tests/router.php` was used.
- **`tests/live_schema_compat_test.php`:** no CEO-supplied sanitized schema was available.
- **Real e-mail delivery, staging or production:** out of scope by rule; a mock SMTP sink only.
- **Firefox and WebKit, real mobile devices, screen readers:** only Playwright Chromium (desktop and mobile emulation) was used.
- **Six revenue measures and multi-month recognition:** not hand-calculated beyond the automated tests.
- **MFA, password reset, data retention:** not implemented; acknowledged as open risks in the Phase 6 report.

## Clean-up performed
I stopped the PHP server, the SMTP sink and MariaDB, dropped every `*test*` database I created, and removed the worktree with `git worktree remove --force /home/user/qa-452e5ea`. No commits, pushes or `gh` write calls were made.
