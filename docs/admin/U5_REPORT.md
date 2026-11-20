# Unified Admin Control Center: milestone U5 report (users and roles, audit log, system health)

Branch `feat/aihubpedia-unified-admin`, on top of U4 (`937fe38`). Not merged, not deployed. No staging, production, Hostinger, database record or credential was touched. No MySQL test was run; every check is database-free.

## 1. Features completed
New owner-only module **Users, roles and audit** (`?m=access`, new permission `access`, held by the owner only), three read-only views in the Operations group:
- **Users and roles:** username, name, role, active or deactivated, created, last sign-in; plus a **role matrix** (what each role can open) generated from the real permission table, so it cannot drift from the menu. Passwords and hashes are never selected or shown. Users are still created and deactivated from the command line only (`scripts/crm_user.php`), so a web compromise cannot create an administrator (D6 unchanged).
- **Audit log viewer:** newest first, 50 per page, filter by user, action prefix and date range (YYYY-MM-DD), paging. Filters are whitelisted and validated (impossible dates dropped, LIKE wildcards escaped, bounded page number). Entries cannot be edited or deleted here. All text is escaped.
- **System health:** the existing read-only checks (database reachable, CRM and Universe schema, tracking state, signing secret state, PHP version) with OK, Attention or Information labels and no secrets. Backups, error monitoring and release history are stated as arriving with Phase 8F (unchanged roadmap).
- The CRM's own owner-only Audit log and Users pages remain available inside the CRM section (U2) and are unchanged.

## 2. Files changed
New: `adm_access.php`, `tests/unified_admin_u5_test.php`, `docs/admin/U5_REPORT.md`, `docs/admin/screenshots_u5/`. Modified: `adm_core.php` (module, owner permission), `adm_nav.php` (menu and three sub-views), `admin-center.php` (GET route; no POST route exists for this module), `tests/adm_shell_preview.php`, `tests/unified_admin_u1_test.php` (menu matrix and owner permission list), `tests/security_manifest.json`, `.github/workflows/ci.yml`. No SQL writes, no migration, no new table.

## 3. Security and permission checks
- Only the owner holds `access`; sales, viewer, editor, verifier and unknown roles do not see the menu item and are refused by the shared module permission check (403, audited) before the page runs.
- Static checks: no write SQL, no password hash column, prepared audit query with integer limit and offset, escaped output, GET-only form, no POST route.

## 4. Tests (database-free)
| Check | Result |
|---|---|
| U5: permissions, menu, users and role matrix, filters, paging, escaping, health, static safety | **39 passed, 0 failed** |
| U1 155, U2 52, U3 42, U4 57 | passed |
| Shell browser checks, 22 synthetic pages x 3 sizes x light and dark | **564 passed, 0 failed** |
| Finance 160, tools 115 and 180, categories 54, security 147, inventory 497 | all passed |
| PHP lint of every file | clean |

## 5. Screenshots
`docs/admin/screenshots_u5/`: users and roles, audit log and system health at desktop, tablet and mobile (synthetic data).

## 6. Known issues and limits (unverified)
- **Not verified against a database:** the two read queries (`crm_users`, `crm_audit_log` with filters and count) and `adm_health()` against a real server.
- The audit log is a viewer, not an investigation tool: no export, no entity drill-down. Opening the viewer is not itself audited.
- Role changes and user creation remain command-line operations by design.

## 7. Commit and branch
Branch `feat/aihubpedia-unified-admin`; draft PR not yet opened.
