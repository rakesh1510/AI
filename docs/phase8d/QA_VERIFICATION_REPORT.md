# Phase 8D Finance: QA verification report

Scope: commit `56a309b` (draft PR #11) reviewed and verified, with the follow-up verification commit on the same branch. Role: manager, developer and QA. PR #11 stays **draft**. Nothing was merged, deployed or run against staging, production or Hostinger. No customer was contacted and no financial transaction was started. Phase 8H and 4.2 not started. Phases 8A to 8C are preserved.

## Environments used (and not available)
| Environment | Status |
|---|---|
| Local disposable MySQL **8.0.46** (scratch databases created and dropped by the tests, a throwaway least-privilege user) | **Used.** Local server only; the test scripts refuse non-local hosts and MariaDB. |
| MySQL **8.4** | **Not available locally** (no 8.4 binary, no containers). Covered only by the new disposable GitHub Actions workflow `finance-db-verify.yml` (service containers 8.0 and 8.4). Its result is reported below under "MySQL 8.4"; if it has not finished, treat 8.4 as **unverified**. |
| MariaDB | Not tested. The target engine for production is unconfirmed; the migration uses a stored generated column (already used by the Phase 7 schema), so MariaDB support is expected but unverified. |
| Staging, production, Hostinger | Not accessed, by instruction. |

## 1. Review of PR #11 against the plan and report
- Plan items F1 to F8 all present: three additive tables, read-only CRM, legacy payments separate, refunds and credit notes with void, streams from CRM fields only, "VAT: Not tracked", the seven expense categories, December 2026 start and the six-step ladder, scenario calculator, all five milestones.
- The two documented plan corrections (generated `active_slot` key, no foreign key into the CRM) are implemented and now **verified on real MySQL** (below).
- Existing code touched: `admin-center.php`, `adm_core.php` only (route, module registration, style hook). `git diff 9e063ad` shows no change to any Phase 6 CRM file, `catalog.php`, templates or the sitemap.

## 2. CRM calculations are reused, not duplicated
- Finance calls `crm_contract_measures()` and `crm_stage_weights_bp()` and never re-implements MRR, ARR, recognized or forecast logic.
- Verified on MySQL: for EUR and USD, finance `mrr`, `arr`, `recognized`, `contracted`, `invoiced`, `received` and `forecast` **equal `crm_revenue_summary()`** exactly (14 comparisons) on a seeded data set.
- Intentional, documented difference: the CRM summary counts `is_synthetic` rows; the finance report excludes them (tested).
- No double counting: legacy `submissions` payments stay in their own section and never enter CRM totals (tested on real rows).

## 3. Migration and rollback on MySQL 8.0.46 (disposable)
| Check | Result |
|---|---|
| Migration run three times | No error; exactly three new tables; no change |
| Existing tables (CRM, submissions) | `CHECKSUM TABLE` and `SHOW CREATE TABLE` identical before and after |
| Engine and collation | InnoDB, utf8mb4 for all three |
| Indexes | `idx_fin_exp_date`, `idx_fin_exp_cat`, `idx_fin_adj_contract`, `idx_fin_adj_date` present |
| Generated target key | `active_slot` is a STORED generated column with a UNIQUE key; a second active target for the same month and measure is **rejected by the database (23000)**; a different measure is allowed; superseded rows may repeat; exactly one stays active |
| Foreign keys | None from the finance tables (by design; contract existence is checked by the engine) |
| Rollback | Drops exactly the three tables (safe to run twice); every other table untouched; module then reports "storage not installed" and revenue pages still read the CRM; a fresh re-apply works and starts empty |
| Phase 8C migration (regression) | Additive and idempotent on MySQL; its rollback keeps the tools |

## 4. Owner-only access, CSRF, audit, refunds, expenses, calculations (real PHP server, real sessions)
99 HTTP checks passed, including:
- Anonymous users see only the sign-in form. The owner opens all 8 Finance pages; **sales, viewer, editor and verifier get 403 and no figures on all 8 pages**; every denial is audited.
- Exports: owner gets CSV (attachment, `no-store`, `noindex`, data-state column); editor and sales get 403; unknown or malformed export is 404; each export is audited.
- CSRF and origin: no token, wrong token, another session's token, and the right token from another origin are all 403 with nothing stored; other methods are 405; a GET never changes data.
- A non-owner POST with a valid token of their own session is still 403 and audited as denied.
- Expense recorded over HTTP in minor units with markup stripped and the creator recorded; the audit row names the user, action and amount; void keeps the row; a refund is recorded and audited; a refund above what was received is refused (422); a target set twice leaves two rows and one active; validation errors re-render accessibly and store nothing; the scenario calculator renders (490 contracts at 2,000 per month for 979,000).
- Hostile company name and expense text are escaped on every page; the server logged no PHP warnings; no secrets in the security log; direct requests for the library files and the SQL expose nothing.

## 5. Realistic synthetic data on MySQL
99 database checks passed, including: EUR and USD contracts, monthly, annual and one-off billing, an unclassified contract, a draft contract, a Unicode company and expense text (utf8mb4 round trip), recurring monthly and annual expenses expanded per month, refunds and credit notes flowing into net income and outstanding, voiding, synthetic rows excluded, missing data producing empty states, a database error inside a write rolling back cleanly with a failure result (no exception), and all Finance screens and exports rendering from real rows.

**Least privilege:** with only the documented grants (SELECT on the CRM and submissions, SELECT, INSERT, UPDATE on the finance tables) reads, writes, voids, targets and refunds all work, and `INSERT` into the CRM, `UPDATE` and `DELETE` on CRM payments, `DELETE` on finance tables, `DROP`, `ALTER` and `UPDATE submissions` are all denied by MySQL.

**Scale:** 3,000 contracts, 30,000 payment records and 3,000 expenses: load and report 0.16 s, overview 0.18 s, three pages 0.56 s, peak memory 48 MB; the expense list is capped at 100 rows.

## 6. Indexes, keys and idempotency
See section 3. Note: the finance tables have no foreign keys by design. If a CRM contract were ever removed, refunds recorded against it would remain (the CRM prevents deletion with `ON DELETE RESTRICT` on its own children, and the CRM has no delete function for contracts), so the risk is low.

## 7. Regression check, Phases 8A to 8C
- Routine database-free suites: Phase 8C tools 115, M4 to M7 174, category safeguards with the 509-tool regression 54, security inventory 476: all pass.
- **Phase 8A database and browser test (110 checks) found one failure, fixed in this commit:** it used Finance as its example of a "planned module"; Finance is now live. Test-only regression, now uses Operations. Re-run result below.
- Phase 8B analytics database test: 82 of 82 passed on MySQL 8.0.
- Phase 8C migration and the Phase 8C MySQL store (create, publish, stale-edit refusal by the revision key, bulk in one transaction, featured mirror, submission approval, soft delete, rollback) passed a smoke test on real MySQL. This closes most of the 8C "MySQL store unverified" gap for 8.0 (8.4 pending the workflow).

## 8. User interface (desktop, tablet, mobile; light and dark)
- Synthetic screens: 264 checks passed (axe WCAG 2.x A/AA, overflow, 44 px targets, skip link). **The real Finance pages, served from MySQL data, also passed 204 axe, overflow and target checks** at three sizes in both themes.
- Visual review: charts, tables, notes and state badges are legible; chart legends and data-table alternatives work; the ladder and scenario calculator read clearly.
- Finding (medium, UX, fixed): on small screens the eight Finance tabs stacked into eight rows. They now sit in two columns (also the tool-management tabs), re-verified with axe and overflow checks.
- Finding (low, existing 8A shell, not changed): on phones the Control Center sidebar (about 960 px tall) appears above the content. This is the Phase 8A layout; a collapsible menu would improve all modules and is a separate change.

## 9. Findings, risks and required fixes
| # | Severity | Finding | Status |
|---|---|---|---|
| Q1 | Medium | **Owner-set month targets were stored and listed but never compared with actual figures.** | **Fixed**: each active target now shows recorded value and reached or not reached (or "no data", "month not started", "unavailable"), never reading missing data as zero; tested. |
| Q2 | Low (test only) | Phase 8A database test used Finance as a "planned module" and failed once Finance went live. | **Fixed.** |
| Q3 | Low (UX) | Eight stacked tabs on mobile. | **Fixed.** |
| Q4 | Info | Verification tests were missing for the MySQL path. | **Added**: `tests/phase8d_finance_db_test.php`, `tests/phase8d_finance_http_test.php`, workflow `finance-db-verify.yml` (manual or on edit of that file only; routine CI stays database-free). |
| R1 | Risk | **MySQL 8.4 and MariaDB** not verified locally. | Open; see the workflow result. Staging engine is 8.4. |
| R2 | Risk | No real data exists to validate the formulas against a real ledger; CRM entries are manual and not bank-verified. | Open by nature; first real month needs an accountant check. |
| R3 | Risk | VAT not tracked; amounts neither net nor gross. | Accepted (decision F5). |
| R4 | Risk | MRR history is reconstructed from current contract records. | Accepted, labelled approximate. |
| R5 | Risk | "Overdue" cannot be computed (no due dates) and runway is unavailable. | Accepted, shown as unavailable. |
| R6 | Risk | Phase 8A to 8C browser flows were not re-run against a live staging server. | Open; staging walk-through still required after approval. |
| R7 | Risk | Production CRM state and content source (MySQL or JSON) were not checked. | Open; owner or administrator to confirm. |

No critical issue was found. The one real functional gap (Q1) is fixed.

## 10. Results summary
| Suite | Environment | Result |
|---|---|---|
| Finance logic, screens, charts, CSV, static | database-free | 160 passed |
| Finance synthetic screens (axe, overflow, targets) | database-free browser | 264 passed |
| Finance database verification (migration, rollback, store, CRM reuse, grants, scale, 8C smoke) | MySQL 8.0.46 | 99 passed |
| Finance HTTP verification (owner-only, CSRF, audit, exports, real pages axe 204) | MySQL 8.0.46 + PHP server | 99 passed |
| Phase 8B analytics | MySQL 8.0.46 | 82 passed |
| Phase 8A control center | MySQL 8.0.46 | see addendum below |
| Phase 8C suites, security inventory (476), security regression (147) | database-free | all passed |

## Required before any merge or deployment (owner decisions)
1. Review this report and PR #11 (still draft). Confirm MySQL 8.4 result (workflow) before relying on the staging engine.
2. Approve merge order (this branch includes the unmerged 8A to 8C work).
3. Separately approve a staging deployment and migration, with a backup, the least-privilege grants above, and a staging walk-through (record, refund, void, export; confirm CRM tables unchanged).
4. Confirm the production content source and CRM state.
