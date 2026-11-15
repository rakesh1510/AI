# UX design system (Phase 3)

Branch `feat/aihubpedia-premium-ux-v3`, built on Phase 2. Nothing is deployed.

## Principles
1. **Server-rendered first.** Every page is complete HTML. JavaScript only adds suggestions, saved tools, theme, filtering and motion. With JavaScript off the nav, search form, category lists and all tool links still work (tested).
2. **Evidence is the brand.** The hero, trust strip and "Reviewed" badges surface what is actually verifiable: tools listed, reviewed profiles with sources, guides, dated prices. Nothing is invented.
3. **Fast by default.** No framework, no web fonts, no icon library, no images in the hero. One CSS file (`ui.css`, ~30 KB) on top of the legacy stylesheet, one deferred script (`app.js`, ~20 KB).
4. **Accessible motion.** All animation is decorative, off under `prefers-reduced-motion`, and content never depends on it.

## Identity
- Typography: system UI font stack with heavy, tight display weights (no font download).
- Colour: indigo→blue→cyan gradient reserved for emphasis and icon tiles; solid indigo for buttons (white text, ≥4.5:1). Light and dark palettes are tokens in `:root` / `[data-theme="dark"]`, and a `prefers-color-scheme` fallback covers no-JavaScript visitors.
- Shape: 14–24 px radii, soft layered shadows, 1 px borders.
- "3D-inspired" details are pure CSS: a perspective card stack in the hero (real catalogue data: ChatGPT, Claude, Otter.ai with dated pricing), pointer-driven parallax, and hover tilt on category cards. Disabled for reduced motion and touch; the stack is hidden below 900 px to protect mobile LCP.

## Components
| Component | Where | Notes |
|---|---|---|
| Header + drawer | every page | Flat crawlable links; drawer only when JS is present and width ≤1180 px; `Escape` closes; More menu for secondary links |
| Search combobox | hero, header, results page | ARIA combobox/listbox, ↑ ↓ Enter Esc, `/` focuses, recent searches, debounce 110 ms, aborts stale requests |
| Tool card (`render_tool_card`) | home, search, category, favorites | One renderer; badges (pricing, Reviewed), save ♥, compare, outbound link with correct `rel`, affiliate note, optional votes |
| Journey cards, goal chips, category cards | homepage | Counts come from the live catalogue |
| Compare tray | all card lists | Up to two tools, session-only, links to `/compare.php?a=&b=` |
| Filter bar | category pages | Client-side filtering of the page's cards, announced via `aria-live` |
| Lead forms | `/business.php`, `/advertise.php` | Labels, `aria-invalid`, inline errors, honeypot, consent checkbox |
| Footer | every page | Newsletter (double opt-in), business CTA, disclosure, privacy choices |

## Pages added or redesigned
Homepage, header/footer, search results (`/search.php`), saved tools (`/favorites.php`), TrustNova Tech page (`/business.php`), advertise page, disclosure page, tool/category/compare enhancements (favorite, compare, filter, difference highlighting, outbound disclosure).

## Search is not AI chat
`discovery.php` ranks the catalogue with transparent rules: exact/prefix/word matches on name (highest), subcategory, category, tags, short description, use cases; +10 for reviewed profiles; a capped click-popularity boost; partial-match and typo (Levenshtein ≤2) fallbacks. It is the retrieval layer a future AI model could call; no model API is implied or stubbed. The UI says "Search", never "Ask AI".

## Accessibility checks performed
Skip link first in tab order; every button named; every field labelled; images have alt; unique ids; one `h1`; focus-visible outlines; 44 px targets on controls; reduced-motion verified in a real browser; Lighthouse accessibility 96–100 (see README). Dark theme contrast tuned from screenshots for the legacy compare, leaderboard and score components.

## Known limitations
- `ai-use-case-explorer.php` and `ai-use-case.php` ship their own light-only inline styling: they lock the light theme and hide the toggle until migrated.
- Tool logos still come from Google's favicon service (a third-party request per logo). Self-hosting logos would improve privacy and performance (see RELEASE_RISKS.md).
- Named collections and cross-device sync need accounts, which the architecture does not have. Favorites and recently viewed are stored in the browser only.
- The compare experience supports two tools.
- Legacy pages (blog, about, contact, submit, leaderboard) inherit the theme and new header/footer but keep their older layouts.
