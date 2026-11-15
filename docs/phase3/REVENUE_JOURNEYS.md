# Revenue and conversion journeys

All journeys use real information only. No testimonials, customer logos, revenue, traffic, sponsorship or ranking claims were created. Where nothing exists yet, the page says so.

| Journey | Entry points | What happens | Status in the real catalogue |
|---|---|---|---|
| **Affiliate referrals** | "Visit site" buttons, tool profile CTA, compare | Outbound links go through `/redirect.php` (click counted). If a tool has an affiliate URL (or a site-wide affiliate parameter is set) the link gets `rel="sponsored nofollow noopener"` and an "Affiliate link" note | **0 affiliate links exist today.** The disclosure page reports the live count. Infrastructure only |
| **Sponsored listings** | Homepage sponsored section, tool profile | Featured/spotlight listings render with a visible **Sponsored** tag, "Why am I seeing this?" link to `/disclosure.php`, and `rel="sponsored"` | **0 active today** (the homepage section is hidden when empty). Test DB seeds two to prove labelling |
| **Advertiser inquiries** | `/advertise.php`, footer, homepage band | Form (type: featured/spotlight or affiliate partnership) → `leads` table + owner email. Page states what a placement does and does *not* do and promises no traffic | Prices shown are the existing configured list prices ($19/$49/$99) |
| **Newsletter** | Footer on every page | Explicit consent checkbox → pending subscriber → confirmation email (double opt-in) → confirmed. One-click-safe unsubscribe (confirm button) | **No newsletter issues are sent by this code.** It stores confirmed subscribers for use with an email provider |
| **TrustNova Tech consultations** | `/business.php`, header CTA, homepage, tool pages | Form (consultation / custom AI agent / custom software) with optional budget and timeline → `leads` + owner email | Services are only those you named; no credentials, clients, response times or pricing are claimed |
| **Custom AI agent / software leads** | same form, `?need=ai_agent` / `software_dev` | Preselects the request type for deep links | same |

## Honest empty states
- No sponsored placements → the homepage omits the sponsored section and shows a labelled advertising CTA instead of fake listings.
- The disclosure page computes "N listings use an affiliate link / N sponsored placements are active" from the data on each load.
- The business page and advertise page contain no testimonials, "trusted by", traffic numbers or guarantees (asserted by tests).

## Data flow and storage
`database/phase3_business.sql` creates `leads`, `subscribers`, `events`. No raw IP address or user-agent string is stored anywhere; rate limiting uses a short salted hash (5 leads and 5 signups per network per hour; confirmation emails throttled to one per address per 10 minutes). Forms use stateless HMAC tokens (CSRF + 2-second time trap + 24 h expiry), a honeypot and server-side validation; no session cookie is set.

## What is NOT built (needs you)
- Payment/checkout for placements (the existing process is manual: submit → approve → invoice).
- Affiliate network onboarding or link generation (add `affiliate` URLs per tool or `affiliate_default` in site settings).
- Sending newsletters, and syncing subscribers/leads to an email provider or CRM.
- A lead admin UI (use SQL or `scripts/business_report.php`).
- Public pricing for TrustNova Tech services, case studies, and any client logos or testimonials. Add them only when real and approved.

## Before enabling in production
1. Configure mail (`site_settings.mail`) with **rotated** credentials; without it, leads are still stored but confirmation emails and notifications fail visibly ("saved but email did not send").
2. Update the privacy policy to cover: contact/lead data, newsletter consent, browser storage (theme, saved tools), and optional anonymous usage statistics.
3. Resolve the legal-notice question about TrustNova Tech (RELEASE_RISKS.md).
4. Put the site behind a proxy setting that exposes the real client IP, or rate limits will treat all visitors as one network (RELEASE_RISKS.md).
