# Phase 8 status (Global Admin Control Center)

Branch `feat/aihubpedia-admin-center-phase8` (from the Phase 7 tip `3bee968`). Stack: PHP/MySQL, server-rendered, no framework. Decisions: EUR is the primary reporting currency (other currencies are never converted or summed with it); production analytics status is NOT confirmed, so nothing assumes tracking is on and no historical data is invented.

## Phase 8A: foundation (DONE, awaiting your review; nothing deployed, no migration)
- `admin-center.php` (entry) + `adm_core.php` (library). Sign-in is the existing CRM account system (CLI-created users, session, CSRF, same-origin, rate limit, idle/max session). No new auth, no new tables.
- Role map `adm_role_perms()` (separate from `crm_roles()`, which is unchanged): owner all; sales CRM + advertising; viewer CRM; editor/verifier AI Universe only.
- Layout: skip link, top bar (user, role, sign-out POST with CSRF), sidebar by group (top-aligned list on tablet and mobile), KPI tiles, light and dark, 44px targets, no JS.
- Tiles come only from stored records: CRM (new inquiries 30d, open opportunities, follow-ups overdue, MRR and revenue received in EUR, renewals), AI Universe (verified counts, awaiting independent review, imported leads not yet adopted), owner-only system health. Later-phase tiles say "Not available yet"; tiles without records say "No data yet".
- Existing tools are linked (CRM, AI Universe editor, legacy admin); planned modules (Analytics 8B, Tools 8C, Finance 8D, Advertising 8E, Operations 8F, Performance 8G) show scope only, no data.
- Audit: sign-in, overview view (once per 30 minutes), denied access and sign-out go to the existing `crm_audit_log`.
- Tests: `tests/phase8a_admin_center_test.php` (authorization matrix for 5 roles + anonymous/inactive, CSRF, methods, no invented figures, escaping, audit, headers, plus Playwright axe/keyboard/overflow/target-size at desktop, tablet, mobile, light and dark via `tests/browser_phase8a_test.js`).
- Files: `adm_core.php`, `admin-center.php`, `tests/phase8a_admin_center_test.php`, `tests/browser_phase8a_test.js`, `tests/security_manifest.json`, `robots.txt`, `.github/workflows/ci.yml`, this file.

## Next (needs your approval)
- 8B Analytics: needs the production `AIHUB_ANALYTICS` status; additive rollup table and `tool_clicks` privacy fix (migration approval required).
- Then 8D Finance, 8E Advertising/CRM, 8F Operations, 8C Tools, 8G Performance (see the audit order).

## Phase 8B: analytics (DONE, awaiting your review; nothing deployed, NO migration executed)
Branch `feat/aihubpedia-analytics-phase8b`. New/changed: `adm_analytics.php` (rollup, ranges, queries, CSV, rendering), `scripts/adm_rollup.php` (CLI), `database/phase8b_analytics.sql` + `phase8b_rollback.sql` (five additive tables), `admin-center.php`/`adm_core.php` (Analytics module, overview tiles), `redirect.php` (bots no longer create new outbound-click events), tests `tests/phase8b_analytics_test.php` (+ shared `browser_phase8a_test.js`), manifest, CI step.
Definitions (also on screen): page view = non-bot request with status < 400; daily unique visitors = distinct daily rotating hash (a multi-day sum is "visitor-days", not people); session (approximate) = a daily hash's views split by > 30 min inactivity; outbound clicks = `events.outbound_click` (server-side); lifetime tool popularity = `tool_clicks` counter (separate, never added); no click-through rate (listing clicks are not tracked); conversions = tracked events and CRM inquiries shown apart, same-day first-touch attribution; no registration metric (no such feature). Missing data is "not available", never zero.
Deferred (not implemented, by your decision): device tracking, deleting or anonymising historical data, retention jobs, visitor-cookie changes.

### Migration review and rollback (NOT executed)
* `database/phase8b_analytics.sql`: creates `adm_traffic_daily`, `adm_traffic_dim_daily`, `adm_tool_daily`, `adm_event_daily`, `adm_rollup_run` (IF NOT EXISTS, idempotent). Existing tables are not altered (tested by column comparison).
* `database/phase8b_rollback.sql`: drops only those five tables; the rollups can be rebuilt with `php scripts/adm_rollup.php --since-first`.
* Needs MySQL 8.0+/MariaDB 10.2+ (window functions); staging 8.4.10 is fine.

### Exact staging deployment requirements (your administrator; staging only)
1. Code: `git fetch origin feat/aihubpedia-analytics-phase8b` then `git checkout --detach <commit>` in `/var/www/html/AI-PHASE3-TEST` (save the previous commit first; rollback = check it out again).
2. Migration (a migrator account with CREATE): `mysql aihub_phase6_test < database/phase8b_analytics.sql`. No backup is required (new tables only); rollback file above.
3. Tracking prerequisites (read-only checks first): `page_views` and `events` tables exist (`database/phase2_analytics.sql`, `phase3_business.sql`); `AIHUB_ANALYTICS=1` and a random `AIHUB_ANALYTICS_SALT` set in the staging vhost ONLY if you want tracking on staging. Without them the dashboard says "not enabled / no tracking data", it never shows zeros as facts.
4. Rollup: run once `php scripts/adm_rollup.php --since-first` (real rows only), then schedule hourly: `cd /var/www/html/AI-PHASE3-TEST && php scripts/adm_rollup.php` with the staging DB environment. The web account needs SELECT on the `adm_*` tables; the rollup account needs SELECT on `page_views`/`events` and INSERT/DELETE on the `adm_*` tables.
5. Acceptance: sign in as owner; Control Center > Analytics shows the correct state (not installed / no data yet / data since <date>); presets and a custom range work; each CSV export downloads and appears in `crm_audit_log`; sales/viewer/editor are refused; Phase 7 counts unchanged.
Next: 8D Finance or another phase on your approval; nothing else started.
