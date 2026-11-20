# One unified AIHubpedia Admin Control Center: investigation and proposal

Status: **INVESTIGATION AND PROPOSAL ONLY.** No code, database record, credential, staging server, production server or Hostinger account was touched. Based on the repository at commit `7c4b35f` (Phase 8D). Implementation waits for the owner's approval of the decisions in section 11.

## 1. Executive summary
- **The confusion is real and has one root cause: AIHubpedia has two unrelated sign-in systems and three different page designs.** Per-person CRM accounts (used by the Control Center, CRM and AI Universe editor) and one shared legacy admin password (used by `admin.php`, `admin-leads.php`, `admin-editorial.php`) do not share a session, a role model or an audit trail.
- **Good news:** the foundation for one system already exists. `/admin-center.php` (Phase 8A to 8D) already provides the shell, the role permissions, CSRF, audit logging and four live modules (Analytics, Tool management, Finance, Overview). CRM and AI Universe are only **linked** to from it; they open in the public-site layout under their own URLs.
- **The proposal:** make `/admin-center.php` the only door. Bring CRM, AI Universe and the three legacy pages **inside** the shell, reusing their existing logic, tables and permission checks; retire the shared legacy password; keep every old URL working (as a wrapper, later a redirect). Add collapsible navigation, a mobile drawer, an environment banner, and an "Account and sign out" control that always shows who you are signed in as.
- **No new database tables are needed.** One small owner action (record that a listing payment was received) closes a gap that the legacy page used to cover.
- **Estimated effort:** about 21 to 28 focused engineering days in seven milestones (about 5 to 6 calendar weeks with review), most of it careful refactoring and testing, not new features.

## 2. Investigation findings
### 2.1 Why the staging session shows "Verifier One (verifier)"
- The Control Center, CRM and AI Universe editor all sign in with **CRM accounts** stored in the `crm_users` table. Each account has a username, a display name and a role. The header shows `display name (role)` of whoever is signed in.
- "Verifier One" does **not** appear anywhere in this repository. It is therefore a **display name of an account row that someone created on the staging database** with `php scripts/crm_user.php add <username> "Verifier One" verifier` (accounts can be created only from the command line; there are no default accounts and none can be created from the web). That fits the Phase 7 staging walk-through, which needs separate editor and verifier accounts to demonstrate independent verification.
- The page shows that name because the browser holds a valid session cookie (`aihub_crm`) for that account (sessions last until the idle limit or the maximum age), or because that account was used to sign in. Nothing in the code grants or changes a role silently: the role comes only from the database row.
- **What cannot be concluded from here:** whether an owner account exists on staging. If none was created, nobody can sign in as owner, which would explain why only the verifier view appears. I did not and will not create or guess any account or password.
- **How to confirm (read-only, by the administrator):** `php scripts/crm_user.php list` (usernames, roles, active flag), then sign out and sign in with the intended owner account. If there is no owner account, create one with the documented command, supplying the password on standard input, never in chat.

### 2.2 Why the legacy admin page says `AIHUB_ADMIN_PASS_HASH` is not configured
- `admin.php`, `admin-leads.php` and `admin-editorial.php` use the **older shared-password system** (`admin_auth.php`, Phase 4). It is deliberately **disabled until the environment setting `AIHUB_ADMIN_PASS_HASH` is set** to a `password_hash()` value. There is no default password. If the setting is absent, the page prints that notice and nothing else.
- On staging it is simply not configured. This is correct, safe behaviour and not a fault.
- **How the owner login is intended to work today, in plain terms:** (1) the **Control Center, CRM and AI Universe** use per-person CRM accounts; the owner is a CRM account with role `owner`. (2) The **three legacy pages** use one shared password that only exists if the administrator sets the hash on the server. The two systems have separate cookies (`aihub_crm`, `aihub_admin`), so signing in to one does not sign in to the other.
- **Governance problem found:** in the legacy editorial verification page the person who verifies a profile **types their own name** into a "reviewer" field, while the login is a shared password. The record therefore proves "someone with the shared password" and not "this named person". The unified system should record the signed-in account instead.
- I did not set or propose any password value. The proposal removes the need for the shared password altogether.

### 2.3 Is the VPS actually running Phase 8D?
**Unknown, and I have no way to check from here** (no staging access by instruction). Nothing in my work deployed anything: Phases 8A to 8D live on feature branches that have never been merged or deployed, and the staging plan has not been approved or executed. The earlier staging packs I prepared (Phase 7 real-data display and Phase 8B) were written procedures only; I executed none of them. The most likely state is Phase 7 or an earlier checkout, but that is an inference.
**The administrator can establish it in two minutes, read-only:**
1. `git -C /var/www/html/AI-PHASE3-TEST rev-parse HEAD` and compare with: Phase 8A `818d93c`, 8B `8aa56ce`, 8C `9e063ad`, 8D `7c4b35f` (later documentation commits do not change the code).
2. `ls /var/www/html/AI-PHASE3-TEST/adm_finance_core.php adm_tools_core.php adm_analytics.php admin-center.php`: each file present means that phase's code is present.
3. Read-only tables check: `SHOW TABLES LIKE 'fin_%'`, `'adm_%'` (tables exist only after the owner-approved migrations; code can be present without them).
4. In the browser as owner: the Control Center sidebar shows **"Phase 8D"** next to Finance when the code is older than 8D, and **"Phase 8C"** next to AI tool management when older than 8C. A live Finance module with "storage not installed" means code is present but the migration has not run.

### 2.4 Inventory of the current administration surfaces
| Entry point | What it does | Sign-in | Roles | Layout |
|---|---|---|---|---|
| `/admin-center.php` | Control Center shell, Analytics, Tool management, Finance, overview tiles | CRM account | owner, plus module-by-module (editor: tools and Universe; sales: CRM and advertising; viewer: CRM; verifier: Universe) | Admin shell (own styles) |
| `/crm.php` | Leads, pipeline, proposals, contracts, campaigns, partners, prospects, outreach, companies, tasks, audit log, users list | CRM account | owner, sales, viewer | **Public site layout** |
| `/universe-editor.php` | AI Universe propose, adopt, independently verify (maker-checker); owner is read-only | CRM account | editor, verifier, owner (read-only) | **Public site layout** |
| `/admin.php` | Public listing **submissions** (reads `data/submissions.json`, approves into `tools.json`), mark paid, featured plan | **Shared legacy password** | any holder | Public site layout |
| `/admin-leads.php` | Public inbound `leads` table: status, retry notification, report | **Shared legacy password** | any holder | Public site layout |
| `/admin-editorial.php` | Tool profile research and **human verification** log | **Shared legacy password** | any holder; reviewer name is typed | Public site layout |
| Public Universe pages | Read-only public pages | none | public | Public |

### 2.5 Duplications and gaps found
- **Two auth systems and two cookies;** the shared password is a single point of weakness and has no per-person audit.
- **Legacy submissions moderation is wrong-source:** the public form writes submissions to the **MySQL** `submissions` table, but `admin.php` reads `submissions.json` (empty) and its approval writes to `tools.json`. Phase 8C Submissions already replaces this properly (draft only, owner decides).
- **Gap:** the legacy "mark paid" action has **no replacement**. Phase 8C approval requires `payment_status = paid`, but no screen sets it. One small owner-only, audited action ("record payment received") is needed, writing the existing column (no schema change). It records a manual bookkeeping fact; it takes no payment.
- Three different page designs and three navigations for one product.
- `adm_modules()` lists "Legacy admin" as a link-out tile.
- The Control Center has planned placeholders for Advertising (8E), Operations (8F) and Performance (8G). Advertising overlaps with CRM campaigns and 8C featured placements and should be a **hub view over existing modules**, not a new system.

## 3. What Phase 8D already gives us
| Capability | Where | Status for unification |
|---|---|---|
| Shell with top bar, sidebar, skip link, light and dark themes, 44 px targets | `adm_page()`, `adm_css()` | Reuse; upgrade the navigation |
| Module registry with groups and permissions | `adm_modules()`, `adm_role_perms()` | Reuse; extend with sub-views |
| Single sign-in, session, CSRF, same-origin, audit | `crm_gate()`, `crm_csrf_ok()`, `adm_audit()` | Reuse unchanged |
| Live in-shell modules | Analytics, Tool management (with Categories, Submissions, Featured), Finance | Reuse unchanged |
| Link-out modules | CRM, AI Universe, Legacy admin | **Must change** (embed) |
| Planned placeholders | Advertising, Operations, Performance | Rework as hubs (see 6.3) |
| CRM already has an audit-log view and a users list | `crm_views()` has `audit`, `users` | Reuse as the Control Center's Audit and Users pages |

## 4. Target design
### 4.1 Principles
1. **One door:** `/admin-center.php` is the only address a person needs. Everything else is reached from its sidebar.
2. **One identity:** per-person CRM accounts for everything; the shared legacy password is retired.
3. **One permission model:** the shell decides what to **show**; every module still **enforces** its own permission and its own checks (defence in depth). Hiding a link is never the control.
4. **Reuse, do not rewrite:** the CRM, Universe and legacy logic, tables and workflows stay; only their output moves into the shell.
5. **Independent verification is untouched:** AI Universe maker-checker rules (editors propose and adopt, verifiers verify independently, the owner is read-only there) are unchanged and tested.
6. **No silent privileges:** no role is created, promoted or assumed by the new shell.

### 4.2 Navigation structure (sidebar, collapsible groups)
| Group | Items (visible only when the role allows) |
|---|---|
| **Overview** | Dashboard (tiles, shortcuts, "needs attention": overdue follow-ups, pending submissions, items awaiting verification) |
| **Sales and customers** | Leads · Pipeline · Companies · Proposals · Contracts · Tasks · Campaigns · Partners · Prospects · Outreach drafts · Inbound forms (the old public leads page) |
| **Content and catalogue** | AI tool management (Tools · Categories and tags · Submissions · Featured) · Tool profile verification · AI Universe (Propose · Adopt · Verify · Review queue) |
| **Growth** | Analytics · Advertising hub (campaigns, featured placements, advertiser submissions, no new tables) |
| **Money** | Finance (Overview · Revenue · Advertising · Projects · Expenses · Refunds · Targets · Exports) |
| **Operations** | Users and roles (read-only list; creation stays CLI-only) · Audit log · System health · Performance (when built) |
| **Account** | Signed-in name and role, "Sign out", and (later) change password |
Planned modules without a build stay hidden from non-owners and are marked "planned" for the owner. Deep addresses stay predictable: `/admin-center.php?m=crm&v=leads`, `?m=universe`, `?m=verify`, `?m=inbound`.

### 4.3 Responsive and accessible interface
- **Desktop:** fixed sidebar, sticky top bar with the signed-in name and role, an **environment banner** (STAGING or PRODUCTION, from a server setting) so nobody mistakes one for the other, breadcrumbs, clear active state (`aria-current`).
- **Tablet:** collapsible sidebar (icons plus labels on demand).
- **Phone:** a "Menu" button opens a full-height drawer; the sidebar no longer sits above the content (the known problem from Phase 8A). Built on native `details`/`summary` elements and CSS so it works without JavaScript, with optional small enhancement for focus handling.
- Collapsible groups are real buttons with `aria-expanded`; open state remembered per user in the browser only; keyboard operable; visible focus; 44 px targets; reduced-motion respected; light and dark; no heavy libraries.
- One component set (cards, tables, forms, badges, empty states) for every module, replacing the public-site styling inside CRM and Universe pages.

### 4.4 Authentication
- **One sign-in page** (CRM accounts), shown by the shell for any admin address. Same security as today: no default accounts, accounts created only from the command line, rate limit, session id regeneration, idle and maximum session timeouts, `Secure`/`HttpOnly`/`SameSite=Strict`, sign-in redirect restricted to local paths.
- **Retire** `AIHUB_ADMIN_PASS_HASH` and `admin_auth.php`: the three legacy pages redirect into the shell. The environment setting can then be removed from servers.
- **Sign-out** from anywhere signs out of everything (one cookie).
- **Optional hardening (separate decision):** one-time-code second factor for the owner, an IP allowlist for the Control Center, self-service password change, and account lock after repeated failures.

### 4.5 Permissions (module visibility; each module also enforces its own rules)
| Module | Owner | Sales | Viewer | Editor | Verifier |
|---|---|---|---|---|---|
| Dashboard | yes | yes | yes | yes | yes |
| CRM (read) | yes | yes | yes | no | no |
| CRM (write, approvals, revenue entries) | owner approvals and revenue; sales write | write | no | no | no |
| Advertising hub | yes | yes | no | no | no |
| AI tool management | full | no | no | create, edit, unpublish | no |
| Tool profile verification | **decision D3** | no | no | no | verify (proposed) |
| AI Universe | **read-only** (as today) | no | no | propose, adopt | verify independently |
| Analytics, Finance | yes | no | no | no | no |
| Users, Audit log, System health | yes | no | no | no | no |
Role changes are made only by an administrator through the existing command-line process.

### 4.6 Preserving existing URLs
Phase 1 (compatibility): old URLs keep working and **render the same module inside the shell** (no redirect), so existing bookmarks, tests and deep links keep working while the sidebar only ever points to `/admin-center.php`. Phase 2 (after acceptance and approval): old URLs return a 302 redirect to their shell equivalent, keeping the query string where meaningful. Public AI Universe pages and all public SEO URLs are not touched.
| Old URL | Shell address |
|---|---|
| `/crm.php?v=leads&id=7` | `/admin-center.php?m=crm&v=leads&id=7` |
| `/universe-editor.php?...` | `/admin-center.php?m=universe&...` |
| `/admin.php` | `/admin-center.php?m=tools&view=submissions` |
| `/admin-leads.php` | `/admin-center.php?m=inbound` |
| `/admin-editorial.php?id=x` | `/admin-center.php?m=verify&id=x` |
Old POST endpoints keep working during phase 1 so cached forms never break.

## 5. Reuse map (no duplicated databases, authentication or workflows)
| Existing | How it is reused | Change needed |
|---|---|---|
| CRM views and handlers (`crm_render_view`, `crm_handle_action`) | Called from the shell; output captured into the shell body; the same handler processes the POST | Make the page wrapper thin; forms post to the shell; keep `/crm.php` as a wrapper |
| Universe editor routing and `uv_handle_post` | Same: moved into a callable that returns the body, keeping all permission and maker-checker checks | Same wrapper approach; owner stays read-only |
| `leads_admin.php` functions (status, retry notification, masked emails, report) | Become the "Inbound forms" view | Render function; CRM roles replace the shared password; detail views stay audited |
| `editorial_review.php` and `editorial_log_append` | Become "Tool profile verification" | **Reviewer comes from the signed-in account**, not a typed name; role rule per D3 |
| 8C Submissions | Replaces legacy listing moderation | Add the small "record payment received" action (writes `submissions.payment_status`, audited, owner only) |
| `crm_audit_log` | Already used by every module | Add an Audit page in the Operations group (reuse the CRM `audit` view) |

## 6. Implementation milestones, acceptance and effort
Effort is in focused engineering days (including tests and review); ranges reflect refactoring risk. Everything stays database-free in routine CI; database and browser verification on a disposable database when approved; staging only after the readiness plan.

| # | Milestone | Acceptance criteria | Days |
|---|---|---|---|
| U1 | **Shell upgrade and navigation registry:** grouped, collapsible sidebar; mobile drawer; breadcrumbs; environment banner; account menu; one sign-in page for every admin address; module registry with sub-views and permissions | Role visibility matrix tests; keyboard and screen-reader checks; axe clean at three sizes in both themes; no change to existing 8A to 8D behaviour | 3 to 4 |
| U2 | **CRM inside the shell:** wrapper approach; all 20 CRM views render in the shell; forms post to the shell; old `/crm.php` works as a wrapper; CRM styles unified | Existing CRM HTTP tests pass against the shell and the old URL; every action still enforces its own role; audit rows unchanged | 4 to 6 |
| U3 | **AI Universe inside the shell:** same approach; maker-checker rules untouched | Phase 7 editor HTTP tests pass (including owner read-only, verifier independence, rate limit); same audit and provenance rows | 3 to 4 |
| U4 | **Retire the shared legacy password:** Inbound forms view; Tool profile verification (reviewer = signed-in account); "record payment received" action; legacy pages redirect or wrap; remove the legacy tile | Every legacy action reachable under CRM roles; no shared-password path remains; verification attribution is per person; masked-email and audit behaviour preserved | 4 to 6 |
| U5 | **Operations pages:** Users and roles (read-only list), Audit log viewer, System health, with filters and CSV export (owner only, audited) | Owner only; no personal data beyond business contact data; export safe | 2 to 3 |
| U6 | **Advertising hub:** one overview over CRM campaigns, 8C featured placements and advertiser submissions; no new tables | Links and counts match their source modules; no duplicated logic; planned placeholders cleaned | 2 to 3 |
| U7 | **Polish and hardening:** consistent components, empty and error states, performance check, full regression tests, documentation and screenshots | Full database-free suite green; real-server HTTP and axe run on a disposable database; staging acceptance script extended | 3 to 4 |
**Total about 21 to 30 days.** The largest risk is U2 and U3 (refactoring pages that print directly into functions that return their output). Optional hardening (second factor, IP allowlist, password change) adds about 3 to 5 days and is a separate decision. U1 and U4 deliver the visible fix quickest if you want a first release: **a single sign-in and one navigation first**, the rest following.

## 7. Security and quality safeguards (unchanged or strengthened)
- Every write stays POST-only with the session CSRF token and a same-origin check; unknown methods are refused; every action audited; `Cache-Control: no-store` and `noindex` on every admin response; direct execution of library files returns 404; sign-in redirects only to local paths.
- **Defence in depth:** the shell filters navigation by role, and each module still checks the role on every request, so a hand-typed URL cannot bypass a hidden link.
- The shared password disappears, removing one credential and one unaudited path. Attribution of verification becomes per person.
- Tests: a role visibility matrix for every sidebar item; a direct-URL authorization matrix for every module and action; CSRF and origin negative tests; maker-checker regression tests; redirect and deep-link tests; accessibility and responsive checks.

## 8. Risks
| Risk | Mitigation |
|---|---|
| Refactoring CRM and Universe output could break workflows | Wrapper-first approach; reuse the existing handlers; run the existing CRM and Universe HTTP suites against both old and new addresses |
| Maker-checker accidentally weakened | Do not change Universe logic; add explicit regression tests (editor cannot verify own proposals; verifier independent; owner read-only) |
| Deep links and bookmarks break | Phase 1 wrapper keeps old URLs; redirects only after approval |
| Session or cookie surprises when the legacy cookie disappears | One cookie after U4; communicate the change; sign out clears everything |
| An owner account does not exist on staging, so nobody can use the shell | Administrator creates it from the command line before testing; never seeded by code |
| Verification workflow change (reviewer identity) affects the existing log | New entries record the account; old entries stay as written |
| Scope creep into new features | Reuse-only rule; new work limited to the shell, one payment action and read-only operations pages |

## 9. What exists in Phase 8D and what changes
- **Already done:** shell, roles, sign-in, CSRF, audit, Analytics, Tool management, Finance.
- **Changes needed:** U1 (shell and navigation), U2 and U3 (embed CRM and Universe), U4 (retire legacy), U5 and U6 (Operations and Advertising hubs), U7 (polish).
- **Not needed:** new database tables, a new authentication system, a rewrite of any business logic.

## 10. What I need from the administrator to settle the open facts (read-only)
1. The staging commit and file checks in section 2.3.
2. `php scripts/crm_user.php list` on staging (usernames, roles, active flag; no passwords).
3. Whether `AIHUB_ADMIN_PASS_HASH` is intended to exist anywhere (staging or production). If production uses it today, the retirement plan must include a short overlap.
4. Whether the staging page the owner saw was `/admin-center.php`, `/crm.php` or `/universe-editor.php` (all show the signed-in name).

## 11. Decisions needed from the owner
- **D1** Approve the single-entry design and the navigation structure in 4.2.
- **D2** Retire the shared legacy password and `admin_auth.php` (recommended), or keep it for a transition period.
- **D3** Who may verify tool profiles: `verifier` only (recommended, matches the independence principle), or verifier and owner.
- **D4** Confirm the owner stays read-only in the AI Universe workflow (current rule).
- **D5** Old URLs: wrapper first, redirect after acceptance (recommended).
- **D6** Optional hardening: second factor for the owner, IP allowlist, self-service password change (separate approval and effort).
- **D7** Build order: all seven milestones, or first release U1 plus U4 (one sign-in, one navigation, no shared password) and the rest afterwards (recommended).
- **D8** Environment banner text and colours for staging and production.
- **D9** The administrator runs the read-only checks in section 10 and shares the results.
