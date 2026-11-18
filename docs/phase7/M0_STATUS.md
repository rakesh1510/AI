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
