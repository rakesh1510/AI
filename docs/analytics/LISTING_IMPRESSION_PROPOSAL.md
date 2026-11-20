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
