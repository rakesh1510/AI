# Historical `tool_clicks` (71,502 rows): what they can and cannot report, and a privacy transition

## 1. What the historical rows can populate (verified in the isolated rehearsal)
Each row is `(tool_slug, visitor_id, ip_hash, created_at)`. They can fill a **separate, labelled "Legacy click records" report** (built, read-only, in `?m=analytics&view=legacy`): records per UTC day and per tool from 2026-03-16 to 2026-11-20, plus an estimate of **probable duplicates**.
They must **not** be used to create:
- **Visitors:** `visitor_id` is a one-year browser cookie, set only when someone clicked or voted; it is not a visitor count and is never counted (a static test asserts the dashboard code contains no `DISTINCT visitor_id`).
- **Page views, sessions, referrers, countries, devices:** not recorded in this table.
- **Entries in `adm_tool_daily`** or any new-definition outbound count: the old rows were written before the single-count fix (JavaScript clicks were written twice, about a second apart), were never bot-filtered, and cannot be separated from bots (no user agent was stored). Mixing them would inflate and misdefine the new numbers.
So: historical clicks inform **relative tool popularity and the shape of demand since March**, with the duplicate caveat, and nothing else. Growth percentages between legacy and new counts are never shown.
Rehearsal result on 71,502 synthetic rows with 30% planted double writes: the report's probable-duplicate count equals the planted number exactly, and legacy totals equal direct SQL counts. On real data the estimate will differ (clicks without JavaScript were single; clicks with a blank or changed cookie cannot be paired); it is labelled an estimate.

## 2. Privacy position of the legacy identifiers
All 71,502 rows may hold `visitor_id` (random 32-hex cookie value, no personal data by itself) and `ip_hash` (salted HMAC of the network address, 16 hex). They are pseudonymous, linkable to a browser, and no longer collected. Nothing was removed or altered. The dashboard never shows them.

## 3. Proposed transition (each phase needs approval; none executed)
- **Phase 0, done in code:** no new identifiers (cookie not set, empty columns), single-count recorder, UTC. Takes effect when the code is deployed (GATE 0).
- **Phase 1, read-only (built):** legacy report with its caveats; the preflight records how many rows hold identifiers.
- **Phase 2, archive before blanking (needs approval and one small additive migration):** the duplicate estimate depends on `visitor_id`. Before identifiers are blanked, store the **aggregate** legacy history in one additive table, for example `adm_legacy_click_daily(day, tool_slug, records, probable_duplicates)` (counts only, no identifiers), filled by one reviewed `INSERT ... SELECT` from the same query the legacy view uses, then verified against `COUNT(*)`. The legacy view then reads the archive table. This preserves the analytical value with no personal data. (Not created: it is a proposal.)
- **Phase 3, blank identifiers (needs approval, backup, dry run):** `scripts/analytics_purge.php --raw-days=90 --id-days=M` blanks `visitor_id` and `ip_hash` on rows older than M days while **keeping every row**. Proposed M = 30 days (so effectively all legacy rows). The row count, tool, and timestamp remain, so the lifetime popularity counter is unchanged. After this the fingerprint in the preflight intentionally changes: record the new fingerprint, and the `COUNT(*)` must stay 71,502 plus new clicks.
- **Phase 4, salt hygiene:** rotating `AIHUB_ANALYTICS_SALT` makes any remaining `ip_hash` values unlinkable to current addresses, but the same salt also feeds the hashes used by votes and rate limiting (`tool_votes.ip_hash`, rate-limit keys): rotate only after checking that effect (vote deduplication may reset). Not recommended as a substitute for blanking.
- **Retention thereafter:** no identifiers collected; raw `page_views` and `events` 90 days; rollups 13 months (approved for planning; validation before enabling).
Rows are never deleted by this transition; "no deletion of historical click records" holds in every phase.

## 4. Questions for the owner
Approve the archive table (Phase 2) before any blanking? Choose M. Confirm whether any affiliate agreement needs click-level records with identifiers (if so, keep a protected copy under agreement terms instead of blanking).
