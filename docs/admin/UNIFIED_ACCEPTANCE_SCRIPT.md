# Unified Admin Control Center: staging acceptance script

Run on STAGING only, by the administrator and the owner, after a backup and after the Phase 8A to 8D acceptance script. Nothing here is run on production. Test data only. Mark each line Pass, Fail or Blocked.

## A. Preconditions
1. `AIHUB_ENV_LABEL=staging` is set; every page shows the amber STAGING banner. (A red PRODUCTION banner on this server is a stop.)
2. At least one personal account per role exists (created with `php scripts/crm_user.php`): owner, sales, viewer, editor, two verifiers.
3. `AIHUB_LEGACY_ADMIN` is NOT set (legacy pages still work during the walk-through).

## B. Sign-in and account (U1)
4. `/admin-center.php` shows the Control Center sign-in (not the public layout). Wrong password fails with a generic message; five rapid failures are rate limited.
5. Owner signs in: name and role chip visible, Sign out in the same place on every page.
6. Account page: change own password (wrong current password refused; weak password refused; success audited; session continues). Sign out and in with the new password.
7. Mobile width: Menu button opens the drawer, Escape and the scrim close it.

## C. Permissions (every role, direct URL as well as menu)
8. Menu matrix: owner everything; sales CRM + Advertising; viewer CRM (read); editor Tools, Universe, Profile verification; verifier Universe, Profile verification. No role sees an item it cannot open.
9. Typing a forbidden module URL (`?m=finance`, `?m=access`, `?m=advertising` as viewer, and so on) returns "Not allowed" and an audit row.

## D. CRM inside the shell (U2)
10. Open each of the 11 sidebar CRM views and the owner Audit log and Users pages. No broken layout, no link that leaves the shell (the address bar always stays on `/admin-center.php?m=crm...`).
11. Search form (Leads, Companies, Pipeline) returns results. Create a note, change a stage, add a task: flash message appears in the shell; viewer cannot write.
12. `/crm.php` still opens on its own address.

## E. AI Universe (U3) and profile verification (U4)
13. Editor proposes an item; a different verifier reviews it; the verifier cannot review their own item; the owner can view but every owner POST is refused.
14. Tool profile verification: editor records a note (reviewer shown as the account, no name field); editor cannot verify; verifier verifies a profile the editor prepared; the same person cannot prepare and verify; owner sees no form. Review history shows account names.

## F. Submissions and payment (U4)
15. Submit a paid-plan listing on the public form (test data). As owner open Tool management, Submissions: approval is blocked, "Record payment received" is shown.
16. Without ticking the confirmation: refused. With it: status becomes paid, audit row `tool_submission_payment_recorded` exists, wording says "not verified against a bank", a second attempt is refused, an editor never sees the form.
17. Approve: a DRAFT tool is created (owner publishes separately). Finance does not include this payment in CRM revenue (legacy payments section only).

## G. Operations (U5) and advertising (U6)
18. Owner: Users and roles (no password data), role matrix matches step 8, Audit log filters and paging work and show your own steps above, System health shows no secrets.
19. Advertising overview, Campaigns, Featured placements, Paid listing submissions (owner); sales sees overview and campaigns only. No button anywhere sends an email or changes data.

## H. Retire the shared legacy password (D2, owner decision, only after A to G pass)
20. Owner confirms in writing that a personal owner login works and that nothing is still needed from `admin.php`, `admin-leads.php`, `admin-editorial.php`.
21. Set `AIHUB_LEGACY_ADMIN=off` on staging only. The three legacy pages answer "This admin page has moved" (HTTP 410). Control Center still works. Remove the setting to roll back.
22. Only after a separate approval: remove `AIHUB_ADMIN_PASS_HASH` from staging. Production follows the normal deployment approval.

## I. Evidence
Keep: screenshots of steps 5, 8, 10, 14, 16, 18, 19; the audit log export of this session; the list of failures with step numbers.
