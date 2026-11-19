# Phase 8C category consistency safeguards: implementation report

Implements owner decisions C1-C4 from `CATEGORY_CONSISTENCY_REVIEW.md` (commit `6ae055a`). The JSON editorial overlays stay authoritative for public classification. Nothing deployed, merged or migrated; no database test run; no production, staging or Hostinger access; Phase 8H and 4.2 not started.

## What changed (module only)
| Requirement | Behaviour |
|---|---|
| 1 Only the 14 public categories | New and edited tools accept only taxonomy names (`taxonomy_categories()`), checked server side. "SEO" and any other name is refused. The MySQL store creates the database category row on demand. |
| 2 Show both categories | Tool detail and the edit form show the public category (with subcategory) and the database category, and say the database one is not shown to visitors when they differ. The list card and the category filter use the public category. |
| 3 Read-only when classification controls it | All 509 current tools are classified, so their category is read-only. A posted category is ignored server side and the stored value is kept. |
| 4 Protect taxonomy categories | The 14 public names, every name used by the classification or curated files, and the legacy name SEO are protected: rename and delete are refused (409) and not offered. Add is limited to a missing public name. A database-only label can be deleted when unused, or renamed only to a public name. |
| 5 No-effect bulk changes | Bulk "Set category" is limited to public names and reports classified or curated tools as "No public effect", which blocks the batch. Bulk tag add or remove on curated tools is blocked the same way; tag rename skips them. |
| 6 Curated fields read-only | For the 20 curated tools the fields the profile overrides publicly (category, short description, description, features, pros, cons, use cases, tags, pricing and type, search title and description, website when the profile sets one) are read-only in the form, and edits are ignored server side. The field list mirrors `catalog_overlay_tool()` and is tested against it. Name, status, placements and history stay editable. |
| 7 Slug collisions | Creating a tool is refused if the address exists in `tools.json`, `classification.json` or `curated_profiles.json` (checked from the files, not the database). Submission approval also avoids those addresses (numbered suffix). |
| 8 Warnings | Tools with no curated profile show "No editorial profile yet" on the form and detail page; classified tools explain that reclassifying is an editorial task (`scripts/classify_tools.php`); curated tools point to `scripts/review_profile.php` and show the review state. |
| 9 Public behaviour preserved | No change to `catalog.php`, `helpers.php`, templates, sitemap, SEO code, `taxonomy.json`, `classification.json`, `curated_profiles.json` or the migration. `git diff 6ae055a` over those paths is empty. The new `adm_tools_overlay.php` only calls the existing read helpers. |
| 10 Regression | `tests/phase8c_category_safeguards_test.php` compares the public resolution of all 509 tools with `tests/fixtures/phase8c_overlay_snapshot.json`, taken from the unchanged overlay code at `6ae055a`. |

## Files
New: `adm_tools_overlay.php`, `tests/phase8c_category_safeguards_test.php`, `tests/fixtures/phase8c_overlay_snapshot.json`, this report. Modified: `adm_tools_core.php`, `adm_tools_ops.php`, `adm_tools_screens.php`, `adm_tools_views.php`, `tests/phase8c_tools_m4_m7_test.php` (categories section updated to the new rules), `tests/adm_tools_preview.php`, `tests/browser_phase8c_preview_test.js`, `tests/security_manifest.json`, `.github/workflows/ci.yml`, screenshots, checklist.

## Test results (database-free)
| Check | Result |
|---|---|
| Category safeguards and 509-tool regression | 54 / 0 |
| M1-M3 logic | 115 / 0 |
| M4-M7 logic | 174 / 0 |
| Synthetic screens, 17 pages x 3 sizes x light/dark (axe, overflow, 44 px) | 408 / 0 |
| Security inventory / regression / secret-init / salt / i18n / content backlog | 462 / 147 / 10 / 10 / 35 / 27, all 0 failed |
| PHP lint, assets check, JS syntax | clean |

## The 509 classifications are unchanged
- 509 of 509 tools resolve to the same public category, subcategory, classification method and curated-field hash as the snapshot; 20 curated profiles, 14 public categories, every public category in the taxonomy.
- The module's own view agrees with the public site for all 509 and locks the category of each.
- The snapshot is regenerated only deliberately (`php tests/phase8c_category_safeguards_test.php --write`) when an editorial change is intended.

## Limits and verification still needed
- Everything above is database-free. The MySQL store (including the new on-demand category row), the migration, the real sign-in flow and a before/after comparison of public pages and the sitemap on staging are still unverified (see `ACCEPTANCE_CHECKLIST.md`).
- Reclassifying existing tools and editing curated profiles remain editorial script tasks (decision C4); there is no admin screen for them.
- A tool that exists only in the database and has a non-public category label (for example from the original source) must be moved to a public category before it can be saved again.
- Production content source (MySQL or JSON) is still unconfirmed.
