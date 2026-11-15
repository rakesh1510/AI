# AIHubpedia Phase 2 — Global Growth Foundation

Branch `feat/aihubpedia-global-growth-v2` (built on Phase 1, `feat/aihubpedia-seo-performance-v1`). Nothing here is deployed.

| Document | Purpose |
|---|---|
| [COMPETITOR_RESEARCH.md](COMPETITOR_RESEARCH.md) | What could and could not be verified about competing directories; where AIHubpedia can win |
| [KEYWORD_STRATEGY.md](KEYWORD_STRATEGY.md) | Keyword-to-page map, prioritisation method and content backlog |
| [AI_SEARCH_READINESS.md](AI_SEARCH_READINESS.md) | Crawler policy and what makes pages citable by AI search |
| [DATA_QUALITY_WORKFLOW.md](DATA_QUALITY_WORKFLOW.md) | Repeatable process for classifying, verifying and enriching tool profiles |
| [TRAFFIC_ROADMAP.md](TRAFFIC_ROADMAP.md) | Milestones from first measurement to 1M daily visitors, with explicit assumptions |
| [MEASUREMENT_PLAN.md](MEASUREMENT_PLAN.md) | What is instrumented, what needs account access, KPIs |
| [AUTHORITY_PLAN.md](AUTHORITY_PLAN.md) | Ethical link-earning, PR and distribution plan |
| [RELEASE_RISKS.md](RELEASE_RISKS.md) | Issues that make release unsafe or need an owner decision |

Rule for every number in these documents: it is either measured in this repository, quoted with its source, or labelled an assumption.

## Verified results (isolated test DB, synthetic data, 2026-11-15)
| Check | Result |
|---|---|
| PHP syntax (146 files) | 0 problems |
| Data-quality tests (taxonomy, classifier gold set, 20 profiles, guides, sitemap, analytics, IndexNow, website-check logic) | 281 passed / 0 failed (279 without a database) |
| SEO regression suite incl. every sitemap URL (≈610) 200 + indexable + self-canonical, Phase 1 cases included | 2,791 passed / 0 failed on `php -S` and on Apache 2.4; 1,261 / 0 in no-database mode |
| Real browser (Chromium desktop 1366×900 + Pixel 7): Phase 1 flows plus guides, alternatives, evidence block, subcategories, methodology, redirect | 131 passed / 0 failed on `php -S` and Apache |
| Lighthouse (mobile/desktop) guide, alternatives, subcategory, methodology | 100/100/100/100 |
| Lighthouse reviewed tool page | perf 100, SEO 100, best practices 100, accessibility 96 |
| Server time / DB queries per page (analytics on) | home 15 ms/7, tool 13 ms/7, guide 9 ms/5, alternatives 7 ms/5 |
| Classifier on 64 hand-labelled records | high 19/19, medium 25/27, overall 47/64 acceptable |
Not verified: live vendor pages, competitor metrics, production performance, any ranking or traffic outcome.
