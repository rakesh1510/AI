# AIHubpedia Phase 3: premium UX, interactive discovery and revenue conversion

Branch `feat/aihubpedia-premium-ux-v3`, built on `feat/aihubpedia-global-growth-v2` (Phase 2), which is built on Phase 1. Nothing is merged to `main` or deployed. All results below were measured on an isolated local test database with synthetic data.

| Document | Purpose |
|---|---|
| [UX_DESIGN_SYSTEM.md](UX_DESIGN_SYSTEM.md) | Principles, tokens, components, accessibility, known limitations |
| [REVENUE_JOURNEYS.md](REVENUE_JOURNEYS.md) | Affiliate, sponsored, advertiser, newsletter, consultation and custom-build journeys; what is and is not built |
| [MEASUREMENT_EVENTS.md](MEASUREMENT_EVENTS.md) | Event dictionary, privacy model, reporting, how to read the numbers honestly |
| [RELEASE_RISKS.md](RELEASE_RISKS.md) | Blockers and owner decisions before any release |
| [screenshots/](screenshots/) | Before/after pairs and a light/dark, desktop/tablet/mobile gallery |

## Test results (final code)
| Check | Result |
|---|---|
| PHP syntax | 161 files, 0 problems |
| Built-asset freshness (`build_assets.php --check`) | up to date |
| Data-quality suite (Phase 2) | 281 passed, 0 failed |
| UX logic suite (search ranking, tokens, leads, newsletter state machine, event privacy) | 137 passed, 0 failed (118 without a database) |
| SEO regression incl. every sitemap URL, Phase 1 + 2 + 3 | 2,902 passed, 0 failed on `php -S` **and** Apache 2.4; 1,368 in no-database mode |
| Browser regression (Phase 1 + 2 flows, desktop + Pixel 7) | 135 passed, 0 failed |
| Browser Phase 3 suite (suggestions, theme, favorites, compare tray, filters, drawer, newsletter, both lead forms, consent-gated events, reduced motion, no-JS) | 194 passed, 0 failed (also on Apache) |
| axe-core accessibility (13 pages × light/dark × desktop/mobile) | 0 violations |
| Catalogue audit | 0 invalid reviewed profiles |

## Performance (Lighthouse 12, simulated mobile and desktop)
Like-for-like on Apache 2.4 with gzip (production-style). "Before" is the Phase 2 code at the start of this phase; "after" is this branch. Third-party favicon requests are blocked in both runs.

| Page | Form | Perf | A11y | Transfer | Requests | FCP | LCP | TBT |
|---|---|---|---|---|---|---|---|---|
| Home | mobile | 100 → **99** | 96 → **100** | 29 → 50 KB | 126 → 87 | 0.75 → 0.80 s | 1.30 → 1.64 s | 47 → 71 ms |
| Home | desktop | 100 → 100 | 96 → 100 | 27 → 48 KB | 126 → 87 | | | |
| Category | mobile | 100 → 100 | 100 → 100 | 27 → 50 KB | 102 → 103 | 0.75 → 0.79 s | 1.02 → 1.61 s | 6 → 55 ms |
| Tool | mobile | 100 → 100 | 96 → **100** | 24 → 45 KB | 19 → 20 | | | |
| Compare | mobile | 100 → 99 | 100 → 100 | 34 → 55 KB | 8 → 9 | | | 53 → 93 ms |
| Search results (new) | mobile | – → 100 | – → 100 | 42 KB | 17 | | | |
| Business (new) | mobile | – → 100 | – → 100 | 43 KB | 7 | | | |
| Guide | mobile | – → 100 | – → 100 | 45 KB | 7 | | | |

SEO is 100 everywhere except the search results page, where Lighthouse reports 66 because the page is deliberately `noindex`.

**What this means, honestly:** scores stay at or near 100, accessibility improved, and requests dropped, but the redesign is **heavier**: about +20 KB gzipped per page (merged CSS 12.5 KB gz and the new script 5 KB gz), LCP is roughly 0.3–0.6 s later in the throttled simulation, and total blocking time is higher. A 0.016 layout shift remains on desktop category pages (below the 0.1 "good" threshold). Without compression (the plain PHP dev server) the same pages weigh 187 KB vs 144 KB, which is why an uncompressed Lighthouse run showed a larger gap earlier (mobile 93 vs 97).

Problems found and fixed during measurement: a scroll-reveal effect that could hide content from non-scrolling renderers; low-contrast "Free" pills; 20 px link targets; skipped heading levels; invalid `aria-pressed` on links; a leftover "Ad placeholder" box on every tool page (removed); suggestions clipped by the hero; an event endpoint that rejected non-default ports; non-focusable scrollable tables.

## What was built
Redesigned header, footer and homepage; ranked database search with accessible suggestions; saved tools, compare tray, recently viewed, category filtering; light/dark themes with reduced-motion support; five revenue and lead journeys with real-information guardrails; consent-gated, privacy-first events and `scripts/business_report.php`; built/minified assets with a freshness check; new tests and CI steps. See the documents above.

## Not verified
Real email delivery (local SMTP sink only), behaviour behind your real proxy/CDN, production capacity, assistive-technology testing (automated checks and keyboard tests only), any conversion, ranking or traffic outcome, and the dark theme under Lighthouse itself (a first attempt silently measured the light theme and was discarded; dark was verified with axe-core and screenshots instead).
