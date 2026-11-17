# Safe staging deployment instructions (for the site owner)

**This work did not deploy anything and had no access to Hostinger or any server.** These steps are for you to follow on a **staging** copy, with synthetic data, before any production decision. Do not point staging at the production database.

## 0. Preconditions
- [ ] Staging is a separate site/subdomain with its **own database** (a copy of the schema, not the live data) and its own mail settings (a mailbox you own).
- [ ] Credentials have been rotated or at least the staging credentials are new and unrelated (MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md).
- [ ] Staging is not indexed: HTTP basic auth or `X-Robots-Tag: noindex` at the server; keep `robots.txt` Disallow.

## 1. Prepare the database copy (read-only checks first)
1. Take a backup of the staging database.
2. Run the read-only preflight against the **staging copy**: `AIHUB_DB_* … php scripts/db_preflight.php --plan`. Read the report and the plan; the plan is text and is never executed by the script.
3. Review each statement, then apply the needed ones **in a migration session on the staging copy**, in this order: missing tables from the install SQL (`CREATE TABLE IF NOT EXISTS`), the interaction-table fixes from the plan (tool_clicks/tool_votes), `database/phase3_business.sql`, `database/phase4_business.sql` (once; `ALTER TABLE` is not repeatable on MySQL 8).
4. Re-run the preflight: **Required features broken must be 0**. Optional items listed as degraded mean that feature is hidden or falls back; decide knowingly.
See DATABASE_COMPATIBILITY_REPORT.md for what each result means and how every scenario behaves.

## 2. Configure the environment (not in Git)
Set as environment variables or an untracked `config.local.php` (see HOSTINGER_SECURITY_CHECKLIST.md for the template): `AIHUB_DB_*`, `AIHUB_FORM_SECRET`, `AIHUB_ANALYTICS_SALT`, `AIHUB_ADMIN_PASS_HASH`, `AIHUB_SMTP_*`, `AIHUB_MAIL_FROM/TO`, `AIHUB_TRUSTED_PROXIES` (+ `AIHUB_PROXY_HEADER`), `AIHUB_RATE_DIR`, `AIHUB_CACHE_DIR` (each a directory outside the web root writable by the web user only). Leave `AIHUB_ANALYTICS` and `AIHUB_DEMO_LOGIN` unset. **Set the database credentials before uploading code**, otherwise the site silently serves the JSON catalogue.

## 3. Upload the code
Deploy the branch contents (not the `tests/`, `docs/`, `scripts/` or `database/` folders if you prefer; they are blocked at the web server anyway). Do not copy `data/*.json` written by tests; keep `data/` writable only where needed (comments, ratings). Ensure `.htaccess` is honoured (`AllowOverride All`) or ported (nginx).

## 4. Smoke tests on staging (manual, with a phone and a desktop)
- [ ] Home, a reviewed tool page, a category, search, compare, wizard result, problem page, advertise, business pages render; no PHP errors on screen.
- [ ] External checks return 404: `/data/site.json`, `/install-database.php`, `/security.php`, `/layout_top.php`, `/vendor/autoload.php`, `/docs/`, `/extensions/community/index.php`, `/.gitignore`, `/config.local.php`.
- [ ] Admin: `/admin.php` shows the sign-in form (no password hint); sign in; open `/admin-leads.php`; sign out.
- [ ] Submit a test lead; confirm the notification arrives; stop/misconfigure SMTP on staging and confirm the admin banner appears; fix and use **Retry notification**.
- [ ] Newsletter: sign up with your own address, confirm via the button (the email link alone must not confirm), unsubscribe.
- [ ] Outbound "Visit site" link redirects (even if the database were unavailable it must still redirect).
- [ ] Response headers: gzip enabled, `X-Frame-Options`, `X-Content-Type-Options`; HTTPS forced.
- [ ] Tests from a trusted machine against **staging only**: `php tests/security_negative_http_test.php https://staging…` is **not** safe to run (it writes test rows and rotates headers); use it only on the local/CI test instance. For staging use manual checks above plus the read-only `php scripts/seo_audit.php https://staging…`.
- [ ] Optional performance warm-up: `php -r 'require "helpers.php"; solution_pages_data();'` after deployment.

## 5. Observe for a few days
Review `AIHUB_SECURITY_LOG` and the PHP error log daily; look for `rate_limited`, `csrf_rejected`, `unsafe_outbound_url_blocked`, `lead_notification_failed`, `rate_dir_unsafe`.

## 6. Go / no-go for production (separate decision)
Go only if: secrets rotated; DB preflight reports 0 broken; staging smoke tests pass; privacy policy/Impressum updated; TLS/HSTS and proxy settings confirmed; backup and rollback rehearsed (ROLLBACK_PLAN.md); full CI green on the exact commit. **No merge to `main` and no production deployment is part of this work.**
