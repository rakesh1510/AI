# Performance benchmark (Phase 4.1)

All measurements: isolated local test environment, synthetic/test data only, one machine. "Before" is Phase 4 commit `eddaa93`; "after" is this branch. Where timings are single runs or medians of few runs this is stated; differences of a few percent are noise. No production system was measured.

## 1. The identified problem: related links on reviewed tool pages
Phase 4 built "Explore related pages" on reviewed tool pages by running ten problem searches over the whole catalogue **on every page view** (and the problem hub and the sitemap did the same).

### What changed
| Change | Detail |
|---|---|
| Derived-data cache (`cache.php`) | Ranked tools per problem page are computed once per catalogue/code version and stored as small JSON files outside the web root. Pages read the cache; tool records are always read fresh |
| Search normalisation memo (`discovery.php`) | The per-tool text normalisation that every query repeated is now memoised by content, making *every* search faster, cached or not |
| Per-slug fallback | If the cache is unusable (disabled, unwritable, unsafe directory) a problem page computes only its own problem, never all ten |

### Invalidation (why it cannot serve stale or wrong results)
An entry is valid only while **all** hold: (1) the **catalogue fingerprint** matches: size, mtime and content checksum of `tools.json`, `curated_profiles.json`, `classification.json`, `taxonomy.json`, `search_synonyms.json`, `solutions.json`, `capabilities.json`, plus `CHECKSUM TABLE tools` when the catalogue comes from MySQL; (2) the **code fingerprint** matches (size/mtime of the ranking code, so a deployment invalidates everything); (3) the entry is **younger than 15 minutes** (the click-popularity boost changes continuously; this bounds how stale the ordering can be). Corrupt, foreign-schema or mismatched files are ignored. Old entries are removed. `AIHUB_CACHE=0` disables it. Wizard recommendations are **not cached** (they depend on the visitor's answers and cost ~10 ms).
Limits to know: the fingerprint is computed from the data files and database, so the cache must only be used for the live catalogue (the benchmark script uses a private cache directory for synthetic catalogues); a database edit that leaves the table checksum unchanged cannot happen; an edit that restores the same content may invalidate unnecessarily (safe direction). Cache directories are per OS user, `0700`, owner-checked and not symlinks, because `/tmp` is shared.

### PHP execution time (`php scripts/bench.php`, 509 real tools, median of 15 runs)
| Case | Before | After (cold: cache miss) | After (warm: cache hit) |
|---|---|---|---|
| Related links, reviewed tool page | 166 ms | ~103 ms once per catalogue/TTL | **0.13 ms** (2.8 ms in a fresh PHP process: read file + fingerprint) |
| Problem hub (10 problems) | 174 ms | ~103 ms once | **0.04 ms** |
| One problem page | 12.7 ms | 5.5 ms | 5.5 ms (served from the same cache) |
| One search ("transcription meeting") | 17.3 ms | 8.4 ms | 8.4 ms (memoised normalisation) |
| Wizard (not cached) | 9.7 ms | 7.6 ms | 7.6 ms |

### Synthetic catalogue (5,000 tools, 20 reviewed)
| Case | Before | After (cold) | After (warm) |
|---|---|---|---|
| Related links, reviewed tool | 846 ms (first call 1,008 ms) | ~800 ms once | **0.4 ms** (2.9 ms fresh process) |
| One problem page | 36 ms | 28 ms | same cache |
| One search | 42 ms | 34 ms | 34 ms |
Scaling note: cold computation grows linearly with catalogue size (≈0.16 ms per tool per ten problems); with the cache, page cost is independent of catalogue size. At 5,000 tools the first visitor after a deployment or after the 15-minute TTL pays ~0.8 s once. If that matters, warm the cache after deploy (`php -r 'require "helpers.php"; solution_pages_data();'`) or lengthen `AIHUB_CACHE_TTL`.

### End to end on Apache 2.4 + mod_php + gzip (median time to first byte of 25 requests)
| URL | Before | After |
|---|---|---|
| Reviewed tool page (`otter-ai`) | 187 ms | **19 ms** |
| Problem hub (`/solutions.php`) | 181 ms | **11 ms** |
| Problem page | 26 ms | 12 ms |
| Unreviewed tool page | 16 ms | 19 ms (noise) |
| Home | 14 ms | 16 ms (noise) |
| Wizard result | 22 ms | 23 ms (noise) |
These include PHP start-up and the database connection. The cache must be writable by the web user: with an unwritable directory the page falls back to computing only its own problem (tool pages then cost ~100 ms again).

### Correctness checked
Cached ranking, scores, reasons and indexing decisions equal a live computation for all ten problems; related-link output equals the previous implementation for all 20 reviewed tools; unreviewed tools still get the generic link; wizard results are not affected; editing a tool's text is reflected immediately by the search memo (keyed by content); TTL, fingerprint, code-version, corruption, foreign-schema, unwritable and symlinked-directory cases are unit tested (tests/phase4_logic_test.php, "4.1 derived-data cache").

## 2. Mobile LCP and asset loading (investigation)
Lighthouse 12.2, simulated slow-4G mobile, Apache 2.4 with gzip, third-party favicon requests blocked, two runs per page (mobile; before / after):
| Page | Perf score | FCP s | LCP s | TBT ms | Transfer | Requests |
|---|---|---|---|---|---|---|
| Home | 99/100 → 99/100 | 0.9 → 0.9 | 1.88/1.85 → 1.86/1.84 | 73/60 → 67/59 | 54 KB | 87 |
| Category | 99/99 → 100/100 | 0.9 → 0.9 | 1.86/1.83 → 1.83/1.82 | 116/68 → 48/47 | 54 KB | 103 |
| Reviewed tool | 100 → 100 | 0.79/0.75 → 0.75 | 1.60/1.43 → 1.43/1.45 | 45/0 → 0/22 | 49 KB | 14 |
| Compare | 100 → 100 | 0.9 | 1.60/1.58 → 1.59/1.55 | 33 | 59 KB | 9 |
| Problem page | 100 → 100 | 0.79/0.75 → 0.75 | 1.61/1.50 → 1.52/1.53 | 16/11 → 0/29 | 48 KB | 31 |
| Wizard | 100 → 100 | 0.75 | 1.26/1.33 → 1.32/1.25 | 5/17 → 24/1 | 46 KB | 7 |
**No client-side change is visible beyond run-to-run noise**: the fix is server-side (it removes ~170 ms of PHP time per reviewed tool page, which Lighthouse's simulated mobile network does not weigh the same way as real time-to-first-byte).

### Findings about LCP
1. The LCP element on mobile is **text** (the hero `<h1>` on the home page, the lead paragraph elsewhere), never an image: observed LCP in an unthrottled Chromium is 60–200 ms. The ~1.9 s figure is Lighthouse's throttling simulation, dominated by one render-blocking stylesheet (66 KB, about 13 KB gzipped) plus the HTML.
2. Without compression (the plain PHP dev server) the same pages show LCP ≈3 s and "potential savings 152 KiB from text compression": production must serve gzip (`.htaccess` enables it; verified on Apache here).
3. **Unnecessary requests**: home and category pages render 40–48 third-party favicon `<img>` tags (all `loading="lazy"`; 80–96 requests in the throttled trace). Every visitor therefore contacts Google's favicon service, which is also a privacy cost (Phase 3 risk #5).
4. **Unused CSS**: Lighthouse reports ~44–49 KB (uncompressed) unused per page. A static scan finds **126 rules / 11.3 KB (17%) whose classes appear in no PHP or JS file** (legacy nav/story styles). Not removed: class names built dynamically could produce false positives, and the gain is ~2.5 KB gzipped. Recommended as a separate, visually reviewed change.
5. DOM size is 1.6–1.8 k elements on the home/category pages (24 and 48 cards): within limits, no change.

### Changes made for assets (without removing features)
- Removed `preconnect`/`dns-prefetch` to Google's favicon hosts from every page (pages without logos, such as the wizard and advertiser page, no longer open a third-party connection).
- Card logos are `loading="lazy"` and now `fetchpriority="low"` so they never compete with CSS and text.
- `tool_logo()` prefers a **locally hosted** logo (`assets/logos/<id>.png`) and `scripts/cache_logos.php` (owner-run, never from the web) downloads each favicon once. After running it, listing pages make **zero** third-party requests. Not executed here (it needs outbound HTTPS and produces ~509 binary files to review before committing).

### Not changed / not verified
Critical-CSS inlining, CSS splitting, and dead-CSS pruning (see 4). No real-device or field (CrUX) data exists. TTFB on the real host (Hostinger PHP version, opcache, disk) is unknown; enable opcache in production.
