# Visitor analytics and AI tool click tracking: assessment and development plan

Status: **inspection and plan only.** Nothing was implemented, migrated, enabled, deployed or merged. No staging, production or live database was touched. All findings come from reading the code in this repository (branch `feat/aihubpedia-unified-admin`, on Phase 8D). Figures in any report must come from measured rows only; this plan promises no numbers.

## 1. Existing capability assessment

**Collection (Phase 2/3, first-party, cookie-less for page views)**
- `analytics.php`: records one `page_views` row per request after the response, only when `AIHUB_ANALYTICS=1` and the table exists. Stores path (no query string), page type, status, referrer class and host, country (from a CDN header only, else `XX`), a bot flag with bot type and name, and `visitor_hash`. No IP, no raw user agent, no cookie. Do-Not-Track and Global Privacy Control requests are counted as views but get no hash. Needs a secret `AIHUB_ANALYTICS_SALT` of 32+ characters; without it no hash is written.
- `visitor_hash` = first 16 hex of sha256(salt, **day**, IP, user agent). It **rotates daily**, so one person on two days is two hashes (weekly window is available with `AIHUB_ANALYTICS_HASH_WINDOW=week`).
- Bot filtering: user-agent heuristic (AI crawlers, search crawlers, SEO tools, social previews, generic patterns). Spoofable; stated as an estimate.
- Referrer classes: Google, Bing, DuckDuckGo, Yahoo, Brave, other search, social, AI apps (ChatGPT, Perplexity, Claude, Gemini, Copilot), internal, direct, other.
- `event.php` + `events` table: consent-gated (browser opt-in), same-origin, DNT/GPC respected, bots dropped. Event names: search, suggestion click, `outbound_click`, compare, favourite, filter, theme, `returning_visit` (visit-count bucket), plus server-side newsletter, lead and wizard events.
- Tool clicks: `redirect.php` is the outbound link (destination always from the catalogue, safe-URL checked, rate limited 20 per 10 min per tool and network). It writes `tool_clicks` (`record_click`) **and** a server-side `outbound_click` event (bot-filtered; records affiliate yes/no and sponsored/organic). `track-click.php` writes `tool_clicks` again; `assets/js/app.js` also sends a client `outbound_click` event.

**Reporting (Phase 8B, already built and in the Control Center)**
- `database/phase8b_analytics.sql`: five additive tables, `adm_traffic_daily`, `adm_traffic_dim_daily`, `adm_tool_daily`, `adm_event_daily`, `adm_rollup_run`; rollback file exists (drops only those five). `scripts/adm_rollup.php` rebuilds them idempotently per day from real rows (CLI only; `--days`, `--from/--to`, `--since-first`).
- `adm_analytics.php` + `?m=analytics` (owner only): date ranges today, yesterday, 7 days, 30 days, custom (max 366 days), status panel, trend chart, traffic table, sources, pages, countries, tools (views, outbound, affiliate, sponsored), conversions with same-day first-touch attribution, lifetime `tool_clicks` ranking (clearly separated), six audited CSV exports, honest empty states. When the five tables are missing it shows **"Analytics storage is not installed."**, which is the message you see on staging.

## 2. Metric-by-metric: supported today vs missing
| Requested metric | Status | Notes |
|---|---|---|
| Visitors today / yesterday / 7 / 30 days | **Supported** after install and rollup | Daily distinct hashes. Sums over several days are **visitor-days, not people** (hash rotates). Must be labelled so. |
| This month / this year / since tracking began | **Needs development** (presets only) | Data model suffices; add presets and the same visitor-day label. |
| Unique versus returning visitors | **Not measurable reliably today** | Hash rotation prevents cross-day recognition. Only a consent-based `returning_visit` event exists (opted-in browsers only, a biased subset). See decision D2. |
| Sessions versus page views | **Supported** (approximate) | Session = a day-hash's views split by 30 minutes of inactivity. |
| Currently active visitors | **Needs development**, feasible | Distinct hashes with a view in the last 5 minutes from raw `page_views` (live query, not rollup). Shown only if tracking is on and rows are recent; otherwise "not available". |
| Daily / weekly / monthly growth % | **Needs development** | Computable from rollups against the previous equal period; shown only when the earlier period has data (never a % from zero). |
| Page views today, most visited pages, pages per session | **Supported / small addition** | Pages per session = views / approximate sessions; add it. |
| Device categories | **Not collected** | Needs a coarse class (mobile, tablet, desktop) derived from the user agent at write time, raw UA still not stored. Requires one additive column (D3). |
| Traffic sources | **Supported** | "Direct" includes visits whose referrer was stripped; label it. |
| Countries | **Partly** | Only when a CDN/proxy country header exists, else unknown. Regions not collected and not recommended. |
| Tool clicks today / yesterday / 7 / 30 days | **Supported** (outbound) | From events via rollup, **but see double counting below.** |
| Top 10 / 20 / 50 tools, per-tool counts (ChatGPT, Claude, Gemini, Perplexity, all) | **Needs development** (table size selector, search) | Data exists in `adm_tool_daily`; today shows top 200 rows. |
| Tool profile page views | **Supported** | From `/tool.php?id=` views. Other pages that mention a tool are not counted. |
| Outbound clicks to official sites | **Supported** | The redirect is the only outbound path in the pages inspected (tool, compare, recommend, use-case, cards). |
| Internal tool-page clicks versus outbound | **Partly** | Internal = tool profile views, with referrer class "internal" as the click-through proxy. Listing-card impressions are **not** recorded. |
| Click-through rate per tool | **Needs development, with a stated definition** | Outbound clicks / profile views of the same tool. Not a card CTR (no impressions). Can exceed 100% if clicks come from cards; flagged. |
| Daily and monthly click trends | **Needs development** (per-tool and total series from `adm_tool_daily`) | |
| Popular categories | **Needs development**, no schema change | Map tool slug to category from the catalogue at report time; label that categories are the current catalogue's. |
| Featured placement performance | **Partly** | `sponsored_clicks` exists; Phase 8C `adm_tool_feature` has dates. Add clicks and views during a placement versus the same tool's prior equal period; labelled descriptive, not causal. |
| Tool-specific funnels | **Partly** | Same-day, same-hash: tool view then outbound click. Later steps (lead, revenue) cannot be tied to a tool or a person; not claimed. |
| CSV exports | **Supported** (six); add the new tables | Owner only, audited, formula-safe. |

## 3. Problems found that must be fixed before any number is trusted
1. **Double counting of outbound clicks.** With JavaScript on, `interactions.js` posts to `track-click.php` (writes `tool_clicks`), then navigates to `redirect.php?...&tracked=1`, which **ignores `tracked`** and writes `tool_clicks` again. Result: lifetime counts roughly double for JS users. The `outbound_click` event can also be written twice (browser event if the visitor opted in, plus the server event in `redirect.php`). The rollup counts every event row, so tool click totals would be inflated.
2. **Three click records, no single definition.** `tool_clicks` (lifetime popularity, no bot filter, not date-filtered in the report), client events (consent-gated) and server events (bot-filtered). The report keeps `tool_clicks` separate (good) but the tool click numbers need one authoritative source.
3. **Persistent identifier in `tool_clicks`.** `record_click` stores `visitor_id` from a 1-year `aih_visitor` cookie plus a salted IP hash. That is stronger than the cookie-less page-view design and needs a privacy and consent review (D4).
4. **Schema mismatch risk.** `database/aihub_interactions.sql` defines `tool_clicks` with `ip_address` and `user_agent`, while `record_click` writes `visitor_id` and `ip_hash` (the application checks for those columns and silently stops counting if absent). Staging must be checked.
5. **Rollup needs MySQL 8** (window function `LAG` and `ROW_NUMBER`).

## 4. Proposed development plan (after approval; no database needed for routine tests)
- **A. Counting integrity.** One authoritative outbound record: the server redirect writes one event and one `tool_clicks` row; `track-click.php`/`interactions.js` stop writing a second row (the UI counter reads the result of the redirect-side count or is refreshed on next load), and the browser `outbound_click` event is removed. A regression test proves exactly one record per click, with and without JavaScript and with `tracked=1`.
- **B. Rollup additions.** `adm_tool_daily` already holds views/outbound/affiliate/sponsored. Add: per-day device class dimension (if D3), a "this month / this year / since start" summary, growth comparisons, and `pages per session`. Everything stays derived from raw rows; idempotent; no estimation.
- **C. Dashboard (inside `?m=analytics`, same permission, same exports).** Sections: Visitors (cards: today, yesterday, 7 days, 30 days, this month, this year, since tracking began, each with the data-quality label), Engagement (page views, sessions, pages per session, top pages, devices, sources, countries), AI tool clicks (cards for today, yesterday, 7, 30 days; ranking 10/20/50 with search so ChatGPT, Claude, Gemini, Perplexity and any tool can be found; profile views, outbound, CTR with its definition; trend charts daily and monthly; categories; featured placements; same-day funnel), "Active now" (only with recent raw rows). Premium look reuses the Unified Admin shell, inline SVG charts with table alternatives (as in Finance), light and dark, responsive, 44 px targets, keyboard accessible, zero-data and unavailable states that say what is missing and why.
- **D. Business views (descriptive only).** High-demand tools and categories, fastest-growing tools and pages (period over period, minimum-volume threshold so tiny numbers do not look like growth), tools with views but few outbound clicks (conversion bottleneck), pages with search-engine traffic (organic opportunities; Search Console integration is a separate approved item), candidate advertisers (tools with sustained outbound clicks and no CRM company or campaign; a list for human follow-up, nothing sent). All with sample sizes shown.
- **E. Tests.** Database-free (in-memory store like Finance): metric arithmetic, labels, empty states, growth rules, double-count regression, permissions, CSV safety, static privacy checks (no IP, no raw UA, no new identifiers), browser checks (axe, responsive, dark/light). MySQL checks only with a separate approval.

## 5. Database and tracking requirements
- Already in the repository: `phase2_analytics.sql` (`page_views`), `phase3_business.sql` (`events`), `aihub_interactions.sql`/live schema (`tool_clicks` with `visitor_id`, `ip_hash`), `phase8b_analytics.sql` (five rollup tables).
- New (only if approved): one additive column `device` on `page_views` (D3) or a device dimension derived without altering `page_views`; no other new tables.
- Configuration: `AIHUB_ANALYTICS=1` (off by default, never auto-enabled), `AIHUB_ANALYTICS_SALT` (32+ random characters, secret, never committed), optional `AIHUB_ANALYTICS_HASH_WINDOW`, `AIHUB_ENV_LABEL`. A consent banner is required for the browser events; page views are cookie-less.

## 6. Safe staging installation and verification plan (nothing below has been executed)
Prerequisites: owner approval, a fresh database backup, MySQL 8.0 or later, administrator access.
1. **Read-only preflight** (administrator): confirm `page_views`, `events`, `tool_clicks` exist; `tool_clicks` has `visitor_id` and `ip_hash`; `SELECT VERSION()` is 8.0+; the five `adm_*` tables are absent; count rows in the raw tables (expect zero if tracking was never on). The existing `scripts/db_preflight.php` and the staging read-only SQL can be reused.
2. **Create the tables:** run `database/phase8b_analytics.sql` once (idempotent, additive, touches no existing table). If the raw tables are missing, apply their migrations first, in order, each after review.
3. **Database permissions:** the application user needs SELECT on `page_views`, `events`, `tool_clicks`; SELECT, INSERT, UPDATE, DELETE on the five `adm_*` tables; INSERT on `page_views` and `events` for collection. The migration is run by an administrator account, not the application user.
4. **Verify without tracking:** open `?m=analytics` as owner: the message changes from "not installed" to "No tracking data yet" with the status panel (storage installed, tracking disabled). Run `php scripts/adm_rollup.php --days=2` once: it must report nothing to roll up and exit 0.
5. **Enable tracking on staging only, after separate approval:** set `AIHUB_ANALYTICS=1` and a generated salt; browse the staging site yourself with test traffic; confirm rows appear in `page_views` (no IP or raw UA), outbound click once per click (after fix A), and that DNT/GPC produce no hash.
6. **Rollup schedule:** hourly `php scripts/adm_rollup.php` (yesterday and today), plus a nightly `--days=3`; a one-time `--since-first` back-fill from real rows only. Check `adm_rollup_run` for status `ok`. Example cron (administrator installs, not us): `7 * * * * php /path/scripts/adm_rollup.php`.
7. **Reconcile:** for one test day, dashboard totals must equal `SELECT COUNT(*)` of raw human rows; exports match the screen.
8. **Rollback:** disable tracking (`AIHUB_ANALYTICS` unset), remove the cron job, run `database/phase8b_rollback.sql` (drops only the five rollup tables; raw tables untouched; rollups rebuildable with `--since-first`).
Production follows only after staging acceptance and a separate approval.

## 7. Privacy and security considerations
Page views: no IP, no raw user agent, no cookie, rotating hash, DNT/GPC honoured. Dashboard speaks of **visitor hashes and visitor-days, never identified people**; anonymous visitors are never named. The persistent `aih_visitor` cookie and `visitor_id` in `tool_clicks` need a documented purpose and consent position (D4); browser events need the opt-in banner. Country only at country level; no regions. Retention: raw `page_views` and `events` need a retention limit (proposal 13 months) with a purge script; rollups keep aggregates. Access: owner only (`analytics` permission), per-user audit of exports, CSV formula guard, no-store headers, all queries prepared. Bot figures are heuristic and labelled. Ad blockers and DNT users reduce counts; the dashboard says counts are a floor, not a census. GDPR/ePrivacy and the privacy notice should be reviewed by counsel before enabling in production.

## 8. Testing and acceptance criteria
1. Every number on screen reconciles to raw rows for a test day (staging). 2. Exactly one outbound record per click (JS on, JS off, `tracked=1`, bot, repeated loop, rate-limited). 3. No metric appears without data: zero-data and unavailable states for each card; no growth % when the baseline is zero; no "unique people" claim anywhere. 4. Date presets and custom range correct in UTC with leap and month boundaries. 5. Permissions: only the owner opens or exports; others get 403 and an audit row. 6. Privacy static checks: no IP or raw UA stored, no new identifiers. 7. Accessibility and responsive checks pass in light and dark. 8. Existing Phase 8A to 8D and Unified Admin tests still pass. 9. Rollback rehearsal succeeds.

## 9. Business use (once real data exists)
The reports are designed to surface: high-demand tools and categories (outbound clicks per tool and category), growth (period over period with minimum volume), conversion bottlenecks (profile views without outbound clicks, tools ranking by search with low click-through), organic opportunities (search-engine landing pages), featured-placement results (clicks during versus before a placement, per tool), and advertiser prospects (tools with sustained demand and no CRM relationship). These support decisions toward the EUR 979,000+ monthly ambition; they are descriptive and make no forecast and no revenue claim.

## 10. Decisions requiring your approval
- **D1** Approve fix A (single authoritative click record, remove the second `tool_clicks` write and the browser outbound event). Recommended: yes.
- **D2** New versus returning: (a) report only daily unique hashes and visitor-days, plus the consent-based returning-visit event with its bias stated (recommended), (b) switch to the weekly hash window for weekly uniques, (c) introduce a first-party visitor cookie with consent (stronger, needs banner and legal review).
- **D3** Add device category (mobile, tablet, desktop) through one additive column on `page_views`, or skip.
- **D4** Review of the existing `aih_visitor` cookie and `tool_clicks.visitor_id` retention and consent; approve a retention limit for raw rows (proposal 13 months).
- **D5** Approve the dashboard scope in section 4 (inside `?m=analytics`, owner only, no new permissions) and the "active now" panel.
- **D6** Approve the staging install in section 6: backup, migration run by the administrator, grants, hourly rollup, and (separately) enabling tracking on staging.
- **D7** Click-through definition: outbound clicks / profile views, labelled; no impression tracking now (approve, or ask for a listing-impression design).
- **D8** Whether to plan Search Console integration for organic-search opportunities (separate approval, not part of this build).
