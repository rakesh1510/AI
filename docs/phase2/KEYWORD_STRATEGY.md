# Keyword-to-page strategy

**No search-volume or difficulty numbers are included because none could be verified** (no keyword tool or Search Console access). Prioritise using the method below once data is connected.

## Principles
1. One page per search intent; never a page per keyword variant.
2. A page earns indexation by containing facts competitors lack (sources, dates, conflicts, limits).
3. Head terms are authority goals; win long-tail and comparison intents first.

## Prioritisation score (fill from Search Console + a keyword tool)
`score = relevance (0-3) + commercial intent (0-3) + own-data coverage (0-3) + ranking feasibility (0-3)`. Feasibility = can we reach page one with content and links we can realistically produce? Start with the highest-scoring cluster whose data coverage is already 3.

## Map: intent → page → status
| Intent cluster | Target page | Status |
|---|---|---|
| "best AI chatbots / assistants", "ChatGPT vs Claude" | [Guide: chatbots](../../guide.php?slug=best-ai-chatbots-and-assistants), compare pages | **Live** |
| "ChatGPT alternatives", "Claude alternatives", "Otter alternatives" (+10 more) | `/alternatives.php?tool=…` (15 pages) | **Live** |
| "AI meeting transcription tools", "Otter vs Fireflies" | Guide: meeting transcription, compare pages | **Live** |
| "Perplexity alternatives", "AI research assistants", "chat with PDF" | Guide: search & research, alternatives pages | **Live** |
| "AI tools for office work", "Microsoft 365 Copilot vs Copilot" | Guide: office work | **Live** |
| "How AIHubpedia reviews tools" (trust/E-E-A-T support) | `/methodology.php` | **Live** |
| "Best AI coding assistants" | New guide + category hub | **Blocked: catalogue has no mainstream coding assistants** (needs onboarding of tools such as the leading IDE assistants, with sources) |
| "Best AI tools for customer support" | New guide | Blocked: needs reviewed profiles (catalogue has a handful of unreviewed entries) |
| "AI marketing automation tools", "AI sales agents" | New guides | Blocked: same |
| "AI video generators", "AI image generators" | New guides | Blocked: category has 2 and 9 unreviewed entries |
| "Free AI tools" | Filter on pricing type + guide | Needs verified free-tier data per tool; start with the reviewed set |
| "Best AI agents for small business" | Guide | Blocked on agent profiles; also high risk of low-quality competition, so verify differentiation first |
| "Best AI tools" (head term) | Homepage + categories | Long-term authority goal |

## Content engine (repeatable)
1. Pick the next cluster by score.
2. **Onboard** 5–8 tools: add to the catalogue, write a curated profile with ≥2 vendor sources each (`data/curated_profiles.json`; validate with `php scripts/audit_catalog.php`).
3. Write the guide in `data/guides.json` (criteria, table fields, picks, checklist, FAQ). The comparison table is generated from the profiles, so facts live in one place.
4. Add alternatives (≥3) so alternatives pages appear automatically and enter the sitemap.
5. Regenerate sitemap/llms.txt (`php scripts/build-seo-files.php`), submit via Search Console, ping IndexNow (`scripts/indexnow.php --apply`).
6. Re-verify every 60–90 days (stale audit); record the date.

## Duplicate-page guard
- Compare URLs are canonicalised (A-vs-B = B-vs-A).
- Alternatives pages exist only with ≥3 reviewed alternatives.
- Subcategory pages are indexable only with ≥3 tools; the "Other" bucket is `noindex`.
- Thin-tool policy switch `AIHUB_THIN_TOOL_POLICY=noindex` removes unreviewed template pages from the index and sitemap once Search Console shows they earn nothing.
