# Phase 8D: Finance. Architecture and milestone plan

Status: PLAN ONLY. No application code, no migration executed, no database test run, no production, staging or Hostinger access. Branch `feat/aihubpedia-finance-phase8d` from `9e063ad` (Phase 8C, unmerged; PR #10 stays open). Phase 8H and 4.2 are on hold.

## 1. Review: what already exists
**Approved 8A scope** (`adm_core.php`, module `finance`, owner-only): income and expense entries, invoices and payment status (paid, pending, refunded, overdue), MRR, profit and loss, CSV export, EUR primary currency with later multi-currency. Today it is a placeholder; the Overview already shows two CRM tiles (MRR and revenue received, EUR).

**Phase 6 CRM (the commercial system of record, reused, not duplicated)**
| Existing piece | What it gives Finance |
|---|---|
| `crm_contracts` (billing period monthly, annual or one-off; start, end, renewal; amount in minor units and currency; confirmed and cancelled states) | Recurring and one-off contract value; `crm_contract_measures()` already computes in-force, MRR, ARR, recognized and contracted amounts |
| `crm_revenue_entries` (kind `invoiced` or `received`, amount, currency, date, external reference) | The only recorded actual money: manual bookkeeping. Rule already enforced: received can never exceed invoiced; no bank or card data |
| `crm_revenue_summary()` | Per-currency proposed, forecast (stage-weighted), contracted, invoiced, received, recognized, MRR, ARR |
| `crm_opportunities.kind` (advertising, partnership, TrustNova project) and `crm_campaigns` (package, quote) | Stream classification and advertising and sponsorship pipeline |
| `is_synthetic` on CRM records, `crm_audit_log`, owner-only `revenue` permission, minor-unit money helpers, currency whitelist | Demo data separation, audit trail, authorization and safe money handling |

**Legacy listing payments:** public featured-listing submissions (`submissions`: plan, price, currency, `payment_status`) are marked paid by an administrator. They are not in the CRM revenue tables.

**Phase 6 revenue documents** (`docs/phase6/REVENUE_MODEL_AND_UNIT_ECONOMICS.md`) are hypotheses and illustrative maths only. No actual revenue, customer or transaction figure exists in the repository.

**Phase 8B analytics** covers traffic and conversions events. Finance will not recompute or display traffic metrics; it may link to the Analytics module.

**Gaps Finance must fill (and only these):** no expense records, no revenue targets (including the owner's EUR 979,000 monthly objective), no profit and loss, no per-month revenue history, no stream-level report, no refund or credit record, no export.

## 2. Principles
1. **Owner only** (`finance` permission). Editors, sales, viewers and verifiers see nothing; the server enforces it on every page, export and action.
2. **Honest data states** on every figure: *Recorded* (from records), *Synthetic (excluded)*, *Estimate* (labelled, for example stage-weighted pipeline), *Target* (owner objective), *Unavailable* (cannot be computed). No zero is shown as an achievement: "No revenue recorded yet" is shown instead. No invented revenue, customer, transaction or result anywhere.
3. **Money is integer minor units**; currencies are never summed or converted. EUR is the primary reporting currency; other currencies appear in separate lines. No exchange rates are invented.
4. **Read CRM, never write it.** Finance reads `crm_*` tables only through the existing functions and writes only to its own `fin_*` tables. No change to any Phase 6 file, table or behaviour.
5. **Bookkeeping, not accounting.** Records are manual and as entered. No invoices are issued, no payments are taken or verified, no tax or VAT logic (net versus gross is not tracked and is labelled). Not a substitute for an accountant.
6. **Append-only corrections.** Nothing is deleted; a wrong entry is voided with a reason and a new entry is added. Every change is audited.

## 3. Data model (additive migration, designed only: `database/phase8d_finance.sql`, rollback drops only these)
No ALTER of any existing table. Three tables, plus one optional.
```sql
CREATE TABLE IF NOT EXISTS fin_expense_entry (        -- manual expense records
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entry_date DATE NOT NULL, category VARCHAR(24) NOT NULL,   -- fixed list, see F6
  vendor VARCHAR(80) NOT NULL DEFAULT '', description VARCHAR(190) NOT NULL, amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL,
  recurrence VARCHAR(8) NOT NULL DEFAULT 'none',        -- none | monthly | annual
  recurrence_end DATE NULL, reference VARCHAR(60) NOT NULL DEFAULT '', created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  voided_at DATETIME NULL, voided_by INT UNSIGNED NULL, void_reason VARCHAR(190) NOT NULL DEFAULT '', is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_fin_exp_date (entry_date), KEY idx_fin_exp_cat (category));
CREATE TABLE IF NOT EXISTS fin_target (               -- owner objectives, never forecasts
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, month DATE NOT NULL, metric VARCHAR(16) NOT NULL,          -- received | mrr
  amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, label VARCHAR(80) NOT NULL DEFAULT '', created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  superseded_at DATETIME NULL, UNIQUE KEY uq_fin_target (month, metric, superseded_at));
CREATE TABLE IF NOT EXISTS fin_adjustment (           -- OPTIONAL (decision F3): refunds and credit notes against a CRM contract
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, contract_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(8) NOT NULL,   -- refund | credit
  amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, entry_date DATE NOT NULL, reference VARCHAR(60) NOT NULL DEFAULT '', note VARCHAR(190) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, voided_at DATETIME NULL, voided_by INT UNSIGNED NULL, is_synthetic TINYINT(1) NOT NULL DEFAULT 0, KEY idx_fin_adj_contract (contract_id));
-- OPTIONAL (decision F4): fin_contract_stream (contract_id, stream) to tag a contract with one of the ten revenue streams.
```
Why separate tables and not CRM columns: `crm_revenue_entries.kind` is a Phase 6 invariant tested by Phase 6 (invoiced and received only), and the CRM must stay unchanged. Targets replaced by a new record mark the old one superseded (history kept).

## 4. Architecture (PHP and MySQL, server-rendered, no framework, no chart library)
| File | Role |
|---|---|
| `adm_finance_core.php` | Pure logic: money parsing and limits (reusing `crm_money_parse`, `crm_currencies`), UTC month periods, expense normalisation (monthly equivalent, recurrence between dates), cash-basis profit and loss (received minus expenses, per currency), target progress and gap, scenario calculator, data-state labelling, stream mapping, CSV with formula-injection guard, validation and the action engine (`adm_fin_apply`) with the permission, state and audit rules |
| `adm_finance_store.php` | Store interface with an in-memory store (tests, previews) and a MySQL store: read-only SELECTs on `crm_*` and `submissions`, reads and writes on `fin_*` only. CRM figures come from the existing `crm_revenue_summary()` and `crm_contract_measures()` so MRR logic is never duplicated |
| `adm_finance_views.php` | Screens and navigation, honest states, tables |
| `adm_finance_charts.php` | Inline SVG charts (no libraries, no heavy effects) with a table alternative for each |
| `admin-center.php`, `adm_core.php` | `finance` module goes live as internal (like Analytics); the POST route reuses CSRF and same-origin checks before any action |

**Revenue streams.** Classified from existing CRM fields: opportunity kind and campaign package (featured placement, newsletter sponsorship, category sponsorship, affiliate and partnership, TrustNova project), and billing period (a recurring contract under a project becomes "retainer or managed service"). Streams from your list that the CRM cannot distinguish today (custom AI agents, vendor subscriptions, integration services) show "No record yet", never an estimate. Optional tagging (F4) would let you assign any contract to one of the ten streams. Contracts with no classification go to "Unclassified".

**Source handling and double counting.** Actual income = CRM `received` entries (recorded). Legacy listing payments (paid submissions) are shown as a separate line and are **not** added to CRM totals, because the same payment might also be recorded in the CRM; you decide how to combine them (F2). Synthetic records are excluded from every total by default and counted separately.

**Screens**
1. **Overview** (owner): this month and year to date (recorded, EUR), MRR and ARR, expenses, profit (cash basis), progress to target, plus per-currency notes and data-state badges.
2. **Revenue**: by month, stream and currency; invoiced, received, recognized, outstanding (invoiced minus received), contracted, forecast (labelled estimate); CSV export.
3. **Advertising and sponsorship**: campaign pipeline (quotes are estimates), active contracts, received, renewals due; sponsored placements link to the Phase 8C featured screen without new numbers.
4. **Projects and retainers**: TrustNova project contracts, recurring retainers (MRR), one-off projects, renewals, concentration (share of the largest contract, shown only with at least two contracts).
5. **Expenses**: add, void, recurring items, by category and month; MRR versus recurring expenses; profit and loss (cash basis); burn shown only from recorded expenses; runway is **unavailable** (no cash balance is recorded).
6. **Targets**: your objective of **EUR 979,000 per month** is stored as a *Target*, not a forecast. Progress, gap and required MRR are computed from recorded data. A *Scenario* calculator (inputs you type, nothing stored) shows arithmetic such as how many contracts at a stated monthly value reach a stated amount, labelled hypothetical. You can also add stepping-stone targets, for example your first EUR 10,000 month.
7. **Exports**: CSV for revenue, expenses and profit and loss, each audited.

**Charts (inline SVG, accessible).** Monthly received versus target (line or area), revenue by stream (stacked bars), MRR trend (reconstructed from current contract records and labelled so), income versus expenses (grouped bars), expense categories (bars), progress to target (bullet bar). Each has a title, description, a data table underneath, labels and patterns that do not rely on colour alone, light and dark palettes, reduced-motion support, and a mobile layout with no horizontal page scroll. The visualization guidance available in this environment will be followed at implementation.

## 5. Security and audit
- Every read and write requires `finance` (owner) server side; direct file execution returns 404; pages and exports send `Cache-Control: no-store` and `noindex`.
- Writes are POST only with CSRF and same-origin checks; amounts and dates are bounded; currency from a whitelist; references are document numbers only (no card, bank or personal data); text is stripped and escaped; CSV cells guarded against formula injection.
- No deletion: void with a reason. Every create, void, target change and export is audited in `crm_audit_log`; reads are not.
- No network calls, no email, no payment integration, no invoice issuance.
- Least-privilege grants for deployment: SELECT on `crm_contracts`, `crm_revenue_entries`, `crm_proposals`, `crm_proposal_versions`, `crm_opportunities`, `crm_campaigns`, `crm_companies`, `submissions`; SELECT, INSERT, UPDATE (void and supersede only) on the `fin_*` tables.

## 6. Database-free test plan (no MySQL, no containers)
- Pure logic with synthetic data: money limits and rounding, month periods, recurrence and normalisation, per-currency separation (never summed), synthetic exclusion, cash-basis profit and loss, target gap and required MRR, scenario arithmetic with overflow limits, double-counting rule, data-state labels, no zero shown as an achievement.
- MRR and revenue figures asserted against the existing `crm_contract_measures()` and `crm_revenue_summary()` logic on synthetic contract arrays (reuse, not reimplementation).
- Action engine on the in-memory store: permission matrix (owner only; editor, sales, viewer, verifier and unknown denied), validation, void rules, supersede for targets, audit callbacks, stale input refusal.
- Rendering: escaping, landmarks, ARIA, chart SVG well-formedness, table alternatives present, honest empty states; CSV safety.
- Static checks: no write to any `crm_*` or `submissions` table, no ALTER or DROP, no hard delete, no network, email or payment calls, security manifest entries, migration contains only the approved `CREATE TABLE IF NOT EXISTS` statements.
- Frontend: synthetic previews through the existing axe, overflow and 44 px pipeline at three sizes in light and dark; screenshots. All in routine CI. MySQL-dependent behaviour is listed as unverified.

## 7. Milestones and acceptance criteria
| # | Milestone | Acceptance |
|---|---|---|
| F1 | Core logic, store interface, in-memory store, migration and rollback files (not run), owner-only Overview dashboard from existing CRM data | Database-free tests pass; owner only (403 for every other role); every figure has a data-state label; no CRM change; honest empty state when there are no records |
| F2 | Revenue report, advertising and sponsorship view, projects and retainers view, legacy listing payments line, CSV exports | Per-currency separation; synthetic excluded; figures match the existing CRM functions on synthetic data; exports audited and formula-safe |
| F3 | Expenses (add, void, recurring), recurring expense versus MRR, cash-basis profit and loss | Append-only corrections; audit on every write; runway shown as unavailable; P&L never mixes currencies |
| F4 | Targets and the EUR 979,000 objective, progress, gap, scenario calculator, stepping-stone targets | Target stored as an objective, never presented as a forecast or result; scenario labelled hypothetical; math tested for overflow |
| F5 | Interactive SVG charts with table alternatives, premium responsive polish, optional refunds and adjustments, screenshots, hardening | axe clean, no overflow at 360, 768 and 1280 px, 44 px targets, reduced motion, charts understandable without colour |

## 8. Risks and dependencies
- **No actual revenue data is known to exist.** The dashboards will mostly show honest empty states until real contracts and payments are recorded in the CRM. The production CRM state was not checked.
- **MRR history is reconstructed** from current contract records, so it is approximate and labelled.
- **Net versus gross (VAT):** proposals carry a tax rate, but revenue entries do not say whether amounts include it. Labelled "as recorded; VAT not tracked" until you decide (F5).
- **Legacy payments** are in another currency set (site currency) and outside the CRM; handled separately to avoid double counting.
- **No bank reconciliation:** "received" means recorded by a person, not verified against a bank.
- **Database verification:** the MySQL store, migration and real sign-in flow stay unverified until a staging run you approve (same acceptance pattern as 8C).
- **8C is unmerged:** this branch includes 8A to 8C commits; merging order is your decision.

## 9. Decisions needed from the owner
- **F1 Data model:** approve the three new tables (`fin_expense_entry`, `fin_target`, optional `fin_adjustment`) and the no-ALTER, read-only-CRM rule (recommended).
- **F2 Legacy listing payments:** show separately and exclude from CRM totals (recommended), or include in a combined "recorded income" total.
- **F3 Refunds and credit notes:** include in scope with `fin_adjustment` (recommended, covers the approved "refunded" status), or leave out for now. "Overdue" cannot be computed because no due dates exist, and will show as unavailable.
- **F4 Stream tagging:** derive streams from CRM fields only (recommended), or add `fin_contract_stream` so you can tag contracts with the ten streams.
- **F5 VAT:** label amounts as recorded and untracked for VAT (recommended), or define that all CRM amounts are net or gross.
- **F6 Expense categories:** approve a fixed list (hosting and infrastructure, software and tools, contractors, marketing, legal and accounting, payment fees, other) or send your own.
- **F7 Target start:** the EUR 979,000 monthly target as a constant objective from a month you choose, plus optional stepping-stone targets (for example EUR 10,000), or only the final target.
- **F8 Build order:** F1 to F3 first and stop for review (recommended), then F4 and F5, or all five in one go.
