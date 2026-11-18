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
