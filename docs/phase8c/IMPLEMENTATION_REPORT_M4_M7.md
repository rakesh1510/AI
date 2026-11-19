# Phase 8C M4-M7 implementation report (AI tool management, completion)

Branch `feat/aihubpedia-tool-management-phase8c`, on top of M1-M3 (`0055df2`, preserved). Nothing deployed, merged or migrated; no database test was run; no production, staging or Hostinger access. Phase 8H not started.

## Implemented
- **M4 categories, tags, bulk (owner only):** add, rename and delete-when-empty categories with tool counts; tag rename and merge across active tools (one history entry per tool, up to 100); bulk publish, unpublish, trash, restore, set category, add tag, remove tag for up to 100 selected tools, through a review page that writes nothing, then an all-or-nothing apply (any blocked or stale tool blocks the whole batch). One audit line per changed tool.
- **M5 submission moderation:** queue of public submissions (pending, decided, all) with plan, payment state and blockers. Approve creates a DRAFT from only what the submitter provided (no invented features, pros, cons or tags), needs a category, applies the existing rule that paid plans need confirmed payment, validates the website, and gives a taken address a numbered suffix. Reject and needs-more-information require a saved reason; needs-info keeps the submission pending. A decided submission cannot be decided again. Owner decides; the editor can view. Nothing is published, charged or emailed.
- **M6 featured placements (owner only):** add a level 1 or 2 placement to a published tool until a date within a year, one active placement per tool, cancel early, derived active, ended and cancelled states, and a history entry. The effective level and end date are mirrored into the existing featured fields so the public featured logic works unchanged. Placements start today (UTC); future-dated scheduling needs a scheduled job and is left out. No ranking change, no payment.
- **M7 interface and hardening:** section navigation (Tools, Categories and tags, Submissions with a pending count, Featured), selection boxes and a sticky bulk bar, status chips, responsive card layout, light and dark, 44 px targets, reduced motion, per-field errors, honest read-only views for editors; revision history now also records featured changes; edits no longer show phantom featured changes.

## Files
New: `adm_tools_ops.php` (rules and engine for M4-M6), `adm_tools_screens.php` (screens), `tests/phase8c_tools_m4_m7_test.php`, this report. Modified: `adm_tools_core.php` (store contract, memory and MySQL stores, permissions, messages), `adm_tools_views.php` (routing, bulk form, handlers, styles), `admin-center.php` (render of results), `tests/adm_tools_preview.php`, `tests/browser_phase8c_preview_test.js`, `tests/security_manifest.json`, `.github/workflows/ci.yml`, screenshots. The migration is unchanged: the existing three tables already cover placements and submission reviews.

## Test results (database-free)
| Check | Result |
|---|---|
| `phase8c_tools_test.php` (M1-M3) | 115 / 0 |
| `phase8c_tools_m4_m7_test.php` | 173 / 0 |
| Synthetic screens (13 pages x 3 sizes x light/dark): axe WCAG 2.x A/AA, overflow, 44 px targets, skip link | 312 / 0 |
| Security inventory / regression / secret-init / salt / i18n / content backlog | 459 / 147 / 10 / 10 / 35 / 27, all 0 failed |
| PHP lint, JS syntax | clean |

## Security review
- Every M4-M6 action requires the owner permission, checked server side in `admt_apply()` (matrix test: editor, sales, viewer, verifier and unknown roles all get 403 and nothing changes). Screens hide owner forms from editors, but the server is the control.
- CSRF and same-origin run before any tool action; methods unchanged; unknown actions are 400.
- Bulk is recomputed from stored data at apply time, never trusting the review page; stale versions block the batch; all-or-nothing in one transaction (MySQL store) with the (tool, revision) unique key as the concurrency guard.
- Untrusted submission text is stripped and escaped; unsafe website addresses block approval; the module sends no email, takes no payment and makes no network call (static test); no hard delete of tools, no `ALTER`/`DROP`, no `tools.json` writes, no AI Universe access (static tests). The MySQL store touches only `tools`, `categories`, `submissions` and the three Phase 8C tables.
- Date and level inputs are validated; "today" comes from the server clock only.

## Remaining limitations and database verification gaps
- **Not verified against MySQL:** the whole `AdmToolPdoStore` (new category, bulk, submission and placement SQL, `FOR UPDATE`, `ON DUPLICATE KEY`, transactions), the migration and rollback, the real session, sign-in and CSRF flow, cache invalidation after edits, and the JSON import guard. First real verification is staging, with a backup, after owner approval.
- **Editorial overlays win on the public site:** `classification.json` and `curated_profiles.json` override a tool's category when it is displayed. A category change or rename in the database may not change what visitors see for classified tools, and renaming a category those files use can split it. A warning is shown on the Categories screen; a later decision is needed on moving classifications into the database.
- A tool restored from the trash that has no recorded publish returns as a draft (seeded tools have no publish history), never published.
- Placements cannot be future-dated and are not auto-expired in the database (public code is date-based, so an ended placement stops showing by itself; the stored status shows as "ended" in the screen).
- Selection is per page (no select-all without JavaScript); tag rename and bulk limit is 100 tools.
- D1 prerequisite still open: whether production serves content from MySQL or JSON is unconfirmed and was not checked.
