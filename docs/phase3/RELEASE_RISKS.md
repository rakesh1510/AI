# Release risks (Phase 3 additions)

Phase 1 and 2 risks still apply (rotate the committed secrets, protect install scripts, CSRF/rate-limit legacy write endpoints, legal notice). New for Phase 3:

| # | Risk | Severity | Action |
|---|---|---|---|
| 1 | **Real client IP behind a proxy/CDN.** Rate limiting hashes `CF-Connecting-IP` or `REMOTE_ADDR`. Behind a proxy that sets neither, every visitor shares one hash, so after 5 leads/signups an hour *everyone* is rate limited | **High before launch** | Configure the proxy (Cloudflare sets `CF-Connecting-IP`; otherwise map `X-Forwarded-For` from trusted proxies only) and test |
| 2 | **Mail credentials.** Lead notifications and newsletter confirmations use `site_settings.mail`. The committed credentials must be rotated and stored securely. Without working mail, leads are stored but nobody is notified | **High** | Rotate, configure, send a test lead |
| 3 | **Privacy policy / legal text** does not yet describe: leads, newsletter, browser storage, optional usage statistics, third-party favicon requests | **High** | Update `privacy.php` with legal review before enabling any form or analytics |
| 4 | **Legal notice vs TrustNova Tech.** The Impressum names an individual owner. The new business page and disclosure present TrustNova Tech as the provider of consulting and as the company behind the site | **High** | Confirm the operating entity and update the Impressum/privacy controller. The company name is configurable (`AIHUB_BUSINESS_NAME`) |
| 5 | **Third-party logo requests.** Every tool card loads a logo from Google's favicon service (visitor IPs and browsing context go to Google; slower pages) | Medium | Self-host logos (cache once, serve from `/assets/logos/`) |
| 6 | **Form tokens in cached HTML.** Tokens expire after 24 h; a CDN that caches HTML longer would serve expired tokens | Medium | Cache HTML ≤ 1 h or fetch tokens client-side; tokens carry no secrets |
| 7 | `data/.form_secret` is generated at runtime in a writable data folder (gitignored); if not writable, tokens fall back to a weaker host-derived secret | Medium | Set `AIHUB_FORM_SECRET` in the environment |
| 8 | Analytics and events must stay disabled until legal sign-off; the code never enables them | Medium | Leave `AIHUB_ANALYTICS` unset until ready |
| 9 | New tables must exist (`database/phase3_business.sql`); pages degrade to a "temporarily unavailable" message with an email fallback if not | Medium | Apply the SQL during deploy |
| 10 | Medium-confidence classifications, "pending editor verification" profiles and conflicting vendor prices (Phase 2) now appear more prominently on the homepage | Medium | Have an editor verify the six profiles shown on the homepage first |
| 11 | Sponsored/affiliate labelling relies on data: if an affiliate URL or featured date is added, labels appear automatically, but legal wording (disclosure page) should be reviewed for your jurisdictions | Medium | Legal review of `/disclosure.php` |
| 12 | Two legacy pages are light-theme-locked; others inherit the theme with older layouts | Low | Migrate over time |

## Not verified in this phase
Production traffic, performance and capacity; real email delivery (the test uses a local SMTP sink); behaviour behind your actual proxy/CDN; screen readers (automated checks and keyboard tests only, no assistive-technology testing); conversion outcomes of any kind.
