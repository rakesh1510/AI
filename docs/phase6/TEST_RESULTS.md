# Test results (Phase 6)

**Environment (local, isolated):** PHP 8.3.6 CLI (the production server runs 8.4; no 8.4-specific construct is used), `php -S` with `tests/router.php`, test database `aihub_test` rebuilt by `tests/setup_test_db.sh` (now including `phase6_crm.sql`) on **MySQL 8.0.46 (the same server version CI uses)** and, earlier in development, MariaDB 10.11, local SMTP sink, Playwright Chromium (desktop, Pixel 7, no-JS), axe-core 4.10.2. Synthetic data only. No production system, Hostinger, VPS, live database, real credential or real e-mail was used. The same steps run in GitHub Actions (MySQL 8) on every push; the CI result is on the pull request.

| Suite | Command | Result |
|---|---|---|
| PHP syntax | `bash tests/php_lint.sh` | 216 files, 0 problems |
| Built assets current | `php scripts/build_assets.php --check` | up to date |
| Data quality | `php tests/data_quality_test.php` | 281 passed |
| Phase 4 logic | `php tests/phase4_logic_test.php` | 8,683 passed (includes the regenerated sitemap fallback check: 629 URLs) |
| Phase 5 editorial / discovery / i18n / backlog | `php tests/{editorial_review,discovery_quality,i18n,content_backlog}_test.php` | 555 / 98 / 35 / 27 passed |
| **Phase 6 CRM logic (new)** | `php tests/crm_logic_test.php` | **131 passed** |
| UX logic | `php tests/ux_logic_test.php` | 138 passed |
| Security unit | `php tests/security_test.php` | 147 passed |
| Security inventory and secrets scan | `php tests/security_inventory_test.php` | 386 passed (123 PHP files classified, new `crm_admin` kind) |
| Database compatibility scenarios | `php tests/db_compat_test.php` | 252 passed |
| **Phase 6 migration (new)** | `php tests/phase6_migration_test.php` | **33 passed** |
| Catalogue audit | `php scripts/audit_catalog.php` | 0 profile errors |
| SEO incl. every sitemap URL | `php tests/seo_test.php … --full-sitemap` | 3,184 passed |
| Security HTTP | `php tests/security_http_test.php …` | 75 passed |
| Negative HTTP (barrage now covers the new endpoints) | `php tests/security_negative_http_test.php …` | 147 passed (Phase 5: 134) |
| B2B lead reliability (mock mail) | `php tests/lead_reliability_test.php …` | 56 passed |
| **Phase 6 CRM and public intake over HTTP (new)** | `php tests/crm_http_test.php …` | **106 passed** |
| Browser desktop + mobile | `node tests/browser_test.js …` | 135 passed |
| Phase 3 UX/conversion/privacy browser | `node tests/browser_ux_test.js …` | 196 passed |
| Phase 4 browser | `node tests/browser_phase4_test.js …` | 100 passed |
| **Phase 6 browser (new; services, partners, advertiser, CRM, axe, CLI)** | `node tests/browser_phase6_test.js …` | **125 passed** |
| Accessibility (axe, 25 pages incl. the 3 new public pages, light/dark, desktop/mobile) | `node tests/axe_audit.js … --strict` | 0 violations (the CRM views are audited inside the Phase 6 browser test, 13 views × 2 profiles, 0 violations) |
| **Live-layout compatibility (new; local only)** | `AIHUB_LIVE_SCHEMA_FILE=<ddl> php tests/live_schema_compat_test.php` | **61 passed** (skipped in CI: the schema file is never committed) |
| Search quality (DB mode, synthetic clicks) | `php tests/discovery_quality_test.php` | P@5 0.693, success@3 0.964, MRR 0.930 (JSON mode, as in Phase 5: 0.679 / 0.964 / 0.912) |

## CRM scale (synthetic 20,000 leads, 5,000 opportunities; MariaDB, one run)
Filtered lead list 4 ms; free-text search 67 ms; dashboard 54 ms; duplicate check 4 ms. Thresholds in the test are generous (1.5 s, 1.5 s, 3 s, 0.5 s). Public pages are unaffected: the CRM libraries are loaded only by the pages that need them. `scripts/bench.php`: related links 0.18 ms (cached), problem hub about 100 ms uncached as in Phase 4.1.

## Real GitHub Actions results (verified)
| Run | Commit | Event | Result |
|---|---|---|---|
| [#32](https://github.com/rakesh1510/AI/actions/runs/44278605993) | `452e5ea` | pull_request | **FAILED** at "Phase 6 migration test" (details below); later steps did not run |
| [#34](https://github.com/rakesh1510/AI/actions/runs/44280206800) | `f735cf6` | push | **PASSED**, all 40 steps (MySQL 8.0.46 service container, Playwright, axe) |
| [#35](https://github.com/rakesh1510/AI/actions/runs/44280219677) | `f735cf6` | pull_request | **PASSED** |
Earlier runs on this branch (#29 on `965f89e`, before the sitemap fallback and POST-guard fixes) failed for those two reasons and are superseded; the CI result for the very latest documentation-only commit is on the pull request.

### Run #32: root cause and fix (reproduced on MySQL 8.0.46, not suppressed)
Three symptoms, two causes, **both in the test harness; the migration SQL itself was correct**:
1. *"applying phase6_crm.sql reports an error"* and *"applying it twice is not idempotent"*: the test captured the MySQL **client's** stderr and required it to be empty. The MySQL 8 client prints `[Warning] Using a password on the command line interface can be insecure` whenever `-p<password>` is used (the MariaDB client I had used locally does not), so the "output must be empty" assertion failed even though the SQL ran with exit status 0. Verified by running the migration twice and the rollback on MySQL 8.0.46 through a private option file: no output, exit 0, 18 tables. **Fix:** credentials now go through a mode-0600 `--defaults-extra-file`, never the command line, and the assertion is unchanged: any genuine message from the SQL still fails the test.
2. *"1137 Can't reopen table: 'a'" at the 20,000-lead seed:* the helper numbers table was a `TEMPORARY` table referenced five times in one `INSERT ... SELECT`; **MySQL does not allow a TEMPORARY table to be opened more than once per query** (MariaDB does). **Fix:** ordinary helper tables in the throw-away database, dropped before the rollback check.
Also hardened: `tests/live_schema_compat_test.php` uses the same option-file mechanism and now keeps the original `utf8mb4_0900_ai_ci` collations when the server supports them (only MariaDB needs them mapped).
**Why it was missed:** I ran the suites on MariaDB only. A real MySQL 8.0.46 server was then unpacked in an isolated directory and **the entire suite was run on it** before pushing the fix (all green: same counts as above, migration 33, live layout 61, CRM scale: filter 35 ms, search 86 ms, dashboard 50 ms, duplicate check 5 ms).

## What the new tests prove
* **Authorization:** the role matrix, and that every library mutator refuses an unauthorized user; over HTTP, anonymous, viewer and sales requests change nothing, owner-only actions are refused to sales (campaign and partner approval), no default credentials exist, CSRF and cross-origin posts are refused, state never changes on GET.
* **Sessions:** HttpOnly + SameSite=Strict cookie, session fixation defeated, idle expiry, login lockout not evaded by forged forwarding headers.
* **Leads:** ingestion creates company/contact/lead; duplicates detected (same email, colleague at same domain not a duplicate, free-mail domains never merge companies); qualification scoring; conversion is atomic; search and filters; hostile text inert.
* **Pipeline:** the full 8×8 stage transition matrix; won needs an amount; lost needs a reason; closed is final; history recorded.
* **Money:** worked example (total 3,221.91), half-up rounding, limits at the extremes without overflow (a real overflow was found and fixed), 300 random proposals keep every identity, versions, approvals, contracts as drafts until an owner attests, MRR/ARR/recognized scenarios (monthly, annual, one-off, cancellation effective in the past), per-currency separation, received ≤ invoiced, forecast labelled hypothetical, empty dashboard shows nulls not zeros.
* **Advertising:** the approval gates (disclosure label, safe landing URL), reject needs a reason, attribution code, reporting definitions, no EUR price published, no audience claim.
* **Partnerships:** an applicant, an approved-but-unverified partner, a suspended partner and a synthetic partner are never shown; a verified, approved, listed partner appears with its evidence link.
* **Business development:** research notes refuse emails and phone numbers; drafts need an opt-out line and a consent basis; no "sent" status; unsubscribe is final and archives drafts; the CRM code contains no mail or network calls; ranking code never reads CRM data; money code has no float.
* **Migration:** additive, idempotent, existing tables unchanged, relationship behaviour, rollback, preflight reporting, scale. **Live layout:** the three-stage test against the supplied schema (CRM-only intake when `leads` is missing, then Phase 2–4 and 6 in order, all existing tables byte-identical).

## Issues found by running the suites during Phase 6 (all fixed)
A test-harness portability defect between MariaDB and MySQL 8 (CLI password warning; TEMPORARY table reuse) found by CI run #32, described above; Money overflow at extreme inputs; `strtotime()` accepting words as dates; a layout variable clobbering the CRM view name; keyboard focus on scrollable tables; the Phase 3/4/6 forms needing the missing `leads` table on the live layout; the login rate limit shared across suites making a later browser test fail; the committed sitemap fallback and the POST-guard security check needed to know the new pages and endpoints (two expectations **extended**, none weakened).

## Not run or not verified
* **Apache 2.4 validation was not repeated** (Apache is not installed in this session).
* PHP 8.4 itself was not available; no deprecated PHP 8.4 construct is used (explicit nullable types), but it is untested on 8.4.
* MySQL **8.4** (the live server's version) was not available; everything ran on MySQL 8.0.46 (local and CI) and MariaDB. The migration uses only portable DDL; re-run `tests/phase6_migration_test.php` on a MySQL 8.4 copy before any live change.
* Real mail delivery, real proxy/CDN, nginx rules, PHPMailer vulnerabilities, load, assistive-technology testing, any real data, any real Futurepedia page (unreachable).
