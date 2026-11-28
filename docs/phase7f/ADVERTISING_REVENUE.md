# Phase 7F: advertising and sponsorship revenue engine (Control Center > Advertising)

Goal: acquire real advertisers and turn each one into recorded, recurring revenue, on top of what already exists. No paid API, no payment subscription or processor, no scraping, nothing is sent to anyone, no public price or page is changed.

## What already existed and is reused
| Existing | Use in 7F |
|---|---|
| CRM company, contact, lead, advertising enquiry (a `crm_campaigns` row), disclosure-label + safe-landing-URL approval gate | A booking belongs to a CRM campaign and company. Content approval is the existing owner gate. |
| CRM opportunity > proposal > contract > revenue entries; Finance report with an "Advertising" view | Agreed money lives there. A booking creates the chain once; invoices and payments are recorded as revenue entries, so Finance already reports them and nothing is entered twice. Refunds are Finance refunds. |
| Tool management placements (`adm_tool_feature`, mirrored into the tool's featured level and end date; shown on the homepage labelled "Sponsored") | The ONLY public placement mechanism. Activation calls it; the placement ends on the campaign end date. |
| Click analytics (`adm_tool_daily`: views, outbound and sponsored clicks) | Measured performance. |
| Existing listed packages (`data/sponsorship_packages.json`, site payment settings) | Shown read-only for reference; never edited or re-priced here. |
| Phase 6 prospects, outreach drafts, consent and unsubscribe | Outreach drafts for advertising prospects. |

## Packages (configurable; nothing public changes)
Five proposed packages are seeded as **proposals, not published**: Featured Tool (49 USD, 30 days, level-1 featured placement, 3 slots), Category Sponsor (149 USD/month, exclusive per category, no public slot exists yet so booking/billing/reporting only, marked not available), Homepage Spotlight (299 USD/month, exclusive, level-2 placement), Newsletter Sponsor (99 USD/issue, marked not available because the newsletter has not launched and no audience figure exists), Custom Enterprise (price on request). The owner edits price, currency, duration, billing, placement, slots, exclusivity, scope, availability and description. Marking a package "available" only allows bookings; publishing a price on the public site is a separate owner decision. A test asserts the existing public price files are byte-for-byte unchanged.

## Advertisers and bookings
An advertiser is an existing CRM company; the profile adds account manager, internal notes and associated AI tools. A booking records package, tool or category, requirements, dates, agreed price and currency, billing, renewal date, optional manually created https payment link, account manager and notes. Existing enquiries are adopted (one booking per enquiry).

## Lifecycle and safeguards
Draft, Enquiry received, Proposal sent, Awaiting payment, Scheduled, Active, Paused, Completed, Cancelled, Expired; stages cannot be skipped.
* **Awaiting payment** needs the owner-approved campaign (disclosure label + safe landing URL), dates, price, and no inventory conflict (inventory is reserved from here).
* **Scheduled** (owner) needs full payment received, or an explicit owner reason (audited, shown on the booking), and no conflict.
* **Active** only through the owner's explicit approval (checkbox). If the start date is in the future the approval is recorded and the daily housekeeping starts it on the start date; nothing is published earlier. Pause, complete, cancel or expiry take the placement down at once.
* **Expiry is automatic**: the placement ends on the campaign end date, so the tool stops being featured even if nobody runs anything; the housekeeping (button, or `ad_sweep()` from the command line; no scheduler is installed) then marks the booking Expired and ends the placement record.
* **Conflicts**: capacity per package (and per category for category sponsorship), exclusive packages hold one booking per scope, and one tool cannot have two overlapping public placements (the existing tool-management rule). Enforced when moving to Awaiting payment, Scheduled, Active and when editing a held booking.
* **Independence**: activation writes only the featured level and its end date. A test compares every editorial field of the tool (name, descriptions, features, pros, cons, pricing, website, affiliate link, category, SEO fields, status) before and after and finds them identical; reviews, evidence and organic scores are not touched. Every placement is shown with the existing "Sponsored" label.

## Money
Expected, invoiced, received and refunds are separate, per currency, never converted. Payment state is derived (not stored): no agreement, agreement not confirmed, not invoiced, awaiting payment, partly paid, paid, refunded. The agreement button (owner) creates the CRM advertising opportunity, a one-line proposal and a DRAFT contract once per booking; the owner separately confirms the signed agreement (the existing rule) and only then can money be recorded. A reference is required for every invoice or payment and cannot be reused, so a duplicate revenue entry is impossible. Recurring sponsorships use monthly contracts and a renewal date; renewals due appear on the dashboard.

## Reports
Per campaign: duration, days elapsed and remaining, outbound clicks and sponsored clicks inside the campaign window (never before it, never future days), tool page views, payment state; category campaigns use clicks on all tools in the category and say so. **Impressions are not measured by the site, so impressions and click-through rate are reported as unavailable and are never estimated.** Counts come from the cookie-free analytics, which exclude visitors who block tracking or send Do-Not-Track/Global Privacy Control, and are a floor. Printable page (browser Print > Save as PDF) and a formula-safe CSV of all campaigns.

## Dashboard and opportunities
Active advertisers and campaigns, available placement slots, pending enquiries, outstanding payments, received revenue, expected (agreed, not yet received), upcoming renewals (45 days), expiring campaigns (14 days), revenue by package and by advertiser, 12-month trend of received money. Missing data and confirmed zero are different states. Opportunities: most-clicked tools and high-traffic categories (measured, 90 days), available inventory, advertisers eligible for renewal (campaign ended or ending soon with no later booking), companies with recorded advertising enquiries, and your own researched advertising prospects. Drafts are editable text created from the existing template; they are refused for prospects marked not a fit or archived, or whose company has an unsubscribed contact; the existing opt-out line is required; nothing is ever sent.

## Database (not executed anywhere except the isolated test database)
`database/phase7f_advertising.sql`: `ad_package`, `ad_booking`, `ad_booking_history`, `ad_advertiser`, `ad_advertiser_tool`. Additive, idempotent, no foreign keys, no change to any existing table (a test checksums them). Rollback `database/phase7f_rollback.sql` drops only these; CRM, finance and tool placements stay. Before the migration the screens say "Not installed" and the existing hub still works. Tool placements need the existing tool-management tables (Phase 8C); without them activation reports that tool management is missing.

## Operating cost
None: PHP, the existing database, CRM, analytics and finance. No payment processor (payment links are optional URLs you create yourself).

## Staging deployment (after approval) and rollback
1. Back up the staging database to a NEW timestamped file; keep every existing backup: `sudo mysqldump --single-transaction aihub_phase6_test | gzip > /var/backups/aihub-staging/aihub_phase6_test-pre7f-$(date +%Y%m%d-%H%M%S).sql.gz`.
2. Deploy the branch to `/var/www/html/AI-PHASE3-TEST` only (note the previous commit).
3. `sudo mysql aihub_phase6_test < database/phase7f_advertising.sql`; verify 5 `ad_*` tables and 5 packages, none published.
4. In Control Center > Advertising: add a test company booking, approve the CRM campaign content, move to Awaiting payment, create and confirm the agreement, record an invoice and payment, schedule, approve activation for a TEST tool, check the homepage shows the labelled Sponsored listing, pause (it disappears), complete.
5. Rollback: cancel or pause active bookings (placements come down), redeploy the previous commit, optionally run `phase7f_rollback.sql`. Production untouched; auto-deploy stays disabled.

## Limits and risks
* No impression measurement exists, so reports show clicks and page views only; do not promise advertisers impressions or click-through rates.
* A public Category Sponsor slot and the newsletter do not exist; those packages are marked not available and can be sold only as bookings once built.
* The existing placement mechanism uses the real current date; the housekeeping must be run (button or command line) for scheduled starts and for the Expired status, although the placement itself already ends on the end date.
* Payment status depends on invoices and payments being recorded; there is no bank feed.
* Whether sponsored placement is legal and sufficiently disclosed in each market is the owner's responsibility; the existing "Sponsored" label and the independence rule are kept.
