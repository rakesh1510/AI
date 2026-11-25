# Global AI Ecosystem: interactive 3D homepage hero (staging only)

Replaces the abstract knot. Homepage artwork only: no database, SMTP, analytics, monetisation or routing change. AI Universe stays hidden. Not merged, not deployed.

## What it is
A real-time Three.js / WebGL scene (not an image): a rotating Earth (NASA imagery, ocean glint, cloud shell with cloud shadows, gold city lights with bloom, luminous blue atmosphere, sun flare), a network of light arcs and travelling particles between 18 world hubs, three glowing blue / cyan / violet orbit rings (one crosses in front of the planet), a glossy violet floor, and up to 10 dark-glass application slabs (8 on phones) that orbit in 3D in front of and behind the Earth.

## Reference match
`docs/redesign/v3/globe/comparison-reference-vs-live.webp` shows the approved reference next to the live render. Matched: composition, slab tiles with official logos and names, rings, flare, night-side Earth with gold lights, reflective floor. Not matched, and why: Meta AI, Midjourney, Canva, Notion and Llama are not tools in the AIHubpedia directory (and OpenAI/Meta marks are limited to what the licensed icon sets provide), so the globe shows the directory's own tools: ChatGPT, Claude, Gemini, Perplexity, Copilot, DeepSeek, NotebookLM, Bing, DeepL, Grammarly and others, with monogram tiles for tools that have no official mark. The reference is a rendered illustration with offline-quality bloom; the live scene uses real-time lighting, so its glow is more restrained.

## Behaviour
- Earth turns continuously (about 63 s per revolution) and the tiles orbit on their own rings; nothing needs the cursor.
- Cursor: smooth perspective shift of the whole scene; hover highlights a tile and shows name, category, plan and "Reviewed - as of" date; click opens the tool's AIHubpedia page. Drag (mouse or touch) rotates the Earth with inertia; automatic rotation resumes when released. Touch: first tap shows the card, second tap opens the page.
- Tools rotate: one tile is replaced every 3 s from a pool of up to 36 directory tools (two with official logos for every monogram tool). The first view shows the best-known logos.
- Sponsored positions: only genuinely sponsored (active featured) tools get them: amber "SPONSORED" tile, `rel="sponsored nofollow"`, tooltip label. With no sponsors there are no ads in the artwork; the "Advertise" link stays in the accessible list.
- Accessibility: the canvas is decorative (`aria-hidden`); every tool is also a real link in a keyboard-reachable list that appears on focus; reduced-motion, Save-Data and no-WebGL visitors keep the static poster and never download the 3D code.

## Loading
Static poster WebP (LCP image, explicit size, no layout shift) first. 2.5 s after load and when the hero is near the viewport, the 3D bundle (133 KB gzip) and textures (about 300 KB) load in the background; initialisation is split into short slices and shaders compile asynchronously, then the canvas cross-fades over the poster. The scene pauses when off-screen or the tab is hidden; DPR is capped (2 desktop, 1.5 phones).

## Measured (isolated test DB, software GPU, so the live-start figures are pessimistic)
Lighthouse mobile: performance 98, accessibility 100, best practices 100, SEO 100, LCP 2.0 s, CLS 0, TBT 0 ms (two runs).
Lighthouse desktop: performance 100, accessibility 100, SEO 100, best practices 96 (only miss: console errors from existing tool cards requesting Google favicons, blocked in the sandbox; `scripts/cache_logos.php` on staging makes them local), LCP 0.7 s, CLS 0, TBT 0 ms.
Tests, non-root: seo 1594, premium_ui 19, universe_hidden 27, security_inventory 552, security_http 76, phase4_logic 8683; axe 0 violations; browser 135 / ux 196 / premium_ui 300 / phase4 100 / phase6 125.
Demonstrations: `docs/redesign/v3/globe/demo-*.webp` and `auto-rotation.gif` (automatic rotation with no cursor, cursor perspective, drag and release).

## Notes
A one-off 2.5 s main-thread block appears in headless/software-GPU runs when the first WebGL context is created; it is an artifact of software rendering (a real GPU is already running), and is why the start is deferred to 2.5 s after load.
