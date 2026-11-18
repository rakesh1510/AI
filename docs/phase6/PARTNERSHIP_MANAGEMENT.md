# Global AI partnership management (Phase 6, priority 4)

## What was built
* `/partners.php`: who can apply (seven categories), how applications are handled, a **Verified partners** list that is empty until a partner is verified, and the application form.
* `partner-apply.php`: validates (category, company, website, proposal of at least 40 characters, consent, honeypot, time trap), stores a public lead and mirrors it into the CRM as a partnership lead plus a **partner record (status "applied", hidden)**.
* **CRM → Partners:** list and detail with company, category, proposal, status, notes, follow-up tasks and history.
* Categories: AI tool vendor, AI startup, SaaS company, affiliate partner, technology provider, implementation agency, enterprise partner.

## Workflow
`applied → in review → approved | rejected`; `approved → suspended`; `suspended → approved | rejected`; `rejected → in review`. **Approve, reject and suspend are owner-only**; rejecting or suspending needs a reason. Leaving "approved" always withdraws any public listing.

## The "never show an unverified company as a partner" rule
A partner appears on the public page only when **all** hold: status approved, public listing explicitly enabled by an owner, a verification timestamp and verifier, an **https evidence URL** (a public source showing the relationship), and the record is not synthetic test data. Approval alone does not list; reinstating a suspended partner does not silently relist (a new verification is needed); an applicant is never shown. This is enforced in `crm_partners_public()` and tested in the library and over HTTP (an approved but unverified partner is not shown; a listed one appears with its source; suspension removes it at once).

## Relationship history and opportunity tracking
Status changes, notes and tasks are recorded on the partner; partnership leads can be qualified and converted to opportunities (kind: Partnership) with expected value and proposals like any other opportunity.

## Limits and owner actions
No partner portal, no referral-fee accounting, no agreement templates, no logo upload (deliberately: partner logos are a trust claim). Decide what verification means for you (a signed agreement, a public announcement, a link from the partner's site) and keep to it.
