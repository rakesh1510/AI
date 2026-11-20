# Unified Admin Control Center: milestone U6 report (Advertising hub)

Branch `feat/aihubpedia-unified-admin`, on top of U5 (`6a1d810`). Not merged, not deployed. No staging, production, Hostinger, database record or credential was touched. No MySQL test was run; every check is database-free.

## 1. Features completed
The **Advertising** module is now live inside the Control Center as a **read-only hub over records that already exist**; it adds no table, no action, no message and no billing.
- **Overview:** campaigns by status (inquiry, in review, approved, scheduled, live, completed; synthetic test records never counted), a summary of featured placements and of paid listing submissions, and a plain "Not built yet" note.
- **Campaigns** (owner and sales): the CRM advertising campaigns with company, status, package, dates, disclosure label and the internal quote, always labelled "indicative, not revenue" (recorded income lives in Finance). Titles link to the CRM campaign page inside the shell (U2) when the role has CRM access; synthetic records are labelled.
- **Featured placements** (owner only): the Phase 8C placement history and schedule, linked to Tool management where they are managed.
- **Paid listing submissions** (owner only): submissions with a price, payment status ("Paid (recorded manually, not bank-verified)", "Unpaid"), decision status, linked to the submission page where the owner confirms payment (U4).
- **Roadmap preserved (D7):** the module keeps its Phase 8E tag and scope. Advertiser profiles, renewal tracking and reminders, sponsored newsletter bookings and approved outbound messages (Phases 8E to 8G) are **not started and not replaced**; the hub says so on screen.

## 2. Files changed
New: `adm_advertising.php`, `tests/unified_admin_u6_test.php`, `docs/admin/U6_REPORT.md`, `docs/admin/screenshots_u6/`. Modified: `adm_core.php` (module live and internal), `adm_nav.php` (four views, two owner-only), `admin-center.php` (GET route only), `adm_crm.php` (link sizes), `tests/adm_shell_preview.php`, `tests/browser_unified_shell_test.js` (group-memory check now waits for the stored value instead of a fixed delay), `tests/security_manifest.json`, `.github/workflows/ci.yml`. CRM campaign code, Tool management, Finance and migrations are unchanged.

## 3. Security and permission checks
- Permission `advertising` unchanged (owner and sales). The placement and submission views need the owner-only `tools_publish`; for any other role those lists are never loaded (not merely hidden) and the view falls back to the overview. Sales sees only campaigns. Editor, viewer and verifier cannot open the module (403, audited).
- No POST route, no forms, no SQL in the module, no email functions (static checks). All output escaped. A role without CRM access gets no CRM links.

## 4. Tests (database-free)
| Check | Result |
|---|---|
| U6: permissions, views, counts, escaping, honest labels, read-only static checks | **23 passed, 0 failed** |
| U1 155, U2 52, U3 42, U4 57, U5 39 | passed |
| Shell browser checks, 27 synthetic pages x 3 sizes x light and dark | **684 passed, 0 failed** |
| Finance 160, tools 115 and 180, categories 54, security 147, inventory 500 | all passed |
| PHP lint of every file, JS syntax | clean |

## 5. Screenshots
`docs/admin/screenshots_u6/`: overview, campaigns and paid listings (owner), and the sales view, at desktop, tablet and mobile (synthetic data).

## 6. Known issues and limits (unverified)
- **Not verified against a database:** `crm_campaigns_list()` with real rows, the placement and submission queries in the hub, and tool-management readiness on a real server. The phase 8A database test (not run) may still expect Advertising to be a planned module; update it if the disposable-database suite is run.
- No campaign-to-revenue linking and no renewal data exist yet (Phase 8E). The hub shows what is recorded, nothing inferred.

## 7. Commit and branch
Branch `feat/aihubpedia-unified-admin`; draft PR not yet opened.
