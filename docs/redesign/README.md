# AIHubpedia premium 3D UI — implementation report

Branch: `feat/aihubpedia-premium-3d-ui` (based on `feat/aihubpedia-analytics`). Local only: nothing deployed, no production/DB/DNS/credentials touched, no emails sent, no payment enabled.
Staging is behind Basic auth, so **all validation is local** (PHP built-in server, JSON catalogue fallback, no database).

## What changed
| Area | Files | Summary |
|---|---|---|
| Visual system | `assets/css/premium.css` (new), `assets/css/ui.css`, `scripts/build_assets.php`, built `site.min.css`/`app.min.js`/`build.json` | Violet-blue identity kept; layered CSS-3D hero cards (perspective, soft shadows, restrained pointer parallax set by `app.js`), hover elevation, limited glass, depth tokens, light/dark; static compact stack on mobile; reduced-motion respected. No WebGL/video/framework. |
| Navigation | `ui.php`, `layout_top.php`, `assets/js/app.js` | Discover · Compare · Guides · For Business · Advertise + “Discuss Your Project” (→ `/services.php#inquiry`); search toggle, favourites, theme; grouped More menu that fits the viewport, scrolls internally, arrow keys/Escape/touch (no hover dependence); mobile drawer flattens groups. |
| AI Universe hidden | `ui.php`, `layout_top.php`, `layout_bottom.php`, `index.php` | Removed from desktop/mobile nav, More, homepage and footer. Routes, code, data and admin tools untouched. |
| Homepage | `index.php`, `directory.php` (new) | Hero (search, goal shortcuts, 3D cards) → three paths → curated discovery (≤12 cards, “Browse All Tools”) → business services (API integrations, workflow automation, PHP/MySQL development, custom AI solutions) → guides/comparisons → sponsor invitation → newsletter. `directory.php`: paginated A–Z (48/page, canonical, rel prev/next, 404 past last page). |
| Cards | `ui.php` | One primary “Visit” action, quiet Compare, short “Pricing as of DATE” provenance only for reviewed tools; outbound/tracking/sponsored/affiliate attributes unchanged. |
| Enquiries | `crm_intake.php`, `services.php`, `contact.php` | Short form: goal (≥20 chars), name, email, consent; company/service/budget/timeline optional. CSRF token, honeypot, rate limits, CRM storage unchanged. “Ad placeholder (Contact sidebar)” removed. No response-time promise. |
| Measurement | `conversion.php`, `assets/js/app.js` | `cta_click` event (consent-gated, no personal data) via `data-cta`. Clicks are counted separately from enquiries, commissions and payments. |
| Tests | `tests/premium_ui_test.php`, `tests/browser_premium_ui_test.js` (new, DB-free); `browser_test.js`, `browser_ux_test.js`, `ux_logic_test.php`, `security_manifest.json` updated | |

## Test results (local)
- `php tests/premium_ui_test.php`: 10 passed. `ux_logic_test` 121, `security_inventory` 540, `security_test` 147, `i18n` 35, `discovery_quality` 98, `data_quality` 279, `content_backlog` 27, `editorial_review` 555, `analytics_privacy` 68, `analytics_config` 196 — all pass. `php scripts/build_assets.php --check`: up to date.
- `node tests/browser_premium_ui_test.js`: **278 passed, 0 failed** — 1440 / 390 / 360 px × light / dark × 10 pages: no horizontal overflow, header + CTA present, no Universe links in header/footer, no ad placeholder, header targets ≥44px, More panel fits viewport, Escape closes More/drawer, 6–12 homepage cards, no empty sections; plus reduced motion, search, no-JS visibility.
- axe (WCAG A/AA) on the key pages, desktop light + mobile dark: 0 violations.
- `tests/seo_test.php` against local server: 1584 passed, 10 failed — all `php -S` artefacts (no rewrite rules/headers), not UI regressions.
- **Local** Lighthouse (headless Chromium, localhost, no network latency — not a live figure): mobile perf 100 / a11y 100 / BP 96 / SEO 100, LCP 1.4 s, CLS 0, TBT 40 ms; desktop perf 100, LCP 0.5 s, CLS 0.
- Not run here (need MySQL): the DB-backed suites and `browser_*_test.js`; the old browser tests were updated for the new markup but **must be re-run in CI**.

## Screenshots
Before: `docs/redesign/screenshots/before/` (from the staging handover). After: `docs/redesign/screenshots/after/` (homepage 1440/390/360, light/dark; mobile menu).
Logos show alt text in local screenshots because the sandbox has no internet; not a staging defect.

## Not configured / unverified
- Newsletter `newsletter.launched` flag is unset → copy says “launch updates”, no audience figures or schedule. Set it only when real sends exist.
- No booking link, phone or WhatsApp destination configured → enquiry form only. No payment/subscription provider configured; no payment buttons enabled; monthly sponsorship is “on request”; newsletter sponsorship is not offered.
- Live staging behaviour, real mail delivery, real analytics ingestion, real affiliate redirects: unverified.
- `sitemap_lib.php`/`robots.txt` still reference Universe routes (they list pages only when verified data exists). Decision pending: leave as is, or add `noindex`/remove while hidden.
- Correction to the staging handover report: it said the homepage listed the full catalogue; the code showed 24 cards. The new homepage shows ≤12.
- Admin dashboard conversions list does not yet include `cta_click` (events are recorded; view not extended).

## Deployment checklist (needs your approval; nothing done)
1. Review the diff on the branch; run CI incl. DB-backed browser suites.
2. `php scripts/build_assets.php --check`; deploy `assets/css/site.min.css`, `assets/js/*.min.js`, `assets/build.json` with the PHP files.
3. Deploy to staging only; keep Basic auth and noindex; no DB migration needed.
4. Staging smoke: nav on all key pages, More menu, mobile drawer, enquiry form submission with test data, `/redirect.php` click counting, consent banner, light/dark.
5. Decide sitemap/robots handling of Universe routes; set `newsletter.launched` when ready.
