# Unified Admin Control Center: milestone U2 report (CRM inside the shell)

Branch `feat/aihubpedia-unified-admin`, on top of U1 (`777cc8b`). Not merged, not deployed. No staging, production, Hostinger, database record or credential was touched. No MySQL test was run; every check below is database-free.

## 1. Features completed
- **CRM now opens inside the Control Center** at `/admin-center.php?m=crm&v=<view>` with the shell, sidebar, breadcrumbs, identity chip, environment banner and Sign out. All 20 CRM views (dashboard, leads and lead, pipeline and opportunity, proposals, contracts, campaigns, partners, prospects, outreach drafts, companies, tasks, audit log, users) render through the **unchanged** `crm_render_view()`.
- **Wrapper-first (D5):** `adm_crm.php` only renders and rewrites. `crm.php`, `crm_views.php` and `crm_core.php` are byte-identical to Phase 8D (asserted by the test). **`/crm.php` still works** on its own sign-in and layout, so old bookmarks keep working; no redirect was added.
- **All CRM links and forms stay in the shell.** Links to `/crm.php?...` and POST forms are rewritten; GET search forms carry `m=crm` as a hidden field (a query string in a GET action would be dropped by the browser).
- **CRM actions** (assign, notes, stage changes, proposals, contracts and so on) post to `/admin-center.php?m=crm` and are handled by the existing `crm_handle_action()`, so every CRM rule (roles, validation, stale-edit checks, audit rows) is unchanged. Result redirect goes back to the same view inside the shell with a flash message.
- **Flash messages** use the `msg` parameter (the shell owns `m`) and the existing fixed label table, escaped.
- **Sidebar** shows the 11 CRM sub-views when CRM is open; the in-page section bar adds the owner-only Audit log and Users links.
- **Compatibility styles** (scoped under `.admCrm`, shell colour tokens, so light and dark work; 44 px targets) make the CRM markup, written for the public layout, look consistent with the shell.

## 2. Files changed
New: `adm_crm.php`, `tests/unified_admin_u2_test.php`, `docs/admin/U2_REPORT.md`, `docs/admin/screenshots_u2/`. Modified: `admin-center.php` (CRM POST route after sign-in, CSRF and the `crm` permission; GET route after the module permission check), `adm_core.php` (CRM module now internal; style hook), `tests/adm_shell_preview.php` (two CRM previews), `tests/security_manifest.json`, `.github/workflows/ci.yml` (U2 test). Not changed: CRM code, AI Universe, legacy admin, Finance, Tools, databases, migrations.

## 3. Security and permission checks
- Order asserted by source: sign-in, then CSRF and same-origin, then the `crm` permission, then the CRM action. Roles with `crm`: owner, sales, viewer; editor, verifier and unknown roles are refused (403, audited as `admin_center_denied`).
- CRM POSTs are recognised only by `m=crm` in the address; the `account_`, `tool_` and `fin_` routes are unaffected.
- Redirect after an action goes through `sec_safe_local_path`, only to whitelisted CRM views. The wrapper contains no SQL, no password or session code, no table.
- Viewer write limits, owner-only audit/users and every CRM validation remain inside the untouched CRM code. The wrapper adds no privilege.
- The old `/crm.php` address keeps its own CRM gate.

## 4. Tests (database-free)
| Check | Result |
|---|---|
| U2: rewriting, views, routing order, permissions, legacy file and untouched CRM files, CSS, section bar | **52 passed, 0 failed** |
| U1 logic | 144 passed (one assertion fewer than before: CRM is no longer a link-out module) |
| Shell browser checks, 12 synthetic pages x 3 sizes x light and dark (axe A/AA, overflow, 44 px, drawer, focus) | **324 passed, 0 failed** (a 44 px failure on table links was found and fixed) |
| Finance 160, tools 115 and 174, categories 54, security regression 147, security inventory 488 | all passed |
| PHP lint of every file | clean |

## 5. Screenshots
`docs/admin/screenshots_u2/`: CRM lead list (sales role) and lead detail with flash (owner), each at desktop, tablet and mobile. Synthetic content built from the real CRM table and form helpers.

## 6. Known issues and limits (unverified)
- **Not verified against a database:** the real CRM pages (queries, per-view markup with live data), real form submissions and redirects through the shell, and `crm_render_view()` output for every view. Each view's markup uses only the link and form patterns the rewriter covers (asserted by a scan), but a staging walk-through of every CRM view and action is required.
- Some CRM views may use classes the compatibility styles do not cover; those would look slightly plain, not broken. To be checked on staging.
- Page title shown is "CRM and leads" for all views; the section bar and sidebar show the current view.
- The CRM's own "Sign out" form is not shown inside the shell (the shell sign-out is used).

## 7. Commit and branch
Branch `feat/aihubpedia-unified-admin` (hash in the report message). Draft pull request not yet opened.
