# Architecture and implementation (Phase 6)

Stack unchanged: server-rendered PHP (tested on 8.3 locally; written to run on 8.4: no deprecated constructs, explicit types), MySQL/MariaDB through PDO prepared statements, progressive-enhancement JavaScript only where it already existed. No new dependency, no framework, no external service.

## Layers
| Layer | Files | Responsibility |
|---|---|---|
| Schema | `database/phase6_crm.sql`, `phase6_rollback.sql` | Additive tables (see DATABASE doc) |
| Core | `crm_core.php` | Roles and permissions, user creation, sessions, CSRF, audit log, strict input and date parsing, money helpers |
| Sales | `crm_sales.php` | Companies, contacts, lead ingestion, duplicate detection, qualification, assignment, conversion, opportunities, pipeline rules |
| Money | `crm_money.php` | Proposal totals (integer arithmetic), versions, approvals, contracts, revenue entries, revenue classification |
| Growth | `crm_growth.php` | Campaign workflow, partnerships, prospects, outreach drafts, consent |
| Metrics | `crm_dashboard.php` | Dashboard figures with honest empty states |
| Intake | `crm_intake.php` | Maps public inquiries to CRM records; project and partner validation and forms |
| Interface | `crm.php`, `crm_views.php` | Sign-in gate, action controller, views |
| Public | `services.php`, `project-inquiry.php`, `partners.php`, `partner-apply.php`, `advertise.php` (extended), `inquiry.php` (CRM hook) | Landing pages and forms |
| Data | `data/trustnova_services.json` | Original service descriptions (no clients, cases or results) |
| CLI | `scripts/crm_user.php` | Create/deactivate CRM users; password from standard input |

## Key decisions
1. **Own identity for the CRM.** The old single-password admin cannot say who did what. The CRM has `crm_users` with roles and per-user audit. Users cannot be created from the web, so a web compromise cannot mint an administrator; the first owner is created from the command line on the server. There are no default accounts (tested).
2. **Authorization in the library, not only the UI.** Every mutating function takes the acting user and refuses when the role lacks the permission. Roles: `owner` (all, including approvals and revenue), `sales` (create and edit, assign, outreach drafts), `viewer` (read). Tests exercise this directly and over HTTP.
3. **Public inquiry first, CRM second.** The existing `leads` flow (validation, honeypot, time trap, rate limit, notification) runs unchanged; the CRM write is best effort inside a transaction and logged on failure, so an unmigrated or failing CRM never breaks a public form.
4. **Integer money.** Amounts are minor units; rates are basis points; half-up rounding is done with integer division; limits (10,000 units, 10 million per unit, 50 lines) keep intermediate values inside `PHP_INT_MAX`. A static test forbids `float` and `round()` in the money code. This design caught a real overflow during development.
5. **Revenue vocabulary is enforced by structure.** Proposals, forecasts, contracts, invoices, receipts and recognized revenue come from different tables or states; a draft contract and an accepted proposal contribute nothing to MRR until an owner attests a signed agreement.
6. **No sending.** The CRM libraries contain no mail or network calls (tested); outreach drafts have no "sent" state.
7. **Advertising cannot touch editorial output.** Ranking, recommendation, evidence and sitemap code contain no reference to CRM data (tested by source scan), and the earlier tests that mutate sponsorship and click data still pass.
8. **Fail safe on missing infrastructure.** No CRM tables: 503 "not set up". No users: 503 "disabled". No default credentials anywhere.

## Request flow (public form)
validate (`lead_validate` + service/category checks) → rate limit → store in `leads` → `crm_intake_record` (transaction: company, contact, lead, activity, audit, campaign or partner record) → owner notification (existing, mock sink in tests) → redirect to a thank-you page that echoes no personal data.

## Request flow (CRM)
`crm.php` → `crm_gate()` (tables, users, sign-in, session, idle timeout) → POST: CSRF + same-origin → `crm_handle_action()` → library function (permission check, validation, transaction, audit) → 303 redirect with a result code; GET: whitelisted view name → render. Output is escaped; opening a lead is logged without the address.

## Things deliberately not built
Payments, invoicing, subscriptions, automated billing, email sending, AI lead matching, enterprise marketplace (all P4; need approval).

## Files changed outside the new modules
`conversion.php` (4 new lead types, form action option), `inquiry.php` (CRM hook, back link), `advertise.php` (types and definitions), `layout_bottom.php` (links), `seo.php` and `sitemap_lib.php` (new pages, canonical param), `.htaccess` and `tests/router.php` (new libraries blocked), `scripts/db_preflight.php`, tests and CI.
