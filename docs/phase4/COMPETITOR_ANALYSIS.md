# Competitor benchmark: Futurepedia (P6)

## Scope and honesty about method
Constraint: use **publicly observable features only**, no copying of content, design or code.
**What could be observed:** the sandbox could not resolve `www.futurepedia.io` (DNS blocked), so no page was loaded, measured or screenshotted. The only information is what a web search returned as public page titles and summaries (listed in Sources). Everything else is marked *not verified*. No traffic, ranking, speed or conversion comparison was possible. Any figure below is **claimed by Futurepedia**, was not verified, and differs between pages and dates according to the search result.

## What the search results showed (claimed by Futurepedia, unverified)
| Area | Observation |
|---|---|
| Directory | Describes itself as the largest AI tools directory; its AI Tools page lists 2,500+ "verified" tools in 70+ categories |
| Newsletter | Issues with new/trending tools; mentions a growing archive and an automations directory |
| Saved lists | Signed-in users can keep a Favorites list in a profile |
| Submission | Paid listing tiers; a "Verified Listing" at a one-time $497 including an enhanced page and a verified check mark; a Basic tier shown as sold out; review in batches |
| Advertising | Newsletter sponsor slots promoted using audience figures (200k+ readers in one issue; "millions of followers" in another) |
| Disclosure | States it may receive compensation for some links |

Sources: [Futurepedia home](https://www.futurepedia.io/) · [Submit page](https://www.futurepedia.io/submit-tool) · [AI Tools](https://www.futurepedia.io/ai-tools) · [Discover](https://www.futurepedia.io/discover) · [newsletter issue](https://newsletter.futurepedia.io/p/10-ai-tools) (via search results, not fetched).

## Comparison grid
"AIHubpedia" is verified by tests in this repository. "Futurepedia" is *not verified* unless stated.

| Dimension | AIHubpedia (this branch) | Futurepedia | Takeaway |
|---|---|---|---|
| Homepage clarity | Search-first hero, three journeys, real data only; Lighthouse a11y 100 (Phase 3) | Not verified | Cannot compare |
| Catalogue breadth | 509 tools; **20 reviewed with sources**; 0 editor-verified | Claims 2,500+ tools in 70+ categories | They claim far larger breadth. Breadth is not our edge; evidence is |
| "Verified" meaning | Four evidence levels with dates and sources; says plainly that none is editor-verified yet | A "verified" label/tier exists; criteria not verified (the paid tier suggests the label may be purchasable) | Our differentiator is transparent, non-purchasable evidence. We must verify profiles before we can claim "verified" |
| Discovery | Ranked search with reasons, synonyms, ten problem pages | Search/discover/category pages exist; mechanics not verified | Reasons-per-result is distinctive |
| Comparison | Feature matrix with Unknown vs Documented, evidence per tool | Not verified | Distinctive if more profiles are reviewed |
| Personalization | Six-question deterministic wizard with explanations, no account | A "Discover" page and profile favorites claimed; not verified | We need no account; they likely rely on one |
| Trust and transparency | Paid/editorial separation table, disclosure, counts-only reporting | States affiliate compensation; separation of paid listings from ranking not verified | Comparable disclosure intent; ours is enforced by tests |
| B2B inquiry | Package comparison without prices for unlaunched items, FAQ, qualified lead form, admin report | Paid listing and sponsor offers; published prices/audience claims | They publish price and audience claims; we publish none because we have no verified audience. This costs us short-term conversion and protects trust |
| Newsletter | Double opt-in, archive page, mock sending only; **not launched** | Established newsletter and archive (claimed) | Large gap. Do not sell sponsorships until launched and measured |
| Mobile | Browser-tested at Pixel 7 width; matrix stacks | Not verified | – |
| Accessibility | axe-core 0 violations (Phase 3); keyboard/radio tests | Not verified | – |
| SEO architecture | Evidence gates, honest lastmod, breadcrumbs | Not verified | – |
| Returning visitors | Saved tools, saved shortlists, recently viewed (browser-only); change log | Accounts/favorites, newsletter, YouTube claimed | They have audience channels we lack |

## Where AIHubpedia can credibly differ (and what it will cost)
1. **Evidence you can inspect** (levels, dates, sources, unknowns). Cost: editors must verify profiles; until then the honest label is "sourced, awaiting verification".
2. **Explainable, non-pay-to-play recommendations** (deterministic, tested to ignore payment).
3. **Problem-first discovery** with vendor questions.
Not credible today: size, audience, newsletter reach, or any "#1" claim.

## Next steps (Proposed)
Review more profiles (each new reviewed profile widens every feature), have an editor verify the 20, then pilot sponsorships with measured click counts before publishing prices. Re-run this benchmark with direct, permitted access to the site (add it to the environment allow-list) so homepage, speed and structure can be compared on evidence.
