# Traffic roadmap: from first measurement to 1,000,000 daily visitors

## Baseline
**Unknown.** No Search Console, Bing, GA4 or server-log access was available, and production was not queried. Phase 2 adds first-party instrumentation (`AIHUB_ANALYTICS=1`, `scripts/traffic_report.php`) that separates humans from bots; **collect 30 days before setting targets.** Do not quote a baseline until then.

## Reality check
The largest figure found for the best-known AI directory (third party, 2024, unverified) is ≈3.6M visits/month ≈ **120k/day**. One million real human visitors per day would be roughly eight times that. Treat it as an ambitious, multi-year outcome that likely needs brand demand, partnerships and non-search channels as well as SEO. **Nothing here is a forecast or a guarantee.**

## Arithmetic (assumptions explicit)
Search visits ≈ impressions × CTR. With an assumed **blended CTR of 3%** (plausible range 1–8% depending on position and AI-answer displacement):

| Milestone (daily human visitors) | Daily search impressions needed at 3% CTR (if 100% from search) | Realistic channel mix (illustrative) |
|---|---|---|
| 1,000 | ~33k | ≥70% organic search, rest direct/referral |
| 10,000 | ~330k (if 70% from search ≈ 230k) | 60–70% search, 10–15% returning/direct, 10–20% referral/community/AI apps |
| 100,000 | ~3.3M (60% from search ≈ 2M) | 50–60% search, 15–20% direct/returning, 10–15% newsletter/social/referral, 5–10% AI apps |
| 500,000 | ~17M (50% from search ≈ 8M) | needs established brand, syndication partners and large returning audience |
| 1,000,000 | ~33M (50% from search ≈ 17M) | brand-led: direct/returning ≥20%, partnerships, newsletter, AI-app referrals, international |

Indexed content: if the average indexable page earns *v* visits/day, pages needed = target ÷ *v*. At v = 2, 1,000/day needs ~500 well-ranking pages; at 100k/day, 50,000. Long-tail directory pages usually earn far less than that unless they hold unique value, which is why Phase 2 invests in reviewed profiles, guides and comparisons instead of mass pages.

## Milestones, dependencies and leading indicators
| Milestone | Must be true first | Leading indicators |
|---|---|---|
| **M0 Measure** | Analytics on; Search Console + Bing verified; sitemap submitted | 30 days of data; indexed-page count vs sitemap |
| **1,000/day** | ≥100 reviewed profiles, ≥12 guides, indexing stable, first backlinks | non-branded impressions growing weekly; pages in positions 4–20 |
| **10,000/day** | ≥500 reviewed profiles, a distribution channel (newsletter or community) with ≥ several thousand subscribers, editorial links from relevant sites | returning-visitor share, referring domains, AI-app referrals |
| **100,000/day** | Top-3 for multiple head or mid-tail clusters; original research that gets cited; 10+ languages/markets considered | branded search demand, citation share in AI answers (sampled) |
| **500,000/day** | Recognised brand, partnerships/syndication, international traffic > 40% | share of direct + branded traffic |
| **1,000,000/day** | Sustained leadership across the category | all of the above, sustained |

Time estimates are deliberately omitted: they depend on authority, competition and execution that cannot be verified from here.

## Infrastructure capacity (assumptions)
Assume 3 page views per visitor → 1M visitors ≈ 3M page views/day ≈ 35 views/s average, 4× at peak ≈ 140 views/s plus assets.
- Measured now: ~10 ms PHP time and 5–7 queries per page on the test setup (Apache + mod_php + MariaDB, single VM); this does **not** include network, TLS or a production-sized dataset.
- Needed before 100k/day: CDN in front with HTML caching (short TTL, purge on change), PHP-FPM with OPcache, an application cache (APCu/Redis) for the catalogue, DB read replica or caching for votes/clicks, asynchronous writes for click/vote/analytics events, load testing on the production stack.
- Writes: votes, clicks and analytics inserts are synchronous today; move to queues at scale. Analytics table needs partitioning/pruning (`traffic_report.php --prune-days=400`).
