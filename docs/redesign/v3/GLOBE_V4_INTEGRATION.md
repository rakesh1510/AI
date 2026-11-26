# Homepage globe V4 (staging only)

Hero globe = ChatGPT's V4 candidate (`assets3d/globe/proto.src.js`, unmodified, kept) built via `assets3d/globe/home.src.js` into `assets/globe/globe.v4.min.js` (~147 KB gzip, Three.js r170). Only `index.php` line 117 changed (`data-src` now points to the V4 bundle). Hero markup, poster, tooltip placeholder, keyboard list, `#globeData`, SEO, navigation, search and sponsor blocks are unchanged. `assets/js/app.js` loader unchanged.

## Differences between `home.src.js` and `proto.src.js` (integration only, no redesign)
1. Genuine sponsors from `#globeData.sponsors` (max 2) are placed first and keep the existing "SPONSORED" tile badge; the hover card adds "Sponsored placement - paid, never changes a tool's facts". With no sponsors nothing is shown.
2. The visible links nav the prototype appended below the canvas is removed; the existing server-rendered `.globeList` (visually hidden, shown on focus, `rel="sponsored nofollow"` for sponsors) is the keyboard path.
3. Rendering pauses when the globe is off-screen or the tab is hidden (IntersectionObserver + visibilitychange); resumes on return.
4. Adds `.is-live` once rendering starts (fades the poster out via existing CSS).
5. Auto-init runs once and swallows WebGL/texture failures, so the static poster stays.

## Loading / fallback (existing loader, unchanged)
Poster first (LCP). Script loads 2.5 s after `load`, at idle, when the hero is near the viewport. Skipped for `prefers-reduced-motion`, Save-Data and 2G: the poster stays. No WebGL or failed textures: the poster stays.
Textures: `assets/globe/v4/{day4k,night4k,clouds4k}.webp` + `assets/globe/earth-water.webp` (about 0.85 MB, loaded only when the globe starts).

## Rebuild
`cd assets3d/globe && ./build.sh` (rebuilds V1 and V4). Preserved: `globe.src.js`/`globe.min.js` (V1), `proto.src.v2-backup.js`, `proto.src.v3-backup.js`, earlier backups. Prototype pages, ChatGPT's supplied V4 source, V2/V3 and earlier source backups are kept in `docs/redesign/v3/globe-prototypes/`, which the existing `.htaccess` rule answers with 404 (not publicly served). Only runtime assets live under `assets/`.

## Rollback
Revert the `data-src` line in `index.php` to `globe.min.js`.

## Background blend
`premium3.css` (last `.globeCanvas,.globePoster` rule): a radial mask intersected with 9 % linear edge fades, so the canvas background feathers to nothing at the box edge while all tiles stay fully opaque. Hero stays dark in both themes (checked).
