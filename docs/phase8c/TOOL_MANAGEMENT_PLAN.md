# Phase 8C: AI Tool Management. Implementation plan and migration review

Status: PLAN ONLY. No application code, no migration executed, no database or server touched. Branch `feat/aihubpedia-tool-management-phase8c` from `8aa56ce` (Phase 8B). Growth and revenue ambitions are reserved for proposed Phase 8H.

## 1. Source of truth: what the code does today (audited)
| Fact | Evidence |
|---|---|
| The public catalogue reads **MySQL when `categories`, `tools`, `posts` and `use_cases` all exist**; otherwise **`data/tools.json`** (509 tools, 11 categories). | `mysql_tables_available_for_content()`, `load_tools_uncached()` in `helpers.php` |
| The MySQL list filters `status = 'published'`. | `helpers.php:182` |
| **Defect:** the single-tool lookup `find_tool()` queries by slug with **no status filter**, so an unpublished or deleted tool would still open by URL. | `helpers.php:250` |
| Public submissions (`submit.php`) are written to the **MySQL `submissions` table**. | `submit.php:40` |
| **Defect:** the legacy `admin.php` moderates from **`data/submissions.json`** (currently empty), so database submissions are never shown to it. Its "approve" writes the new tool to **`tools.json`**, which the site ignores when MySQL content is active. | `admin.php:8,83` |
| `import-json-to-mysql.php` (CLI) upserts every JSON tool with `ON DUPLICATE KEY UPDATE`: **re-running it would overwrite managed edits**. | script lines 44-48 |
| Catalogue overlays are keyed by tool slug: `classification.json`, `curated_profiles.json`, votes, clicks, changelog, comments. | `catalog.php`, `data/` |
| Page caches fingerprint the `tools` table with `CHECKSUM TABLE`, so database edits invalidate them automatically. | `cache.php:34` |
| The AI Universe (`uni_*`) has its own entities and human verification. It does not read or write `tools`. | `uni_core.php`, `database/phase7_universe.sql` |
| Whether production uses MySQL or JSON for content is **not confirmed**. | unknown, owner to confirm |

### Proposed source of truth (decision D1)
**MySQL `tools` and `categories` become authoritative for managed content. `tools.json` remains the seed and the automatic fallback and is never written by the new module.**
- Rationale: soft delete, history, concurrent editing, auditing and featured scheduling need transactions. JSON files cannot give them safely.
- The module works **only when the content tables exist**. Otherwise it shows a read-only "storage not installed" state (the 8B pattern) and changes nothing.
- Fallback is unchanged: with no database, visitors still get the last JSON seed.

## 2. Additive migration (designed, NOT executed): `database/phase8c_tools.sql`
Principles: only `CREATE TABLE IF NOT EXISTS`; **no ALTER of any existing table**; reuse existing columns.
- Lifecycle uses the existing `tools.status` (VARCHAR 50): `draft | published | unpublished | deleted`. The public read path already shows only `published`, so soft delete needs no new column.
- Tags stay in the existing `tools.tags` JSON column (no second source). Categories use the existing `categories` table.
- Audit entries also go to the existing `crm_audit_log` through `crm_audit()`.

```sql
CREATE TABLE IF NOT EXISTS adm_tool_revision (      -- append-only history; powers diff and restore
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tool_slug VARCHAR(190) NOT NULL, revision_no INT NOT NULL,
  action VARCHAR(24) NOT NULL,                      -- create|edit|publish|unpublish|delete|restore|feature|import
  changed_by VARCHAR(40) NOT NULL, changed_at DATETIME NOT NULL,
  before_json LONGTEXT NULL, after_json LONGTEXT NULL, note VARCHAR(500) NULL,
  UNIQUE KEY uq_tool_rev (tool_slug, revision_no), KEY idx_rev_at (changed_at)
);
CREATE TABLE IF NOT EXISTS adm_tool_feature (       -- featured placement history and schedule
  id BIGINT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(190) NOT NULL,
  level TINYINT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manual',     -- manual|submission|campaign
  submission_id INT NULL, status VARCHAR(12) NOT NULL DEFAULT 'scheduled',  -- scheduled|active|ended|cancelled
  note VARCHAR(500) NULL, created_by VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL,
  KEY idx_feat_tool (tool_slug, status), KEY idx_feat_dates (starts_on, ends_on)
);
CREATE TABLE IF NOT EXISTS adm_submission_review (  -- reviewer decisions for public submissions
  id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, decision VARCHAR(12) NOT NULL,  -- approved|rejected|needs_info
  tool_slug VARCHAR(190) NULL, reviewed_by VARCHAR(40) NOT NULL, reviewed_at DATETIME NOT NULL, note VARCHAR(1000) NULL,
  UNIQUE KEY uq_sub_review (submission_id), KEY idx_sub_dec (decision)
);
```
- The effective featured level and date are mirrored into the existing `tools.featured_level` and `featured_until` in the **same transaction**, so every existing public feature (`is_featured_tool`, `featured_tools`) keeps working unchanged. Expiry is already date-based.
- `submissions.status` (existing VARCHAR) carries `pending | approved | rejected`; reviewer notes live in `adm_submission_review`.

**Rollback** (`database/phase8c_rollback.sql`): `DROP TABLE IF EXISTS adm_submission_review, adm_tool_feature, adm_tool_revision;`. It removes only the three new tables. Tool rows and `status` values are untouched. Before any tool-data change a staging backup is mandatory. Statuses set by the module (`draft`, `unpublished`, `deleted`) are valid for the old code too (it shows only `published`).

**Data-safety guards in the same change set:**
1. `import-json-to-mysql.php` refuses to update existing tools once the module has created revisions, unless run with an explicit `--overwrite-managed` flag (otherwise a re-run would silently revert edits).
2. `find_tool()` gains `AND t.status = 'published'` (fixes the URL leak above); the admin preview uses its own owner-only query.
3. Legacy `admin.php` moderation is replaced by a link to the new moderation queue; the legacy file is not deleted.

## 3. Roles, authorization, CSRF, validation, audit
- Sign-in reuses the CRM accounts, and routing reuses the Control Center (`admin-center.php?m=tools`, `crm_gate`, `crm_csrf_ok`, same-origin check). The module is added to `adm_modules()` as live and internal, like Analytics.
- **Permissions (decision D2):** `tools` (view, create, edit drafts, unpublish) for owner and editor; `tools_publish` (publish, delete, restore, featured, bulk actions, category changes, moderation decisions) **owner only**. Sales, viewer and verifier get nothing. The AI Universe roles are unchanged.
- Every write is POST-only, CSRF-protected and same-origin, and is applied inside one transaction with its revision row. GET is read-only. Unknown actions return 400.
- **Validation (server side, pure functions, unit-testable):** slug `[a-z0-9-]{2,120}` and **immutable after creation** (votes, clicks, overlays and the Universe all key on it); name 2-120; short description 20-300; website through `sec_safe_external_url` (http/https only, no private hosts); affiliate URL the same; pricing from a fixed list; category must exist; tags max 12, each 2-30 characters; JSON list fields capped; all output escaped; CSV and text fields guarded against formula injection.
- **Audit:** every change records an actor, action and entity in `crm_audit_log`, plus a full before/after in `adm_tool_revision`. Reads are not audited. No secrets are logged.
- Publishing requires the minimum fields complete (name, category, short description, website, at least one feature) and no duplicate name or website among non-deleted tools (warning, owner can override with a note).

## 4. Functional plan
| Area | Behaviour |
|---|---|
| List and search | Server-rendered, paginated (25/page); search over name, slug, tags; filters: status, category, tag, featured, missing fields; sort by name, updated; counts per status. |
| Create / edit | One accessible form with live character counts and inline errors; starts as `draft`; revision saved per edit; concurrent-edit check via `updated_at` (stale save refused with a clear message). |
| Publish / unpublish | Owner publishes after the readiness check; unpublish is immediate and reversible. |
| Soft delete and restore | Delete sets `status='deleted'` (slug stays reserved); a Trash view lists deleted tools with Restore. No hard delete in this phase. |
| Categories and tags | Add and rename categories (existing table; deleting a category that has tools is blocked); tag rename and merge as a bulk action over `tools.tags`. |
| Bulk actions | Select up to 100: publish, unpublish, soft delete, restore, set category, add or remove tag, featured on or off. Confirm step with the count and names; all-or-nothing transaction; one audit line per tool. |
| Moderation | Queue of `submissions` with status, plan and payment state; approve creates a **draft** tool prefilled from the submission (never auto-published); reject and needs-info record a reason. Paid plans cannot be approved until `payment_status = 'paid'` (existing rule kept); no payment is taken or activated by this module. |
| Featured placements | Schedule level 1 or 2 with start and end dates, see active, scheduled and ended, cancel early; no overlap per tool; mirrors to `tools.featured_level/until`. Placements are labelled as sponsored on the public site as today; editorial ranking independence is untouched. |
| Revision history | Per-tool timeline with diff and "restore this version" (creates a new revision). |

## 5. AI Universe and editorial integrity
The module never reads or writes `uni_*`, verification states, reviews or the Universe API. Verified badges, maker-checker and independent verification are untouched. Featured status does not alter ranking logic (existing editorial-independence tests stay). Classification and curated overlays remain editorial files and still override category display; the module shows them read-only next to the tool.

## 6. Database-free test plan (no MySQL, no containers)
- **Pure logic** moved into `adm_tools_core.php` (validators, slug rules, status transitions, bulk plan builder, featured-overlap checker, readiness check, diff builder, query builder for list filters), tested with plain PHP unit tests and synthetic data.
- **Storage layer behind an interface** (`AdmToolStore`) with an in-memory mock implementation, so create, edit, publish, delete, restore, bulk, moderation and featured flows, plus revision and audit side effects, run with no database.
- **Authorization matrix** for owner, editor, sales, viewer, verifier, anonymous and inactive across every action; CSRF, method and origin rejection; stale-edit refusal; hostile input (XSS, URL schemes, long strings, null bytes, formula injection).
- **Regression:** static checks that `find_tool()` filters by status, the import script guard exists, the migration file contains only `CREATE TABLE IF NOT EXISTS` and the rollback drops only the three tables, and the security manifest lists every new file.
- **Frontend (database-free):** rendered HTML from mock data checked for labels, landmarks and focus order; a no-DB JS syntax check. Playwright and axe against a page that needs a database are **not run** (unverified).
- All go into the routine `ci.yml`. `ci-database.yml` stays manual and unused.

## 7. Milestones and acceptance criteria
| # | Milestone | Acceptance |
|---|---|---|
| M1 | Core logic + mock store + migration/rollback files (not run) | Unit tests pass database-free; migration review file matches section 2; no existing table altered. |
| M2 | Read-only list, search, filters, detail and revision view | Matches mock data; escaped output; owner and editor only; empty, error and "storage not installed" states. |
| M3 | Create, edit, publish, unpublish, soft delete, restore | Authorization matrix, CSRF, validation, stale-edit and audit tests pass. |
| M4 | Categories, tags, bulk actions | All-or-nothing behaviour and limits tested. |
| M5 | Submission moderation | Approve makes a draft only; paid-plan rule enforced; reasons recorded. |
| M6 | Featured placements | No overlaps; mirror fields correct; expiry date logic tested. |
| M7 | UI polish and hardening | Responsive at 360, 768 and 1280 px; 44 px targets; keyboard and reduced-motion; fixes for `find_tool` and the import guard; routine CI green; docs and screenshots (static, from mock data). |

## 8. Interface design
Builds on the Control Center shell: a clean data-table with sticky filters, status chips, a side detail panel on desktop that becomes a full-page form on mobile, and a bulk-action bar. Premium look comes from the existing tokens plus layered card depth, soft shadows and gradients on summary tiles only; CSS only, no WebGL, no new dependencies, motion disabled under `prefers-reduced-motion`.

## 9. Risks
| Risk | Mitigation |
|---|---|
| Production content source unknown (JSON versus MySQL) | Owner confirms (D1); module is inert without the tables. |
| Re-running the import script overwrites edits | Import guard (section 2). |
| First database check of the new code happens on staging, because database tests are paused | Backup, rollback script, staging-only first run, owner approval; stated as unverified in the report. |
| Slug immutability limits renames | Deliberate; name can change, slug cannot. |
| Unpublished tools still in `tools.json` reappear only if the database is down | Documented; the JSON seed is regenerated by an export step in a later phase if wanted. |
| Featured placements are commercial | Labelled sponsored as today; no ranking change. |
| Existing legacy admin duplicates function | Replaced by a link, not deleted. |

## 10. Decisions needed from you
- **D1** Source of truth: MySQL authoritative, JSON seed and fallback (recommended), or keep JSON authoritative (needs a file-locking design and loses transactions). Also tell me whether production currently serves content from MySQL.
- **D2** Permissions: editor may create, edit and unpublish; only the owner publishes, deletes, restores, features and runs bulk actions (recommended), or give editors publishing too.
- **D3** Approve the three additive tables, the no-ALTER rule, and the two guards on existing files (`find_tool` status filter and the import-script guard).
- **D4** Moderation: approve creates a draft only (recommended), or may publish directly.
- **D5** Hard delete: none in 8C (recommended), or an owner-only purge of trashed tools.
- **D6** Scope order: all of M1-M7, or stop after M3 for review first (recommended: review after M3).
