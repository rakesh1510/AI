# Tool catalogue quality audit and editorial verification queue

Source: `php scripts/editorial_queue.php` (read-only, deterministic, JSON-catalogue mode; the full ranked list is [editorial_queue.csv](editorial_queue.csv)). **No tool was marked verified and none can be by this audit**: verification means a person opens the live vendor pages, confirms each fact and records it with `scripts/review_profile.php`. Today **0 of 509** profiles are editor-verified and 20 are "sourced, awaiting verification".

## 1. Findings across the 509 profiles
| Finding | Count | Why it matters | Suggested handling |
|---|---|---|---|
| **Pricing without a source** (all unreviewed tools) | 489 | The `pricing`/`pricing_type` text comes from the original listing; no vendor page backs it. Pages say "not reviewed" but the number still appears on cards | Keep labelled; never use in comparisons or the wizard (already gated). Source or drop prices when a profile is reviewed |
| **Template features / pros / cons** (identical lists on every unreviewed profile: "Fast setup and onboarding", "Saves time", "Easy to start"…) | 489 | These are placeholders, not facts about the tool, yet they render as features/pros/cons. They are the biggest source of unverified claims | Highest-value editorial or code decision: stop rendering them for unreviewed tools (show the one-line description only) until a profile is reviewed. Not changed here because it alters every tool page; recommended next |
| **Marketing claims in the one-line description** ("best", "leading", "10x", "%", "unlimited", "trusted by"…) | 118 | Vendor copy repeated as fact | Rewrite neutrally during review; consider a lint at import time (`editorial_lint()` already detects these patterns) |
| **Category assigned by rule with low confidence** | 188 | Wrong category = wrong navigation and wrong problem pages | Review in the queue order; high-visibility tools first |
| **Category "Other" (unclassified)** | 165 | Navigation dead-end; these pages are `noindex` | Classify during review |
| Category differs from the original source category | 430 | Informational: Phase 2 reclassified them; accuracy on a hand-labelled sample was good for high confidence only | Spot-check medium/low confidence ones |
| **Website field points to an affiliate/tracking host** (`appsumo.8odi.net`) | 4 (`gajix`, `keyword-discovery`, `vizologi`, `desksense`) | The "Visit site" link goes to a referral/tracking host rather than the vendor, and was previously undisclosed | Now marked `rel="sponsored"` with a visible "referral or tracking address" note. Editor should replace with the vendor URL or document the programme |
| Duplicate website domain | 15 | Possible duplicate or marketplace listings (AppSumo, AWS, Chrome Web Store) | Merge or differentiate |
| One-line description under 40 characters | 15 | Thin cards | Expand during review |
| Website not HTTPS | 1 | Trust and security | Fix or remove |
| **Reviewed profiles with conflicting sources** | 11 of 20 | Pricing/limits differ between vendor pages | Resolve against the live pages; keep the conflict note until then |
| Reviewed profiles with limited evidence | 16 of 20 | Few sources or weak confidence notes | Verify and add sources |
| Reviewed profiles with fewer than four documented capabilities (weak comparisons) | 13 of 20 | The feature matrix shows mostly "Unknown" for these | Verify and extend profiles |
| Pricing as-of date older than 90 days | 0 today | Will become stale on the next quarter | Re-check on a schedule |
| **Stale vendor information** | not measurable | No website-check data exists (`data/website_checks.json` absent); `scripts/check_websites.php` needs outbound HTTPS | Run it on a networked build machine and attach results |
Other data-quality observation: `data/clicks.json` holds ~10,000 clicks for each of the first tools. These look seeded, not measured. In the JSON fallback mode they feed "Top tools" and the small popularity boost, so rankings in that mode are not evidence. Production uses database counts; consider zeroing the file.

## 2. How the queue is ordered (transparent score)
`score = visibility + risk`.
- **Visibility** (errors cost more when many people see them): in a guide +15; on a problem page +10 each (max 20); in a published comparison +10; eligible for wizard results +10; listed as an alternative +5; has a reviewed profile +10.
- **Risk**: reviewed but not verified +25; conflicting sources +10; limited evidence +5; pricing older than 90 days +10; weak comparison data +4; tracking/referral website link +20; marketing claim +8; template boilerplate +5; low-confidence category +5; category Other +3; duplicate domain or name +8; short description +4; non-HTTPS +4; missing website +10.
Priority: **P1** ≥ 50, **P2** 25–49, **P3** < 25. Result: **19 P1, 11 P2, 479 P3**.

## 3. P1: verify these first (the 19 most visible reviewed tools)
ChatGPT, Claude, NotebookLM, Sonix, Fireflies.ai, Gemini, Perplexity, ChatPDF, DeepSeek, Otter.ai, Trint, Canva AI, Microsoft Copilot, Grammarly, You, Copilot for Microsoft 365, Motion, Pi, MarketMuse. They appear in guides, problem pages, comparisons, alternatives and wizard results, so an error there is repeated on many pages.
**P2** (11) includes Character.ai and the four tracking-link tools (`gajix`, `keyword-discovery`, `vizologi`, `desksense`) and unreviewed tools that appear on problem pages. Everything else is P3.

## 4. Verification workflow (what a person must do)
For each profile, in queue order:
1. Open every source URL listed on the profile and the vendor's current pricing page; note the date.
2. Confirm or correct: company, pricing summary and free option, plan names and limits, each key feature, each limitation. Resolve every listed `known_source_conflicts` or keep it.
3. Check the website field points to the vendor (no tracking host) and the category/subcategory is right.
4. Record the result with `php scripts/review_profile.php <id> ...` (sets `human_verified`, `verified_on`, reviewer); then add a verified entry to the change log with `scripts/add_changelog_entry.php` if something changed.
5. Re-run `php scripts/audit_catalog.php` and the tests. Verified profiles move to "Verified by an editor" automatically and become "due for a re-check" after 90 days.
Acceptance rule: a profile is *verified* only if a named person checked **all** displayed facts on live vendor pages on a recorded date. Anything less stays "sourced, awaiting verification". The tests assert that no profile in the data is marked verified without this.

## 5. Suggested editorial capacity plan
20 reviewed profiles ≈ 20–40 person-hours (about 1–2 hours each). Unreviewed profiles that appear on problem pages (87) are the next batch; the long tail (≈400) should stay clearly labelled basic listings rather than be reviewed superficially.
