# Phase 8C M1-M3 implementation report (AI tool management)

Branch `feat/aihubpedia-tool-management-phase8c`. Plan: `TOOL_MANAGEMENT_PLAN.md` (approved at `e41f79f`). Milestones M4-M7 are NOT started. Nothing deployed, merged, migrated or run against a database.

## Implemented
- **M1 core:** `adm_tools_core.php` (validation, slug rules, status transitions, readiness check, diff, list/filter/sort/paginate, permission map, `admt_apply()` action engine, `AdmToolStore` interface, in-memory store, MySQL store). Migration `database/phase8c_tools.sql` (3 additive tables) and `database/phase8c_rollback.sql`; both are NOT executed. Guards: `find_tool()` returns published tools only; `import-json-to-mysql.php` never updates existing records once the module tables exist.
- **M2 read screens:** list with status tabs and counts, search, category, tag, featured and missing-fields filters, sort, pagination (25), tool detail with revision history, honest "storage not installed" state. Card layout (no wide tables), light and dark, 44 px targets, reduced motion.
- **M3 changes:** create (always draft), edit (address immutable), publish, unpublish, soft delete (with confirmation) and restore (back to draft or unpublished, never straight to published). POST-only, CSRF and same-origin checked before any tool action, per-field errors, stale-edit refusal by version, revision row per change, audit row in `crm_audit_log`.
- **Permissions (D2):** owner and editor create, edit and unpublish; only the owner publishes, deletes and restores; enforced in `admt_apply()` server side and again in the screens (buttons hidden). Sales, viewer and verifier have no access. The editor's other access is unchanged.
- **Not in this change:** categories and tags management, bulk actions, submission moderation, featured placements (M4-M7); hard delete (never in 8C).

## Files changed
New: `adm_tools_core.php`, `adm_tools_views.php`, `database/phase8c_tools.sql`, `database/phase8c_rollback.sql`, `tests/phase8c_tools_test.php`, `tests/adm_tools_preview.php`, `tests/browser_phase8c_preview_test.js`, `docs/phase8c/*`.
Modified: `admin-center.php` (route and POST handler), `adm_core.php` (permissions, live module, CSS hook), `helpers.php` (`find_tool` guard), `import-json-to-mysql.php` (overwrite guard), `tests/security_manifest.json`, `tests/phase8a_admin_center_test.php` (editor now sees the tools module), `.github/workflows/ci.yml` (two database-free steps).

## Test results (all database-free; no MySQL, container or HTTP server)
| Check | Result |
|---|---|
| `tests/phase8c_tools_test.php` | 115 passed, 0 failed |
| Screens on synthetic data: axe WCAG 2.x A/AA, overflow, 44 px targets, skip link; 6 pages x 3 sizes x light/dark | 144 passed, 0 failed |
| Security inventory (every PHP file classified, redirect hygiene, library direct-execution guard) | 451 / 0 |
| Security regression, secret-init, analytics salt | 147 / 10 / 10, all 0 failed |
| PHP lint | clean |

## Security review
- CSRF and same-origin before any tool action (asserted in the test by source order); methods unchanged.
- Server-side authorization for every action, with a denied-attempt audit row; no client-side-only checks.
- Validation: address pattern, immutable on edit; website, affiliate and logo through `sec_safe_external_url` or an `/assets/` path; markup and control characters stripped; list sizes capped; category and pricing type from fixed sets; output always escaped; flash text from a fixed list only.
- Revisions and audit for every write; no hard delete, no file writes, no `tools.json` writes, no `ALTER`, no `uni_*` access (all asserted by static tests).
- Residual: an editor's edit to a published tool goes live on save (decision D2 allows editing; history and unpublish exist).

## Database-dependent behaviour NOT verified
- `AdmToolPdoStore` SQL (reads, transaction, category lookup, JSON encode, version via `MAX(revision_no)`, the unique key as the concurrency guard).
- The migration and rollback actually running on MySQL 8.0 or 8.4.
- Content-source switching, cache invalidation after edits, and the real sign-in, session and CSRF flow in the browser.
- `import-json-to-mysql.php` behaviour against a real database.
- `ci-database.yml` was not run. First real verification would happen on staging, with a backup, after owner approval.

## Unresolved deployment prerequisite (D1)
Whether production serves content from MySQL or JSON is unknown (production was not accessed). Until confirmed, deploying this module there could show a "storage not installed" state or manage a database the public site is not reading.

## Screenshots
`docs/phase8c/screenshots/`: list (light desktop, tablet, mobile; dark desktop, tablet, mobile), detail and new-tool form with validation errors (desktop, tablet, mobile). All use synthetic data from `tests/adm_tools_preview.php`, not live records.
