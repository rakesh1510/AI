# Commercial proposals and revenue management (Phase 6, priorities 5 and 6)

**Internal record keeping only.** Nothing sends a proposal, issues an invoice, charges a card, creates a subscription or contacts anyone. Invoiced and received amounts are manual records of events that happened outside this system.

## Proposals
* Created from an opportunity: currency (EUR, USD, GBP, INR, CHF, CAD, AUD), billing period (one-off, monthly, annual), validity date (not in the past), line items (description, quantity to 2 decimals, unit price, line discount %), document discount %, tax %, internal terms.
* Totals in integer minor units, half-up rounding: line = qty × unit − line discount; subtotal = Σ lines; document discount on the subtotal; tax on the discounted amount; total = subtotal − discount + tax. Worked and tested example: 2 × 1,500.00 with 10% line discount plus 1.5 × 99.99, 5% document discount, 19% tax → subtotal 2,849.99, discount 142.50, tax 514.42, **total 3,221.91**. 300 randomised proposals check the identities; maxima stay exact integers; invalid values are refused.
* **Versions:** every revision is a new version (earlier versions stay readable) and returns the proposal to draft so it needs approval again.
* **Status:** draft → pending approval → approved (**owner**) → accepted / declined / expired (recorded by an owner) or withdrawn. An accepted proposal can no longer be edited.

## Contracts, renewals, cancellations
A contract is created **as a draft** from an accepted proposal. It counts as contracted revenue only after an **owner attests that a signed agreement exists** ("confirm"). Renewal dates are tracked (list of contracts renewing in 60 days); a renewal extends the term (never shortens); a cancellation needs a reason and an effective date and stops MRR from that date.

## The six revenue words (never mixed)
| Word | Definition | Source |
|---|---|---|
| **Proposed** | Value of proposals that are draft, pending or approved | `crm_proposals` |
| **Forecast** | Open opportunity value × assumed stage weight. **Hypothetical planning figure, labelled as such, never revenue** | `crm_opportunities` |
| **Contracted** | Value over the term of contracts an owner confirmed (monthly × months, annual pro-rata, one-off in full) | `crm_contracts` |
| **Invoiced** | Amounts recorded as invoiced outside this system | `crm_revenue_entries` |
| **Received** | Amounts recorded as received; can never exceed invoiced for a contract | `crm_revenue_entries` |
| **Recognized** | Contracted value earned to date on a simple, conservative schedule: whole elapsed months for recurring contracts; a one-off only after its end date; cancellation stops accrual | computed |
Also: **Expected recurring** (annualised recurring value of open proposals) vs **Confirmed MRR/ARR** (active, signed, in-force recurring contracts only). MRR monthly-equivalent: monthly amount, or annual ÷ 12 rounded half-up. All figures are **per currency**; no conversion is attempted. This is a management view, not accounting or tax advice.

## Executive dashboard (`CRM → Dashboard`)
New inquiries (by kind), qualified leads and qualification rate, conversion rate, possible duplicates, open opportunities and pipeline value by stage, win rate, proposal acceptance rate (decided proposals only), confirmed MRR/ARR, lead sources, campaign attribution, follow-ups and overdue count, renewals in 60 days, and the revenue table above. Windows: 30, 90 or 365 days.
* **Never fabricated:** a rate with no denominator shows "not enough data" (internally `null`, never 0%); with no records the page says nothing has been recorded, so there is nothing to report.
* **Synthetic data is labelled:** if any record is flagged synthetic, a banner says so and warns against presenting the figures as real.

## Verification
`tests/crm_logic_test.php` (131 checks incl. the worked calculation, rounding, limits, property test, lifecycle, permissions, MRR/ARR/recognition scenarios, cancellation and renewal, currency separation, empty dashboard) and `tests/crm_http_test.php` (the full owner workflow over HTTP: lead → opportunity → proposal 2,975.00 with 19% tax → contract → MRR 2,975.00 / ARR 35,700.00 → invoiced and received).

## Not built
Invoice generation, payment collection, tax filing, currency conversion, revenue-recognition standards compliance (IFRS 15), CSV export. Talk to an accountant before relying on any figure for reporting.
