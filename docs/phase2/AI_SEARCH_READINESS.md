# AI search readiness

## Crawler behaviour (from vendor documentation as reported by search on 2026-11-15)
Primary pages could not be fetched directly, so details below come from search snippets and secondary summaries. **Re-check each vendor page before changing policy.**

| Service | Crawlers / tokens | Purpose | robots.txt behaviour (reported) |
|---|---|---|---|
| OpenAI | `OAI-SearchBot` | ChatGPT search index | Honoured; token to manage search opt-outs |
| | `GPTBot` | Model training | Honoured; blocking it does not remove a site from ChatGPT search |
| | `ChatGPT-User` | User-initiated fetches | OpenAI docs say robots rules may not apply (language was removed in Dec 2025) |
| Anthropic | `Claude-SearchBot` | Search indexing | Honoured |
| | `ClaudeBot` | Training data | Honoured; blocking it does not affect the other two |
| | `Claude-User` | User-initiated fetches | Reported as honoured per Anthropic's support article |
| Perplexity | `PerplexityBot` | Search index | Honoured; Perplexity recommends allowing it to appear in results |
| | `Perplexity-User` | User-triggered fetches | Reported as generally ignoring robots.txt |
| Google | `Googlebot` | Search incl. AI Overviews / AI Mode | Standard robots; Google says no special AI markup is required |
| | `Google-Extended` (token) | Gemini training use | robots token, not a separate crawler |
| Microsoft | `Bingbot` | Bing index; Copilot answers commonly draw on Bing (verify) | Standard robots |

Sources: [OpenAI crawler docs](https://developers.openai.com/api/docs/bots), [Anthropic crawler article](https://support.claude.com/en/articles/8896518-anthropic-crawlers), [Perplexity crawlers](https://docs.perplexity.ai/docs/resources/perplexity-crawlers.md), [Google: AI features and your website](https://developers.google.com/search/docs/appearance/ai-features), [IndexNow documentation](https://www.indexnow.org/documentation).

## What Google says (important)
Search coverage of Google's guidance says site owners should focus on **unique, non-commodity content**, and that chunking, AI-specific rewriting, llms.txt and over-optimising structured data for AI are not needed. Phase 2 follows that: investment goes into original evidence (sources, dates, conflicts) rather than tricks. `llms.txt` is retained only as a low-cost courtesy for tools that read it; **do not expect it to affect Google**.

## Current robots policy (Phase 1, unchanged)
All search and AI-search crawlers are allowed on public pages; action endpoints, install scripts, private folders and `redirect.php` are disallowed. Training-related agents (`GPTBot`, `ClaudeBot`, `Google-Extended`, `Applebot-Extended`) are currently **allowed**.

**Owner decision:** allowing training crawlers does not affect search visibility per the vendors' documentation. If you prefer to opt out of training use, disallow those four tokens only; keep `OAI-SearchBot`, `Claude-SearchBot`, `PerplexityBot`, `Googlebot`, `Bingbot` allowed. We did not change this.

## What makes a page citable
| Requirement | Implemented |
|---|---|
| Content in plain server-rendered HTML (no JavaScript needed) | Yes (PHP) |
| Facts with source links and retrieval dates | Reviewed profiles, guides, alternatives |
| Conflicts disclosed instead of guessed | `known_source_conflicts` shown on the page |
| Explicit method and corrections path | `/methodology.php` |
| Answer-shaped sections (tables, FAQ, "best for") | Guides, alternatives |
| Accurate structured data (no invented ratings) | `SoftwareApplication`, `Article`, `ItemList`, `BreadcrumbList`; ratings only from real votes |
| Authorship / publisher | "AIHubpedia Editorial Team", Organization schema |
| Freshness signals | `dateModified`, review date on every profile |
| Crawl efficiency | sitemap with `lastmod`, IndexNow script |

## Monitoring
- Crawler visits by bot type/name and errors: `php scripts/traffic_report.php` ("Crawler activity").
- Referrals from ChatGPT, Perplexity, Claude, Gemini, Copilot: referrer classes `ai_*` (also detects `utm_source`).
- Citations themselves cannot be measured with first-party data; sample prompts monthly (manual or a licensed AI-visibility tool) and log which AIHubpedia URLs are cited.
