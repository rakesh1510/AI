# Unified Admin Control Center: milestone U1 report (shell, navigation, sign-in, account)

Branch `feat/aihubpedia-unified-admin`, from the Phase 8D code (`7c4b35f`). Approved proposal: `c1cce8e` (decisions D1 to D9). Not merged, not deployed. No staging, production, Hostinger, database record or credential was touched. No MySQL test was run (not authorized for this work); every check below is database-free.

## 1. Features completed
- **One sign-in experience for the Control Center.** `adm_gate()` renders a standalone, branded sign-in page (not the public-site layout) and then calls the **existing** CRM account functions (`crm_login`, rate limit, session id regeneration, idle and maximum timeouts, CSRF token, same-origin check, local-path-only redirect). It creates no account, session system or password hash. Setup notices ("not set up", "no accounts") use the same design.
- **Environment banner (D8).** Amber `STAGING`, red `PRODUCTION`, grey for development, local or an unset or unknown value ("ENVIRONMENT NOT SET: ask the administrator to set `AIHUB_ENV_LABEL`"). The setting is read through the existing `sec_setting()`; hostile or unknown values are never trusted. The banner shows on every Control Center page and on the sign-in page.
- **Always-visible identity.** Name and role chip in the top bar on every page; it links to the account page. Consistent **Sign out** (POST with CSRF token) in the same place on every page.
- **Grouped, collapsible navigation.** Registry in `adm_nav.php` (single source: labels and addresses still come from `adm_modules()`): Overview; Sales and customers; Content and catalogue; Growth; Money; Operations; Account. Groups are native `details` elements; the active module is highlighted and shows its own sub-views (Finance 8, Tool management 4; CRM's 11 are defined and appear when CRM moves into the shell in U2); empty groups are hidden; planned modules show their phase. Collapsed groups are remembered in this browser only.
- **Mobile drawer.** "Menu" button, drawer with Close button, scrim, Escape to close, focus moves into the drawer and returns to the button, Tab stays inside while open, closes on tap outside and on resize to desktop. Progressive enhancement: without JavaScript the menu is a normal stacked list. One small external file, `assets/js/admin-shell.js`, no libraries, no network calls.
- **Breadcrumbs** (`Control Center / Group / Module / View`) that never mention a module the role cannot open.
- **Account and password page** (`?m=account`, every role): identity, role, the modules the role may open, **self-service password change** (D6), sign out, and a "Two-factor sign-in: not enabled" panel (MFA is prepared in `docs/admin/MFA_PREPARATION.md`, not implemented).
- **Password change rules:** it always changes the **signed-in user's own** password (the account id comes from the session, never from the request); the current password must be entered; at least 12 characters, at most 200, not the current one, not too repetitive, not containing the username or name, not an obvious weak choice; the two new entries must match; rate limited (5 attempts per 15 minutes) with a 429; every outcome audited (`account_password_changed`, `_failed`, `_rate_limited`); after a change the session id and CSRF token are renewed; password fields are never echoed.
- **Permissions:** the only permission added is `account` (every role may manage its own account). No role gained any other permission; the owner set is otherwise unchanged. IP allowlisting was **not** added (D6).
- **Read-only VPS verification commands** prepared for the administrator (D9): `docs/admin/READ_ONLY_VPS_VERIFICATION.md`. I did not and will not run them.

## 2. Files changed
New: `adm_nav.php`, `adm_auth.php`, `adm_account.php`, `assets/js/admin-shell.js`, `tests/unified_admin_u1_test.php`, `tests/adm_shell_preview.php`, `tests/browser_unified_shell_test.js`, `docs/admin/U1_REPORT.md`, `docs/admin/MFA_PREPARATION.md`, `docs/admin/READ_ONLY_VPS_VERIFICATION.md`, screenshots.
Modified: `adm_core.php` (new shell markup and styles, `account` module and permission), `admin-center.php` (single gate, account route and password POST after the CSRF check), `tests/security_inventory_test.php` (accepts the new gate and the validated-redirect library), `tests/security_manifest.json`, `.github/workflows/ci.yml` (two database-free steps). **Not changed:** CRM, AI Universe, legacy admin, Analytics, Tool management, Finance logic, databases, migrations.

## 3. Security and permission checks
- Visibility matrix (database-free): for each of the five roles and every module, the menu item is shown **exactly when the permission is held**; an unknown role sees nothing; a verifier sees only Overview, Content and Account; an editor sees no Finance, Analytics, Operations or Sales; breadcrumbs never reveal an inaccessible module. The menu is cosmetic: **every module still enforces its own permission** (unchanged code).
- Sign-in reuses the existing token, same-origin, rate-limit and redirect checks; a static test confirms the gate creates no accounts, no hashes and no session system.
- Password change: POST only, after the CSRF and same-origin check (asserted by source order); own account only; rate limit and audit tested; session id and CSRF token renewed.
- Shell output: all user-supplied text escaped; no inline event handlers; only one external script plus a one-line class switch; `noindex`, `no-store` retained.
- **Not yet done (later milestones):** retire the shared legacy password. **Per D2 it is not removed**, and no legacy access is changed until a working owner login is confirmed on staging.

## 4. Tests (database-free)
| Check | Result |
|---|---|
| U1 logic, shell markup, banner, sign-in, account and password change, static checks | **145 passed, 0 failed** |
| U1 browser checks on 10 synthetic pages x 3 sizes x light and dark: axe WCAG 2.x A/AA, overflow, 44 px targets, skip link, banner colours, drawer open, focus, Escape, scrim, Tab trap, remembered group collapse | **276 passed, 0 failed** |
| Existing screens after the shell change: Finance 264, Tool management 408 (axe, overflow, targets) | all passed |
| Finance 160, tools 115 and 174, category safeguards 54 | all passed |
| Security inventory 485, security regression 147, secret-init 10, salt 10, i18n 35, content backlog 27 | all passed |
| PHP lint (every file), JS syntax, built assets | clean (see commit status) |

Two existing tests were adjusted deliberately: the security inventory now accepts `adm_gate()` as the Control Center gate and lists `adm_auth.php` among libraries that redirect only to a validated local path.

## 5. Screenshots
`docs/admin/screenshots_u1/`: owner on staging (desktop, tablet, mobile; dark desktop), production banner, editor on mobile, account page (desktop, mobile), sign-in (desktop, mobile), and the mobile drawer open. Synthetic preview content only.

## 6. Known issues and limits (staging-dependent items are unverified)
- **Database-dependent behaviour is unverified:** the real sign-in and sign-out flow through `adm_gate()`, the password-change SQL (`UPDATE crm_users`), the CSRF and session behaviour on a live server, and the phase 8A database test's navigation expectations (it expects module labels, which are unchanged, but it was not run). These belong to the staging acceptance script and any separately authorized disposable-database run.
- CRM, AI Universe and the legacy admin still open on their own pages (U2 to U4). Their sub-views appear in the sidebar only after they move inside the shell.
- Other signed-in browsers of the same account stay signed in after a password change (sessions are not centrally revocable); documented on the account page.
- The sidebar is not sticky on desktop (long menus scroll with the page).
- `AIHUB_ENV_LABEL` must be set on each server for the banner to say STAGING or PRODUCTION; otherwise it says "ENVIRONMENT NOT SET".

## 7. Commit and branch
Branch `feat/aihubpedia-unified-admin` (see the commit hash in the report message). Draft pull request not yet opened.
