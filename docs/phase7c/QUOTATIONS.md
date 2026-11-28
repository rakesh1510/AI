# Phase 7C: professional quotations and client conversion (private CRM)

A quotation **is** a proposal: this phase extends the Phase 6 proposal and contract modules (`crm_money.php`) instead of adding a parallel quoting system. Nothing here sends a quotation, e-mails a customer, invoices or charges; no paid service, library or API is used.

## What was added (and what was reused)
| Need | Reused from Phase 6 | Added in Phase 7C (`crm_quote.php`) |
|---|---|---|
| Numbering | unique number column | Per-year gap-free sequence `Q-2026-0001` (atomic counter, parallel-safe, prefix via `AIHUB_QUOTE_PREFIX`). A failed creation does not burn a number. Old `P-` numbers stay valid. |
| Pricing, discounts, currencies | line items, line + document discount, 7 currencies, integer money, half-up rounding | Free-text lines already allow any service and price; no invented price list. |
| Taxes | tax % per proposal | Tax label shown on the quotation and **configurable presets** `AIHUB_QUOTE_TAX_PRESETS="VAT 19%=19;VAT 7%=7;Reverse charge (no VAT)=0"` (defaults: 19, 7, none; a convenience, not tax advice). Custom % still possible. |
| Payment milestones | none | Up to 6 milestones per version, percentages must add up to 100, amounts fixed at save with the remainder on the last one (always sums to the total), due rule: on acceptance / N days after / on delivery / fixed date. Shown on the quotation and on the contract (as a plan; invoices and payments are still recorded with the existing revenue records). |
| Validity | `valid_until`, approval blocked when expired | Validity state (valid / expiring in 7 days / ended) on the proposal and list; accepting a decision dated after the validity date is refused; printed quotation is stamped "VALIDITY ENDED". |
| Acceptance / rejection tracking | status accepted/declined | Records who, when, how (e-mail, signed document, meeting, call), customer contact, reference and, for declines, the reason. "Sent to customer" is recorded too. Needs the existing `revenue` permission (owner); "sent" needs `write`. |
| Conversion to contract | `crm_contract_create` (accepted proposal -> draft contract) | One conversion path: exactly one draft contract per proposal (a draft is not revenue), start date defaults to the acceptance date, the opportunity moves to won when its rules allow, audited. |
| Branded printable quotation | none | `/crm.php?v=quote&id=N`: standalone A4 print layout, same sign-in, noindex, no-store. **PDF: use Print, then Save as PDF** (every browser; no PDF library, no cost). Drafts, declined, withdrawn and validity-ended quotations are stamped so they cannot pass for an offer. Internal notes are never printed. |

Seller details are printed only when configured (empty values are left out, never invented): `AIHUB_QUOTE_NAME` (default `AIHUB_BUSINESS_NAME` or TrustNova Tech), `AIHUB_QUOTE_ADDRESS` (`;` separates lines), `AIHUB_QUOTE_VAT_ID`, `AIHUB_QUOTE_EMAIL`, `AIHUB_QUOTE_PHONE`, `AIHUB_QUOTE_WEBSITE`, `AIHUB_QUOTE_BANK` (payment details line), `AIHUB_QUOTE_FOOTER`, `AIHUB_QUOTE_DEFAULT_TERMS` (prefilled customer terms), `AIHUB_QUOTE_VALIDITY_DAYS`.

## Database (not executed anywhere except the isolated test DB)
`database/phase7c_quotations.sql` (after `phase6_crm.sql`): four new tables `crm_proposal_extra`, `crm_proposal_milestones`, `crm_proposal_events`, `crm_quote_counter`. Additive, idempotent, no foreign keys, no change to existing tables. Rollback: `database/phase7c_rollback.sql` (drops only those four; quotations stay in `crm_proposals`, milestones/decision records/counters are lost).
Before the migration the screens behave exactly as in Phase 6 (old numbers, the generic status form, the old contract form) and the printable quotation already works without milestones.

## Operating cost
None: PHP, the existing database, the browser's own PDF export.

## Limits
* No e-mailing and no e-signature: acceptance is recorded by a person (signed document, e-mail, meeting). 
* Milestone amounts are plan figures; invoices/payments remain manual records.
* Quotation currency/tax text is the owner's responsibility; the tool does not give tax or legal advice.
* A server-side PDF file would need a library or service; it can be added later if browser printing is not enough.
