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

## Phase 8B: analytics (PLAN ONLY, awaiting approval; branch `feat/aihubpedia-analytics-phase8b` from 818d93c; no code, no migration)
Findings: `page_views` (cookie-free, no raw IP, daily rotating hash, bot flag, DNT/GPC get no hash, country only from a CDN header else XX, no device, no session id, no automatic retention); `events` (outbound_click, lead_submit, newsletter_signup/confirmed, wizard_complete; rotating hash, no bot flag); `tool_clicks` (code writes persistent-cookie `visitor_id` + `ip_hash`; repo schema files still show raw `ip_address`/`user_agent`: live columns unconfirmed; lifetime popularity counter, separate from events). No member registration feature exists.
Plan: additive rollup tables `adm_traffic_daily`, `adm_traffic_dim_daily`, `adm_tool_daily`, `adm_event_daily`, `adm_rollup_run` (rollback = drop); CLI `scripts/adm_rollup.php` (incremental, cron); Control Center `?m=analytics` (presets + custom range, SVG trends, server-side sortable tables, CSV export with formula-injection guard, daily business summary). Metric definitions and optional privacy changes: see the plan message; privacy changes to existing tracking need separate approval.
