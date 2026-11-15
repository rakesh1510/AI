# Competitor research

## Method and limits
- Research date: 2026-11-15, using web search (US-only results). Direct fetching of competitor sites was **blocked from the build sandbox**, and no paid SEO tool (Ahrefs, Semrush, Similarweb) is connected.
- Therefore **no competitor traffic, keyword or backlink figure below is verified**. Where numbers appear they come from third-party articles, many written by competing directories or affiliate sites, and are quoted only as context.
- Before relying on any competitor metric, pull it from a licensed tool and record the export date.

## What the search results say (secondary, unverified)
| Directory | Claims found | Reliability |
|---|---|---|
| There's An AI For That (TAAFT) | Most-cited traffic leader; one 2024 roundup quotes ~3.6M monthly visitors, another third-party estimate ~2.5M; browse by job-to-be-done; listing counts quoted from ~4,000 | Third-party, dated |
| Futurepedia | Framed as editor-curated with a large newsletter; listing counts quoted at 28,000+; reported as requiring payment to submit | Self-reported / roundup posts |
| Toolify | Framed as a high-volume search index; differentiator is showing estimated traffic and revenue of tools (sourced from estimation services, "frequently inaccurate" per one review) | Roundup posts |

Sources: [eztalks directory comparison](https://eztalks.gitbook.io/blog/ai/top-10-ai-tools-directories-of-2025-a-dual-perspective-analysis), [ToolJunction vs TAAFT](https://www.tooljunction.io/vs/theresanaiforthat), [Toolworthy list](https://www.toolworthy.ai/blog/best-ai-tools-directory), [PoweredByAI comparison](https://poweredbyai.app/alternatives/toolify). All four publishers have a commercial interest.

**Sanity check, not a fact:** the largest figure found (≈3.6M monthly visits, 2024, third party) is about 120k visitors per day. One million daily visitors would be roughly eight times that, so the target needs more than "a better directory".

## Pattern across leaders (qualitative)
1. **Breadth** — thousands of listings, mostly vendor-submitted, with thin per-tool content.
2. **Job-to-be-done navigation** — find a tool by task rather than by category name.
3. **Audience channels** — newsletters and community in addition to search.
4. **Monetisation** — paid listings, sponsored placement and affiliate links.

## Where AIHubpedia can realistically differentiate
| Gap in the field | AIHubpedia response (implemented in Phase 2) |
|---|---|
| Thin, undated, unsourced profiles | Reviewed profiles with linked vendor sources, retrieval dates, explicit **source conflicts**, and honest review status |
| Vendor-supplied superlatives | No quality ranking without a test; guides say "not benchmarked" |
| Price information goes stale silently | Every price is dated; stale-profile audit (`audit_catalog.php --stale-days`) |
| Opaque categorisation | Published taxonomy, rule-based classifier with measured accuracy and a review queue |
| Unclear commercial relationships | Methodology page discloses sponsored placements and affiliate links |
| Answer engines need citable facts | Plain HTML, JSON-LD, dated facts, comparison tables, source lists |

## Where AIHubpedia is behind (be honest)
- **Scale:** 509 listings and **20 reviewed profiles**; leaders list thousands.
- **Authority:** unknown. No backlink or brand-demand data is connected.
- **Content depth:** 4 guides and 15 alternatives pages exist; leaders have far more.
- **Distribution:** no newsletter, community or PR programme yet (see AUTHORITY_PLAN.md).

## Recommended positioning
"The AI tools directory that shows its sources": smaller than the giants but demonstrably more accurate and current for the tools it covers, expanding the reviewed set outward from the highest-demand tools. Compete on depth and trust per tool, not on listing count.
