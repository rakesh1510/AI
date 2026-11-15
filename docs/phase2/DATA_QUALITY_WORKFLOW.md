# Data quality workflow

## State of the catalogue (measured by `php scripts/audit_catalog.php`)
- 509 tools; **20 reviewed profiles**, 489 basic (template) listings.
- 499 records carried identical bulk-generated features/pros/cons; 432 sat in a catch-all "Automation" category inherited from "Personal Assistant"/"Research Assistant" sources.
- Even the "curated" categories were wrong in places (Perplexity under SEO, DeepSeek under Coding, ChatPDF under Audio, Motion under Video).
- 5 duplicate domains and 14 records whose website is an affiliate/store/redirect link rather than the vendor's site (`audit_catalog.php` lists them).

## Pipeline
| Step | Tool | Output |
|---|---|---|
| 1. Classify | `php scripts/classify_tools.php --write` | `data/classification.json` (category, subcategory, confidence, signals, `needs_review`) |
| 2. Spot duplicates, bad URLs, boilerplate | `php scripts/audit_catalog.php` | report; exit 1 if any reviewed profile is invalid |
| 3. Check websites respond | `php scripts/check_websites.php --write` (run where internet is available) | `data/website_checks.json` |
| 4. Write evidence-based profile | edit `data/curated_profiles.json` | profile with ≥1 https source, dates, limitations, conflicts |
| 5. Validate | `php tests/data_quality_test.php` / CI | schema, taxonomy, no placeholder text |
| 6. Editor verifies on live pages | `php scripts/review_profile.php <id> --by="Name" --confirm` | `human_verified: true`, dates refreshed |
| 7. Publish | `php scripts/build-seo-files.php` | sitemap + llms.txt |
| 8. Re-verify | `audit_catalog.php --stale-days=90` | stale list |

Overlay design: raw records stay untouched (JSON or MySQL); classification and curated data are applied at load time, so rollback is deleting an entry. Editorial entries (`method: editorial`) are never overwritten by the classifier.

## Classifier (transparent, rule-based)
Keywords in `data/taxonomy.json` score name and one-line description; category is chosen by summed evidence and confidence by margin over the runner-up.

Measured on a **hand-labelled sample of 64 records** (labels by the author, not independently reviewed; `tests/fixtures/classifier_gold.json`):
| Confidence | Share of catalogue | Precision on sample |
|---|---|---|
| high | 147 tools | 19/19 |
| medium (applied, flagged) | 171 tools | 25/27 |
| low/none (kept in source category or "Other") | 191 tools | not applied |

Overall, 47/64 sample records landed in an acceptable category; the rest were correctly left unclassified rather than guessed. **166 tools remain in "Other"** and need either better descriptions or editor review. CI fails if precision drops below 95% (high) / 80% (medium).

## First 20 reviewed profiles
ChatGPT, Claude, Google Gemini, Perplexity, Microsoft Copilot, DeepSeek, Grammarly, Canva AI, NotebookLM, Character.AI, Microsoft 365 Copilot, Otter.ai, Fireflies.ai, Pi, ChatPDF, MarketMuse, Trint, Sonix, Motion, You.com — chosen because they are the recognisable tools already in the catalogue (the catalogue lacks many market leaders; see KEYWORD_STRATEGY.md).

Evidence per profile: vendor pricing/help pages found by domain-restricted web search on 2026-11-15, recorded with URL and retrieval date. **Honest limits:**
- Facts come from search-result text, not from reading each live page, so profiles are marked **"pending editor verification"** and none is `human_verified`.
- Several vendors' own pages conflict (e.g. OpenAI Pro tiers, Perplexity Max, MarketMuse tiers, Fireflies free limits). Conflicts are recorded in each profile and shown to visitors.
- Otter, Motion and Sonix prices come from vendor blog/marketing pages, not a pricing table, and are labelled as such.
- No product was hands-on tested; no accuracy or quality claims are made.

## Review backlog
`audit_catalog.php` ranks un-reviewed tools by click demand (production click data should be used; the test database holds synthetic clicks). Review the top 25, then repeat. Target cadence suggestion: 20 profiles per sprint, each re-verified every 90 days.

## Known data issues to fix next
- Duplicate domains: `summit.im` (3 tools), `appsumo.8odi.net` (4), `aws.amazon.com` (2), `chrome.google.com` (2), `superagi.com` (2) — merge or correct the website field.
- Affiliate/store URLs used as `website`: replace with vendor sites so outbound links and schema `url` are accurate.
- Description boilerplate ("… is an AI tool listed under Personal Assistant …") in 499 records.
