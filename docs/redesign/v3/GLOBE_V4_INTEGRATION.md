# Homepage globe V4 (staging only)

Hero globe = ChatGPT's V4 candidate (`assets3d/globe/proto.src.js`, unmodified, kept) built via `assets3d/globe/home.src.js` into `assets/globe/globe.v4.min.js` (~147 KB gzip, Three.js r170). Only `index.php` line 117 changed (`data-src` now points to the V4 bundle). Hero markup, poster, tooltip placeholder, keyboard list, `#globeData`, SEO, navigation, search and sponsor blocks are unchanged. `assets/js/app.js` loader unchanged.

## Differences between `home.src.js` and `proto.src.js` (integration only, no redesign)
1. Genuine sponsors from `#globeData.sponsors` (max 2) are placed first and keep the existing "SPONSORED" tile badge; the hover card adds "Sponsored placement - paid, never changes a tool's facts". With no sponsors nothing is shown.
2. The visible links nav the prototype appended below the canvas is removed; the existing server-rendered `.globeList` (visually hidden, shown on focus, `rel="sponsored nofollow"` for sponsors) is the keyboard path.
3. Rendering pauses when the globe is off-screen or the tab is hidden (IntersectionObserver + visibilitychange); resumes on return.
4. Adds `.is-live` once rendering starts (fades the poster out via existing CSS).
5. Auto-init runs once and swallows WebGL/texture failures, so the static poster stays.

## Loading / fallback (existing loader, unchanged)
Poster first (LCP). Script loads right after `load` (browser idle, 400 ms cap) when the hero is near the viewport; the four textures are preloaded in parallel with the script. Skipped for `prefers-reduced-motion`, Save-Data and 2G: the poster stays. No WebGL or failed textures: the poster stays.
Textures: `assets/globe/v4/{day4k,night4k,clouds4k}.webp` + `assets/globe/earth-water.webp` (about 0.85 MB, loaded only when the globe starts).

## Rebuild
`cd assets3d/globe && ./build.sh` (rebuilds V1 and V4). Preserved: `globe.src.js`/`globe.min.js` (V1), `proto.src.v2-backup.js`, `proto.src.v3-backup.js`, earlier backups. Prototype pages, ChatGPT's supplied V4 source, V2/V3 and earlier source backups are kept in `docs/redesign/v3/globe-prototypes/`, which the existing `.htaccess` rule answers with 404 (not publicly served). Only runtime assets live under `assets/`.

## Rollback
Revert the `data-src` line in `index.php` to `globe.min.js`.

## Background blend
`premium3.css` (last `.globeCanvas,.globePoster` rule): a radial mask intersected with 9 % linear edge fades, so the canvas background feathers to nothing at the box edge while all tiles stay fully opaque. Hero stays dark in both themes (checked).

## Poster = V4 (post-release fix)
The static poster is now a render of the V4 scene at t=0 (`assets/globe/globe-poster-v4.webp` 830x692 for wide screens, `globe-poster-v4-m.webp` 600x612 for <=900 px), rendered with the same bundle and the same quality tier the live globe uses (the hero box is always < 600 px, so the live scene runs in its "small" tier). The live globe starts at the same t=0 state and its motion eases in over 0.7 s (smoothstep) while the 0.5 s cross-fade runs, so there is no visible jump and no stationary wait. The old V1 posters (`earth-poster*.webp`) are no longer referenced and stay in the repo.
Start-up cost: the loader starts the bundle right after load; `home.src.js` now warms up in steps (async shader compile, per-texture upload, one off-screen frame) before the first visible frame, and the canvas only fades in after that frame is drawn.
Regenerate a poster after any visual change: serve the site, open a temporary page that loads `globe.v4.min.js?freeze&globeforce` in a box of 553x461 (wide) or 400x408 (small) CSS px at deviceScaleFactor 1.5, wait for `data-ready`, call `snapshotFrames(1)` and screenshot the box.
The `interest-cohort` token was removed from the Permissions-Policy header in `security.php` (Chrome no longer recognises it).

## Transparent canvas, real start-up fix (staging feedback round 2)
- **Start-up root cause (measured with performance marks):** the IntersectionObserver started the render loop while the warm-up was still awaiting, so the first rAF frame compiled every shader and uploaded every texture synchronously (the ~1.5 s frame) and the canvas only became visible after it. `go()` now waits for `warmed`; the first visible frame is cheap (14 ms after warm-up in the probe run).
- **Dark rectangle root cause:** the canvas and the poster were opaque (#02040c) on a gradient hero. The renderer now uses `alpha: true`, no scene background, and a last shader pass sets coverage = max(scene alpha, brightest channel) after tone-mapping, which over black equals the previous opaque render. The posters are WebP with alpha, rendered from the same bundle on a transparent page (`omitBackground`). The CSS mask only feathers the extreme corners.
- Poster size is kept small (71 KB / 40 KB) because the poster is the LCP element.

## Start-up architecture (round 3) and the `?globeperf` diagnostics
**Order of work** (`home.src.js`, `init()`): the loader (`assets/js/app.js`) starts right after the browser has painted its first largest element (never waits for window `load`, cards or favicons; 1.5 s safety net) and preloads the four textures as `fetch` resources in parallel with the bundle. Inside `init()`:
1. Textures: `fetch` + `createImageBitmap` (off-thread decode, pre-flipped; falls back to `<img>` + `decode()`), started first, overlapping everything below. Pixel-identical to the previous `TextureLoader` path (verified against the poster: mean diff ~2.5/255).
2. Scene, composers and passes are built synchronously (about 60 ms CPU).
3. **One compile batch:** scene materials, the bloom swap materials (with the same light counts as the scene), the bloom/composite/output/mix passes (render-target context) and the final pass (screen context) go to `renderer.compileAsync` together. Where `KHR_parallel_shader_compile` exists the driver links them concurrently and the main thread stays free; without it three.js uses its ordinary synchronous path. `OutputPass`'s lazily built defines are set up front so its first render does not compile it again. Verified: 0 extra programs are created by the first real frame.
4. **Texture uploads:** one per frame (`initTexture`) in arrival order, while the compile runs; then the eight tile textures.
5. One invisible warm frame, then the loop starts; `is-live` is added on its first frame; motion eases in over 0.7 s.
Observers: the `ResizeObserver` only resizes (de-duplicated) until start-up is finished; the `IntersectionObserver`/`visibilitychange` cannot start the loop before that either.

**Diagnostics:** open `/?globeperf` and read the collapsed console group `[globeperf]` about 3 s after the globe appears (also `window.__globePerf`). Every row says what it is: measured CPU/main-thread time, measured network, measured wall-clock that includes GPU/driver work (shader compile and texture upload cannot be separated from it), real frame cadence (rAF intervals), measured GPU time per frame (only where the browser exposes `EXT_disjoint_timer_query_webgl2`), or an explicit ESTIMATE. Rows: navigation to poster, bundle download and init, texture fetch/decode/upload, shader compile (per group and whole batch, parallel-extension flag), post-processing build, first live frame, first visible animation, poster-to-animation total, long tasks, fps. Nothing is logged without the flag.
(`?globeforce` only overrides the software-rasteriser guard and is for testing in headless/software environments.)

## Round 4: measured-bottleneck fixes (staging GPU diagnostics)
Findings from the first real-GPU report and what changed:
- **Instrumentation was misleading:** `init_start` sat after WebGL context creation; the "warm frame" row included the wait for uploads; compile rows overlapped; upload rows included `gl.finish()`. Now: `init_start` is the first statement of `init()`, WebGL context creation has its own `context_start/context_ready` marks, every row is on one navigation-relative origin (an "at" column and a "duration" column) and labelled (CPU / network / wall-clock / GPU timer / frame cadence). The `performance.mark('globe:*')` entries are always written (also in normal browsing): `performance.getEntriesByType('mark').filter(m => m.name.startsWith('globe:'))`, or call `window.__globeReport()` after the globe has appeared. `?globeperf` prints the report 3 s after the globe appears and adds **no** work and no GPU synchronisation. `?globeperf=gpu` additionally calls `gl.finish()` after each texture upload and uses GPU timer queries where the browser exposes them (diagnosis only: slower than normal browsing).
- **Textures:** the loader (`assets/js/app.js`) starts `fetch` + `createImageBitmap` for the four textures in parallel with the bundle download; `init()` takes the decoded bitmaps (each consumed once; closed after upload; released if the bundle never runs). Pixel-identical to the previous path. Fallback chain: bitmap job -> fetch in `init()` -> `<img>` + `decode()`.
- **GL initialisation while textures load:** after the compile batch, all 15 render targets are initialised and one invisible draw is run, with textures not yet uploaded (their `needsUpdate` is set at upload time, so the warm draw can never trigger a synchronous 4K upload). Textures are not part of three.js's program key: the report's program counts (after compile / after warm draw / at first live frame) must be equal.
- **Loader:** starts when the browser reports the poster as the painted LCP element (same signal as Lighthouse's LCP), without the idle-callback and IntersectionObserver hops when the hero is already near the viewport. *Tried and reverted:* creating the WebGL context in the loader (measured +~250 ms main-thread on software renderers and -6 Lighthouse mobile points), and starting at DOMContentLoaded / poster `load` (Lighthouse LCP 0.7 -> 1.9 s desktop). A `<link rel=preload>` for the script was not added for the same reason.
- **Animation:** ease-in 250 ms (was 700 ms). The first visible rotation (>= ~1 px) is expected about 200 ms after the first live frame; poster/live drift during the 0.5 s cross-fade stays within a few pixels.
- **Manifest:** `<link rel="manifest" ... crossorigin="use-credentials">` so Chrome sends the Basic-Auth credentials with the manifest request (it omits them by default, which produced the 401). No server or auth configuration was touched.

## Hero composition refinement (V4.2, layout only)
`assets/css/premium3.css` (last block): on >= 901 px the copy and the globe share one top line (`align-items:start`), the globe is larger at wide breakpoints (122 % -> 128 % from 1300 px -> 136 % from 1440 px -> 140 % from 1700 px of its column; each step verified to keep every orbiting tile inside the viewport and clear of the copy), the copy column is compacted (headline 2 lines, tighter lead/search/goals/business-strip spacing) so the search bar and all six goals sit higher, and the search bar is slightly larger with a stronger glow. `.homeHero.stage` clips horizontal overflow. Tablet/mobile keep the stacked order with tighter gaps.
Because the hero box is now up to ~650 px wide, `home.src.js` uses the small quality tier below 700 px (it used `< 600`): the live scene keeps exactly the tier, framing and bloom the posters were rendered in. Three posters match the three live tiers: `globe-poster-v4.webp` (921x767, > 900 px), `-t` (780x795, 601-900 px), `-m` (600x612, <= 600 px); poster vs live difference ~2.3/255 at 1920, 1440, 768 and 390 px. Regenerate with the procedure above using boxes of 614x511, 520x530 and 400x408 CSS px at deviceScaleFactor 1.5.
