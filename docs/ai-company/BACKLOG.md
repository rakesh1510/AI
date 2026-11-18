# Company backlog

Owner: `ai-coo`. Status: `todo | in-progress | blocked | in-review | done`. "Done" for code means QA-verified.

## Phase 6 — AIHubpedia global revenue (`feat/aihubpedia-global-revenue-phase6`)

| ID | Task | Owner | Status | Depends on | Deliverable |
|---|---|---|---|---|---|
| DEV-001 | Fix CI failure: committed `sitemap.xml` out of sync with generator (`tests/phase4_logic_test.php`, 1 of 8683 assertions) | development | done | — | Already fixed by the parallel Phase 6 session in `1272812`. Development regenerated the file independently and it matched byte for byte, so no duplicate commit was made. No-DB suites pass on `452e5ea` |
| QA-001 | Independent verification of Phase 6 head `452e5ea`: full local suite, migration apply/re-apply/rollback, CRM authorization, security manifest, CI result | qa-testing | done | — | `docs/ai-company/qa/RELEASE_READINESS.md`, verdict **not ready** on `452e5ea`. Totals: 4,542 passed, 0 failed (MariaDB 10.11 + Chromium) |
| QA-002 | Verify each of the eight Phase 6 priorities with evidence | qa-testing | done | priority list taken from `docs/phase6/FINAL_PHASE6_IMPLEMENTATION_REPORT.md`. The CEO's master spec would confirm it | per-priority table in RELEASE_READINESS.md |
| QA-003 | Live-schema compatibility run (`tests/live_schema_compat_test.php`) | qa-testing | blocked | CEO: sanitized, data-free DDL file | result in RELEASE_READINESS.md |
| DEV-003 | CI on `452e5ea` fails in `tests/phase6_migration_test.php` on GitHub's MySQL: (1) the mysql CLI warning "Using a password on the command line" is counted as a migration error; (2) `SQLSTATE[HY000] 1137 Can't reopen table: 'a'` at line 65 (MySQL cannot self-join a TEMPORARY table). All other CI steps before it pass | development (active Phase 6 implementation session) | in-review | — | Fix pushed in `f735cf6` (harness only: no password on the command line, no reused TEMPORARY tables). QA confirmed it was a harness defect, not a migration defect. CI on `f735cf6` was running when this was written |
| DEV-002 | QA-BUG-002 (medium): CRM files unrelated inquirers under one company. Matching by e-mail/website domain ignores the typed company name (`crm_sales.php:21-23`), and the free-mail list (`crm_core.php:57`) misses web.de, gmx.net, yahoo.co.in, rediffmail.com etc. A public visitor can attach a lead to a real customer's company by entering that customer's website | development | todo | CEO go-ahead (changes CRM matching rules) | commit + QA re-test |
| DEV-004 | QA-BUG-003 (low): `layout_top.php:34` replaces the CRM's `X-Robots-Tag: noindex, nofollow` with `noindex, follow`, and `crm.php` is missing from robots.txt | development | todo | — | commit + QA re-test |
| QA-004 | Re-verify on the CI-green head: full 38-step CI on MySQL 8, and re-test QA-BUG-002/003 after the fixes | qa-testing | todo | DEV-003, DEV-002, DEV-004 | update RELEASE_READINESS.md |
| MKT-001 | Update the Futurepedia benchmark (`docs/phase6/FUTUREPEDIA_COMPETITOR_ANALYSIS.md` is from search results, unverified): direct observation, sources and dates; add 3–4 other directories; SEO/AI-search gap list mapped to pages | marketing-growth | todo | — | `docs/ai-company/marketing/COMPETITOR_BENCHMARK.md` |
| REV-001 | Monetization strategy building on `REVENUE_MODEL_AND_UNIT_ECONOMICS.md`: stream ranking, pricing pilots for the unapproved EUR 199/499 offers, scenario model with labelled assumptions | revenue-strategy | todo | — | `docs/ai-company/revenue/MONETIZATION_STRATEGY.md` |
| SALES-001 | TrustNova client-acquisition workflow mapped to real CRM stages, ICPs, qualification rubric, outreach/proposal draft templates | b2b-sales | todo | — | `docs/ai-company/sales/CLIENT_ACQUISITION_WORKFLOW.md` |
| PART-001 | Partnership and affiliate opportunity list (verified vs unverified), top-10 proposal outlines, onboarding checklist | partnerships | todo | — | `docs/ai-company/partnerships/OPPORTUNITIES.md` |
| COO-001 | Consolidate the above into the CEO dashboard and decision list | ai-coo | todo | all above | `CEO_DASHBOARD.md` |

Recommended order (sequential, cost-aware): DEV-001 ✓ → QA-001/QA-002 ✓ → DEV-003 (CI) → DEV-002/DEV-004 → QA-004 → (CEO supplies the schema) → QA-003 → DEV-002. Business tasks MKT-001, REV-001, SALES-001, PART-001 are independent of code and of each other; run them one at a time when the CEO wants them, REV-001 first because pricing decisions feed sales and partnerships.
