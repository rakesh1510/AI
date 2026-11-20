# Unified Admin Control Center: milestone U3 report (AI Universe review inside the shell)

Branch `feat/aihubpedia-unified-admin`, on top of U2 (`63d3636`). Not merged, not deployed. No staging, production, Hostinger, database record or credential was touched. No MySQL test was run; every check is database-free.

## 1. Features completed
- **The AI Universe editorial workflow opens inside the Control Center** at `/admin-center.php?m=universe` (home, my queue, new proposal, review queue, owner overview, item pages) with the shell, sidebar, breadcrumbs, identity, banner and Sign out.
- **Wrapper only (D5).** `adm_universe.php` mirrors the standalone page's order of checks and calls the **unchanged** `uv_*` views and the single existing write handler. `universe-editor.php`, `uni_views.php` and `uni_core.php` are byte-identical to Phase 8D (asserted). **`/universe-editor.php` still works.**
- **Maker-checker preserved (D4, D3).** Proposing stays with editors, verification stays with verifiers, the "not your own item" and independence rules stay inside the existing handler, and the **owner keeps read-only access**: an owner POST is refused with 403 "Read only" before the rate limit and before the handler, without touching the database (tested with a database that fails on any use). Other roles (sales, viewer, unknown) get 403 on every request.
- **Links, forms and the flash code stay in the shell.** Links and POST forms are rewritten; the flash code travels in `msg` (the shell owns `m`).
- **Scoped styles** (`.admUni`, shell tokens, light and dark, 44 px targets).

## 2. Files changed
New: `adm_universe.php`, `tests/unified_admin_u3_test.php`, `docs/admin/U3_REPORT.md`, `docs/admin/screenshots_u3/`. Modified: `admin-center.php` (universe POST route after sign-in, CSRF and the `universe` permission; GET route), `adm_core.php` (module internal, style hook), `tests/adm_shell_preview.php`, `tests/security_manifest.json`, `.github/workflows/ci.yml`. Not changed: Universe code, CRM, legacy admin, Finance, Tools, databases, migrations.

## 3. Security and permission checks
- Order asserted by source: sign-in, CSRF and same-origin, `universe` permission, then the workflow; inside it: role, owner read-only, rate limit, then the one write path (`uv_handle_post` is called from exactly one place).
- Permissions unchanged: owner, editor, verifier hold `universe`; sales and viewer do not (menu and server).
- Editors still see only their own items and verified items. Page-level role checks refuse cross-role views (editor to queue, verifier to new proposal, owner to queue and so on) without database access.
- The wrapper contains no SQL and no credential code; redirect goes through `sec_safe_local_path`.

## 4. Tests (database-free)
| Check | Result |
|---|---|
| U3 wrapper, roles, owner read-only, order, parity, untouched Universe files | **42 passed, 0 failed** |
| U2 52, U1 143 | passed |
| Shell browser checks, 15 synthetic pages x 3 sizes x light and dark | **396 passed, 0 failed** |
| Finance 160, tools 115 and 174, categories 54, security 147, security inventory 491 | all passed |
| PHP lint of every file | clean |

## 5. Screenshots
`docs/admin/screenshots_u3/`: editor home with flash, verifier home, owner read-only notice, each at desktop, tablet and mobile (synthetic content built from the real Universe helpers).

## 6. Known issues and limits (unverified)
- **Not verified against a database:** real queue, item and review pages, real proposals, submissions and verification decisions through the shell, the 422/404 re-render path, and the rate-limit store. A staging walk-through with an editor, a verifier and the owner is required (including a check that an editor cannot verify and the verifier cannot verify their own item).
- The admin-editorial page (legacy, reviewer typed by name) is not part of U3; it moves with the legacy retirement in U4.
- The wrapper re-implements the page's routing (about 40 lines) instead of editing the standalone page; the test asserts parity on roles, read-only message and item visibility. If the standalone page changes, change both.

## 7. Commit and branch
Branch `feat/aihubpedia-unified-admin`; draft PR not yet opened.
