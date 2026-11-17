# Staging readiness (Phase 5): checks only, nothing deployed

This file lists what to verify **if and when you separately approve a staging deployment**. Follow `docs/phase4/STAGING_DEPLOYMENT_INSTRUCTIONS.md` for the procedure (isolated host, a new empty test database, test-only credentials, no production data, no real mail). Phase 5 added no database migration and no new environment variable that is required.

## New files to expect
`editorial_review.php`, `i18n.php`, `admin-editorial.php`, `data/editorial_review_log.json` (must contain no entries on first deploy), `data/locales.json`, `data/translations.json`, `scripts/search_quality.php`, `scripts/content_backlog.php`.

## Pre-flight (on the staging copy only)
1. `bash tests/php_lint.sh` and `php scripts/build_assets.php --check`.
2. The data directory is writable by the PHP user and **not** web-readable (`/data/editorial_review_log.json` must return 404).
3. `/editorial_review.php` and `/i18n.php` return 404; `/admin-editorial.php` shows only the sign-in form when signed out and 503 "Admin is disabled" until `AIHUB_ADMIN_PASS_HASH` is set.
4. `AIHUB_EDITORIAL_LOG` is unset (default path) or points to a private, writable, backed-up file.

## Behaviour to verify by hand
* A tool page shows the Evidence ledger; an unreviewed tool says "Evidence ledger: unknown".
* The comparison page shows Pricing status and Features status rows; ChatGPT shows "Sources conflict" for pricing.
* Search for "voice cloning" returns audio tools first; "write code" returns code tools first; a nonsense query shows the honest empty state.
* `/multilingual-demo.php` shows only the English notice; no `hreflang` links appear on any page; `/de/…` is 404.
* As the admin, record a **note** on a test tool (not a verification) and check it appears in the review history. Do not record a verification on staging data that will be copied to production.
* Run `php tests/editorial_review_test.php`, `discovery_quality_test.php`, `i18n_test.php`, `content_backlog_test.php`, then the Phase 4.1 suites (list in TEST_RESULTS.md).

## Stop conditions
A test fails; the review log contains entries you did not make; any editor-verified label appears that nobody recorded; any translated text appears; a secret or personal data is visible in a response or log.

## Rollback
See RELEASE_RISKS_AND_OWNER_DECISIONS.md and `docs/phase4/ROLLBACK_PLAN.md`.
