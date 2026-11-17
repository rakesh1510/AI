# Content authority and SEO/GEO plan (Phase 5, Priority 3)

No ranking, traffic or AI-search inclusion is promised. This is a plan for earning them honestly and measuring the result after a separately approved launch.

## 1. Audit of indexable routes (measured on this branch)
Sitemap entries: **617** (home 1, categories 1 + 39 category pages, 509 tool pages, 17 comparisons, 14 alternatives pages, 4 problem pages, 4 guides, 3 pricing groups, 5 use cases, plus utility pages). Existing rules, unchanged: self-canonical, honest `lastmod` (omitted where it would be invented), noindex for parameterised/search URLs and thin posts, structured data only for visible content (no Review, Rating, FAQ or HowTo markup), `llms.txt` generated from the same sitemap and stating the real number of verified profiles (0).

**Finding (decision needed): 489 of the 509 indexable tool pages are template-only listings** with boilerplate titles and descriptions and bulk-generated feature text. They are indexable today. The switch `AIHUB_THIN_TOOL_POLICY=noindex` (existing since Phase 1) would remove exactly those 489 from the sitemap and mark them noindex; `tests/content_backlog_test.php` now measures that effect (617 to 128 sitemap URLs). Recommendation: decide before launch whether a scaled set of template pages should be indexed; keeping them noindex until each is researched is the lower-risk, quality-first choice. Default left unchanged because it changes what search engines can see and needs the owner's approval.

## 2. Content types, gates and the backlog
`scripts/content_backlog.php` writes `docs/phase5/editorial_backlog.csv` (**113 rows**, deterministic, tested): comparison 50, alternatives 20, task pages 23, change explainers 12, workflow guides 4, agent explainers 4. Each row has search intent, audience, proof needed, differentiation, internal links, conversion goal, publication gate, the **current gate result** from `editorial_gate()`, what blocks it, and verification state. Search volume is `unmeasured` for every row; it needs Search Console data after launch.

| Type | Gate before it may exist or be indexed |
|---|---|
| Tool vs tool | Both profiles reviewed with 2+ vendor sources, conflicts resolved or disclosed; no winner without a hands-on test |
| Alternatives to a tool | 4+ reviewed tools with sources; reasons are documented capability overlap, not popularity or payment |
| Tools for a job/problem | 3+ reviewed tools with sources |
| Step-by-step workflow and integration guides | A recorded hands-on test with dates, plus a named editor's approval. **None exists: all blocked** |
| Practical AI-agent explainers | Same; the due-diligence checklist also needs review by a qualified person |
| Vendor pricing/feature change explainers | A verified changelog entry with a vendor URL and verifier. **None exists: all blocked** |

61 rows currently pass the numeric gate but still await an editor; 52 are blocked. "Passes the gate" never means published or verified.

## 3. How to differentiate each page (so it is not thin or duplicative)
Original structure per page: the criteria stated before the ranking; each tool's evidence state and dates; unknowns shown as unknown; limitations; who it is for; what we could not verify. No text is copied from vendor or competitor sites; no AI-generated bulk copy is published without a person's review.

## 4. Search engines and AI-powered search
* **Google and Bing:** sitemap and robots are in place; submit the sitemap in Search Console and Bing Webmaster Tools after launch. `scripts/indexnow.php` exists for Bing/IndexNow notifications and was **not run** (it would contact an external service). Start with the pages that pass their gates.
* **AI-powered search (Google AI features, Bing Copilot, Perplexity, ChatGPT search):** these systems tend to cite pages that are crawlable, clearly structured, specific and corroborated. Practical steps: keep pages server-rendered (they are), put the direct answer and the evidence at the top, show dates and sources, keep structured data truthful. `llms.txt` is a convention some tools may read; it is **not** a ranking or inclusion mechanism and nothing depends on it.
* **No guarantees:** none of this ensures inclusion or ranking.

## 5. Measurement (after launch only)
Search Console impressions, clicks, queries and indexed-page counts by page type; Bing Webmaster equivalents; manual spot checks of AI answers for citation of AIHubpedia (recorded with date and query, not automated scraping). Analytics stays disabled until the privacy and legal requirements are met.

## 6. Owner actions
Decide the thin-page policy; assign editors to the P1 backlog rows; approve any new page type before it is indexed; supply real hands-on tests for workflow and agent guides.
