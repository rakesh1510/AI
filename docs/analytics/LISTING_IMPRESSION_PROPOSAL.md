# Proposal: genuine listing-impression tracking (separate from the approved outbound / profile-view ratio)

Status: proposal only; nothing built. The dashboard's "Outbound / profile views" is **not** a click-through rate and is never labelled as one (decision D7). A true CTR needs impressions: how many times a tool card was actually shown.

## What an impression would mean
A tool card (listing, category, search results, recommendations, comparison, featured slot) that was **rendered in the visitor's viewport** for a minimum time (for example 50% visible for 1 second). Server-side rendering alone is not an impression: pages below the fold, prefetched or bot-rendered pages would inflate it.

## Options
1. **Browser viewport events (IntersectionObserver), batched.** One small script observes cards, sends one batched event per page view (tool slugs and placement name only, no identifier) after the consent and Do-Not-Track checks already used by `event.php`. Pros: genuine impressions, per placement. Cons: needs the visitor's opt-in (the existing banner), so counts cover only consenting browsers, a biased sample; ad blockers drop some.
2. **Server-side "rendered" counts.** Count tool cards in the HTML response. Pros: no consent, no JavaScript. Cons: counts cards never seen; not an impression; would overstate and must not be called CTR.
3. **Sample-based CTR.** Impressions from consenting browsers only, paired with the clicks from the **same consenting** browsers (click events with the same consent), reported as a CTR for that sample with its size. Honest, but small.
**Recommendation:** option 3 built on option 1, shown only when a minimum sample is reached, with the sample size and "consenting browsers only" printed next to every rate. Option 2 is not a CTR and is not proposed.

## Design sketch
Event `card_impression` (new, client-only, consent-gated): properties slug, placement (`listing`, `category`, `featured`, `compare`, `recommend`). Rollup into a new daily table `adm_tool_impression_daily(day, tool_slug, placement, impressions, consenting_clicks)` (additive, same pattern as 8B). Dashboard columns: impressions, sample clicks, sample CTR with minimum-sample rule (for example 200 impressions), per placement, and featured-placement CTR versus organic.
Volume control: batch per page view, cap at 40 cards, dedupe per page view, aggregate in the rollup, short raw retention.

## Risks
Consent bias; double counting on infinite scroll or back navigation; bots running JavaScript (filtered by the same user-agent heuristic, imperfect); heavier event traffic. Impact on performance must be measured before release.

## Decisions requested
Approve option 3 for planning; minimum-sample thresholds; whether featured slots must be measured first; schedule after the analytics dashboard has real data.

## Design update (decision: design work only; nothing built, no tracking added)
**Principles.** No personal identifier, no cookie, no device id, no IP in the event or the stored row. The event carries only tool slug, placement name and a page type. Impression counts are never shown without their definition, their consent basis and a minimum sample.
**Definition (the only one used):** a tool card is an impression when at least 50% of it has been visible in the viewport for at least 1 continuous second, once per card per page view. Cards below the fold, in collapsed sections or in background tabs are not impressions. A card re-shown by scrolling back is not counted again in the same page view.
**Event (client, consent-gated, Do-Not-Track honoured):** one batched `card_impressions` request per page view, at most 40 cards: `{page_type, placement, slugs[]}`; accepted only from same-origin and non-bot agents; rejected if the batch is malformed. Server increments counters only (no row per impression): `adm_tool_impression_daily(day, tool_slug, placement, impressions)`.
**Anti-inflation rules:** ignore requests without a prior page view in the same minute (no hash needed: check the page-view rate limit bucket); cap batches per network; drop duplicates within a batch; exclude bots by the existing classifier; a rate guard per network and placement.
**Click side for a true CTR:** clicks must come from the same consenting population. The redirect cannot know consent (it stores nothing about the visitor), so the CTR is computed as consenting-browser clicks (an opt-in `card_click` event with the same slug and placement) divided by consenting-browser impressions, shown as "sample CTR (n impressions, consenting browsers only)". The existing outbound / profile views ratio stays separate and labelled.
**Reporting rules:** minimum 200 impressions per tool and placement before a rate is shown; below that, "not enough data"; never extrapolate to all visitors; featured versus organic compared only within the same placement.
**Tests planned (database-free):** viewport maths on a synthetic DOM (Playwright), once-per-page-view dedupe, batch cap, consent and Do-Not-Track suppression, bot exclusion, no identifier in payload or table, minimum-sample display rule, no use of the word CTR for the outbound ratio.
**Open decisions:** consent banner wording and default; placements to measure first (featured slots); sample thresholds.
