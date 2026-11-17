# Implementation report (Phase 5)

Branch `feat/aihubpedia-content-authority-phase5` from `feat/aihubpedia-global-growth-phase4` at `1ff5b37` (verified). Draft PR targets the Phase 4 branch. Nothing merged, deployed or published. Exact test results: TEST_RESULTS.md.

## By priority
| Priority | Delivered | Not delivered (and why) |
|---|---|---|
| Baseline | Feature-gap audit with reproducible counts (509 / 20 / 0) | – |
| 1 Editorial credibility | Per-fact states with freshness rules and expiry dates; append-only review log with checklist-gated human verification; admin queue and detail page; research tasks; ledger on tool pages; template text removed from search and comparisons | No profile was researched or verified (no live vendor access, no authorised human). Per-editor accounts: owner decision |
| 2 Discovery | 28-query fixture and evaluator; search relevance fixes (P@5 0.593 to 0.679, success@3 0.893 to 0.964, MRR 0.806 to 0.912); comparison pricing/features status rows; tests proving sponsorship, affiliate flags and click counts do not change the wizard, problem pages or comparison pairs | Role/task filters: the catalogue has no role data; needs editorial input |
| 3 SEO/GEO | Evidence-gated backlog (113 rows, CSV, tested); thin-page policy measured (617 to 128 sitemap URLs if noindex); strategy for Google, Bing and AI search without promises | No new public pages, by design: every new type is gated and none passes its evidence requirement yet |
| 4 International | Locale registry, path-prefix strategy, hreflang/canonical/duplicate validator, 35 tests; machine-translated demo retired | No routing, switcher or translated content (needs reviewed text) |
| 5 Retention | Verified existing saved tools, shortlists, recent views, compare tray, newsletter archive, mock send; no change needed | Real newsletter: needs approval and infrastructure |
| 6 B2B | Reporting-transparency section on `/advertise.php`; price audit and separate EUR proposals; media-kit structure; commercial validation plan; mock-campaign attribution tests (9 new checks) | No price or package changed; no audience claims |

## Behaviour changes to review
1. **Search:** template phrases are no longer searchable; task verbs (write, make, create, generate, find, build, get, use, help, need, want) are ignored when a more specific word remains; a reviewed profile's +10 bonus now applies only when the tool matched in name, subcategory, category or tags (else +3). Reason labels are unchanged. Tokenisation (`search_tokens`) is unchanged.
2. **Comparison page:** "Top features/strengths" no longer list template phrases; empty shows "Not documented yet".
3. **Tool page:** Evidence ledger table; unreviewed tools show an "unknown" line.
4. **`multilingual-demo.php`:** English notice instead of machine translations (still noindex).
5. **Cache fingerprints** include the review log and `editorial_review.php`.
6. **Blocked files:** `editorial_review` and `i18n` libraries added to `.htaccess` and the test router.

## Deliberate test expectation changes
None weakened. The negative-test matrices were **extended** (admin-editorial page, forged verify request, library list). A Phase 4.1 expectation that "write code" would find templates through "draft" was never asserted; the one existing tokenisation expectation (`search_tokens('Write a Blog')`) is unchanged because the verb handling was placed in the ranker, not the tokeniser.

## Before and after
| Metric | Phase 4.1 | Phase 5 | Note |
|---|---|---|---|
| Search P@5 / success@3 / MRR (28 queries) | 0.593 / 0.893 / 0.806 | 0.679 / 0.964 / 0.912 | taxonomy-judged by the team |
| Search time, median (`scripts/bench.php`) | 8.4 ms | 9.6 ms | +1.2 ms; single machine; recorded, not hidden |
| Other benchmarked paths | – | unchanged within noise | related links 0.14 ms (cached), problem page 6.5 ms |
| Top-level PHP files classified | 106 | 109 | all classified, 348 inventory checks |
| Verified profiles | 0 | 0 | by design |

## Files changed
`git diff --stat 1ff5b37`: 34 files, about 1,340 insertions. New: `editorial_review.php`, `i18n.php`, `admin-editorial.php`, `data/{editorial_review_log,locales,translations}.json`, `scripts/{search_quality,content_backlog}.php`, `tests/{editorial_review,discovery_quality,i18n,content_backlog}_test.php`, `tests/fixtures/search_quality.json`, `docs/phase5/*`. Modified: `discovery.php`, `seo.php`, `evidence.php`, `cache.php`, `helpers.php`, `tool.php`, `compare.php`, `layout_top.php`, `advertise.php`, `multilingual-demo.php`, `assets/css/ui.css` (+ rebuilt assets), `.htaccess`, `tests/router.php`, `tests/security_manifest.json`, two existing test files, `.github/workflows/ci.yml`.

## Screenshots
`docs/phase5/screenshots/`: tool-evidence-ledger, tool-unreviewed-ledger, compare-fact-status, advertise-reporting, admin-editorial-queue, admin-editorial-detail (local test server, synthetic test admin).
