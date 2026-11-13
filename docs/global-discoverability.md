# AIHubpedia discoverability audit and implementation

Audit date: 2026-11-13. Repository: rakesh1510/AI.
Base: `feat/aihubpedia-global-seo-phase-2`, commit `68a3dff0c770a9c0f241c741166a21a99a24b226`.
Scope: inspected public PHP templates, helpers, metadata, sitemap files, robots.txt, blog SEO, comparison generator and deployment workflow. Repository tree inspected for routing/configuration and tests. No production database or credential files read. This is a targeted audit, not a complete security audit.

## Five highest-impact evidenced problems

| Priority | Evidence in base commit | Consequence | Implemented change |
|---|---|---|---|
| P0 | sitemap.php emits `/tools/{slug}`, `/categories`, `/compare`; templates and links use `.php` URLs. No rewrite configuration found in repository. Sitemap calls `db()` without loading db.php and catches Exception, not Error. Tool SQL does not filter publication status; every lastmod is today's date. robots.txt advertises `Sitemap: sitemap.php`. | Route inconsistency, possible fatal sitemap generation, unpublished URLs, false freshness. Actual production rewrites remain unknown. | Sitemap uses existing content loaders, actual PHP routes, published-tool filters, deduplication, XML escaping, size guard and 503 on uncaught failure; no invented lastmod. Absolute sitemap index advertised. |
| P0 | layout_top.php defaults canonical to request host/URI and never emits robots_meta. index.php emits another canonical and robots/OG tags after the head has closed; post.php emits head metadata after layout_top.php. | Duplicate metadata and query/host variants; unreliable indexing controls. | One shared canonical and robots tag in head, trusted origin, tracking removal, query identity preservation, blog head callback, safe JSON-LD serialization. |
| P1 | tool.php uses null-coalescing for seo_title/seo_description although loaders supply empty strings. category.php renders missing categories as 200, with generic description and empty image alt. | Empty metadata, soft 404s and weak category discovery. | Nonempty fallbacks, explicit tool/category canonicals, missing-category 404/noindex, breadcrumbs and category ItemList, descriptive image alt/dimensions. |
| P1 | tool.php generates product-specific features/pros/cons/use cases from category keywords and shows avg_rating_demo. homepage-trust-section.php claims weekly verified checks and displays unconditional verification badges. index.php uses free-text pricing as Offer.price. | Unsupported facts, misleading trust signals and invalid price semantics. | Only recorded product fields; demo score removed; verification gaps disclosed; unsupported blanket badges/claims removed; no fabricated Offer or aggregateRating schema. |
| P1 | compare.php runs CREATE TABLE, website fetching, and upserts during public GETs; SSL verification is disabled. Scores derive from feature counts, text signals and clicks. generate_compare_pages.php mass-produces cross-category pages. | Crawl-time side effects, latency and unreliable comparison conclusions; low-value indexed combinations. | Read-only comparison of recorded facts, sources and unknowns; no inferred winner or live fetch. Parameter combinations noindex/follow. Generator CLI-only, same-category drafts and noindex. Legacy `/compare/` pages noindex via shared layout. |

## Work completed in the branch

- Shared title/description normalization, canonical, robots, OG URL/type/site/image alt and Twitter metadata.
- Script-safe JSON-LD; minimal Organization identity without unverified founding date, profiles or email; correct existing logo path.
- Tool SoftwareApplication schema without unsupported prices, reviews or ratings; category CollectionPage/ItemList; visible breadcrumbs matching schema.
- Canonical category destination `/category.php?name=...`; existing homepage category filters remain usable with canonical consolidation.
- Deterministic comparison-pair canonicals; interactive comparisons remain usable but noindex until an editorial page is reviewed.
- Published-only direct database tool/post reads; successful no-match queries do not fall through to stale JSON. Explicit draft status is filtered in JSON fixtures. Legacy JSON entries with no status remain public for compatibility.
- Sitemap contains tool, category, post and use-case URLs. Static sitemap covers existing public landing pages. Dynamic sitemap fails above 45,000 URLs or 45 MB; implement shards before reaching that threshold. No nested sitemap indexes.
- Footer links for sponsored listings, partnerships and custom AI development; outbound affiliate link disclosure/rel on tool detail. `data-conversion` attributes are instrumentation hooks, not a tracking integration or proof of conversions.
- Optional `page_language` and explicitly supplied `published_translations` mapping. No translated pages, hreflang variants or regional targeting generated automatically.
- No database optimization, migrations, credentials, production writes, publishing, merge or deployment.

## Live observations and boundaries

A read-only HTTPS request to production robots.txt returned HTTP 200 and the same 44-byte wildcard policy with relative Sitemap directive as the repository. Public web retrieval returned the homepage. These checks do not establish full live rendering, crawlability or index coverage.

The repository deployment workflow triggers on pushes to main only. Changes are proposed on a new branch from the named Phase 2 SHA. Server rewrites, CDN/WAF, cache behavior, response headers and current deployed revision were not inspected. Do not assume repository URLs supersede live canonical redirects: verify before merge.

The existing GA tag is present in layout_top.php; account ownership, consent handling and event ingestion were not verified. No analytics statistics are reported.

## Documented crawler policy (checked 2026-11-13)

| Platform | Documented distinction and action |
|---|---|
| ChatGPT Search | OAI-SearchBot is the search crawler; GPTBot relates to foundation-model training. ChatGPT-User performs user-directed retrieval and is not the search indexing control. Keep public routes crawlable; verify official IP ranges at the firewall. |
| Google AI Overviews/AI Mode | Foundational Search eligibility, crawlable/indexable pages and snippet eligibility apply. No special AI schema/text file guarantees inclusion. Current Google documentation also describes a Search generative AI control in Search Console: check the actual property setting. |
| Gemini | Google-Extended governs both specified Gemini training and grounding uses. It is not a Google Search ranking signal. A training opt-out here can affect Gemini grounding; do not treat it as training-only. |
| Bing/Copilot | Verify Bingbot access and Webmaster Tools eligibility. Microsoft's October 30, 2026 guidance separates AI Training and AI Grounding preferences; review the transition from NoArchive/NoCache before choosing restrictions. This branch adds no restrictive AI usage preference. |
| Perplexity | PerplexityBot is the search crawler, not a foundation-model training crawler. Perplexity-User is user-initiated retrieval. Check official IP feeds and WAF rules. |
| Claude | Claude-SearchBot supports search; Claude-User supports user-directed fetching; ClaudeBot collects content potentially used for training. |
| DuckDuckGo/Yahoo | Bing indexing is relevant: DuckDuckGo largely sources traditional links from Bing and also has DuckDuckBot; Yahoo identifies Bing as its algorithmic results provider. This is not a guarantee of identical visibility. |

The wildcard public-content policy already permits these bots; redundant named Allow groups are unnecessary and can accidentally override shared restrictions. Private data/vendor/git paths are disallowed for cooperative crawlers. **robots.txt is not access control**: separately verify server-side denial/authentication for private data, installer/admin/debug routes. Do not retrieve private data files merely to test this.

Training permissions on public content are preserved, not newly granted or opted out. Obtain an explicit business policy before changing GPTBot, ClaudeBot, Google-Extended or Microsoft's training/grounding settings. Never trust a user-agent string alone as proof of crawler identity. No llms.txt or invented AI ranking metadata is introduced.

Official sources:
- OpenAI: https://developers.openai.com/api/docs/bots
- Google AI features: https://developers.google.com/search/docs/appearance/ai-features
- Google current optimization guide: https://developers.google.com/search/docs/fundamentals/ai-optimization-guide
- Google crawler controls: https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers
- Bing publisher preferences: https://blogs.bing.com/search/2026/10/your-content-your-choice-clearer-preferences-for-training-and-grounding/
- Perplexity: https://docs.perplexity.ai/docs/resources/perplexity-crawlers
- Anthropic: https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler
- DuckDuckGo sources: https://duckduckgo.com/duckduckgo-help-pages/results/sources
- Yahoo sources: https://en-maktoob.help.yahoo.com/kb/SLN35619.html

## Editorial templates and international architecture

Tool detail: original summary; provider URL; pricing amount/currency/billing interval when known; pricing source URL and human verification date; recorded features/use cases; evidence-backed pros/cons; alternatives with an explanation of fit; affiliate/sponsored disclosure. The current template shows missing pricing verification explicitly. Source/reviewer fields need a separately reviewed content model before badges can be enabled.

Category: unique explanation of the problem and selection criteria, relevant published tool list, useful subcategories, breadcrumb and internal links. Current implementation provides the list and basic introduction; original category-specific editorial guidance remains to be written.

Comparison: current page is an interactive recorded-data table. A future indexable editorial comparison must have a deliberate same-use-case selection, original testing, version/date/method, evidence for each claim and a qualified recommendation. Do not index every pair. Alternatives currently live as a linked section on each tool page; dedicated alternatives pages require unique editorial rationale, not duplicates of that list.

Translations: retain English as current content language. Add human-approved routes such as `/de/...` only after translated copy, navigation, units and disclosure are reviewed. Each variant needs a self-canonical and reciprocal hreflang entries including itself. Use one explicit translation map per entity; allow `x-default` only for a genuine fallback. Validate every map URL returns the matching language and HTTP 200. Avoid IP redirects and invented regional variants.

## Measurement setup (requires owner/account access)

1. Google Search Console: verify a Domain property using owner-managed DNS, or an HTTPS URL-prefix property using its supplied verification method. Submit `https://aihubpedia.com/sitemap.xml` after approved release. Inspect representative home, category, tool, post and comparison URLs; check canonical selection, robots eligibility, sitemap processing and current Search generative AI controls.
2. Bing Webmaster Tools: owner verifies the domain or imports an authorized Search Console property; submit the same sitemap; inspect URLs, crawl errors and AI preference settings. IndexNow can follow as an owner-authorized change notification workflow, not a ranking shortcut.
3. Analytics: confirm the existing GA4 property and consent configuration. Configure click events from `data-conversion` values: `tool_outbound`, `sponsorship_interest`, `partnership_interest`, `development_interest`. Include only page type and tool ID where appropriate; never send form content, emails or other personal data. A click is an intent signal, not a lead or sale. Record confirmed lead submission only on validated backend success; reconcile affiliate sales with network reports.
4. Build weekly reports for Google/Bing impressions, clicks, CTR and indexed eligible pages; organic sessions; tool outbound click rate; confirmed qualified leads; sponsor inquiries; affiliate conversions and revenue. Filter bots/internal traffic. Keep source and reporting period on every metric.
5. Classify observable referral hosts (e.g. chatgpt.com, perplexity.ai, claude.ai, gemini.google.com, copilot.microsoft.com) into an AI referral group, using hostname matching rather than substring tests. Referrer suppression means this is a lower-bound attribution signal. Do not claim all AI traffic can be measured or infer citations from referrals alone.
6. Run free Lighthouse/PageSpeed Insights and Search Console field CWV reports. Separate lab results from real-user p75 data; target LCP <=2.5s, INP <=200ms, CLS <=0.1 where field data exists. No CWV baseline was measured in this task.

## 30 / 60 / 90-day roadmap and acceptance criteria

These are targets and planned deliverables, not completed growth outcomes. The clock starts with an approved release.

| Window | Owner roles | Deliverables and acceptance criteria |
|---|---|---|
| Days 1–30 | Developer, QA, Analytics, SEO | Review draft and staging MySQL behavior; verify every submitted URL resolves to canonical 200 with indexable content; no blocked/noindex/redirect/404 URLs in sitemap; 0 critical failures in regression tests; verify both webmaster properties; capture a dated 28-day baseline; manually review 20 priority tool records and 5 category briefs. |
| Days 31–60 | SEO, Marketing, Revenue, QA | Prepare 5 original comparisons and 5 alternatives/use-case briefs using documented evaluation criteria; publish only after approval. Verify source/pricing dates on 100% of those pages; confirm measurement events and actual lead attribution. Research 20 relevant partnership opportunities and draft outreach without sending. Prioritize CWV fixes using measured bottlenecks. |
| Days 61–90 | Manager, Analytics, SEO, Revenue | Compare two complete 28-day periods for non-brand clicks, qualified leads and revenue; target +20% qualified organic sessions over baseline as an experiment, not a forecast. Expand only clusters that show useful engagement/conversions. Pilot one language only if demand and human review capacity are evidenced; 100% reciprocal/self hreflang checks on any approved variants. |

Release criteria: all offline tests pass; staging reproduces MySQL and web-server routing; representative sitemap URLs self-canonicalize; actual search crawlers verified in logs are not challenged; reviewed content only; production publication remains owner-approved.

Remaining blockers: webmaster/analytics access; production routing/WAF/log configuration; staging database with sanitized fixtures; review of the comparison UI replacement; editorial verification records; approved translation content. Current code still contains legacy admin/install/debug/extension surfaces and JSON fallbacks: a dedicated security and content-surface audit is required before exposing more routes. Their production exposure is not established here.

## Validation

Run `PHP_BIN=php python3 -m unittest discover -s tests -v` (PHP 8+, Python 3). Tests copy an explicit allowlist of templates into a temporary site and create synthetic JSON content. They never copy db.php, production data or credentials. No new CI workflow or billable workflow execution is added.

Nine regression tests cover generated head metadata/JSON-LD, missing/draft records, search noindex, canonical aliases/tracking, comparison reversal/read-only behavior, XML sitemap URLs, crawler-policy equivalence and language/URL helpers. PHP syntax is checked separately. These tests verify the JSON-backed path; a real MySQL execution remains a staging prerequisite. JSON-LD parsing is not a rich-result eligibility certification.

Observed results: all 9 tests passed on PHP 8.4.27 with Python 3.12; 21 inspected local PHP files passed syntax checks. Robots-policy tests cover nine user agents on public and disallowed paths and compare returned tool HTML. These are simulated user agents against a local server, not authenticated live crawlers. `git diff --check` passed.

Browser validation attempted: the Playwright-managed Chromium download failed with an invalid/truncated ZIP. Installed `/usr/bin/chromium` was then attempted and failed at startup with `socket() failed: Operation not permitted`. Therefore no successful browser screenshots or responsive visual validation are claimed. Desktop/mobile review remains required before release. No production performance benchmark, webmaster verification, rich-results certification or real MySQL integration test was completed.
