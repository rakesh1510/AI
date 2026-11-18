# Phase 6 baseline and feature-gap audit

**Base verified:** `feat/aihubpedia-content-authority-phase5` at `eba6ac0f49b6d5700eec3c9ddde08dcd7041f00d` (matches the commit named in the brief; the Phase 5 draft PR #6 had passed CI on it). Phase 6 branch: `feat/aihubpedia-global-revenue-phase6`. It did not exist locally or on GitHub before this work. Phase 4.2 was not started. No production system, Hostinger, live database or credential was touched.

## 1. Re-verified baseline (measured, not assumed)
| Fact | Measured on the base | Source of the check |
|---|---|---|
| Catalogue profiles | 509 | `tests/editorial_review_test.php` |
| Reviewed (curated) profiles | 20 | same |
| Editor-verified profiles | **0** | same (and the committed review log is empty) |
| Top-level PHP files classified in the security manifest | 109 before Phase 6, 123 after | `tests/security_inventory_test.php` |
| Tables defined by committed SQL before Phase 6 | 17 (tools, categories, posts, use_cases, site_settings, tool_clicks, tool_votes, submissions, contact_messages, leads, lead_status_history, subscribers, events, page_views, three compare tables) | `database/*.sql`, `tests/phase6_migration_test.php` |
| Live "aihub" schema | **Supplied mid-phase and checked** (12 live tables; 6 earlier-phase tables absent live; not committed) | see DATABASE_COMPATIBILITY_AND_MIGRATIONS.md |

## 2. What already existed (reused, not rebuilt)
| Area | Existing | Reused how |
|---|---|---|
| Public lead capture | `inquiry.php`, `lead_validate()`, honeypot, time trap, per-network rate limit, owner notification with failure tracking, `leads` table, `/admin-leads.php` status workflow | New lead types were added to `lead_types()`; the Phase 6 handlers call the same validation, storage and notification, then mirror into the CRM |
| Advertiser journey | `advertise.php`, packages JSON, $19/$49/$99 public prices, inquiry-only monthly and newsletter items | Kept as is; the inquiry form gained category-sponsorship and custom-campaign types; reporting definitions were added |
| Attribution | `lead_attribution_from_request()` (utm slugs) | Stored on CRM leads and counted per campaign code |
| Admin | single-password admin (`admin_auth.php`), editorial admin, leads admin | **Left unchanged.** The CRM has its own user model (see below) |
| Security controls | CSRF, same-origin, file-based rate limiter, trusted-proxy IP logic, security log without PII, blocked-file rules, endpoint manifest, secrets scan, hostile-request suite | Extended to every new file and endpoint |
| Mail | PHPMailer wrapper with file/SMTP transports | Used only for the existing owner notification; **no CRM code sends mail** (a test enforces this) |
| Editorial independence | evidence ledger, deterministic wizard, tests that sponsorship does not change rankings | A new test also proves ranking, recommendation, evidence and sitemap code never read CRM data |

## 3. Gaps found against the Phase 6 brief, and their state now
| Brief item | Gap | State after Phase 6 |
|---|---|---|
| Reliable user identity for assignment and audit | One shared admin password: no identity, no roles, no audit trail | **Resolved safely:** `crm_users` table, roles (owner, sales, viewer), session auth, per-user audit log; users created only from the CLI (no default accounts, no web user creation) |
| Companies, contacts, leads, opportunities, pipeline, tasks, notes, audit | None existed beyond the flat `leads` table | Implemented (`crm_*` tables, `crm_sales.php`) |
| Duplicate detection | None | Same-email, same-domain and same-name detection; free-mail domains never merge companies; flagged, never auto-merged |
| Proposals, versions, line items, tax, discount, currency, contracts, renewals, cancellations | None | Implemented in integer minor units (`crm_money.php`) |
| Revenue classification (proposed, forecast, contracted, invoiced, received, recognized) | None | Implemented and kept apart; per currency, never mixed |
| Executive dashboard | `scripts/business_report.php` (CLI counts) | Secure web dashboard with honest empty states |
| Advertising campaign review workflow | Leads only | Campaign records with approval gates (disclosure label, safe URL), attribution codes, reporting definitions |
| Partnership management | A single "affiliate partner" lead type | Application form, review workflow, public listing only after verification |
| TrustNova service pages and guided project inquiry | One generic consultation form (`business.php`) | 10 service pages and a guided inquiry connected to the CRM |
| Ethical business development | None | Prospect research records, outreach **drafts only**, consent and unsubscribe handling |
| Competitor research | Phase 4 benchmark (competitor unreachable) | Updated from public search results, still unverified (FUTUREPEDIA_COMPETITOR_ANALYSIS.md) |
| Schema compatibility with the live "aihub" database | Unknown until the schema arrived | Checked against the supplied schema: no conflicts; one incompatibility (forms needed the missing `leads` table) fixed; migration tested on a live-shaped synthetic database |

## 4. Gaps that remain (honest list)
* Real e-mail delivery, payments, invoicing, subscriptions: intentionally not built (the brief forbids activating them).
* Per-editor identity for the older admin pages: they still use one shared password; only the CRM has user identity.
* Role-based access is coarse (three roles); no per-record ownership restriction.
* No multi-factor authentication, no password reset flow, no account lockout beyond the rate limiter (passwords are set by an owner from the command line).
* Public pricing for the proposed EUR 199 and EUR 499 offers, newsletter sales and any audience figure remain unapproved and unpublished.
* 489 of 509 tool pages are still template-only listings (Phase 5 finding; the `AIHUB_THIN_TOOL_POLICY` decision is unchanged).
