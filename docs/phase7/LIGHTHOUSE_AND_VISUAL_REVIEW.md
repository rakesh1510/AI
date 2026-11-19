# Phase 7: navigation, Lighthouse and visual review

Scope: AI Universe navigation (hub `universe.php`, header, More menu, footer, section sub-navigation), Lighthouse runs and a visual/responsive review of the graph, heatmap, lineage, arena, editor sign-in and home pages. All data used was SYNTHETIC (fixtures `tests/phase7_pages_server.php`, `tests/fixtures/phase7_pages_seed.php`); nothing touched production, staging or any remote host (only npm for tooling).

## Navigation

- New hub `universe.php`: four cards (Graph, Activity heatmap, Release lineage, Battle Arena) with a status computed from verified ledger rows (`uni_public_*`): "N verified entities / events / releases", "Needs at least two verified entities" or "No verified data yet". It is `noindex` while the ledger is empty or missing, 503 + noindex when the data cannot be read, and is added to the sitemap (`up_sitemap_entries`) only once verified data exists. Registered in `seo.php` and `tests/security_manifest.json`.
- `layout_top.php`: "AI Universe" primary item; the four experiences in the More menu (flat list in the mobile drawer); `aria-current="page"` on the current page, `aria-current="true"` on "AI Universe" while inside any experience; a labelled sub-navigation ("AI Universe sections") above the content of the hub and the four experiences. `layout_bottom.php`: new footer column "AI Universe".
- Tests: `tests/phase7_pages_test.php` gained hub checks (links, counts from the ledger, aria-current, noindex when empty or without tables, sitemap rules, 405).

## Lighthouse

Command (per page and preset; JSON output, categories performance, accessibility, best-practices, seo):

```
CHROME_PATH=/opt/pw-browsers/chromium lighthouse <url> [--preset=desktop] \
  --only-categories=performance,accessibility,best-practices,seo \
  --chrome-flags="--headless=new --no-sandbox --disable-gpu" --output=json
```

Environment: lighthouse 13.6.0 (npm, installed outside the repo), Chrome for Testing 153 headless, software rendering, shared sandbox, `php -S` with `tests/router.php`, MySQL 8.0.46 (`aihub_test`), empty pages on the CI-style test DB, populated pages on the synthetic fixture database. Mobile = default Lighthouse mobile emulation (simulated throttling), desktop = `--preset=desktop`.

Caveat: a local software-rendered Chromium on a shared sandbox with a local PHP built-in server is not representative of real devices or networks. Treat the numbers as regression signals only; timings (LCP, TBT) are not field data.

Scores (P / A / BP / SEO), after the fixes below:

| Page | Mobile | Desktop |
|---|---|---|
| Home (/) | 99 / 97 / 100 / 100 | 100 / 100 / 96 / 100 |
| Hub, populated | 100 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |
| Hub, empty | 100 / 100 / 100 / 66 | 100 / 100 / 100 / 66 |
| Graph, populated | 100 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |
| Graph, empty | 100 / 100 / 100 / 66 | 100 / 100 / 100 / 66 |
| Heatmap | 99 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |
| Lineage (?entity=modelx) | 100 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |
| Arena (?e=modelx,modely) | 100 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |
| Editor sign-in | 100 / 100 / 100 / 69 | 100 / 100 / 100 / 69 |

SEO 66 and 69 are the intended `noindex` of empty-ledger pages and of the editor sign-in (audit `is-crawlable`); not a defect.

Main remaining audits: `unused-css-rules`, `render-blocking-insight` (one combined stylesheet, by design), cache headers of the dev server, `unminified-javascript` / `unused-javascript` on the graph (assets owned by the graph-first-paint work).

## Findings

| # | Finding | Status |
|---|---|---|
| 1 | Header overflowed horizontally at 1181-1500px after adding the sixth primary item (CTA 26px outside the header, page scrolled sideways up to 1280px). Fix: compact padding, font size and search basis for the desktop header (`assets/css/ui.css`, rebuilt). Before: scrollWidth 1286 at 1280px; after: equals the viewport at 1181, 1200, 1280, 1366, 1440. | Fixed |
| 2 | Graph page CLS 0.30 (mobile) / 0.36 (desktop): the hidden app is revealed by script and pushes the entity list down. Fix: reserve 688px for `#ugapp[hidden]` with script enabled. After: CLS 0.010 / 0.014, performance 84 to 100. | Fixed |
| 3 | Mobile sub-navigation wraps to two rows at 360px (hub link on its own row). Readable, no overlap. | Accepted |
| 4 | Home mobile contrast 3.44 on category counts: measured while the `.reveal` fade-in was mid-animation; the token (`--muted` #575f80) passes at rest. Not changed. | Not a defect |
| 5 | Home desktop best-practices 96: console errors from favicon requests to an external host, blocked by the sandbox network. | Environment only |
| 6 | Heatmap, lineage, arena: `label-content-name-mismatch` (aria-label of a day cell does not start with the visible text "15 3"). Existing tests assert the current label format. | Open (low) |
| 7 | Arena table: `td-has-header` (empty corner cell in the header row). Table markup belongs to the arena content work. | Open (low) |
| 8 | Newsletter consent "Privacy policy" link is 18px high (inline text link in a 13px label). | Open (low, sitewide) |

Visual review: every page was captured at 360, 768 and 1280px, light and dark, empty and populated (98 screenshots, kept out of git) and inspected. No horizontal overflow, clipped text, overlapping elements or unreadable dark-mode text was found beyond finding 1; empty states are explicit ("No verified data yet", dashed panels). Representative screenshots: `docs/phase7/screenshots/` (hub populated 1280 light, hub empty 360 dark, graph populated 1280 light; each under 150 KB).
