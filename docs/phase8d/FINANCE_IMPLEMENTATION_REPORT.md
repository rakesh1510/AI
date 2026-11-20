# Phase 8D Finance: implementation report (F1 to F5)

Branch `feat/aihubpedia-finance-phase8d` (from the Phase 8C tip `9e063ad`). Plan: `FINANCE_PLAN.md` (approved at `004d96e`, decisions F1 to F8). Nothing deployed, merged or migrated; no database test run; no production, staging or Hostinger access; no payment, invoice, email or customer contact; Phase 8H and 4.2 not started.

## What was built, by milestone
| Milestone | Delivered |
|---|---|
| **F1 Core and Overview** | `adm_finance_core.php` (pure maths and the action engine), `adm_finance_store.php` (store interface, in-memory store, MySQL store), migration `database/phase8d_finance.sql` and rollback (not executed), the Finance module live in the Control Center (owner only), and an Overview with eight tiles, three charts and a data-quality panel. Every figure carries a data-state label; missing data reads "No revenue is recorded in EUR yet", never zero. |
| **F2 Revenue reporting** | Revenue per currency (invoiced, credit notes, received, refunds, net income, outstanding, contracted, recognized, stage-weighted pipeline), by month and by stream; Advertising and sponsorship view (streams, campaign pipeline, open quotes labelled as estimates, renewals); Projects and retainers view (retainer MRR, one-off projects, concentration, contract list); legacy listing payments in a separate section that is excluded from CRM totals; six audited CSV exports. |
| **F3 Expenses and profit** | Record, void (reason required, nothing deleted) and list expenses in the approved categories (one-off, monthly, annual with optional end); cash-basis profit and loss per currency; expenses by category; recurring expenses against MRR; runway shown as unavailable. |
| **F4 Targets and scenarios** | The approved ladder (EUR 10,000, 50,000, 100,000, 250,000, 500,000, 979,000 per month) monitored from December 2026 against recorded income and MRR, with "no recorded data", "not reached" and "reached" states; owner month-specific targets that supersede with history kept; a scenario calculator (customers, contracts, subscriptions, partnerships, optional mix and close rate) labelled hypothetical, nothing stored, nothing assumed. |
| **F5 Charts, refunds and polish** | Inline SVG charts (grouped bars, line, horizontal bars, progress bar) with titles, descriptions, hover tooltips, legends, data-table alternatives, direct labels where useful, light and dark palettes validated with the visualization skill's checker; refunds and credit notes (record, limits against received and invoiced, void); responsive layouts, 44 px targets, reduced motion; screenshots; this report. |

## Decisions applied (F1 to F8)
- F1: CRM and legacy tables are read only (SELECT); three new tables; no ALTER. F2: legacy payments shown separately and never added to CRM totals; every received figure says "Recorded manually, not bank-verified". F3: refunds and credit notes with audit and void; nothing deleted. F4: streams derived from CRM fields only (opportunity kind, campaign package, billing period); no new contract management. F5: "VAT: Not tracked" on every screen and export. F6: the seven approved expense categories. F7: December 2026 start, the six-step ladder, scenario calculator. F8: all five milestones built in sequence.
- Two small corrections to the plan, found while writing the migration: (1) `fin_target` uses a generated `active_slot` key so "one active target per month and measure" is a real database rule (a plain unique key including a nullable column would not enforce it); (2) `fin_adjustment` has no foreign key into the CRM (the CRM stays untouched); the engine checks the contract instead.

## Files
New: `adm_finance_core.php`, `adm_finance_store.php`, `adm_finance_charts.php`, `adm_finance_views.php`, `database/phase8d_finance.sql`, `database/phase8d_rollback.sql`, `tests/phase8d_finance_test.php`, `tests/adm_finance_preview.php`, `docs/phase8d/*`, 11-screen screenshots. Modified: `admin-center.php` (finance route, POST handler, audited CSV export), `adm_core.php` (module live, style hook), `tests/browser_phase8c_preview_test.js` (screenshot list), `tests/security_manifest.json`, `.github/workflows/ci.yml`. No Phase 6 CRM file, no Phase 8A to 8C logic file and no public template changed.

## Financial integrity review
- **No invention.** All figures come from records. Empty inputs show "No data" or "unavailable". A chart whose only values are zero or missing becomes an explanatory empty state. Synthetic records are counted and excluded from every total and every export. A zero is never shown as progress: the ladder reports "No recorded data" when nothing exists.
- **Currencies are never combined.** Each currency has its own report, chart and table; the primary currency (EUR) is shown first. There is no conversion mechanism, so none is applied.
- **No double counting.** Legacy listing payments live in their own section and are excluded from CRM totals, with a visible explanation; CRM revenue comes only from `crm_revenue_entries`.
- **Honest labels.** Data states: Recorded manually (not bank-verified), Synthetic (excluded), Estimate (pipeline and quotes), Target, Unavailable, Reconstructed (MRR trend, approximate), Hypothetical (scenario), From contract terms (MRR, ARR, recognized, contracted).
- **Formulas shown on screen.** Net income = received minus refunds; net invoiced = invoiced minus credit notes; outstanding = net invoiced minus received; profit (cash basis) = net income minus expenses. Refunds cannot exceed what was recorded as received, credit notes cannot exceed what was invoiced, and received cannot exceed invoiced (existing CRM rule).
- **MRR, ARR and recognized revenue** are computed by the existing `crm_contract_measures()`; the stage-weighted pipeline uses the existing `crm_stage_weights_bp()`. Nothing is re-implemented.
- **Targets are aspirations.** The EUR 979,000 objective and the ladder are labelled Target, not forecast, and start in December 2026.
- **Money is integer minor units**, with bounded amounts; no floating point is used for money.

## Security review
- Owner only: the `finance` permission is held by the owner alone, checked on every page, every action in the engine (editor, sales, viewer, verifier and unknown roles get 403 and nothing changes) and before every export.
- POST only with CSRF and same-origin checks before any finance action (asserted by source order); unknown actions 400; direct execution of library files returns 404.
- Validation: real dates not in the future, whitelisted currency and category, bounded amounts, markup stripped, output escaped; references are document numbers only (no card, bank or personal data).
- Nothing is deleted: expenses and adjustments are voided with a reason; a new target supersedes the old one and both are kept. Every create, void, target change and export, and every denial, is written to the audit log.
- CSV: formula-injection guard on every cell, `Cache-Control: no-store`, `X-Robots-Tag: noindex`; every row carries a data-state and VAT column; exports audited.
- Static checks (tests): no write to any CRM, submissions or other table, no ALTER or DROP, no hard delete, no email, payment, network or file writes, the MySQL store touches only the named tables.
- Least-privilege grants for deployment: SELECT on `crm_contracts`, `crm_companies`, `crm_opportunities`, `crm_campaigns`, `crm_revenue_entries`, `crm_users`, `submissions`; SELECT, INSERT, UPDATE on `fin_expense_entry`, `fin_target`, `fin_adjustment`.

## Test results (all database-free)
| Check | Result |
|---|---|
| Phase 8D finance logic, engine, screens, charts, CSV and static checks | 158 passed, 0 failed |
| Synthetic Finance screens: 11 pages x 3 sizes x light and dark (axe WCAG 2.x A/AA, overflow, 44 px targets, skip link) | 264 passed, 0 failed |
| Phase 8C suites (category safeguards with the 509-tool regression 54, tools 115, tools M4 to M7 174) | all passed |
| Security inventory 474, security regression 147, secret-init 10, salt 10, i18n 35, content backlog 27 | all passed |
| PHP lint, JS syntax, built assets | clean |

Hand-computed expectations cover EUR and USD reports (invoiced, received, refunds, credits, MRR, ARR, recognized, contracted, forecast, monthly series, expenses with recurrence, profit, streams, concentration, renewals, legacy payments), the ladder before and after the start month, and the scenario arithmetic (for example 490 contracts at 2,000 per month reach 979,000).

## Screenshots
`docs/phase8d/screenshots/`: Overview (light desktop, tablet, mobile; dark desktop, tablet, mobile), Revenue, Advertising, Expenses, Refunds, Targets with the scenario calculator, and the empty Overview, at desktop and mobile. All from synthetic data created for the preview.

## Known limitations and unverified behaviour
- **Not verified against MySQL:** the whole MySQL store (CRM read queries including the package lookup, the voiding transaction with `FOR UPDATE`, target supersede inside a transaction, user id lookup), the migration and rollback on MySQL 8.0 and 8.4, the generated `active_slot` key, character set behaviour, and real sign-in, session and CSRF flow. First real check is a staging run you approve, with a backup.
- **No data to show yet:** no real revenue, customer or transaction is known to exist; production CRM state was not checked. Dashboards will mostly be honest empty states until records exist.
- **Figures are recorded, not verified:** "received" means a person recorded it; no bank reconciliation. VAT is not tracked, so amounts are neither net nor gross.
- **MRR history is reconstructed** from current contract records and labelled approximate; it is not an exact historical record.
- **Overdue** cannot be computed (no due dates exist); **runway** is unavailable (no cash balance); no currency conversion.
- **Streams:** the CRM has no field that separates custom AI agents, vendor subscriptions and integration services; they show "No record yet". Contracts without a classification are Unclassified.
- **Legacy payments** are amounts in whole currency units from the public submissions form, marked paid manually.
- **Scenario inputs** are not stored; there are no hidden defaults. The calculator is arithmetic, not a forecast.
- Merge order: this branch contains the unmerged Phase 8A to 8C commits; the draft pull request targets the Phase 8C branch.

## Deployment prerequisites (all open, none executed)
Owner approval for merge, staging deployment and migration; administrator-run migration after a backup; least-privilege grants above; confirmation of the production CRM and content source; staging walk-through (record an expense, refund, credit note and target; void each; export each CSV; confirm CRM tables unchanged and legacy payments absent from CRM totals); rollback rehearsal with `database/phase8d_rollback.sql`.
