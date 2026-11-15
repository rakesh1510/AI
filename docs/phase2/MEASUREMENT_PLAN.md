# Measurement plan

## Implemented in this branch (no account access needed)
| Capability | How | Enable |
|---|---|---|
| Human vs bot traffic, daily visitors, page views | `page_views` table, user-agent bot filter | `AIHUB_ANALYTICS=1`, apply `database/phase2_analytics.sql`, set `AIHUB_ANALYTICS_SALT` |
| Referrer classes incl. Google, Bing, DuckDuckGo, Brave, Yahoo and AI apps (ChatGPT, Perplexity, Claude, Gemini, Copilot) | referrer host + `utm_source` | same |
| Traffic by country | `CF-IPCountry` / `X-Country-Code` header (needs Cloudflare or similar; otherwise `XX`) | same |
| AI crawler visibility (which bots, how often, errors) | bot rows by type/name | same |
| Broken URLs hit by people | 404 rows with requested path | same |
| Search-engine verification | `AIHUB_GOOGLE_SITE_VERIFICATION`, `AIHUB_BING_SITE_VERIFICATION` meta tags | set env vars |
| Fast Bing/Yandex/Naver indexing | `scripts/indexnow.php --init`, then `--apply` | needs the key file live on the site |
| Report | `php scripts/traffic_report.php --days=30 [--json]` | — |

Privacy design: no cookies, no IP or user-agent stored, rotating salted hash for visitor counts, Do-Not-Track and Global Privacy Control honoured. **Returning visitors cannot be measured by design** (hash rotates daily; `AIHUB_ANALYTICS_HASH_WINDOW=week` allows weekly uniques). Get legal review for your jurisdictions before enabling; first-party aggregate analytics is generally lower risk than third-party cookies, but that is not legal advice.
Limits: bot detection by user agent is heuristic and spoofable, so cross-check with Search Console and server logs.

## Requires accounts or access (not connected; nothing is claimed about their data)
| Need | Account / action | Notes |
|---|---|---|
| Organic clicks, impressions, queries, positions, country | **Google Search Console** domain property; submit `sitemap.xml`; use the Search Analytics API/BigQuery export for daily storage | Required for non-branded query and ranking distribution reports |
| Bing and Copilot-adjacent search data | **Bing Webmaster Tools** (can import from Search Console); IndexNow | Includes Bing's AI performance reports where available |
| Cross-session behaviour, returning users, conversions | **GA4** (or Plausible/Matomo) | Needs a consent banner in the EU/UK; not enabled in code |
| Affiliate conversions | Affiliate network dashboards (+ sub-ID per tool) | Outbound clicks are already counted in `tool_clicks`; add sub-IDs when affiliate programmes exist |
| Advertiser inquiries and qualified leads | Contact form + inbox/CRM tagging | Add a "reason" field to the contact form and tag in the CRM |
| Backlinks, keyword ranks | Licensed SEO tool (Ahrefs/Semrush) | For competitor benchmarking |
| AI answer citations | Licensed AI-visibility tool or a monthly manual prompt sample | Not measurable from first-party logs |

## KPI sheet (weekly)
Indexed pages / sitemap pages · non-branded clicks and impressions · pages in positions 1–3, 4–10, 11–20 · human visitors/day · AI-app referrals/day · crawler hits by bot · human 404s · share of traffic by country · outbound clicks per tool · reviewed-profile count · stale profiles · advertiser inquiries · affiliate clicks → conversions.
