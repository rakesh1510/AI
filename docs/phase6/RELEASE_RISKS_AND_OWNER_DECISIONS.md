# Release risks and owner decisions (Phase 6)

Phase 6 is **not merged and not deployed**; the draft PR targets the Phase 5 branch. It is suitable for a controlled **staging** review (see Phase 4 `STAGING_DEPLOYMENT_INSTRUCTIONS.md`, plus the steps below), not for production. Phase 4.2 has not been started.

## Urgent, independent of this work
**Rotate the credentials that remain in Git history** (database, SMTP, admin): `docs/phase4/MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md`; see SECURITY_AND_PRIVACY_REVIEW.md for the scan summary.

## Blocked on information you must supply
| # | Item | Why blocked |
|---|---|---|
| 1 | **The schema export you supplied contained data rows** (not schema-only) | It was not committed or printed; only its DDL was extracted locally. Treat the original as sensitive and share a truly schema-only export next time (DATABASE doc) |
| 2 | A working mail provider and authenticated sending domain (SPF/DKIM/DMARC) | Needed for any real notification or newsletter; none was used |
| 3 | Legal entity and texts for TrustNova Tech (Impressum, privacy policy for CRM data, retention period) | Forms now store business contact data in a CRM |

## Decisions needed
| # | Decision | Recommendation |
|---|---|---|
| 1 | Who are the CRM users and roles? Who gets owner? | One or two owners; others sales or viewer; create from the CLI; restrict `/crm.php` by IP if possible |
| 2 | Retention and deletion policy for CRM personal data | Define (for example delete disqualified leads after 12 months) and add a documented purge routine before launch |
| 3 | Reply routine and target for inquiries | A named person and a same-business-day target; the CRM shows unassigned and overdue items |
| 4 | Publish EUR 199 featured placement? | **No** until a pilot; keep inquiry-only and record internal quotes |
| 5 | Sell newsletter sponsorship? | **No**: blocked until a launched newsletter with measured clicks |
| 6 | Currency, VAT and invoicing entity | Decide before any price edit; the CRM supports seven currencies but does not convert |
| 7 | Verification meaning for partners | Signed agreement or public announcement plus an https source; keep the list empty until true |
| 8 | Thin-page indexing policy (489 template pages) | Noindex until researched (Phase 5 recommendation, unchanged) |
| 9 | Service pages: which to feature, any reference or case study | Only with evidence and client permission |
| 10 | Approve any outreach | Outreach is drafts only; any contact is manual and outside the system |
| 11 | MFA and password reset for the CRM | Add before more than one or two people use it |

## Risks
| Risk | Level | Mitigation |
|---|---|---|
| Historical credentials in Git | High | Rotate (urgent) |
| CRM holds personal data without a retention routine | Medium | Decide policy; manual purge until built |
| Single-factor CRM login | Medium | Strong passwords, IP restriction, MFA later |
| Live database lacks the Phase 2–4 tables (leads, subscribers, events, page_views, contact_messages) | Medium | Apply the documented order on a copy first; forms already degrade safely and the CRM-only path keeps inquiries |
| Unanswered inquiries | Medium (commercial) | Reply routine; dashboard shows unassigned and overdue |
| Demand for paid placements unproven; no audience data | High (commercial) | Services first; pilots before prices |
| Forecast figures misread as revenue | Medium | Labelled hypothetical; separated vocabulary |
| Editorial capacity (0 of 509 verified) | High (credibility) | Owner decision on editors |
| Apache and real-proxy behaviour not re-verified here | Low–Medium | Re-run the Apache validation script and staging checks |
| Real mail, nginx, PHPMailer CVEs, load | Medium | As in earlier phases |

## What was intentionally not built
Payments, invoicing, subscriptions, automated billing, e-mail sending (including outreach and newsletters), AI lead matching, an enterprise marketplace, public placements driven from CRM data, partner logos, import/export, password reset, MFA. All are P4 or need approval.

## Rollback
Everything is additive: do not merge, or revert the merge. For the database run `database/phase6_rollback.sql` **only** if the CRM holds no real data (export first otherwise); it removes only `crm_*` tables. See DATABASE doc.

## Safe, non-production testing plan
1. Fresh clone of the Phase 6 branch on a laptop or CI.
2. `bash tests/setup_test_db.sh` against a local throw-away database named `*test*`.
3. Start `php -S 127.0.0.1:8002 -t . tests/router.php` with `AIHUB_ADMIN_PASS_HASH` and the mail sink (`tests/smtp_sink.py`).
4. Create a CRM user with `scripts/crm_user.php`, sign in, submit the public forms with fake data and watch records appear.
5. Run the suites in TEST_RESULTS.md. Delete the throw-away database afterwards. Never use real contacts or credentials.
