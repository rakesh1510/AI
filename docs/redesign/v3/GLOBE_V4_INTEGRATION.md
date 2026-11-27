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
