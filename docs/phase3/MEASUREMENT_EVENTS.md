# Business measurement: events and privacy

## Privacy model (default OFF)
| Layer | Default | Enabled by | What it stores |
|---|---|---|---|
| Server page views (Phase 2) | off | `AIHUB_ANALYTICS=1` | day, path, page type, status, referrer class, country header, rotating visitor hash, bot flag |
| Server business events | off | `AIHUB_ANALYTICS=1` | name, tool id / lead type, two short properties, **no visitor hash** |
| Client events | off | `AIHUB_ANALYTICS=1` **and** the visitor ticks "Allow anonymous usage statistics" in *Privacy choices* | same schema, plus a rotating visitor hash and a returning flag |
| Functional storage | on | user action | theme, saved tools, recently viewed, recent searches (browser `localStorage`/`sessionStorage`; never sent to the server) |

The site sets **no cookies** (asserted by a browser test). Without the visitor's opt-in the browser sends no event requests (asserted by counting requests to `/event.php`). Withdrawing consent stops events immediately. Do-Not-Track and Global Privacy Control are honoured. The endpoint requires POST, same-origin, a consent flag, a non-bot user agent and a whitelisted event name; it stores no IP, user agent or email.

Production: nothing is enabled unless `AIHUB_ANALYTICS=1` is set in the environment. This branch never sets it.

## Event dictionary
| Event | Fired | `ref` | Props | Side |
|---|---|---|---|---|
| `search_submit` | results page load | – | `source` (hero/header/results), `results` bucket (0, 1to5, 6to20, 21plus) | client, opt-in |
| `suggest_click` | suggestion chosen | – | `kind` (tool/category/guide/recent), `pos` | client, opt-in |
| `filter_use` | first use of a filter | – | `kind` (text/free/reviewed) | client, opt-in |
| `outbound_click` | click on a tool link | tool id | `affiliate` yes/no, `placement` | client (opt-in) and **server** at `/redirect.php` (affiliate flag; sponsored/organic) |
| `compare_start` / `compare_open` | tool added to tray / comparison opened | tool id | `n` | client, opt-in |
| `favorite_add` | tool saved | tool id | – | client, opt-in |
| `theme_toggle` | theme switched | – | `theme` | client, opt-in |
| `returning_visit` | first visit of a new day by someone who visited before | – | `visits` bucket (2, 3to5, 6plus) | client, opt-in |
| `newsletter_signup` / `newsletter_confirmed` | valid signup / confirmation | – | `source` page | server |
| `lead_submit` | lead stored | lead type | – | server |

Tool profile visits come from the server page views (`page_type = tool`). Advertiser and consultation inquiries are counted from the `leads` table (the source of truth), split by type.

## Reporting
`php scripts/business_report.php --days=30 [--json]` prints raw counts for: search engagement, suggestion clicks, filter use, profile visits (top 20), outbound clicks (server and opt-in), advertiser/partner vs consultation/agent/software leads, business page views, newsletter signups vs confirmations, returning-user engagement. It computes **no rates, forecasts or "improvements"**.

## How to read it honestly
- Opt-in events undercount; compare them only with each other, over time.
- Server counts (leads, subscribers, redirect clicks, page views) are complete once enabled.
- Returning-visit events exist only for opted-in visitors; the server's rotating hash cannot identify returning people by design.
- Do not quote a conversion improvement until you have a pre-change baseline period of the same length and enough events for it to mean something.
- Attribute revenue in the affiliate network/CRM, not here: the site never sees sales.

## Suggested KPI definitions (to track weekly once enabled)
Search-to-profile: `suggest_click(kind=tool)` + search results page → tool page views (aggregate, not per person). Outbound click-through: server `outbound_click` ÷ tool profile views (same period). Qualified leads: leads with status moved from `new` by you. Newsletter: confirmed ÷ signups. Business interest: `/business.php` and `/advertise.php` views vs leads submitted.
