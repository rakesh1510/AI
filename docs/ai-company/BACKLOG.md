# Company backlog

Owner: `ai-coo`. Status: `todo | in-progress | blocked | in-review | done`. "Done" for code means QA-verified.

## Phase 6 — AIHubpedia global revenue (`feat/aihubpedia-global-revenue-phase6`)

| ID | Task | Owner | Status | Depends on | Deliverable |
|---|---|---|---|---|---|
| DEV-001 | Fix CI failure: committed `sitemap.xml` out of sync with generator (`tests/phase4_logic_test.php`, 1 of 8683 assertions) | development | done | — | Already fixed by the parallel Phase 6 session in `1272812`. Development regenerated the file independently and it matched byte for byte, so no duplicate commit was made. No-DB suites pass on `452e5ea` |
| QA-001 | Independent verification of Phase 6 head `452e5ea`: full local suite, migration apply/re-apply/rollback, CRM authorization, security manifest, CI result | qa-testing | in-progress | — | `docs/ai-company/qa/RELEASE_READINESS.md` |
| QA-002 | Verify each of the eight Phase 6 priorities with evidence | qa-testing | in-progress | priority list taken from `docs/phase6/FINAL_PHASE6_IMPLEMENTATION_REPORT.md`. The CEO's master spec would confirm it | per-priority table in RELEASE_READINESS.md |
| QA-003 | Live-schema compatibility run (`tests/live_schema_compat_test.php`) | qa-testing | blocked | CEO: sanitized, data-free DDL file | result in RELEASE_READINESS.md |
| DEV-003 | CI on `452e5ea` fails in `tests/phase6_migration_test.php` on GitHub's MySQL: (1) the mysql CLI warning "Using a password on the command line" is counted as a migration error; (2) `SQLSTATE[HY000] 1137 Can't reopen table: 'a'` at line 65 (MySQL cannot self-join a TEMPORARY table). All other CI steps before it pass | development (being handled by the active Phase 6 implementation session, which is watching CI) | in-progress | — | green CI on PR #7 |
| DEV-002 | Close any gaps QA finds against the eight priorities | development | blocked | QA-002 | commits + QA re-verification |
| MKT-001 | Update the Futurepedia benchmark (`docs/phase6/FUTUREPEDIA_COMPETITOR_ANALYSIS.md` is from search results, unverified): direct observation, sources and dates; add 3–4 other directories; SEO/AI-search gap list mapped to pages | marketing-growth | todo | — | `docs/ai-company/marketing/COMPETITOR_BENCHMARK.md` |
| REV-001 | Monetization strategy building on `REVENUE_MODEL_AND_UNIT_ECONOMICS.md`: stream ranking, pricing pilots for the unapproved EUR 199/499 offers, scenario model with labelled assumptions | revenue-strategy | todo | — | `docs/ai-company/revenue/MONETIZATION_STRATEGY.md` |
| SALES-001 | TrustNova client-acquisition workflow mapped to real CRM stages, ICPs, qualification rubric, outreach/proposal draft templates | b2b-sales | todo | — | `docs/ai-company/sales/CLIENT_ACQUISITION_WORKFLOW.md` |
| PART-001 | Partnership and affiliate opportunity list (verified vs unverified), top-10 proposal outlines, onboarding checklist | partnerships | todo | — | `docs/ai-company/partnerships/OPPORTUNITIES.md` |
| COO-001 | Consolidate the above into the CEO dashboard and decision list | ai-coo | todo | all above | `CEO_DASHBOARD.md` |

Recommended order (sequential, cost-aware): DEV-001 ✓ → QA-001/QA-002 (running) → (CEO supplies the schema) → QA-003 → DEV-002. Business tasks MKT-001, REV-001, SALES-001, PART-001 are independent of code and of each other; run them one at a time when the CEO wants them, REV-001 first because pricing decisions feed sales and partnerships.
