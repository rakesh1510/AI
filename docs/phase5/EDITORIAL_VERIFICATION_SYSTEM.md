# Editorial verification system (Phase 5, Priority 1)

## What problem it solves
Phase 4.1 had one verification flag per profile, set only by a command-line script. There was no per-fact view, no expiry rule, no queue and no record of who checked what. Phase 5 adds those, **without any way for software to grant approval**.

## The five fact states
Each tool shows three kinds of fact (pricing and plans, features and capabilities, overview and limitations). Each has exactly one state:

| State | Meaning | How it is reached |
|---|---|---|
| **Unknown** | No sourced information | Tool has no reviewed profile, no usable source, or no source date |
| **Documented** | Taken from vendor sources listed on the page; not confirmed by a person | Reviewed profile with a vendor source and a date, inside the freshness window |
| **Sources conflict** | Vendor sources disagree | The profile lists a known source conflict (pricing words route it to the pricing fact, otherwise to features/overview) |
| **Past its re-check date** | The freshness rule has expired | Pricing 45 days, features 180, overview 365, counted from the verification date if there is one, else the source date |
| **Verified by an editor** | A person confirmed it on the live vendor pages | Only through a recorded human verification (below) |

Precedence: conflicting, then stale, then verified, then documented. A conflict or an expired date always beats an old verification.

## Where it is shown
* **Tool page**: an "Evidence ledger" table (fact, state, source link and date, re-check-by date). Unreviewed tools state plainly that no fact is sourced or verified.
* **Comparison page**: "Pricing status" and "Features status" rows next to the existing documented/unknown matrix.
* **Admin** (`/admin-editorial.php`, signed-in admin only): queue ordered by the Phase 4.1 priority score, a detail view with the ledger, conflicts, research tasks, review history and the review form.

## How a verification is recorded
`editorial_log_append()` is the single entry point (`editorial_review.php`). A `verify` event is refused unless **all** hold:
1. the tool has a reviewed profile with at least one source;
2. a reviewer name of at least two characters;
3. all five checklist items are ticked (sources opened, pricing compared with the live page, features confirmed, conflicts resolved or disclosed, no unsupported marketing claims);
4. the number of sources checked equals the number of listed sources;
5. an explicit confirmation that the reviewer personally verified the profile;
6. the channel is `admin_session` (the page checks the admin session and CSRF token first) or `cli`.

Other events (note, research needed, sources checked, reject, reopen) need a reviewer and, where relevant, a note. The log is append-only JSON (`data/editorial_review_log.json`, file-locked); the latest verify/reject/reopen decides the current state. **The committed log has no entries**, so the site still reports 0 verified profiles. The existing CLI `scripts/review_profile.php` remains the other human path.

## What it cannot guarantee (be honest in operations)
* **Identity is self-declared.** There is one admin password, so the reviewer name is typed by whoever signs in. Real accountability needs per-editor accounts (owner decision; not built).
* The channel flag proves the code path, not the person. Treat the log as an audit aid, and keep the admin credential to named editors only.
* A JSON log is simple and reviewable but not tamper-proof. Back it up with the other data files.

## Research tasks, never invented facts
`editorial_research_tasks()` turns gaps into tasks ("find the vendor's pricing page and record URL and date", "resolve this conflict", "re-check pricing at URL", "add a second source", "an editor verifies"). Each names what real evidence is needed. Tasks contain no vendor facts; tests assert this. External research was not available or attempted in this phase, so **no profile was researched, changed or verified**.

## Template text
489 profiles carry bulk-generated features/pros/cons. Phase 5 stops them acting as evidence: they are removed from the search index, hidden in comparisons ("Not documented yet"), and flagged as unknown in the ledger. The tool page already labelled them as general notes.

## Tests
`tests/editorial_review_test.php` (555 checks): committed state has 0 verified; fact-state transitions and expiry dates; tasks hold no facts; every refusal path; a full verify, expire, reject cycle against a temporary log; control-character and length cleaning; the committed log stays empty. `tests/security_negative_http_test.php` posts forged verify requests to the admin page anonymously and asserts that nothing changes.

## Owner actions
1. Decide who the editors are; create named accounts later if you want real identity.
2. Edit a profile only from vendor pages, then verify it with the checklist.
3. Work the queue from the top (`docs/phase4/editorial_queue.csv`); the 4 "sourced" profiles and the 12 with conflicts come first.
