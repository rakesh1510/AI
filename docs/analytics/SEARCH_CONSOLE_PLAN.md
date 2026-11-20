# Planning: Google Search Console integration (decision D8, planning only)

No account has been accessed, no property verified, no credential created or requested, no API called. This evaluates feasibility and design for a later, separately approved step.

## Why
Our own analytics see only visits that arrive. Search Console shows what happens **before** the click: queries, impressions, clicks and average position per page and country. It is the only genuine source of organic-search impressions and CTR for AIHubpedia pages (the missing "impressions" for search), and it identifies growth opportunities (pages with high impressions and low clicks, queries ranking 8 to 20).

## Options
1. **Manual CSV export** from the Search Console UI into the Control Center (owner uploads a file). No credentials stored, no API, lowest risk, but manual and limited to the export row caps.
2. **Search Console API with a service account** added as a user on the property (read-only "restricted" access), scheduled CLI job storing daily aggregates in new tables (`adm_gsc_daily(day, page, query_group, country, device, impressions, clicks, position_sum)`). Pros: automatic, history beyond the interface's 16 months once collected. Cons: credential handling (a JSON key on the server), Google API quotas, delayed data (about 2 days), and sampling or anonymised queries (rare queries hidden).
3. **BigQuery bulk export** (Search Console to BigQuery). Most complete, but needs a Google Cloud project, billing and BigQuery reads: out of scope without spending approval.
**Recommendation:** start with option 1 (a CSV importer that validates and aggregates, owner only), then option 2 once the owner approves credential handling.

## What the dashboard would add
Per page and per tool profile: impressions, clicks, CTR and average position from Google (labelled as Google's figures, with the delay), joined to our own views by path; "opportunity" lists (high impressions, low CTR; positions 8 to 20; pages that lost clicks versus the previous period, with the baseline rules already used in analytics). Never merged into visitor counts: Google clicks and our visits differ (consent, ad blockers, redirects, bots) and the two are shown side by side with an explanation.

## Security and privacy
Read-only scope (`webmasters.readonly`); key stored outside the web root and outside git, file mode 600, rotated on staff change; nothing personal is received (queries are aggregated by Google); store only aggregates; owner-only; audited imports.

## Preconditions and approvals needed
Owner access to the Search Console property (verified domain); decision on option 1 versus 2; where the credential would live (environment secret via the platform's credential facility, never in the repository); new tables (additive migration, staging first); a retention rule; separate approval before any account access.

## Open questions
Is the property verified as a domain property or URL prefix? Which hostnames (apex, www, staging excluded)? Do we need international targeting reports? Who is the technical owner of the Google account?
