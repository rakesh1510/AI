# B2B revenue strategy and price reconciliation (P2)

Status legend: **Implemented** (code + tests) · **Proposed** (documented, not built) · **Manual** (needs the owner) · **Open**.
No public price was changed. No audience figure, customer logo, testimonial, case study or conversion rate was created.

## 1. Price audit: what exists today
Source: `data/site.json → payment.pricing` (USD, one-off), rendered by `advertise.php` and `submit.php`, applied by `admin.php` (`featured_settings_for_plan`).

| Package (key) | List price | Duration / effect | Where it appears |
|---|---|---|---|
| Free listing (`free`) | $0 | Manual review, "may take several days" | Tool profile and category page |
| Priority approval (`priority`) | **$19** | Review within 24 hours | Same as free, reviewed faster |
| Featured listing (`featured`) | **$49** | 7 days, `featured_level` 1 | Labelled "Sponsored" homepage section |
| Homepage spotlight (`homepage`) | **$99** | 14 days, `featured_level` 2 | Top labelled homepage spotlight |

There is no payment integration: the process is manual (submit → approve → agree dates in writing → invoice).

## 2. Proposed offerings from the brief
- **€199 per month featured placement**
- **€499 per month newsletter sponsorship**

## 3. Reconciliation: why they cannot be simply swapped in
| Question | Finding |
|---|---|
| Same product? | No. Existing items are one-off, 7–14 day, USD, listing-oriented. The proposals are recurring monthly, EUR, placement/sponsorship-oriented. A monthly featured placement is roughly a *recurring bundle of the existing 7-day Featured slot* (about four 7-day runs a month would cost 4 × $49 = $196 at list price, which happens to be close to €199, but the currencies and terms differ; this is arithmetic on list prices, not market evidence). |
| Is there evidence for the prices? | **No.** The repository holds no audience, traffic, subscriber or conversion data that supports €199 or €499. The prices must be justified by owner decision and, ideally, by measured results from pilot placements. |
| Can the newsletter be sold? | **Not today.** The newsletter has not launched: the code stores and confirms subscribers; it sends nothing. There is no audience figure to quote and no sending infrastructure (domain authentication, provider, legal basis). Selling €499/month sponsorship would require an audience claim that does not exist. |
| Currency and tax | Mixed USD/EUR needs a decision (display currency, VAT/reverse charge, invoicing entity). The legal operator (Impressum vs TrustNova Tech) is unresolved (Phase 3 RELEASE_RISKS #4). |
| Overlap/cannibalisation | A €199 monthly plan would undercut four $49 weekly-style purchases only if the term is shorter; define minimum term and cancellation. |

## 4. What was done (Implemented)
- `data/sponsorship_packages.json`: the six-package catalogue. The four existing tiers take their price from the site configuration. The two proposed packages are **inquiry-only with no price**; the newsletter row states plainly that it is not available yet.
- `advertise.php`: package comparison table, "editorial and paid are separate" table, advertiser FAQ (only statements the system makes true; e.g. no refund or response-time promises), package/goal qualification fields, deep links (`?package=`), attribution fields.
- Tests assert: $19/$49/$99 unchanged, no `€`/199/499 on the page or in the data file, no testimonials/audience numbers/guarantees, the newsletter row says "not yet".

## 5. Decisions needed before any price changes (Manual)
1. Approve the package structure (below) and which prices to publish.
2. Choose display currency and tax treatment; confirm the operating entity.
3. Decide whether monthly plans replace or sit alongside the 7/14-day products, with term, cancellation and make-good rules agreed in writing.
4. Launch the newsletter (provider, SPF/DKIM/DMARC, privacy text) and measure for at least several issues before pricing sponsorship; publish only measured figures.
5. Decide what can be reported to advertisers. Technically available today: outbound clicks through `/redirect.php` per tool and per placement (server-side, complete). Not available: impressions, conversions on the advertiser's site.

## 6. Proposed package structure (for approval; **not applied**)
| Package | Term | What it is | Evidence needed before listing a price |
|---|---|---|---|
| Listing (free) | – | Standard reviewed listing | none |
| Priority review | one-off | Faster review, no placement | review turnaround actually met for a period |
| Featured week | 7 days | Labelled homepage slot | pilot with 2–3 advertisers and their click counts |
| Spotlight fortnight | 14 days | Top labelled slot | same |
| Monthly featured (proposed €199) | monthly, min. 3 months | Rolling labelled slot + one profile review | owner-approved price; pilot results |
| Newsletter sponsor (proposed €499/issue-month) | per issue | Labelled sponsor block | launched newsletter with confirmed-subscriber count and measured opens/clicks (opens are unreliable; prefer clicks) |

Guardrails for every package (already enforced by code/tests): labelled "Sponsored", never changes profile facts, ranking, search, problem pages, comparisons, alternatives or recommendations; discloses affiliate relationships; no guaranteed outcomes.

## 7. Funnel, qualification and reporting (Implemented)
- **Inquiry forms:** advertiser, affiliate partner, newsletter sponsorship (register interest), consultation, custom AI agent, custom software. Optional qualification: package interest, main goal, budget, timeline.
- **Attribution:** `utm_source/medium/campaign`, `ref` and `tool` query values are normalised to slugs and stored with the lead (campaign tags, not personal data). Internal CTAs pass `ref=tool-page|solutions|wizard`.
- **Storage:** additive migration `database/phase4_business.sql` (reversible); the app runs without it (answers are appended to the message).
- **Status workflow:** new → contacted → qualified → proposal sent → won/lost/spam, with history; invalid jumps are refused.
- **Admin prototype (`/admin-leads.php`)**: admin session only; masked emails in lists, full address only on a logged detail view; counts-only report (type, status, stage counts, source page, attribution, package interest) and a warning when rows are synthetic; no rates, revenue or ROI are computed.
- **Synthetic data:** `scripts/seed_synthetic_leads.php` refuses any database whose name lacks "test" and flags rows `is_synthetic`.
- **Retention:** `scripts/purge_leads.php` (dry-run by default). Retention length is a legal decision.

## 8. Not done / unresolved
- No payments, checkout, invoicing or billing (kept disabled by design).
- No real email delivery for lead notifications was tested (local sink only).
- Lead data is personal data: the privacy policy does not yet describe it (Phase 3 risk #3).
- "Won" has no monetary value field on purpose; revenue tracking belongs in accounting, not in this prototype.
- Retention, access logging review and role separation beyond a single admin remain proposals.
