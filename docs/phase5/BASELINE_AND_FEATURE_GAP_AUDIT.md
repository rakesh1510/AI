# Phase 5 baseline and feature-gap audit

**Baseline:** `feat/aihubpedia-global-growth-phase4` at `1ff5b37` (verified against `origin` before branching; CI run #22 on that commit passed). Phase 5 branch: `feat/aihubpedia-content-authority-phase5`. All counts below were measured on this branch with read-only PHP one-liners (the commands are listed at the end) so they can be reproduced.

## 1. Verified counts
| Fact | Measured | Phase 4.1 report said | Match |
|---|---|---|---|
| Catalogue profiles | 509 | 509 | yes |
| Curated (reviewed) profiles | 20 | 20 | yes |
| Human/editor-verified profiles | **0** | 0 | yes |
| Evidence levels | 489 not reviewed, 16 limited evidence, 4 sourced-awaiting-verification | not itemised | – |
| Profiles that still carry bulk-generated template features/pros | 489 | 479 P3 in queue | consistent (489 = all non-curated) |
| Boilerplate SEO titles and descriptions | 489 / 489 | – | new figure |
| Tools with an affiliate field set | 0 | – | new figure |
| Problem-solution pages / guides / comparison pairs shown | 10 / 4 / 16 | – | – |
| Sitemap URLs | 617 | 618 (HTTP test counted one extra) | ±1, see note |
| Public sponsorship packages | free, $19, $49, $99 (+2 inquiry-only) | – | – |

Note on 617/618: the sitemap builder returns 617 entries; the Phase 4.1 test report counted the home URL twice in a URL walk. No functional difference.

## 2. Feature map
Legend: **Done** = implemented and covered by a CI test; **Improve** = exists but has a gap this phase addresses; **Missing**; **Blocked** = needs real-world evidence, owner or legal approval.

| Area | Capability | State | Evidence / where |
|---|---|---|---|
| Editorial | Curated profiles with sources, `as_of`, conflicts, review block | Done (20) | `curated_profiles.json`, `evidence.php` |
| Editorial | Evidence levels incl. "Verified by an editor" (never automatic) | Done | `tool_evidence()`; CLI `scripts/review_profile.php` is the only way to set `human_verified` |
| Editorial | Per-fact states (documented / unknown / conflicting / stale / verified), per-field freshness rules, expiry dates | **Missing → Phase 5** | only a single 90-day profile age exists |
| Editorial | Review ledger with reviewer, timestamp, checklist; admin review queue | **Missing → Phase 5** | CLI only |
| Editorial | Research tasks for evidence gaps | **Missing → Phase 5** | queue CSV has scores, not tasks |
| Editorial | Generic template text hidden from unreviewed profiles | Improve | tool page hides it; cards, comparisons and some lists still use it |
| Editorial | Human verification of any profile | **Blocked** | needs a real editor on live vendor pages |
| Discovery | Ranked search, synonyms, classification, wizard (6 questions, deterministic), comparison matrix, alternatives, 10 problem pages | Done | `discovery.php`, `recommend_engine.php`, `solutions.php` |
| Discovery | Search relevance fixtures with measured precision | **Missing → Phase 5** | only unit-level ranking tests |
| Discovery | Proof that sponsorship, affiliate fields and click counts do not influence recommendations | Improve | true by design; no regression test pins it |
| Discovery | Task/role filters on search | Improve | filters.php exists by category/pricing |
| SEO/GEO | Sitemap, canonicals, JSON-LD, robots, `llms.txt`, evidence-gated indexing, SEO audit gate | Done | `seo.php`, `scripts/seo_audit.php` |
| SEO/GEO | Backlog of evidence-gated content types with gates | **Missing → Phase 5** | only `content_templates.json` |
| SEO/GEO | Rankings, traffic, AI-search inclusion | **Blocked** | real-world outcome, not promised |
| i18n | Locale registry, hreflang/canonical rules, language switcher, translation status | **Missing → Phase 5** | only `multilingual-demo.php` (noindex page with unreviewed machine translations) |
| i18n | Published German/French/Spanish/Hindi content | **Blocked** | needs human-reviewed translation |
| Retention | Saved shortlist (`ah_fav`), recently viewed (`ah_recent`), recent searches, changelog, newsletter archive, mock send | Done | `assets/js/app.js`, `newsletter_send_mock.php` |
| Retention | Related-content navigation on tool pages | Improve | alternatives exist; "next best step" links are thin |
| Retention | Real newsletter delivery | **Blocked** | owner approval, SPF/DKIM/DMARC, legal |
| B2B | `/advertise.php`, consultation form, leads, admin reliability, attribution field | Done | Phase 4/4.1 |
| B2B | Package audit ($19/$49/$99) vs proposed €199/€499, media-kit structure, validation plan | **Missing → Phase 5 docs** | strategy exists in Phase 4 |
| B2B | Real sponsors, audience figures, prices | **Blocked** | measured evidence required |
| Security | All Phase 4.1 controls (CSRF, rate limits, blocked files, manifest, secrets scan) | Done | must not regress |
| Security | Credential rotation | **Blocked (owner, urgent)** | independent of this phase |

## 3. Evidence gaps that block quality claims
1. 489 of 509 profiles are unreviewed catalogue listings whose features/pros/cons are templates; none can be presented as vetted.
2. 16 reviewed profiles have limited evidence (fewer than two sources, a known conflict, or dated pricing); only 4 are "sourced, awaiting an editor".
3. 0 profiles have human verification; the pipeline to record it is a CLI with no queue or checklist.
4. Pricing has an `as_of` date on 20 profiles only; the other 489 show no sourced price.
5. 489 pages have boilerplate SEO titles. They are protected by the existing evidence-gated indexing rules; Phase 5 must not loosen that.

## 4. Reproducing the numbers
```
php -r 'require "helpers.php"; $t=array_values(tools_by_id()); echo count($t);'
php -r 'require "helpers.php"; foreach(tools_by_id() as $t){$e=tool_evidence($t); $c[$e["level"]]=($c[$e["level"]]??0)+1;} print_r($c);'
php scripts/editorial_queue.php --json
```
