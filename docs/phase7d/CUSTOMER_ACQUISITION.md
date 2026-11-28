# Phase 7D: corporate customer acquisition and prospect management (private CRM)

Goal: find, research and win real corporate customers, with the least possible extra tooling. A "prospect" stays the Phase 6 `crm_prospects` record; this phase adds the company-level profile, scoring, workflow, CSV, outreach drafts and a dashboard on top (`crm_acquisition.php`, `crm_acq_views.php`). No paid API, no scraping, no automatic sending, no public-site change.

## Principles
* **Recorded data only.** Nothing is fabricated or inferred: an unrecorded input scores 0 and is listed as "still to research". Estimated project value is an internal estimate with a mandatory basis, labelled as such everywhere (page, CSV, dashboard) and never counted as revenue.
* **Company level only.** Research fields refuse e-mail addresses and phone numbers. A person's contact data enters the CRM only at conversion, from what the company itself sent.
* **Nothing is sent.** Drafts are text to review, personalise and copy. There is no sending code in this feature (a test checks that).
* **Permission and suppression are enforced, legality is not decided by the tool.** Marking a company "contacted" needs a recorded permission basis (they asked / consent / existing relationship / business channel, with a note) and a company that is not suppressed. Marketing rules differ by country and channel (for example unsolicited e-mail to named individuals is restricted in Germany even for B2B); manual sending does not bypass consent requirements. Check local law, ask counsel where unsure.

## Prospect profile
Company, website, country (name or any ISO code; worldwide), region, industry, company size, business requirements, potential automation opportunities, relevant services (8 TrustNova lines), budget information (unknown / public signal / stated by the company), urgency (unknown / planned / urgent, with source), estimated value + currency + basis, research notes, public information sources (https only, with check date, max 8), owner, next action and follow-up date.

## Transparent score (0-100; planning assumptions, not win rates)
| Input | Max | Points |
|---|---|---|
| Service relevance | 20 | 1 service 10, 2 services 15, 3+ 20 |
| Business requirements | 15 | any 5, 80+ characters 10, 200+ 15 |
| Automation potential | 15 | any 5, 40+ 10, 120+ 15 |
| Company fit | 15 | size 1-10: 5, 11-50: 10, 51-200: 13, 201-1,000: 15, 1,000+: 12 |
| Budget information | 15 | public signal 8, stated by the company 15, unknown 0 |
| Project urgency | 10 | planned 5, urgent 10 (source required), unknown 0 |
| Research quality | 10 | sources (1: 3, 2+: 5) + basics (website, country, industry: up to 3) + notes 100+ chars 2 |
Bands: Priority 70+, Promising 45-69, Research more below 45. The prospect page lists every reason, the points and the missing inputs.

## Workflow and history
Identified, Researching, Qualified, Contact planned, Contacted, Responded, Consultation requested, Converted to lead, Not interested, Do not contact. Steps cannot be skipped; every change is stored with who, when and a note. Not interested and Do not contact need a reason. Do not contact is final: it adds the domain to the suppression list (new prospects on that domain start as Do not contact, open drafts are archived); only the owner can lift a suppression, with a reason.
**Conversion** needs "Responded" or "Consultation requested" and creates exactly one CRM lead from the contact the company gave us (a unique key on the link makes a second conversion impossible, also for parallel clicks). The lead then follows the Phase 7B/Phase 6 lead process.

## CSV (company level)
Export: `/crm.php?v=prospects&export=csv` (read permission, audited, spreadsheet-formula-safe, estimates labelled). Import: company-level columns `company, website, country, region, industry, company_size, services, requirements, automation_opportunities, estimated_value, currency, estimate_basis, research_notes, source_url, next_action, next_action_date` (only `company` required; labels or keys accepted; `;` or `,` delimiters; UTF-8; max 500 rows / 1 MB). Every import is a dry run first (nothing saved), reports each line, skips duplicates (same website domain or same normalised name, in the database or earlier in the file) and needs a second confirmation. Existing CRM companies are flagged, not blocked. Exporting and re-importing a file is safe: every row is a duplicate.

## Outreach drafts (English and German)
Templates for all eight services, factual (no client names, results, guarantees or statistics). `{{company}}` is filled from the record; `{{name}}`, `{{observation}}` (a specific, public observation you write), `{{sender}}` and `{{imprint}}` (legal notice) must be completed by a person: a draft cannot be marked Reviewed while any `{{placeholder}}` remains. Every draft keeps the opt-out line (STOP / STOPP). Drafts are refused for suppressed or not-interested companies; a draft shows a warning when no contact permission is recorded. A Copy button copies subject and body.

## Dashboard (`/crm.php?v=acquisition`)
Total researched companies (beyond Identified), qualified prospects, prospects awaiting action, companies contacted, responses, consultations requested (these three are cumulative from the status history), converted leads, conversion rate (converted / contacted; "Not enough data" until someone was contacted), open opportunities by industry, country and service (counts and internal estimated value per currency, never summed across currencies), overdue follow-ups.

## Database (not executed anywhere except the isolated test database)
`database/phase7d_acquisition.sql` (after `phase6_crm.sql`): `crm_prospect_profile`, `crm_prospect_status_history`, `crm_prospect_suppressions`. Additive, idempotent, no foreign keys, no change to existing tables. Rollback `database/phase7d_rollback.sql` (drops only these; Phase 6 prospects, drafts and contacts stay). Before the migration the prospect page shows the Phase 6 records and says what is missing.

## Operating cost
None: PHP, the existing database, CSV files. No subscriptions, no data-provider or scraping service.

## Staging deployment (after approval) and rollback
1. Back up the staging database to a new timestamped file (keep every existing backup): `sudo mysqldump --single-transaction aihub_phase6_test | gzip > /var/backups/aihub-staging/aihub_phase6_test-pre7d-$(date +%Y%m%d-%H%M%S).sql.gz`.
2. Deploy the branch to `/var/www/html/AI-PHASE3-TEST` only (`git fetch origin feat/phase7d-customer-acquisition && git checkout --detach origin/feat/phase7d-customer-acquisition`; note the previous commit first).
3. Migrate: `sudo mysql aihub_phase6_test < database/phase7d_acquisition.sql`; verify `SHOW TABLES LIKE 'crm_prospect%'` returns three tables.
4. Check `/crm.php?v=acquisition` and `/crm.php?v=prospects`; add one test prospect and move it through the workflow; export the CSV.
5. Rollback: redeploy the previous commit; optionally `sudo mysql aihub_phase6_test < database/phase7d_rollback.sql` (leaving the tables is harmless). Production is not touched; auto-deploy stays disabled.

## Limits and risks
* Scores rank effort, they do not predict purchases; they are only as good as the research recorded.
* "Awaiting action" is a defined set of statuses, not a time-based measure.
* Duplicate detection is by domain and normalised name; very different spellings or subsidiaries can slip through.
* The permission basis is the user's statement; the tool cannot verify consent or law.
* Prospect-to-lead conversion needs the contact data the company supplied; there is intentionally no contact-finding feature.
