# Deployment checklist (nothing here was executed)

Do every step on **staging first**. This work was not deployed anywhere; it has been tested only on an isolated local MariaDB / `php -S` / Apache-style rule set with synthetic data, and in GitHub Actions with MySQL 8. See [HOSTINGER_SECURITY_CHECKLIST.md](HOSTINGER_SECURITY_CHECKLIST.md) for server-side steps.

## A. Before deploying code (blocking)
- [ ] Rotate the exposed database password, the SMTP app password and the old admin password. Store new values in environment variables or an untracked `config.local.php`.
- [ ] **Set database credentials first** (`AIHUB_DB_USER`, `AIHUB_DB_PASS`, optionally `AIHUB_DB_HOST/NAME`). The code no longer has a fallback: without them the site silently serves the JSON catalogue (no votes, leads or newsletter).
- [ ] Set `AIHUB_FORM_SECRET` (64+ random hex), `AIHUB_ANALYTICS_SALT`, and `AIHUB_ADMIN_PASS_HASH` (`password_hash`).
- [ ] Configure `AIHUB_TRUSTED_PROXIES` (+ `AIHUB_PROXY_HEADER`) for your proxy/CDN, otherwise all visitors share one rate-limit identity.
- [ ] Mail: `AIHUB_SMTP_*`, `AIHUB_MAIL_FROM/TO`. Verify SPF/DKIM/DMARC before relying on confirmation emails.
- [ ] Legal: update privacy policy (leads, newsletter, browser storage, saved shortlists, optional statistics), confirm the operating entity (Impressum vs TrustNova Tech). Leave `AIHUB_ANALYTICS` unset until signed off.
- [ ] Back up the production database and `data/`.

## A2. Added in Phase 4.1 (read before deploying)
- [ ] Run the **read-only** `scripts/db_preflight.php --plan` on a restored copy of the live database (DATABASE_COMPATIBILITY_REPORT.md). The committed install SQL for `tool_clicks`/`tool_votes` does not match the code; without the plan those features return 503.
- [ ] `AIHUB_DEMO_LOGIN` stays unset; `/extensions/`, `/docs/`, `/vendor/`, dotfiles must return 404 on your server (nginx users: port the rules).
- [ ] `AIHUB_RATE_DIR`/`AIHUB_CACHE_DIR` private to the web user, or leave unset.
- [ ] Follow STAGING_DEPLOYMENT_INSTRUCTIONS.md; rotate credentials first (MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md).
- [ ] Editorial: the 19 P1 profiles in EDITORIAL_VERIFICATION_QUEUE.md are still unverified; do not claim "verified" anywhere until a person has verified them.

## B. Migrations (owner, in a migration session; never from the web)
1. `database/phase3_business.sql` if not already applied.
2. `database/phase4_business.sql` (additive; see ROLLBACK_PLAN.md). The code works before and after it.

## C. Deploy and verify on staging
- [ ] `php scripts/build_assets.php --check` is clean; regenerate `sitemap.xml`/`llms.txt` with `php scripts/build-seo-files.php`.
- [ ] External checks: `/data/site.json`, `/install-database.php`, `/security.php`, `/config.local.php`, `/.git/config`, `/vote_debug.php` → 404.
- [ ] Admin: sign in at `/admin.php`, open `/admin-leads.php`, change a status, sign out. Submit a test lead to a mailbox you own.
- [ ] Newsletter double opt-in with your own address. **Do not** run any newsletter sending against real subscribers: only the mock sender exists.
- [ ] Wizard, problem pages, comparison matrix and advertiser page on a phone and a desktop.
- [ ] Run `php tests/security_http_test.php <staging-url>` from a trusted machine (creates test comments/ratings: staging copy only).
- [ ] Submit the sitemap in Search Console on staging-equivalent *only after* production approval; monitor coverage for 4 weeks (noindexed thin pages: 2 posts, empty archive, 6 of 10 problem pages).

## D. Not part of this release (decide separately)
Payments/checkout; real newsletter sending; publishing €199/€499 prices; any claim about audience, traffic or results; multilingual launch; Content-Security-Policy enforcement; HSTS.

## E. Go/no-go
Go only when: secrets rotated, DB credentials set, trusted proxies configured, legal text updated, staging checks above pass, and the full CI run is green on the exact commit being deployed.
