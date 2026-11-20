# Unified Admin Control Center: milestone U4 report (replace the shared password's functions; payment action)

Branch `feat/aihubpedia-unified-admin`, on top of U3 (`3f1a8db`). Not merged, not deployed. No staging, production, Hostinger, database record or credential was touched. No MySQL test was run; every check is database-free.

## 1. Features completed
- **Inbound forms.** Listing submissions already moved into the Control Center in Phase 8C (Tool management, "Submissions": list, detail, approve to a DRAFT, reject, needs information). U4 adds the missing piece below; the old JSON-based `admin.php` list is not needed for this any more. Leads: the CRM (U2) covers the legacy leads page.
- **Owner-only "Record payment received"** on a submission (`submission_record_payment`), in the existing Submissions detail page:
  - Owner only (the existing `tools_publish` permission). Editor, sales, viewer, verifier and unknown roles get 403 and nothing changes.
  - **Explicit manual confirmation:** the owner must tick "I received this payment (manual confirmation, not verified against a bank)". Anything other than the exact value `1` is refused.
  - Honest wording everywhere: "Nothing here takes payment or checks a bank", "recorded as a manual confirmation by you", "Payment recorded as received (entered manually by the owner; not verified against a bank)". Nothing implies bank verification.
  - **Audit record** `tool_submission_payment_recorded` with the owner's username, "manual confirmation, not bank-verified", price and currency, and an optional payment reference (markup stripped, 200 characters, no card or bank data requested).
  - **Clear payment status** on the page (unpaid, paid, not required); the form disappears once paid; no way to reverse it here.
  - **Integrity:** the database store changes only `submissions.payment_status`, only from `unpaid` to `paid`, inside a transaction with a row lock, only for a pending submission with a price. A second confirmation is refused (409, no audit row), a free or already-decided submission is refused, an unknown id is 404. No new table or column. The existing 8C approval blocker ("paid plan, payment not confirmed") clears only after this action; no other rule changed. Finance (8D) files are unchanged: this action changes a submission row, never CRM revenue, and legacy listing payments stay separate from CRM totals.
- **Tool profile verification inside the shell** (`?m=editorial`, replaces `admin-editorial.php` for daily use). Same evidence ledger and rules (`editorial_review.php`, unchanged): queue, facts, sources, research tasks, history, checklist.
  - **Reviewer = the signed-in account** (display name and username); a posted reviewer name is ignored and the field no longer exists.
  - **Independent verifiers only (D3):** only verifiers can verify, reject or reopen; only editors prepare (note, research needed, sources opened); a verifier cannot verify a profile that same account prepared; the owner is read-only (D4). Every denial and every review is audited per user.
- **Legacy password retirement switch (D2).** `AIHUB_LEGACY_ADMIN=off` makes the shared-password pages show a "moved" notice (HTTP 410) pointing to the Control Center. **Default is on; nothing was removed or switched off.** Per D2 it must only be turned on after a working personal owner login is confirmed on staging. `AIHUB_ADMIN_PASS_HASH` and the legacy login code are untouched.
- Menu: new "Tool profile verification" item (Content and catalogue) for owner, editor and verifier (the existing `universe` permission).

## 2. Files changed
New: `adm_editorial.php`, `tests/unified_admin_u4_test.php`, `docs/admin/U4_REPORT.md`, `docs/admin/screenshots_u4/`. Modified: `adm_tools_core.php` (store method, message), `adm_tools_ops.php` (action), `adm_tools_screens.php` (payment panel), `admin_auth.php` (retirement switch), `admin-center.php` (editorial route), `adm_core.php`, `adm_nav.php`, `adm_crm.php` (link sizes), `tests/adm_shell_preview.php`, `tests/unified_admin_u1_test.php` (menu matrix), `tests/security_manifest.json`, `.github/workflows/ci.yml`. Not changed: `editorial_review.php`, `admin-editorial.php`, `admin.php`, `admin-leads.php`, Finance, CRM, Universe, migrations.

## 3. Security and permission checks
- Payment: permission, confirmation, status and ownership rules tested for every role (25 checks). Editorial: role-to-action, independence, owner read-only, identity from session, one write path, audit of denials and reviews (26 checks). Order asserted: sign-in, CSRF/same-origin, module permission, then the action.
- Retirement switch: default, `on`, unknown value (never retires) and `off` tested; legacy login code still present.

## 4. Tests (database-free)
| Check | Result |
|---|---|
| U4: payment action, editorial verification, legacy switch | **57 passed, 0 failed** |
| U1 149, U2 52, U3 42 | passed |
| Shell browser checks, 19 pages x 3 sizes x light and dark | **492 passed, 0 failed** (one intermittent group-collapse timing failure seen once, passed on re-run) |
| Tools screens incl. payment panel 408; Finance 160; tools 115 and 180; categories 54; security 147; inventory 494 | all passed |
| PHP lint of every file | clean |

## 5. Screenshots
`docs/admin/screenshots_u4/`: owner payment panel, editor and verifier (refusal message) and owner read-only editorial pages, each at desktop, tablet and mobile (synthetic data).

## 6. Known issues and limits (unverified)
- **Not verified against a database:** the `UPDATE submissions` SQL and its transaction, the submissions detail page with real rows, and the real ledger file (`editorial_log_append` was replaced by a recorder in tests; the ledger itself is unchanged and has its own tests). Staging acceptance needed.
- Independence is identity-based on the recorded reviewer text (`Display Name (username)`). Older entries made by name on the legacy page cannot be matched to an account.
- The legacy `admin.php` submissions list reads `submissions.json`, while Phase 8C reads the `submissions` database table. Which one holds live data is unconfirmed (VPS read-only check pending); the shell uses the database table.
- Retirement of the shared password is **prepared, not done**, pending a confirmed personal owner login (D2).

## 7. Commit and branch
Branch `feat/aihubpedia-unified-admin`; draft PR not yet opened.
