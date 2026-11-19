# Phase 8C final code review: category and editorial-overlay consistency

Status: REVIEW AND PROPOSAL ONLY. No code, data file, migration or architecture change was made for this. Awaiting owner approval.

## 1. What the code does (evidence)
Public tool data is built in three layers. Later layers win (`catalog.php`, `catalog_overlay_tool()`):
1. **Source record:** MySQL `tools` (category through `categories`) or `data/tools.json` when MySQL content is unavailable.
2. **`data/classification.json`:** replaces `category` and sets `subcategory` for the tool.
3. **`data/curated_profiles.json`:** replaces `category`, `subcategory`, tagline (`short`), summary (`description`), features, pros, cons, use cases, tags, pricing, SEO title and description, and sometimes website. Its review block carries the editorial verification state (`human_verified`).

Measured on the current repository files (read-only analysis, no database):
| Fact | Value |
|---|---|
| Tools in `tools.json` | 509 |
| Tools with a classification entry | 509 (all); 362 are flagged `needs_review`; 318 rule-based, 191 from source |
| Tools whose public category differs from the source category | **444 of 509** (for example source "Automation" becomes Other 165, Business 79, Productivity 60, Lifestyle 44, Research 32) |
| Curated profiles | 20, none human-verified yet |
| Public taxonomy (`taxonomy.json`) | 14 categories, plus a legacy redirect SEO to Marketing |
| Source and database `categories` (imported from the source) | 11 names; "SEO" is not in the taxonomy; Marketing, Business, Lifestyle and Other are missing from it |
| `category.php` for a name outside the taxonomy | real 404 (except the legacy redirect) |

## 2. Findings
- **F1 (high): the module's category tools act on the wrong namespace.** Category add, rename, delete, the tool form's Category field and bulk "Set category" change only the source layer. For all 509 current tools that has no public effect, because classification overrides it. The screens suggest otherwise.
- **F2 (high, SEO): a new tool can get a category the public site does not know.** The module accepts any name in the database `categories` table (11 names including "SEO", or any name the owner adds). Tool pages build their breadcrumb and JSON-LD from the public category, so a new tool in a non-taxonomy category links to a **404 category page** and appears in no category listing. A new tool has no classification entry, so the database value is what visitors see.
- **F3 (medium): curated tools ignore module edits.** For the 20 curated tools, edits to short description, description, features, pros, cons, use cases, tags, pricing and SEO fields are stored but never shown. Bulk tag add/remove and tag rename also change nothing publicly for them. An editor could believe a verified profile was updated.
- **F4 (medium): slug collision with seed data.** The module checks only database slugs. If `tools.json` or the classification file holds a slug the database lacks (partial import, MySQL-to-JSON fallback), a new tool with that slug would silently inherit that tool's classification and curated profile.
- **F5 (low): new module-created tools are thin pages.** With no classification or curated entry they are rated "basic" and indexed under the default thin-content policy. This is existing behaviour, but worth a visible hint.
- **F6 (info): editorial verification is intact.** The module never writes classification, curated or Universe data, and verified state lives only in the curated JSON. Unpublishing a tool hides it publicly but does not change its review record.

SEO today is safe: the module changes no public template, sitemap, canonical or overlay code. The risk is only through F2 for newly created tools, and through false expectations (F1, F3).

## 3. Options
| Option | What it is | Risk | Verdict |
|---|---|---|---|
| **A. Guard and clarify (recommended)** | Keep JSON overlays as the editorial authority. Make the module honest and safe: validate category against the public taxonomy, lock what the overlay controls, protect taxonomy names, reserve seed slugs. No migration, no change to `catalog.php`, templates, SEO or data files. | Low | Do now |
| B. Database-backed editorial classification | New table for classification and curated fields, overlay reads it, maker-checker workflow, import of the 509 and 20 entries. | High (touches the SEO-critical read path, needs database verification and a new review) | Separate later phase, only with approval |
| C. Document only | Leave behaviour as is. | F2 can create 404 breadcrumbs | Not recommended |

## 4. Proposed solution (Option A), for approval
1. **Category = public taxonomy.** Creating or editing a tool without an overlay entry accepts only names from `taxonomy_categories()`. The form shows those names; the database `categories` row is created on demand for a valid taxonomy name. "SEO" and other non-taxonomy names are rejected with a clear message. Fixes F2.
2. **Show both categories, lock the overlay.** Tool detail shows "Public category (editorial classification)" and the source category. For any tool with a classification or curated entry the Category field is read-only with the reason "controlled by editorial files". Changing existing classifications stays an editorial, reviewed change through the existing scripts (`classify_tools.php`, `review_profile.php`), outside the admin UI. Fixes F1.
3. **Category management shrinks to what is true.** The Categories screen lists taxonomy categories with counts of tools in the database and by public classification. Add is allowed only for a taxonomy name that is missing from the database table. Rename and delete are disabled for every name used by the taxonomy, classification or curated files. Bulk "Set category" is restricted to taxonomy names and blocked for overlay-covered tools ("no public effect"). Fixes F1.
4. **Curated tools: read-only fields.** For the 20 curated tools the overlay-controlled fields are read-only with a banner naming the curated profile and its review state; bulk tag operations report them as "no public effect" problems. Name, status, featured placements and history stay manageable. Fixes F3.
5. **Reserved slugs.** Creating a tool is refused when the slug exists in `tools.json`, `classification.json` or `curated_profiles.json` (checked in code, not the database). Fixes F4.
6. **Visible hint for thin tools.** Detail page notes "No editorial classification or profile yet: shown as a basic page". Addresses F5.

### Scope and safeguards
- Touches only the module files (`adm_tools_core.php`, `adm_tools_ops.php`, `adm_tools_screens.php`, `adm_tools_views.php`, a small `adm_tools_overlay.php` that reads the overlay through the existing `classification_overrides()` and `curated_profile()` helpers) and tests.
- **No** change to `catalog.php`, `helpers.php` read path, templates, sitemap, `robots`, `taxonomy.json`, `classification.json`, `curated_profiles.json`, the Universe, or the migration.
- Database-free tests: for all 509 current tools the public category, subcategory and curated fields resolved by the existing overlay are identical before and after the change (snapshot hash); a source check that the module files never write the overlay or taxonomy files; unit tests for each rule above; screens re-checked with axe.
- No new migration. Existing M1-M7 behaviour for names, status, tags on non-curated tools, placements and moderation is unchanged. Submission approval also uses the taxonomy category list.

## 5. Decisions for the owner
- **C1:** Approve Option A (recommended), choose B as a later phase, or choose C.
- **C2:** Category management: keep the reduced screen above (recommended), or remove category management entirely until Option B.
- **C3:** Curated tools: read-only fields (recommended) or editable but flagged "no public effect".
- **C4:** Confirm that reclassifying existing tools stays an editorial script and review task for now.
