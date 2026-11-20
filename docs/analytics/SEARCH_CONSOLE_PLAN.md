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

## Plan: manual CSV import first (decision D8: no account connection, no API credentials, nothing requested)
**Process (owner, manually):** in Search Console, Performance, choose a date range (up to 16 months), export. The export is a zip of CSVs: `Queries.csv`, `Pages.csv`, `Countries.csv`, `Devices.csv`, `Dates.csv`, `Search appearance.csv`, `Filters.csv`. Only the owner uploads; nothing is fetched from Google.
**Importer (CLI or owner-only upload page, to be built after approval):** validates file names, UTF-8, header row, numeric columns (Clicks, Impressions, CTR as a percentage string, Position), row cap (50,000), maximum file size (5 MB), no formulas (cells starting with `= + - @` are stored as text); stores aggregates only: `adm_gsc_import(id, imported_at, imported_by, date_from, date_to, source_file, rows)` and `adm_gsc_page(import_id, page, clicks, impressions, position)`, `adm_gsc_query(import_id, query, clicks, impressions, position)`, `adm_gsc_date(import_id, day, clicks, impressions, position)`. Re-importing the same period replaces that import (idempotent by date range). Additive migration, staging first.
**Dashboard (read-only, owner):** Google's clicks, impressions, CTR and average position per page, query and day, always labelled "Google Search Console, delayed about 2 days, queries below Google's privacy threshold are not included"; joined to our own page views by path only for context, never summed with our visits. Opportunity lists: pages with high impressions and low CTR; queries at positions 8 to 20; pages whose clicks fell versus the previous equal period (with the same baseline rules as the analytics dashboard: no percentage from a zero or incomplete baseline); tool profile pages with search demand but few outbound clicks.
**Privacy and security:** Google's export contains no personal data (aggregated queries); still keep it owner-only, audited, retained 16 months at most (Google's own window) or replaced by newer imports; no credentials exist in this step.
**Tests planned (database-free):** parser on synthetic CSVs (CTR parsing, comma and quote handling, BOM, large and malformed files, formula injection), idempotent re-import in memory, labels and baseline rules, owner-only access.
**Later, separately approved:** the API option with a read-only service account (credential handling decided then).
