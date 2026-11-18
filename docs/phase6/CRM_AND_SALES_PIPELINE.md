# CRM and sales pipeline (Phase 6, priority 2)

Internal, authenticated, audit-logged. Address: `/crm.php` (noindex, no-store). Not linked from the public site.

## Access
* **No default accounts.** Until a user exists the page answers 503 "CRM is disabled". Create the first owner on the server: `printf '%s' "$PASSWORD" | php scripts/crm_user.php add <username> "<Name>" owner` (password from standard input, at least 12 characters, stored as a hash). Deactivate with `deactivate <username>`. Users cannot be created or promoted from the web.
* Roles: **owner** (everything, including approvals, contracts, revenue records, audit log, user list), **sales** (create and edit records, qualify, assign, notes, tasks, drafts), **viewer** (read only; edit forms are not shown and server-side writes are refused).
* Sessions: HttpOnly, SameSite=Strict, Secure on HTTPS, id regenerated at login, 30-minute idle and 8-hour absolute limits; the user is re-read from the database on every request, so deactivation or a role change applies at once. Login is rate limited (5 per 15 minutes per network, not evaded by forged forwarding headers) and answers identically for unknown users and wrong passwords.
* Every POST needs the session CSRF token and a same-origin request; state never changes on GET (tested).

## Data model (see DATABASE doc)
Company → Contacts → Leads (three kinds: advertising, partnership, TrustNova development) → Opportunity → Proposals (versions) → Contract → Revenue entries; Activities, Tasks and the Audit log attach to any record.

## Pipeline
`New → Qualified → Contacted → Discovery → Proposal → Negotiation → Won | Lost`
* Forward only, any later open stage; **Won only from Negotiation** and only with an amount and a valid currency; **Lost from any open stage** and only with a reason; closed is final. The complete transition matrix is tested.
* Every stage change is recorded with user and time.
* Forecast weights per stage (5, 15, 25, 35, 50, 70 percent) are **planning assumptions, not measured win rates**, and are labelled hypothetical wherever shown.

## Lead handling
* **Source tracking:** page, `utm_source/medium/campaign` slugs, tool reference.
* **Qualification:** four yes/no/unknown answers (need, budget, timeline, decision-maker) give a transparent score 0–4; a person decides qualified or disqualified (disqualifying needs a reason). Only qualified leads convert to opportunities (one transaction; a failure changes nothing).
* **Assignment:** owner or sales can assign to an active user; the list can be filtered by assignee, kind, status, source, duplicates and free text (wildcards and SQL fragments are inert).
* **Duplicate detection:** same normalised email (database unique key), same website domain, same company name; free-mail domains never merge companies; the lead is flagged "possible duplicate of #n" and **nothing is merged automatically**.
* **Follow-ups:** tasks with due date and assignee, overdue flag on the dashboard.
* **Notes and history:** per record; no credentials or card data should be pasted (stated in the form).
* **Budget range and expected value:** lead budget bucket plus opportunity amount in a chosen currency.
* **Proposal linkage and outcomes:** proposals hang off opportunities; won/lost outcome, value and lost reason stored.
* **Audit:** logins, ingestion, assignments, qualification, conversion, stage moves, proposal and contract steps, approvals, consent changes. Summaries are scrubbed of email addresses and never contain credentials (tested).

## Privacy
Only business contact details the person submitted. Inquiries are stored as "inbound inquiry" (a basis to reply to that inquiry), **not** marketing consent. The list masks addresses; opening a lead is logged with the id only. Retention and erasure routines are an owner decision (see RELEASE_RISKS).

## Limits
No password reset, no MFA, no per-record visibility rules, no import/export, no merge tool (by design: avoid silent data loss).
