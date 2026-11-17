# Test results (Phase 5)

**Environment (local, isolated):** PHP 8.3.6 CLI, `php -S` with `tests/router.php`, MariaDB test database `aihub_test` rebuilt by `tests/setup_test_db.sh`, local SMTP sink (`tests/smtp_sink.py`), Playwright Chromium (desktop and Pixel 7), axe-core 4.10.2. Synthetic data only; no network service, real mail, live database or production system was used. The same steps run in GitHub Actions (`.github/workflows/ci.yml`, MySQL 8); the CI result for the final commit is on the pull request.

| Suite | Command | Result |
|---|---|---|
| PHP syntax | `bash tests/php_lint.sh` | 199 files, 0 problems |
| Built assets current | `php scripts/build_assets.php --check` | up to date |
| Data quality | `php tests/data_quality_test.php` | 281 passed |
| Phase 4 logic | `php tests/phase4_logic_test.php` | 8,683 passed |
| **Editorial verification (new)** | `php tests/editorial_review_test.php` | **555 passed** |
| **Discovery quality and independence (new)** | `php tests/discovery_quality_test.php` | **98 passed** |
| **Localisation (new)** | `php tests/i18n_test.php` | **35 passed** |
| **Content backlog (new)** | `php tests/content_backlog_test.php` | **27 passed** |
| UX logic | `php tests/ux_logic_test.php` | 138 passed (with the test database) |
| Security unit | `php tests/security_test.php` | 147 passed |
| Security inventory and secrets scan | `php tests/security_inventory_test.php` | 348 passed (109 PHP files classified) |
| Database compatibility scenarios | `php tests/db_compat_test.php` | 252 passed |
| Catalogue audit | `php scripts/audit_catalog.php` | no invalid reviewed profiles |
| SEO incl. every sitemap URL | `php tests/seo_test.php http://127.0.0.1:8002 --full-sitemap` | 3,148 passed (618 URLs checked) |
| Security HTTP | `php tests/security_http_test.php …` | 75 passed |
| Negative HTTP (hostile barrage, admin matrix now includes `admin-editorial.php`) | `php tests/security_negative_http_test.php …` | 134 passed (Phase 4.1: 123) |
| B2B lead reliability + mock campaign attribution | `php tests/lead_reliability_test.php …` | 56 passed (Phase 4.1: 48) |
| Browser desktop + mobile | `node tests/browser_test.js …` | 135 passed |
| Phase 3 UX/conversion/privacy browser | `node tests/browser_ux_test.js …` | 196 passed |
| Phase 4 browser (wizard, problems, matrix, advertiser, no-JS) | `node tests/browser_phase4_test.js …` | 100 passed |
| Accessibility (axe-core, 22 pages, light/dark, desktop/mobile) | `node tests/axe_audit.js … --strict` | 0 violations |
| Business report | `php scripts/business_report.php --days=1` | runs |
| Search quality | `php scripts/search_quality.php` | 28 queries: P@5 0.679, success@3 0.964, MRR 0.912 |
| Benchmark | `php scripts/bench.php` | search 9.6 ms median; related links 0.14 ms; problem page 6.5 ms |

## What the new tests prove
* The committed catalogue has 509 tools, 20 reviewed and **0 verified**; no fact in the catalogue is editor-verified; the committed review log is empty after the tests run.
* Every refusal path of the verification log; a full verify, expire, reject cycle on a temporary log; research tasks state no facts.
* Search regression floors (P@5 at least 0.65, success@3 at least 0.94, MRR at least 0.88), navigational queries, honest empty results, template text not searchable.
* Wizard, problem pages and comparison pairs are identical when every tool is flagged sponsored/affiliate/featured and click counts are extreme.
* Locale rules: only English live, no hreflang or switcher, planned locales not routed, validator catches wrong canonicals, missing x-default, non-reciprocal hreflang, duplicate content, unserved locales.
* Backlog: 113 rows, all gated, none verified, no stated volume, workflow/agent/change pages blocked, CSV matches a fresh run; thin-page policy effect measured (617 to 128 sitemap URLs).
* Anonymous forged verification requests to the admin page change nothing; mock campaign attribution is stored, sanitised and reported as raw counts only.

## Not run or not verified
* **Apache 2.4 validation was not repeated** (Apache is not installed in this session). The only `.htaccess` change adds two library names to an existing block list; it is verified on the test router and by the inventory, not on Apache. Re-run `tests/apache/run_apache_validation.sh` where Apache is available.
* Real mail delivery, real proxy/CDN, nginx/LiteSpeed rules, PHPMailer vulnerability status, load and capacity, assistive-technology testing, the live database, real vendor pages (no profile was researched), competitor sites.
* Search relevance is judged by taxonomy by the team, not by users.

## CI finding fixed during this phase
The first CI run failed the backlog test: the generator's "exists/candidate" state for comparisons depended on tool order, which differs between JSON mode and database mode (and after other tests touch the database). The generator now sorts tools by id, the CSV was regenerated, and the test passes with and without a database and after the Phase 4 logic suite. Reproduced locally before the fix and verified after.
