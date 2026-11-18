# TrustNova Tech AI development lead generation (Phase 6, priority 3)

## What was built
* `/services.php`: an index of 10 services and one page per service (`/services.php?s=<slug>`), each with who it suits, deliverables, the questions we will ask, the engagement process and an honest-claims panel. Content is original (`data/trustnova_services.json`). Services: custom AI agents, enterprise AI assistants, RAG and document intelligence, AI workflow automation, custom SaaS applications, API integrations, backend development, enterprise software modernisation, cloud solutions, AI-powered business applications.
* **Guided project inquiry** (three fieldset steps, works without JavaScript): project category (preselected from the service page), business problem (minimum 40 characters), existing technology, budget range, timeline, name, business email, company (required), website, preferred contact method (email, video call, phone), consent.
* `project-inquiry.php` validates (honeypot, time trap, token, field rules), stores a public lead, mirrors it into the CRM as a **development** lead with the project details and attribution, notifies the owner (mock sink in tests) and redirects to a thank-you page that echoes no personal data. Invalid input returns 422 with field errors and keeps what was typed; fields are marked `aria-invalid`.
* The inquiries appear in **CRM → Leads** (kind: TrustNova development) with the details block, and can be qualified, assigned, converted, quoted and tracked.
* Pages are in the sitemap (11 URLs, self-canonical) and linked from the footer; `business.php` (existing consultation form) is unchanged.

## What the pages do NOT claim
No client names, case studies, testimonials, project counts, team size, locations, results or timelines. Three plain statements instead: no published case studies yet (references only with client permission), outcomes agreed in a written scope rather than promised, and AI systems can be wrong so delivery includes review, logging and fallbacks. A test asserts the page contains no client or testimonial claims.

## How to verify
`php tests/crm_http_test.php http://127.0.0.1:8002` (project inquiry into the CRM, 7 rejection cases, honeypot, time trap, forged token, hostile text, duplicate flag) and `node tests/browser_phase6_test.js …` (ten cards, preselection, labels, keyboard path, validation, no-JS, mobile layout, axe).

## Suggested measurement (after launch, with consent and privacy text updated)
Inquiries by service and source; qualified share; time to first reply (a real risk: an unanswered inquiry is the cheapest loss); win rate and value by service. All visible in the CRM dashboard from real records only.

## Owner decisions
Who answers inquiries and how fast; which services to feature first (the page lists all ten equally); whether to show any reference or case study (only with evidence and permission); legal entity and privacy text for TrustNova Tech (an Impressum/TrustNova question is still open from earlier phases).
