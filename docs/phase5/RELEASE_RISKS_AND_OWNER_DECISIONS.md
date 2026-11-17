# Release risks and owner decisions (Phase 5)

Phase 5 is **not deployed and not merged**. Verdict: suitable for a controlled staging review together with Phase 4/4.1; not for production until the blockers below are closed. Phase 4.2 (final hardening) has not been started.

## Urgent owner action, independent of this work
**Rotate historical credentials** (database, SMTP, admin) that remain in Git history and on `main`: `docs/phase4/MANUAL_CREDENTIAL_ROTATION_CHECKLIST.md`.

## Owner decisions needed
| # | Decision | Why it matters | Recommendation |
|---|---|---|---|
| 1 | Thin-page policy: keep 489 template-only tool pages indexable, or `AIHUB_THIN_TOOL_POLICY=noindex` (617 to 128 sitemap URLs) | Scaled template pages are a quality and search-policy risk; changing it alters what search engines see | Noindex until each page is researched; revisit with Search Console data |
| 2 | Who are the editors, and do you want per-editor accounts? | Verification is only as credible as its reviewers; identity is self-declared today | Name 1 to 2 editors; plan accounts before scaling |
| 3 | Which 5 profiles are verified first | Top of `docs/phase4/editorial_queue.csv` (4 sourced; 12 with conflicts) | Start with chatgpt, claude, notebooklm, sonix |
| 4 | Language rollout and who reviews translations | No translation may be published unreviewed | English evidence first; static trust pages second |
| 5 | Currency, VAT, legal entity for sponsorship; keep $19/$49/$99? | Mixed USD/EUR; Impressum vs TrustNova Tech unresolved | Decide before any price edit |
| 6 | Launch a newsletter? Provider, domain authentication, consent text | Prerequisite to any newsletter sponsorship | Separately approved project |
| 7 | Approve new page types for indexing (comparisons beyond the current 16, alternatives, workflow guides) | Each needs evidence and an editor | Only pages that pass their gate and a human read |
| 8 | Analytics: enable with consent and updated privacy text, or stay off | Needed for any experiment or sponsor reporting | Stay off until legal review |

## Risks
| Risk | Level | Mitigation / status |
|---|---|---|
| 0 of 509 profiles human-verified; editorial capacity is the bottleneck | High (credibility) | Workflow built; labels honest; capacity is an owner matter |
| Credentials in Git history | High (security) | Rotation checklist; owner action |
| Indexable template pages (489) | Medium-High (SEO) | Switch exists; decision 1 |
| Verification identity is self-declared | Medium | Document; accounts later |
| Search metric is team-judged taxonomy relevance, not user data | Medium (interpretation) | Stated in the fixture and report; validate after launch |
| Search time +1.2 ms on one benchmark pair | Low | Recorded; memoised fields |
| JSON review log writable by PHP, not tamper-proof | Low-Medium | Backup; moderation |
| Locale architecture unused until reviewed content exists | Low | Tested rules prevent premature publishing |
| Real mail, proxy/CDN, nginx behaviour, PHPMailer CVEs, load: unverified | Medium | As in Phase 4.1 |

## Not done in this phase (and why)
* **No profile was researched or verified**: no authorised human action and no live vendor access.
* **Task/role filters on search**: the fixture and ranking work came first; the existing category and pricing filters are unchanged. A role filter needs role data the catalogue does not hold (owner/editor input).
* **No translated content, locale routing or switcher UI**: by design until reviewed content exists.
* **Newsletter**: workflow remains mock-only (existing `scripts/newsletter_send_mock.php`); no real send.
* **Competitor re-check**: unreachable; claims remain unverified.

## Rollback
Everything is additive on `feat/aihubpedia-content-authority-phase5`. To revert: do not merge, or revert the merge commit; `data/editorial_review_log.json` is empty by default (if editors used it, copy it before reverting because it is the only record of verifications). Search-ranking changes live in `discovery.php` (three small edits) and can be reverted without data changes.
