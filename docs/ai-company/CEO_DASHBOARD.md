# CEO dashboard: TrustNova Tech & AIHubpedia

This is a project document, updated each time the team is invoked. It is not a live dashboard, and agents do not run in the background.
**Last updated:** 2026-11-18 · **Branch:** `feat/aihubpedia-global-revenue-phase6` @ `f735cf6` · **PR:** rakesh1510/AI#7 (draft, not merged, not deployed)

## Headline
- The seven-agent team is set up and validated (`.claude/agents/`, see `docs/ai-company/README.md`).
- **QA verdict on `452e5ea`: NOT READY.** 4,542 local checks passed and 0 failed (MariaDB 10.11 + Chromium), and the authorization checks found no holes. Two things block readiness: (1) GitHub CI was red; (2) QA-BUG-002, where the CRM can file unrelated inquirers under the same company. Full report: `qa/RELEASE_READINESS.md`.
- Priorities: 6 of 8 verified. #6 (analytics dashboard) and #8 (UX: Chromium only) are partially verified. Database integration on MySQL 8 and against your live schema is still unchecked.
- The CI red was a defect in the test harness, not in the migration. The active Phase 6 session pushed a fix in `f735cf6`, and CI on it was running when this was written. The Phase 6 final report's claim that "all suites passed" was not true on CI at the time.

## Agents

| Agent | Current assignment | Status | Completed deliverables | Blockers | Next action |
|---|---|---|---|---|---|
| ai-coo | Phase 6 coordination, backlog | in-progress | `BACKLOG.md`, this dashboard | — | Consolidate the QA verdict, then queue the business tasks |
| development | DEV-003 CI harness fix (pushed `f735cf6`, waiting for CI); DEV-002 QA-BUG-002; DEV-004 QA-BUG-003 | in-review / todo | Regenerated the sitemap with the repo's generator. It matched `1272812`, so no duplicate commit. No-DB suites pass on `452e5ea` (phase4_logic 8657/0, security 147/0, security_inventory 386/0, lint 216 files clean) | — | Fix QA-BUG-003; fix QA-BUG-002 once the CEO approves the matching rule |
| qa-testing | QA-004: re-verify after the CI fix and bug fixes | todo | `qa/RELEASE_READINESS.md` (QA-001/002 done, verdict not ready) | QA-003 needs the sanitized schema | Re-run on the green head |
| b2b-sales | SALES-001 client-acquisition workflow | todo (queued) | earlier session: `docs/phase6/TRUSTNOVA_CLIENT_ACQUISITION.md` | Waits for a CEO go-ahead | Build on the existing doc |
| marketing-growth | MKT-001 Futurepedia benchmark refresh | todo (queued) | earlier session: `docs/phase6/FUTUREPEDIA_COMPETITOR_ANALYSIS.md` (unverified, search-based) | Waits for a CEO go-ahead | Direct-observation benchmark with sources |
| revenue-strategy | REV-001 monetization strategy | todo (queued, recommended first) | earlier session: `docs/phase6/REVENUE_MODEL_AND_UNIT_ECONOMICS.md` | Waits for a CEO go-ahead | Stream ranking, pricing pilots, scenarios |
| partnerships | PART-001 opportunity list | todo (queued) | earlier session: `docs/phase6/PARTNERSHIP_MANAGEMENT.md` | Waits for a CEO go-ahead | Affiliate and vendor research |

## CEO approvals and inputs required

| # | Item | Type | Recommendation |
|---|---|---|---|
| 1 | **Rotate credentials still in Git history** (DB, SMTP, admin) | Security, urgent | Do now. Checklist: `docs/phase4/MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md` |
| 2 | Provide a **sanitized, data-free schema** (DDL only) for QA-003 | Input | Share it as a file. It is passed via `AIHUB_LIVE_SCHEMA_FILE` and never committed. The earlier export contained data rows |
| 3 | Confirm the **Phase 6 master spec** priority list matches the 8 in `FINAL_PHASE6_IMPLEMENTATION_REPORT.md` | Input | Share the spec text, or confirm |
| 4 | Go-ahead for the business tasks (REV-001 → SALES-001 → MKT-001 → PART-001) | Scope | Run them one at a time to control cost |
| 5 | **QA-BUG-002 matching rule**: stop merging inquirers into a company by domain alone; require a matching company name, and widen the free-mail list | Product | Approve. It prevents mis-filed leads and stops visitors attaching leads to real customers |
| 6 | PR #7 merge / staging / deploy | CEO-only | Not yet: QA says not ready. Decide after QA-004. Note: **a push to `main` auto-deploys to the VPS** |
| 7 | Open owner decisions from Phase 6 (CRM users and roles, data retention, EUR 199 placement, newsletter sales, currency and VAT, MFA) | Business | See `docs/phase6/RELEASE_RISKS_AND_OWNER_DECISIONS.md` |
