# Phase 7 M0 status (engineering record)

Branch `feat/aihubpedia-ai-universe-phase7`, based on the Phase 6 branch (draft PR targets the Phase 6 branch, never `main`).

## Dependencies on Phase 6
- `database/phase7_universe.sql` references `crm_users(id)` (reviewer, proposer and verifier identities): `database/phase6_crm.sql` must be applied first.
- Roles (`editor`, `verifier`) will extend `crm_roles()` in `crm_core.php`; CSRF and same-origin checks reuse `crm_csrf_ok()`.
- Phase 6 fixes included: QA-BUG-002/003, secret-init recursion, CI on MySQL 8.0 and 8.4, deterministic tampered-token test.

## Done (checked)
| Item | Evidence |
|---|---|
| `uni_*` schema (11 tables), additive and idempotent | `tests/phase7_schema_test.php`: 245 passed, 0 failed on MySQL 8.0.46 (local); agent run on MariaDB 10.11: 249 passed |
| Rollback, re-apply, FK RESTRICT everywhere | same test |
| Invariants I1-I7 enforced by CHECKs (portable) and optional triggers (`phase7_triggers.sql`), each with a bypass-attempt test | same test; the no-trigger mode lists 6 documented gaps that the PHP service layer must close |

## Not done yet
- MySQL 8.4 result (CI will provide), PHP service layer, role changes, read APIs, importer, editor UI, graph prototype integration, pilot data (0 records exist; nothing is verified).
- "No-TRIGGER-privilege host" simulation is skipped unless `P7_ALLOW_CREATE_USER=1` on a private server.

## Open design questions
1. Parents with the same month/year at coarse precision tie under the strict "earlier" rule.
2. Reviewer role check at review insert time (trigger) vs service layer.
3. Case/trailing-space variants of state values under a case-insensitive collation.
4. 20% second-reviewer sampling lives in the service layer.
5. Source edits on verified rows are blocked, not flagged.

## Graph rendering prototype (`prototypes/universe-graph/`, synthetic data only)
Canvas + d3-force (worker) + d3-zoom + quadtree hit-test; accessible table view; six separate relationship types; public mode never draws unverified edges. Measured (two headless runs, indicative only): 500 and 5,000 nodes pass the thresholds (p95 frame <= 33 ms desktop, <= 50 ms mobile with 4x CPU throttle); 50,000 nodes pass on desktop only with clustering or progressive loading; 50,000 on mobile fails or sits at the threshold (50.0 and 50.1 ms). Not tested: real devices, GPU, screen readers, Firefox, WebKit. Not integrated with the PHP APIs yet.

## QA review round 1 (independent agent, executed against a scratch database) and fixes
Confirmed bypasses, all fixed and now covered by tests (`tests/phase7_schema_test.php`, section 6b; 257 passed, 0 failed on MySQL 8.0.46 locally):
1. HIGH: rewrite `proposed_by` then self-verify in one UPDATE: `proposed_by` and `origin` are now immutable (trigger).
2. HIGH: flip `origin` import -> human while verifying: same fix.
3. HIGH: REPLACE INTO / DELETE of a verified row: BEFORE DELETE triggers on entity, event, edge, release, correction.
4. MED: verified -> superseded without a successor: now requires a verified successor version (is_current may only move 0 -> 1 on a verified row).
5. MED: edge verified with unverified endpoints; source links added to verified rows; source `created_by`/`origin` changed under a verified row: all rejected.
7. LOW: state/origin case and trailing-space variants and unsafe source URLs: CHECKs are now case-sensitive, reject trailing spaces, and the URL CHECK allows only `https?://` without whitespace or `<>"`.
Not fixable in SQL (documented gap): TRUNCATE, DROP TRIGGER and ALTER ... DROP CHECK are DDL. The application database user must NOT hold DROP, ALTER, TRIGGER, DELETE or TRUNCATE rights (grant review is part of staging verification).
Prototype findings fixed: `?mode=review` can no longer be switched on from the URL (needs a flag set only by an authenticated editorial page; real deployments must filter on the server), and every interpolated field in the detail panel is escaped. Still open: table `role=grid` accessibility polish, edge-label overlap, real-device measurements.

## PHP service layer (`uni_core.php`) and roles
- `editor` and `verifier` added to `crm_roles()` (no CRM page permissions; Phase 6 roles unchanged). Verification needs the literal role `verifier` (owner cannot verify), maker != checker, a different user for the proposer, source with non-empty quote and retrieved date, AI-assisted/import rows only through a human review, 20% deterministic second-review sampling (first 25 always), corrections create new versions, duplicates and conflicts are flagged in the audit log (never auto-merged).
- Public reads return only verified, current rows, with a lineage `ref` instead of database ids; no proposer/reviewer identity, origin or notes.
- Version publish order changed after the QA review: successor verified first, then predecessor superseded, then successor made current.
- `tests/phase7_service_test.php`: 727 passed, 0 failed on MySQL 8.0.46 (runs the whole suite without triggers and with triggers).
- Open: concurrent double-review is covered sequentially only (unique review key + row lock); entities have no source table so an entity can be verified without a source; HTTP editor UI and read endpoints not built yet.

## Graph prototype round 2 (accessibility, labels, mobile limits), verified by a second run
- Accessibility: native table with row headers, skip links, live announcements, keyboard pan/zoom/select, dark theme. axe-core 4.10.2: 0 violation nodes in 32 runs (8 UI states x light/dark x desktop/360px), reproduced independently; keyboard/focus script passes. Not tested: real screen readers, Firefox, WebKit; some contrast results are "incomplete" in axe (not verified by eye).
- Label collisions: overlapping label pairs 94.8-97.4% before, 0 after (labels are suppressed and counted in a "+N hidden" pill; zero overlap is by construction, not proof of readability).
- Mobile (4x CPU throttle, headless Chromium, software canvas): 50,000 nodes default frame p95 83.4 ms before, 16.8 ms after, but the default now draws a 2,000-company aggregate overview and loads neighbourhoods progressively; the same code with limits off still fails (83.3 ms). Unclustered 50,000 on mobile: 33.4 ms. First paint regressed (mobile 50,000: about 1.2 s to 1.9-2.0 s) because of the new data adapter; not yet optimised. The CPU throttle does not slow the layout worker; no real devices or GPU measured.
- The data adapter (`data-source.js`) has a synthetic source; the HTTP source is not implemented yet (next task: connect to `universe-api.php`).

## Public pages: Heatmap, Lineage, Battle Arena (verified data only)
- `universe-heatmap.php`, `universe-lineage.php`, `universe-arena.php` on `uni_public_*`: yearly/monthly/weekly calendar with clickable days and an accessible table, coarse-precision events listed separately and never drawn as single days; lineage with commit history, SVG branch graph (separate labelled relationship types, ancestry only where documented), version diff and timeline; arena compare with explicit "Unavailable: no verified data", no winner or score invented. Pages without verified data are noindex and not in the sitemap.
- Local checks (MySQL 8.0.46): pages test 309 passed, 0 failed; browser test 680 passed, 0 failed including axe on 100 page states (public, sign-in, editor, verifier, owner; desktop and 360px, light and dark) with 0 violations, keyboard-only, no horizontal overflow, 44px targets, no-JS, reduced motion; SEO 3,184 passed; security inventory 415 passed; negative security 150 passed.
- Not done: navigation links to the new pages, Lighthouse/visual review; arena "features/platforms/benchmarks" come from release change_set keys because the compare route has no such fields.

## Graph connected to the verified-data API (`universe-graph.php`)
- `prototypes/universe-graph/data-source.js` now has a real HTTP source (paged, abortable, validated, DOM-only rendering) for `universe-api.php`; the production page `universe-graph.php` forces it (no synthetic data, no review mode) and works without JS as a server-rendered list; the synthetic source remains in the prototype page only, labelled SYNTHETIC TEST DATA. App assets are served from `assets/universe/` (byte copy built by `scripts/build_assets.php`).
- When the ledger tables are not installed yet (additive migration not applied), the page shows the honest empty state (200, noindex) instead of a 5xx; found by the negative-security suite and fixed.
- The API has no per-relationship verification date, so the page shows each source's retrieval date and says so. At most 2,000 entities are loaded; relationships to entities outside the list are dropped and counted.
- All results below were re-run on MySQL 8.0.46 (the sandbox's default server had silently become MariaDB 10.11 after a restart; runs made on it were discarded and repeated): graph page test 206/0, http-source browser test 117/0 with axe 0 violations in 36 runs, pages test 309/0, browser test 680/0 (axe 0 violations on 100 page states), editor HTTP 277/0, schema 260/0, service 731/0, API 298/0, concurrency 2,528/0, privilege 405/0, SEO 3,184/0, security HTTP 76/0, negative security 150/0, inventory 418/0.

## Source-backed comparison facts (Arena), importer, superseded-endpoint edges
- New additive tables `uni_fact`, `uni_fact_source`, `uni_fact_review` (`database/phase7_facts.sql`, triggers, rollback); fact types feature, platform, integration, pricing, benchmark, limit; same provenance rules as events (source with a quote, different human verifier, imports never verified, verified rows immutable); no score, ranking or winner field exists. Compare API returns `facts`, `fact_matrix` and `fact_notes` (verified facts only, "Unavailable" otherwise); the editor can propose facts. No existing table definition or data was changed; when the fact tables are absent everything degrades to empty reads.
- CLI importer (`scripts/uni_import.php`): proposed, origin `import` only, dry-run default, editor user required, never verified, no network; see `IMPORTER.md` and `SECURITY_AND_PRIVILEGES.md`.
- Edges proposed before an endpoint entity was superseded now verify against the live entity rows (endpoints re-pointed), in both trigger modes.
- MySQL 8.0.46 focused runs: facts 473/0, schema 260/0, service 743/0, API 298/0, pages 309/0, editor HTTP 277/0, importer 235/0 (one failure seen once while another suite ran at the same time; 3 clean solo re-runs), privilege 405/0, graph page 206/0, inventory 423/0.
- Not yet done: the facts migration is not in an installer script (applied manually by an admin, like the other Phase 7 files); fact review sampling counts only fact accepts.
