# Test results (Phase 4 and 4.1)

## Phase 4.1 final results (supersede the Phase 4 counts below)
Isolated local environment only (MariaDB test database, `php -S`, Apache 2.4 + mod_php, local SMTP sink, synthetic data). The same suites run in GitHub Actions (MySQL 8) on every push; see the pull request for the run on the final commit.

| Suite | `php -S` | Apache 2.4 + gzip | Notes |
|---|---|---|---|
| PHP syntax | 190 files, 0 problems | – | |
| Built-asset freshness | up to date | – | |
| Data quality (Phase 2) | 281 | – | |
| Catalogue audit | 0 invalid profiles | – | |
| Security unit (`security_test.php`) | 147 | – | adds rate-directory safety |
| **Security inventory + secrets scan** | **338** | – | 106 PHP files classified; 543 tracked files scanned |
| Phase 4 logic (`phase4_logic_test.php`) | **8,683** (8,657 without a database) | – | adds cache correctness/invalidation, synthetic 2,000-tool catalogue, editorial queue, logo/third-party checks, mail error handling |
| UX logic (Phase 3) | 138 | – | |
| **Database compatibility scenarios** | **252** | – | 7 synthetic databases incl. "no database"; migration plans applied to copies |
| SEO regression incl. every sitemap URL | 3,148 | 3,150 | 618 URLs |
| Security HTTP (`security_http_test.php`) | 75 | 75 | |
| **Negative HTTP** (`security_negative_http_test.php`) | **123** | **123** | hostile barrage with state snapshots, forms abuse, spoofed headers, admin brute force |
| **B2B lead reliability** (mock mail) | **48** | **48** | |
| SEO/GEO audit gate | 0 issues | – | |
| Browser regression (desktop + Pixel 7) | 135 | – | |
| Phase 3 UX/conversion/privacy | 196 | – | +2: newsletter confirmation is now two-step |
| Phase 4 browser (wizard, problem pages, matrix, advertiser) | 100 | 100 | desktop, mobile, no-JS |
| axe-core (22 pages × light/dark × desktop/mobile) | **0 violations** | – | audit now waits for scroll-reveal fades to settle (a CI-only timing flake in the previous version) |
| Business report | runs | – | |

Bugs found *by* the new suites and fixed in this phase: blocked-file gaps, placeholder extension pages, unauthenticated-looking demo login, GET-triggered newsletter confirmation, click inflation, session cookie flags, mail Host poisoning (Apache only), `/redirect.php` HTTP 500 without a database, old-shape interaction tables, silent rate-limit disabling on an unsafe directory (Apache only), invisible notification failures. See SECURITY_VERIFICATION_REPORT.md.
Two existing expectations changed deliberately: `/login.php` is now 404 (demo disabled), and the Phase 3 browser test follows the two-step newsletter confirmation.

Performance: PERFORMANCE_BENCHMARK.md. Database: DATABASE_COMPATIBILITY_REPORT.md. Lead workflow: B2B_FORM_RELIABILITY_REPORT.md.
Not verified: real mail delivery, real proxy/CDN, nginx rules, vendor dependency vulnerabilities, load/capacity, assistive technology, the live database.

---
## Phase 4 results (historical)

All results are from an **isolated local environment**: MariaDB test database (name contains "test"), `php -S` with `tests/router.php`, local SMTP sink, **synthetic data only**. Nothing touched production, staging, hosting or real customers. The same suites run in GitHub Actions on MySQL 8 (see the PR for the run). Counts are checks, not test cases.

## Existing suites (no regression)
| Suite | Result |
|---|---|
| PHP syntax | 181 files, 0 problems |
| Built-asset freshness | up to date |
| Data quality (Phase 2) | 281 passed |
| Catalogue audit | 0 invalid reviewed profiles |
| UX logic (Phase 3) | 138 passed (118 without a database) |
| SEO regression incl. every sitemap URL (Phase 1–3 + new pages) | **3,150 passed** (618 sitemap URLs: 200, indexable, self-canonical) |
| Browser regression (Phase 1/2 flows, desktop + Pixel 7) | 135 passed |
| Phase 3 UX/conversion/privacy browser suite | 194 passed |

Two existing expectations were **deliberately changed** because behaviour changed by design: `browser_ux_test.js` checked for the old `.priceCard` markup (now the package table), and `seo_test.php` expected `/blog.php` and the thin post to be indexable (now noindex). `ux_logic_test.php`'s multi-word partial-match query was changed because synonyms now (correctly) match "write blog" fully.

## New suites
| Suite | Result | What it covers |
|---|---|---|
| `tests/security_test.php` | 144 passed | CIDR/IP-proxy logic, spoofed headers ignored, redirect and URL validators (60+ hostile inputs), CSRF tokens/origin, rate limiter, log hygiene (no raw IP/secret), admin hash handling and lockout, source scans (no hard-coded secrets, no `display_errors`, forwarded headers only in `security.php`, install scripts CLI-only, every `$_POST` consumer guarded), no DB fallback credentials |
| `tests/security_http_test.php` | 75 passed | Against a running server: GET inertness, CSRF/origin on every write endpoint, XSS escaping, honeypot, rate limit with rotated spoofed forwarding headers, blocked files (16 paths), redirect behaviour, submit validation, admin login/cookie flags/CSRF/logout, lead admin masking/authorization/status changes |
| `tests/phase4_logic_test.php` | **8,032 passed** (8,008 without DB) | Search reasons and synonyms, evidence levels (stale, conflicts, unsafe sources), capability documented-vs-unknown, matrix, alternatives, problem-page gates, packages/prices, lead qualification and attribution, status workflow, reports, editorial independence, wizard (inputs, determinism, honesty rules, page), change log validation, newsletter issues/roundup/mock delivery, content templates/gates/linter, SEO gates, sitemap honesty, llms.txt, internal links |
| `tests/browser_phase4_test.js` | 100 passed | Desktop, Pixel 7 and no-JS: wizard flow with keyboard, results explanation/evidence/pricing, hostile input, problem pages and gates, relevance reasons, evidence matrix (stacked on mobile, never a bare "No"), tool evidence panel, alternatives, advertiser page (no € prices/testimonials, FAQ, deep links), no horizontal scroll, no JS errors |
| `scripts/seo_audit.php` (CI gate) | 0 issues | 618 sitemap URLs: no non-200, missing title/description/h1/canonical, duplicates, noindex-in-sitemap, invalid JSON-LD, images without alt |
| axe-core (`tests/axe_audit.js --strict`) | **0 violations** | 22 pages × light/dark × desktop/mobile (adds wizard form/results, problem pages, matrix, pricing comparison, change log, alternatives, archive). Two contrast failures found on the new pages (`--ink-soft` 4.48:1 on shaded rows) were fixed |

## Performance (Lighthouse 12.2, simulated throttling, local **uncompressed** `php -S`)
"Base" = Phase 3 commit `50c6348` served the same way; "new" = this branch. Third-party favicon requests blocked in both. Only relative changes are meaningful; Phase 3's absolute numbers were measured on Apache with gzip and are not comparable. Single runs (no medians): differences of a few hundredths of a second are noise.

Mobile (perf / LCP s / TBT ms / transfer KB):
| Page | Base | New |
|---|---|---|
| Home | 93 / 3.06 / 72 / 187 | 93 / 3.08 / 76 / 194 |
| Category | 94 / 3.00 / 60 / 215 | 92 / 3.23 / 47 / 222 |
| Tool | 99 / 1.95 / 40 / 115 | 99 / 2.06 / 0 / 125 |
| Compare | 98 / 2.10 / 89 / 179 | 98 / 2.10 / 122 / 189 |
| Search ("transcribe") | 99 / 1.99 / 18 / 109 | 98 / 2.40 / 15 / 141 (more results now match through synonyms: 45 requests vs 17) |
| Advertise | 100 / 1.65 / 61 / 105 | 100 / 1.65 / 44 / 119 |
| New: recommend / results / problem page / change log / pricing | 99, 99, 98, 100, 100 | LCP 1.65–2.33 s, CLS 0 |

Desktop: every page scores 100 (home, tool, compare, category, advertise, new pages); category CLS 0.016 is unchanged from Phase 3. Accessibility 100 on all pages (the pricing page scored 97 before the contrast fix). SEO 100 except `search.php` and wizard results (66 because they are deliberately `noindex`). **Cost of this phase:** +7 to +14 KB per page (evidence panel, matrix, new CSS/JS), category LCP +0.2 s on throttled mobile, tool pages unchanged in LCP terms, compare TBT +33 ms. Tool pages for reviewed tools now run ten problem searches (≈140 ms CPU in PHP) to build related links; this should be cached before production (see Open items).

## Not verified / not tested
- Real email delivery (local sink and file outbox only); real newsletter sending is not implemented.
- Behaviour behind the real proxy/CDN; `AIHUB_TRUSTED_PROXIES` was tested logically, not on a real edge.
- `.htaccess` changes (new blocks, header list) were mirrored in the test router but **not re-validated on Apache 2.4 in this phase** (Phase 3 was).
- Production capacity, caching, TTFB; Lighthouse on compressed Apache; dark-theme Lighthouse (dark checked with axe only).
- Assistive-technology testing with real screen readers (automated checks and keyboard tests only).
- Penetration test, dependency vulnerability scan (`vendor/` not audited), load tests.
- Any ranking, citation, traffic, conversion or revenue outcome.
- The competitor site (not reachable from this environment).

## Open items found by testing
1. Related-link computation on reviewed tool pages should be cached (or precomputed into a data file) before launch.
2. The two thin blog posts are noindexed, not fixed: an editor must expand or remove them.
3. Reviewed coverage (20/509) and zero editor verification limit what the wizard, comparisons and problem pages can say.
