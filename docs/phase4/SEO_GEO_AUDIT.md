# Technical SEO and GEO audit (P4)

Method: `scripts/seo_audit.php` reads every sitemap URL of a running local instance (test database, synthetic data) and reports facts; `tests/seo_test.php` (3,148 checks incl. every sitemap URL: 200, indexable, self-canonical, JSON-LD types) is the regression gate. No ranking, traffic or citation outcome is claimed or measured. No search-engine data was available.

## 1. Audit before changes (Phase 3 code, 621 sitemap URLs)
| Check | Result |
|---|---|
| Non-200 / missing title / description / h1 / canonical | 0 |
| Duplicate titles or descriptions | 0 |
| noindex pages in sitemap / invalid JSON-LD / images without alt | 0 |
| Canonical mismatches | 0 |
| Pages without a visible breadcrumb | 17 (utility/legal pages and 5 use-case pages whose JSON-LD had a breadcrumb the page did not show) |
| Thin pages (<150 words of main text) | 8 (see below) |
| `lastmod` | Home and all category pages claimed "today" on every request: inaccurate, so search engines learn to ignore it |

Findings that mattered:
1. **Two blog posts were 17 and 43 words** ("How to Monetize an AI Tools Directory"; "Best AI Tools in 2026 (Quick Picks)" with no picks) and were indexed and in the sitemap. Thin, generic content risks a site-wide quality signal.
2. Inaccurate `lastmod` (above).
3. Use-case pages emitted breadcrumb structured data without a visible breadcrumb (structured data should reflect visible content).
4. Comparison pages for unreviewed tools were not gated (the sitemap list happened to contain only reviewed pairs).
5. Tool pages had few contextual internal links to topic pages.

## 2. Changes (Implemented, tested)
| Change | Detail |
|---|---|
| Thin posts | `post_is_indexable()` requires ≥250 words. Posts below it stay readable but are `noindex` and leave the sitemap; the blog index is `noindex` while no post qualifies. Content was not deleted: an editor should expand or remove it |
| Honest `lastmod` | Home: omitted. Categories: newest real listing date in the category, else omitted. Tools: review or added date as before |
| Evidence gates | Problem pages (≥3 reviewed tools), pricing comparisons (≥3 reviewed tools with ≥2 sources each), comparisons (both tools reviewed), newsletter archive (≥1 valid issue), recommend result URLs (always noindex) |
| Breadcrumbs | Visible breadcrumbs added to use-case pages; every new page has visible breadcrumbs + matching JSON-LD |
| Internal linking | Reviewed tool pages link to problem pages that really list them (verified by test), the matching pricing comparison, the change log and the wizard; hub pages link down to detail pages |
| Structured data | Only types that match visible content: WebPage/CollectionPage/ItemList/BreadcrumbList. **No** FAQPage, Review, Rating, Product or HowTo markup was added (FAQ is visible but rich results are not claimed; ratings would be unsupported) |
| `llms.txt` | Regenerated: new pages, how to cite evidence levels, the real count of editor-verified profiles (0 of 20) |
| Sitemap sync | `sitemap.xml` fallback regenerated; test asserts it matches the generator in JSON mode |

## 3. After changes (618 sitemap URLs)
0 non-200, 0 missing title/description/h1/canonical, 0 duplicates, 0 noindex-in-sitemap, 0 invalid JSON-LD, 0 images without alt. Pages without visible breadcrumb: 12 (home and legal/utility pages, where a trail adds nothing). Thin-by-word-count: 4, all hub/listing pages whose content is the cards (not text-thin) plus legal text.

## 4. Search-intent coverage (what exists, what is missing)
| Intent | Coverage | Gap |
|---|---|---|
| "best AI tools for <task>" | 10 problem pages (4 indexable), 4 guides | Most problems have <3 reviewed tools; more reviewed profiles are the bottleneck |
| "<A> vs <B>" | 16 comparison pairs, feature matrix | Only reviewed pairs are indexable |
| "<tool> alternatives" | 14 pages | Needs ≥3 reviewed alternatives |
| "<tool> pricing" | Tool pages + 2 pricing groups | Prices are vendor text with dates, not structured |
| industry workflows / agent implementation guides | **None published** | Templates exist; publishing needs recorded hands-on tests and a named editor |

## 5. GEO (AI-search readiness)
Principles applied: factual, sourced statements; stable crawlable URLs (no JS required, server-rendered); explicit dates and evidence levels; "Unknown" instead of guesses; sponsored content separated and labelled. `robots.txt` crawler policy (Phase 2) is unchanged. **No claim is made that any of this improves citations.** Measuring it needs referrer/crawl data over weeks.

## 6. Core Web Vitals and mobile
See TEST_RESULTS.md for Lighthouse before/after (simulated throttling, uncompressed local server: relative changes only). The feature matrix uses a stacked layout under 640px; browser tests assert no horizontal scroll on the new pages at Pixel 7 width.

## 7. Multilingual architecture plan (Proposed, nothing implemented)
- Language is a property of *content*, not just UI. Do not machine-translate the catalogue into thin pages.
- URL pattern: sub-directories (`/es/…`) on the same host; one canonical per language; reciprocal `hreflang` including `x-default`; only for pages with human-reviewed translations. Untranslated pages stay English and are not duplicated.
- Start with the highest-value evergreen pages (methodology, 4 guides, wizard, advertise) translated by a person; keep tool facts in English with translated UI labels until profiles are translated.
- Per-language sitemaps; `<html lang>` per page; language switcher that links to the equivalent page only when it exists.
- Prerequisites: legal text per jurisdiction, translated disclosure, ownership of translation review, and Search Console per language. Decide markets from measured demand, not assumption.

## 8. Unresolved
Reviewed-profile coverage (20/509) limits indexable depth; no editor has verified any profile; no Search Console data; tool pages for unreviewed tools remain template-based (policy switch `AIHUB_THIN_TOOL_POLICY=noindex` exists, default unchanged); CSP/HSTS are hosting decisions.
