# B2B partner and revenue plan (Phase 5, Priority 6)

Nothing here activates payments, contacts a company, changes a public price or claims an audience. It builds on `docs/phase4/B2B_REVENUE_STRATEGY.md`, which holds the full price reconciliation.

## 1. Audit of what is public today
| Package | Public price | Term | Notes |
|---|---|---|---|
| Free listing | $0 | standard | manual review |
| Priority approval | $19 | one-off | review within 24 hours of a complete submission |
| Featured listing | $49 | 7 days | labelled "Sponsored" homepage section |
| Homepage spotlight | $99 | 14 days | top labelled homepage spotlight |

These come from `data/site.json` (`payment.pricing`, USD, one-off), shown on `/advertise.php` and `/submit.php`. **Unchanged in Phase 5.** No payment integration exists; the process is manual. The monthly featured placement and newsletter sponsorship appear on the site only as inquiry-only items without prices ("price on request", "not available yet: the newsletter has not launched").

## 2. Proposed, not applied (documented separately)
| Proposal | Status | What is missing before a price may be shown |
|---|---|---|
| Featured placement, EUR 199 per month | Proposal only | Owner decision on currency, VAT, minimum term; a pilot with measured outbound clicks. Roughly equals four 7-day featured runs at list price (4 x $49 = $196), but currencies and terms differ, so it is not a like-for-like swap |
| Newsletter sponsorship, EUR 499 per month | Proposal only | **A launched newsletter.** Today it only stores and confirms subscribers and sends nothing. No audience figure exists, so none may be quoted |

## 3. What the advertiser journey now says (implemented)
The `/advertise.php` page already separated editorial and paid content, listed what a placement does and does not do, covered affiliate disclosure and had an FAQ. Phase 5 adds **"What we report, and what we do not"**: no audience figures are published; any sponsorship states in writing, before it starts, what is measurable and how; raw counts with dates only; no promise of rankings, traffic, sign-ups or revenue; a sponsor cannot change a tool's facts, evidence level or recommendation position (that independence is now pinned by tests that mutate sponsorship and click data).

## 4. Package deliverables (to define in writing per deal, not on the site)
Placement and dates; label text ("Sponsored"); creative approval rules (no unsupported claims; we may refuse); the destination URL and whether it carries tracking (disclosed); what is counted (outbound clicks on our redirect, with the rate limit and exclusions explained); reporting date; cancellation. Nothing about guaranteed results.

## 5. Media-kit structure (a template to fill only with measured, approved facts)
1. Who we are (TrustNova Tech, the editorial methodology link). 2. What the site is and how listings are reviewed (counts of reviewed and verified profiles, taken live from the catalogue). 3. Placement formats with examples as labelled screenshots. 4. Editorial independence and disclosure policy. 5. Audience: **left empty until measured and approved** (state the measurement method and date range with every number). 6. Reporting you will receive. 7. Terms, contact. A fabricated or rounded-up figure anywhere invalidates the kit.

## 6. Lead capture and attribution (tested)
Landing parameters (`utm_source`, `utm_medium`, `utm_campaign`, `ref`, `tool`) are reduced to short slugs, carried into the form, stored with the lead, and reported as raw counts by source, campaign and package. The mock-campaign test (`tests/lead_reliability_test.php`) covers: parameters to form, form to stored lead, hostile and oversize values reduced to safe slugs, unknown packages dropped, report counts by source and package, and that the report has no conversion-rate or revenue fields. Failed owner notifications stay visible to the admin with a safe reason code and a retry (Phase 4.1).

## 7. Commercial validation plan (evidence before pitching real sponsors)
| Step | Evidence to have | Who |
|---|---|---|
| 1. Fix credibility | Rotate historical credentials; verify the top profiles; publish the verified count honestly | Owner, editors |
| 2. Launch the newsletter (separately approved) | Domain authentication (SPF/DKIM/DMARC), provider, privacy and consent text, a first issue sent to confirmed subscribers | Owner, legal |
| 3. Measure | 8+ weeks of confirmed-subscriber counts and click-through counts, measured without a third-party tracker unless consent and legal basis exist | Owner |
| 4. Interview | 10 target advertisers (AI vendors) about what they need; record answers, do not pitch | Owner |
| 5. Pilot | 2 or 3 low-cost pilots with agreed measurable outputs, reported honestly including poor results | Owner |
| 6. Price | Set prices from pilot results and interviews, then update the packages and the media kit | Owner |
Not before step 3: any newsletter sponsorship price; not before step 5: any monthly price.

## 8. Owner decisions
Currency and VAT; the legal operator (Impressum vs TrustNova Tech); whether to launch a newsletter; whether to keep $19/$49/$99 as they are; who answers leads and how fast (an unanswered lead is the cheapest loss).
