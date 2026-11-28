# Revenue, lead and trust review (staging only)

Scope of this iteration: header consistency, homepage messaging for individuals and companies, consultation CTA, services promotion, enquiry-journey verification, mobile spacing, monetisation gap analysis. No production, database, mail-configuration or server change. No client, testimonial, review or statistic was invented: every claim below exists in the catalogue/config or is a commitment already published on `services.php`.

## 1. What changed
| Area | Change |
|---|---|
| Header | Frosted glass is the default from first paint in both themes (light: 91 % `--bg` glass; dark: 86 % `#0a0d24` glass; subtle bottom border). The transparent-over-hero state was removed, so nothing changes on scroll (measured: identical computed background and border at load, settled and scrolled, both themes). |
| Messaging | Hero lead now states the individual benefit (compare plans, limits and prices with sources and dates; choose with confidence) and the company path (custom AI built when no tool fits). "What brings you here?" and the footer name the offer: custom AI agents, workflow automation, chatbots, API integrations, custom software. |
| CTA | "Discuss Your Project" -> **Book a Free Consultation** in the header, hero, services band, footer (x3), business and services pages, enquiry form heading and submit button ("Request my free consultation"). `data-cta` ids are unchanged, so existing conversion reporting stays continuous. Hero business strip is now a bordered accent panel with a filled primary button. |
| Services on the homepage | The "Build with AI" band is retitled "Custom AI and software for your business" and lists the catalogue services (custom AI agents, enterprise AI assistants, RAG, workflow automation, SaaS, API integrations, backend, modernisation, cloud, AI business applications). "Chatbots" is covered by the catalogue's custom agents and enterprise assistants. |
| Mobile | Tighter section padding, card grid gap, tool-card padding/clamped descriptions, goals and path spacing at <= 560 px. |
| Globe | Untouched. |

## 2. Enquiry journey: verification (isolated test database, mock SMTP sink, non-root; nothing sent to a real address)
| Check | Result |
|---|---|
| `forms_email_e2e` (validation, references, owner notification, customer acknowledgement, duplicate guard, spool/retry) | 41 / 41 |
| `lead_reliability` | 56 / 56 |
| `crm_http` (public forms through HTTP, CRM intake) | 116 / 116 |
| `security_negative_http` (CSRF/token, honeypot, rate limits, bad input) | 187 / 187 |
| `universe_hidden` | 27 / 27 |
| `premium_ui`, `security_inventory` | 19 / 19, 552 / 552 |
| Browser journey at 390 px (iPhone profile): hero CTA -> services form | CTA text and link correct; empty submit -> 422 with an alert and 4 `aria-invalid` fields; honeypot filled -> rejected; valid submit -> 303 to `/inquiry.php?sent=dev_project&ref=AHP-L-...` with the thank-you page; the SMTP sink received the owner notification (reference in subject) and the customer acknowledgement |
| Conversion tracking | `data-cta` clicks send `cta_click` via `sendBeacon` to `/event.php` when `data-events="1"` and the visitor has consented; `lead_submit` is recorded server-side. Tracking is **off unless analytics is enabled in the environment** (by design: privacy first). Decide when to enable it on staging/production; the code needs no change. |
| Spam protection | Form token + hidden honeypot + time trap + per-IP rate limit (429 path) are in place and tested. No CAPTCHA (no third-party dependency); revisit only if spam appears. |
| Real e-mail delivery | Not testable here (no SMTP credentials, by rule). Use `scripts/mail_health.php --probe` and `scripts/mail_send_test.php --yes` on staging (see `docs/redesign/v3/MAIL_SETUP_STAGING.md`) and submit one real enquiry. |

## 3. Monetisation: what exists and what is missing
Existing (do not duplicate): sponsored listings and homepage spotlight with "Sponsored" labelling and `rel="sponsored nofollow"`; `advertise.php` packages (free, $19 priority, $49 featured 7 days, $99 spotlight 14 days; manual invoice) plus inquiry-only monthly placement and newsletter sponsorship; affiliate-link support with disclosure and outbound tracking (`redirect.php`, `outbound_click.php`, `affiliate-dashboard.php`); TrustNova services leads into the CRM; partner programme; newsletter capture.
Gaps and ranked opportunities (none built here, to avoid duplication and keep the iteration focused):
1. **Contextual consultation CTA on tool, compare and guide pages.** Today only the global footer promotes services (tool/compare/guide pages contain no services link). A slim "Need this integrated or built around your process? Book a free consultation" strip, tagged `data-cta` per page type, targets the highest-intent corporate visitors. Highest expected lead value, low risk.
2. **Affiliate coverage audit.** Verify in the admin how many catalogue tools actually have an affiliate URL; add genuine programme links only where the vendor has an official programme; keep the disclosure line. Revenue depends on coverage, not on new code.
3. **Enable conversion analytics** (consent-based) so CTA ids can be ranked by lead volume before any further redesign.
4. **Newsletter sponsorship and monthly placement**: publish only after the owner approves the structure and real measured audience figures exist (the package file forbids invented numbers).
5. **Vendor funnel**: free-listing submitters are the best prospects for paid placements; a post-approval e-mail offering the labelled placements (manual, from the CRM) needs no new feature.
6. **Booking step** (calendar link after the thank-you) only if the owner wants a self-service call; needs an external provider and a privacy review.

## 4. Trust and credibility (verifiable only)
Kept: "509 tools listed", "20 reviewed profiles with sources", "4 comparison guides", "Prices carry an as-of date", the independent-directory statement, the disclosure page, and on `services.php` the commitments that already exist: written scope before work starts, no outcomes promised in advance, no client case studies claimed. Not added: client logos, testimonials, ratings, user counts or revenue/traffic figures (none are verifiable from the repository). When real, permissioned references exist they can be added to the services page.

## 5. Positioning update: global AI, automation and enterprise software company
TrustNova Tech is presented through eight business-oriented services (`data/trustnova_services.json`, field `portfolio` 1-8). **Every existing slug is kept** (URLs, sitemap entries, stored enquiries and `service` values stay valid); one slug is new.

| # | Customer-facing service | Slug | Note |
|---|---|---|---|
| 1 | Custom AI Agents | `custom-ai-agents` | unchanged URL |
| 2 | Business Process Automation | `ai-workflow-automation` | retitled |
| 3 | AI Chatbots & Assistants | `enterprise-ai-assistants` | retitled |
| 4 | Custom Software Solutions | `custom-saas-applications` | retitled; backend work is part of it |
| 5 | AI & Enterprise Integrations | `api-integrations` | retitled |
| 6 | Data Analytics & AI Insights | `rag-document-intelligence` | reframed around business data and referenced insights |
| 7 | Cloud & Digital Transformation | `cloud-solutions` | retitled; includes staged modernisation |
| 8 | AI Consulting & Strategy | `ai-consulting-strategy` | **new page**, added to the sitemap automatically |

Specialist engagements keep their URLs and are listed on the services page as part of a portfolio service: Backend & API Engineering (was "Backend development"; the homepage tile "PHP/MySQL development" is gone), Enterprise Software Modernisation, AI-Powered Business Applications.
Updated for consistency: homepage services band, hero business panel and path card, services overview and detail pages (outcomes shown as the fact row), business page, footer, project-type list in the consultation form, SEO titles/descriptions (`seo.php`, `services.php`, `business.php`), disclosure wording. No technology names (PHP, MySQL) appear in customer-facing service copy; the technology stack itself is unchanged.
Honesty rules kept: outcomes are "designed to support / aims", a new catalogue note states they are goals, not guarantees; no clients, testimonials, certifications, statistics or guarantees were added.
**Owner to confirm before production:** that TrustNova can genuinely deliver (a) analytics and reporting views for client data (service 6 lists "reports or views that present the findings") and (b) AI strategy consulting engagements (service 8). Remove or soften a deliverable line in `data/trustnova_services.json` if not.
