# V3.1 premium refinements (staging only; V3 refined, not rebuilt)

No database, mail, analytics, monetisation, security or routing change. AI Universe stays hidden. Not merged, not deployed.

| Area | Change |
|---|---|
| Header | Translucent glass that follows the theme. On pages that open with a dark hero it floats over the hero (transparent, light text) and turns frosted (91% theme surface, blur 24px) after 12px of scroll. Falls back to a solid theme surface where `backdrop-filter` / `:has()` are missing. Mobile drawer stays solid. |
| Logo | New transparent AI tile (`assets/images/logo-mark-44/88.webp`, cropped and rounded from the existing logo) plus a live wordmark in Space Grotesk. No white plate; adapts to light/dark. Same brand in the footer. The old `logo-44/88.webp` files are unchanged. |
| Hero type | Headline `clamp(2rem, .9rem + 4.2vw, 4.1rem)`, line-height 1.04, letter-spacing -.036em, balanced wrapping; lead 1.02-1.2rem at 1.62; shorter eyebrow (mobile shows only "Independent directory"). |
| Search | White high-contrast field on the dark hero, placeholder #5a6180 on white (AA), 4px focus ring, subtle sheen on the button. Placeholder shortened so it is never truncated on phones. |
| Popular goals | One aligned grid of 6 equal buttons (3 columns desktop, 2 on phones) under a small label. |
| Decluttering | The business prompt is a quiet divider row with a secondary button (the header already carries the primary "Discuss Your Project"); one fewer filled button in the hero. |
| 3D art | Halo light, contact shadow, soft reflection (`-webkit-box-reflect`, progressive) and a tuned drop-shadow around the existing Blender render. A true re-render needs Blender, which is not installed here. |
| Floating cards | Glass cards, 44px tile, single-line "Reviewed · as of DATE" (previously overflowed). **Logo**: uses `assets/logos/<id>.png|webp` when present, otherwise a coloured monogram tile. See below. |
| Motion | Transform/opacity only: staggered rise-in of hero blocks, slow bob of the two cards, button sheen, brand-mark tilt on hover. All disabled under `prefers-reduced-motion`. |
| Consistency | Header, brand, footer and spacing under the header apply to every page that starts with a hero (`main>.stage:first-child`). |

## Recognisable tool logos
The sandbox cannot reach logo services and the app deliberately makes no third-party request for decorative cards. To show real logos on staging, run once on the staging server (it downloads each tool's favicon once, stores it locally; nothing else changes):
```bash
cd /var/www/html/AI-PHASE3-TEST && sudo -u www-data php scripts/cache_logos.php --limit=600
```
`assets/logos/` is created as untracked runtime files. Commit them only if you want them in Git.

## Verification (isolated test DB, run as a non-root user)
PHP: seo 1596, premium_ui 19, universe_hidden 27, security_inventory 552, security_http 76, phase4_logic 8683, forms_email_e2e 41, lead_reliability 56, crm_http 116.
Browser: axe 0 violations, browser_test 135, browser_ux 196, premium_ui 300, phase4 100, phase6 125.
Lighthouse, homepage, mobile, uncompressed dev server: performance 98, accessibility 100, best practices 100, SEO 100, CLS 0, TBT 10 ms. CSS 165,092 -> about 175,900 bytes before compression.
Bugs found and fixed during verification: search suggestions were painted under the animated goals block (z-index), the first pass of the footer wordmark was dark on dark, the scrolled header looked grey over the hero edge.

Screenshots: `docs/redesign/v3/v31/` (before/after desktop and mobile, scrolled header, footer).
