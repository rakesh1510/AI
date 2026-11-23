# Analytics: privacy and retention assessment (updated after decision D4: option B approved and implemented)

## Update: what changed (commit after `f1cff38`)
- **Option B implemented.** Following an outbound link now sets **no cookie** and stores **no visitor identifier and no IP hash**. `record_click()` writes `visitor_id = ''` and `ip_hash = ''` (the columns must still exist because the current table shape requires them; nothing is stored in them) and no longer calls the cookie function. The vote feature keeps its own cookie, set only when someone votes. Tests: `tests/analytics_privacy_test.php`.
- **Hashed IP storage reviewed and eliminated where possible.** `tool_clicks.ip_hash` served no reporting purpose and abuse prevention does not use it: the inflation guard (20 per 10 minutes per tool and network) and the 5-second duplicate guard use short-lived counters in the server's private rate-limit directory, keyed by a salted HMAC of the network address and the tool, and are never stored with the click or in the database. Those counters remain (necessary for abuse prevention, expire within the window, no database copy). The rotating `visitor_hash` on page views is unchanged (cookie-less, daily, salted, no IP stored).
- **No alternative persistent identifier introduced.** The click path reads no cookie, sets none, hashes no IP into storage, and the user agent is read once only to exclude bots and is never stored.
- **Historical records are not touched.** Existing `tool_clicks` rows (if any) keep whatever identifiers they have until a cleanup is separately approved; see `RETENTION_AND_CLEANUP_PLAN.md`. Nothing was deleted or altered.
- **UTC everywhere.** Page views and events are now stamped with UTC dates and times (`gmdate`), the active-visitor window is computed in UTC, and reports were already UTC. If a server was set to another timezone, rows written earlier would carry local days; see "Timezone" in `STAGING_INSTALL_AND_VERIFY.md` (a documented correction needing your approval before any live data is changed). No tracking has been enabled, so no such rows are known to exist.

## Approved retention policy (for development planning; deletion not enabled)
| Data | Approved proposal | Mechanism |
|---|---|---|
| Raw analytics rows (`page_views`, `events`) | **90 days** | `scripts/analytics_purge.php --raw-days=90`, dry run by default |
| Aggregated, non-identifying reports (`adm_*` rollups) | **13 months (about 396 days)** | `--rollup-days=396`, never below 365 |
| Persistent click identifiers | **Not collected any more.** Existing ones are blanked on a schedule to be decided (proposal: 30 days) | `--id-days=M` blanks `visitor_id` and `ip_hash` on old `tool_clicks` rows; rows (counts) stay |
| Rate-limit counters | Window length (10 minutes) | automatic expiry |
Safety rule built into the script: raw rows are **refused deletion** while any older day has no rollup row, so history cannot be lost by deleting too early. 90 days is feasible operationally because the rollup runs hourly and the dashboard history comes from rollups; 13 months supports year-on-year comparison. **Validation required before enabling deletion:** counsel's confirmation that 90 days and 13 months suit the stated purposes and the privacy notice; confirmation that no legal or contractual duty requires keeping raw data longer (for example affiliate-program audit terms: check each program's agreement); confirmation of backup rotation (deleted rows persist in backups until they rotate).

---
(The assessment below is the original version from `f1cff38`; where it conflicts with this update, this update governs.)


Decision D4: review existing cookies, hashed IPs and retention, and propose minimisation, deletion and a defensible retention policy. **13 months is a proposed maximum for review, not authorised.** Nothing below is switched on, scheduled or deleted. Counsel should review before production use.

## 1. Inventory: what is stored today
| Data | Where | Identifier? | Purpose | Notes |
|---|---|---|---|---|
| Page view: path (no query), page type, status, referrer class and host, country (CDN header or `XX`), bot flag, `visitor_hash` | `page_views` | Rotating hash: first 16 hex of sha256(secret salt, **day**, IP, user agent). No IP, no user agent, no cookie. | Traffic statistics | Not recognisable across days. Do-Not-Track and Global Privacy Control: counted, no hash. Needs a 32+ character secret salt; without it no hash. |
| Device class (A4, optional column) | `page_views.device` | One word: mobile, tablet or desktop; empty for bots and opted-out requests | Device mix | Derived from the user agent at write time; the string is not stored; no version, OS, screen or other attribute, so it is not a fingerprint. |
| Events (search, compare, favourite, filters, theme, returning-visit bucket, newsletter, lead, wizard) | `events` | `visitor_hash` (same rotating hash) for browser events; none for server events | Product analytics, conversions | Browser events need the visitor's opt-in and honour Do-Not-Track. The **outbound click event is now written only by the server** (A1), without any hash. |
| Outbound click | `events` (`outbound_click`, tool slug, affiliate yes/no, sponsored/organic) and `tool_clicks` | Event: none. `tool_clicks`: **`visitor_id` (value of the 1-year `aih_visitor` cookie) and `ip_hash` (salted HMAC, IPv6 truncated to /64)** | Event: dated reporting. `tool_clicks`: lifetime popularity counter | `tool_clicks` is the one place a persistent identifier is stored for analytics-like data. The dashboard does not use it. |
| `aih_visitor` cookie | Browser, set by `get_visitor_id()` | Random 32 hex, 1 year, HttpOnly, SameSite Lax, Secure on HTTPS | Votes (one vote per visitor) and, today, clicks | It is set when someone votes **or follows an outbound link** (the click path calls it). |
| Rate-limit counters | Server files, hashed network key | HMAC of IP, short windows | Abuse prevention | Not analytics; expires with the window. |
| Rollups | `adm_*` tables | None (counts only) | Reporting | Contain no hash, IP or cookie; safe to keep long term. |

## 2. Findings and risks
1. **Persistent identifier on outbound clicks.** Following a tool link sets a 1-year cookie and stores it with a hashed IP, although nothing uses it for reporting. That is more than the cookie-less page-view design promises and is the weakest point for ePrivacy and GDPR (a cookie not strictly necessary for the click itself; the privacy notice and the "no cookies for analytics" statement must match what happens).
2. **Hashed IP is pseudonymous, not anonymous.** An IPv4 space is small; with the salt and the table anyone can test an address. Keep the salt secret, limit access, and shorten retention.
3. **Daily hash is deliberately not a person.** The dashboard says so, and no feature may chain hashes across days (decision D2).
4. **Raw rows grow without limit.** No automatic deletion exists today.
5. **Country by CDN header** is country-level only; acceptable.
6. **Consent for browser events** exists (opt-in) and Do-Not-Track is honoured; server-side counting of an outbound click stores no personal data once the identifier is removed (see option B).

## 3. Proposed minimisation (options for your decision; none implemented except those marked)
**A. Already done in A1:** one server-side count per click; no browser outbound event; bots, prefetches and rapid repeats not counted; `track-click.php` writes nothing; device is one word.
**B. Recommended (needs approval, small change):** stop calling the cookie-setting function on the click path and write empty `visitor_id` and `ip_hash` for new `tool_clicks` rows. The lifetime counter keeps working (it counts rows); the vote feature keeps its own cookie, set only when someone votes. After this, following an outbound link sets no cookie and stores no identifier.
**C. Delete what exists (needs approval of periods):** use `scripts/analytics_purge.php` (dry run by default, no default period): blank `visitor_id` and `ip_hash` on `tool_clicks` older than M days (the row stays as a count) and delete `page_views` and `events` older than N days. Rollups keep the aggregates, so history in the dashboard survives.
**D. Privacy notice:** state exactly what is collected, why, for how long, and how Do-Not-Track and the opt-in banner work; mention the vote cookie.

## 4. Defensible retention policy (proposal for review)
| Data | Proposed maximum | Why |
|---|---|---|
| `page_views`, `events` raw rows | **13 months at most; consider 90 days to 6 months** | Year-over-year comparison needs about 13 months of aggregates, not raw rows; the rollups already keep the aggregates. Shorter is safer if year-on-year is not needed. |
| `tool_clicks.visitor_id`, `ip_hash` | **30 to 90 days, then blanked** (or never stored, option B) | No reporting use; protects against re-identification. |
| `tool_clicks` rows (counts) | Kept (lifetime popularity) | Carries no identifier after blanking. |
| Rollup tables | Kept (aggregates only) | No personal data. |
| Rate-limit files | Window length | Abuse prevention only. |
| Backups | Same cycle as the database | Deleted rows leave backups when backups rotate; say so in the notice. |
Rationale: data minimisation and storage limitation; the stated purpose (traffic and tool-popularity statistics) is served by aggregates. The longer the raw period, the more must be justified.

## 5. Deletion and data-subject requests
Page-view and click data cannot be tied to a person (no IP, no stable identifier). A request to "delete my data" can be answered truthfully only for what is identifiable: the `aih_visitor` cookie value can be matched to its `tool_clicks` and vote rows if the person supplies it; an administrator can delete or blank those rows. Document this in the privacy notice and keep an internal procedure.

## 6. Access and security
Owner-only dashboard and exports (permission `analytics`), per-user audit of every export, formula-safe CSV, no-store headers, read-only queries with bound parameters, no raw user agent or IP anywhere in the dashboard code (asserted by tests), database grants per `STAGING_INSTALL_AND_VERIFY.md`.

## 7. Decisions requested
1. Approve option B (no cookie and no identifier on outbound clicks) as a small follow-up change. 2. Choose the retention periods N (raw rows) and M (identifiers); 13 months is a ceiling, not a default. 3. Approve a privacy-notice update and a legal review before production tracking. 4. Approve an annual review of this assessment.
