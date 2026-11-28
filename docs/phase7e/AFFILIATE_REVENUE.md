# Phase 7E: affiliate revenue engine (private Control Center, "Money" > "Affiliate revenue")

Goal: turn the existing AI tools directory into legitimate, measurable affiliate revenue with the least extra machinery. Owner only (the existing `finance` permission). No paid API, no scraping, no automatic applications, no automated clicking of tracking links, no estimated earnings.

## What already existed and is reused
| Existing piece | How 7E uses it |
|---|---|
| Tool record `affiliate` URL (tool management) | Still honoured. A link activated here overrides it for that tool; deactivate and it comes back. |
| `/redirect.php` outbound click, `outbound_click.php`, the click analytics (`adm_tool_daily`, affiliate flag at click time) | Unchanged. Clicks are read from the existing rollups (recorded data only). |
| "Affiliate link" disclosure on the tool page, `outbound_rel()` | Unchanged; shown automatically whenever the tool carries an affiliate link. |
| Finance module (`admf_*`) | Received (paid) commissions are fed in as income stream "Affiliate commissions (received)". |
| CRM audit log, owner permission, Control Center shell | Every change is audited; same sign-in and navigation (Money group). |

The ONLY change to public behaviour: `catalog_overlay_tool()` now sets `$tool['affiliate']` to the approved active link (cached list, 2 minutes, failure-safe; no table or any error means the previous behaviour). Because the click, disclosure and analytics code already read that field, nothing else needed to change. No page design is touched.

## Partnerships
Company, AI tool, network (Awin, Impact, PartnerStack, CJ Affiliate, Rakuten Advertising, Direct program; more can be added; the migration inserts only these NAMES, never a partnership), application URL, program URL, status (Researching, Not applied, Applied, Pending, Approved, Rejected, Active, Suspended, Expired), commission structure (as published), recurring / one-time / unknown, currency, cookie days, payment threshold, payment schedule, terms, restrictions, last verification date and its source, notes, follow-up date. Everything except the company is optional: nothing is invented. Approved, active, rejected, suspended and expired need a note naming the source (e.g. the approval e-mail and date). The page for each partnership shows the manual application workflow; this system never applies for you.

## Links (centralised, with approval)
* A link is recorded **inactive**; visitors are unaffected.
* Structure check (no network call): https, public domain, no credentials, no IP or local host, no URL shorteners (no cloaking), not the plain official site. It does not prove the link works.
* **Activation is the approval step.** It needs the owner, a partnership that is Approved or Active, a recorded date on which the program terms (including redirect and disclosure rules) were read, a link that passes the check, and an explicit confirmation. At most one link per tool can be active (a database unique key enforces it).
* Optional tool-specific tracking id (e.g. `aihub-<tool>`), appended only if the network's tracking-parameter name has been entered by the owner from the network's own documentation. Never guessed.
* Verification is done by a person opening the link in a browser and recording Working or Broken. A server-side request could count as a click with the network, so none is made. A broken report switches an active link off automatically (visitors get the official link). An edit clears the verification and keeps the old URL in the history. Suspending, expiring or rejecting a partnership switches its links off.
* Full link change history (who, when, old and new URL, note).

## Commissions
Manual entry and validated CSV import (dry run first, then confirm). Columns: `transaction_ref, partner, tool, transaction_date, commission_amount, currency, status, status_date, payout_ref, note`. The pair (partner, reference) is unique: a transaction is never imported twice (a repeat in the file or in the database is skipped as a duplicate). Optional "update" mode advances the status of existing transactions (amount and currency must match; conflicts are reported, not applied). Statuses: Pending, Approved (not yet received), Paid (received), Rejected, Reversed; a commission cannot be paid before it is approved; Paid and Reversed need a real date. CSV export is formula-safe.

**Finance, no double counting:** only status Paid counts as income, in the month it was paid; a later reversal counts negative in the month reversed; Approved and Pending are never income. It is a separate stream, never a contract payment (do not also record the payout as a contract receipt). Currencies stay separate everywhere.

## Dashboard
Partners, approved partnerships, pending applications, monetized and unmonetized tools (managed here vs set in tool management), affiliate clicks, recorded conversions, conversion rate (conversions / affiliate clicks, indicative), pending / approved / received / rejected / reversed commissions per currency, earnings per click per currency, top earning tools, top networks, 12-month trend. States: unavailable (storage missing) is not shown as zero; "no commissions recorded yet" is not shown as zero.

## Opportunities report
Most-clicked tools without an active link (with their state and whether program facts are verified), potential partnership opportunities (3+ recorded clicks and no partnership researched), high-traffic categories with under 20% of clicks on monetized tools, active partnerships with no recorded conversion after 30 days, applications needing follow-up (follow-up date reached, or applied over 14 days ago with no date), links needing verification (never or over 30 days, reported broken, partnership not active, failing the structure check). No estimated earnings are produced.

## Database (not executed anywhere except the isolated test database)
`database/phase7e_affiliate.sql`: `aff_network`, `aff_partner`, `aff_partner_history`, `aff_link`, `aff_link_history`, `aff_commission`, `aff_commission_event`. Additive, idempotent, no foreign keys, no change to any existing table. Rollback `database/phase7e_rollback.sql` (drops only these). Before the migration: the screens say "Not installed", the public site and finance are unchanged.

## Operating cost
None: PHP, the existing database and click analytics. No subscription, API or crawler.

## Staging deployment (after approval) and rollback
1. Back up the staging database to a NEW timestamped file; keep every existing backup: `sudo mysqldump --single-transaction aihub_phase6_test | gzip > /var/backups/aihub-staging/aihub_phase6_test-pre7e-$(date +%Y%m%d-%H%M%S).sql.gz`.
2. Deploy the branch to `/var/www/html/AI-PHASE3-TEST` only (note the previous commit first).
3. Migrate: `sudo mysql aihub_phase6_test < database/phase7e_affiliate.sql`; verify seven `aff_*` tables and 6 network rows.
4. In `/admin-center.php?m=affiliate`: add one test partnership, move it through the workflow, add a link, activate it for a TEST tool, open that tool page (disclosure shown; `/redirect.php?id=<tool>` goes to the link), switch it off (official link again). Import a small CSV twice (second time: all duplicates).
5. Rollback: switch off any active link (visitors get the official links), redeploy the previous commit; optionally `sudo mysql aihub_phase6_test < database/phase7e_rollback.sql`. Production is not touched; auto-deploy stays disabled.

## Limits and risks
* Real affiliate programs differ in what they allow (redirects, click counting, disclosure wording, brand terms). The owner confirms the terms before activating; the tool cannot read them for you.
* Link verification is manual by design; a stale link is caught by the 30-day verification report, not by a robot.
* Conversion rate is indicative: networks count and attribute conversions on their own terms and may report later than clicks.
* Commissions are only as complete as what is entered or imported; a partner with no recorded conversion may simply not have been imported yet.
* The 2-minute cache means an activation or switch-off reaches visitors within 2 minutes (immediately on the same server, where the cache file is cleared).
