# Advertising and sponsorship (Phase 6, priority 1)

## What works now
| Capability | Implementation | Verified by |
|---|---|---|
| Advertise landing page | `advertise.php` (existing, extended) | browser and HTTP tests |
| Inquiry types | featured placement, category sponsorship, custom campaign, newsletter sponsorship (register interest), affiliate or referral | `tests/crm_http_test.php` |
| Company and contact details, goals, budget range, package interest | existing lead form fields, stored in `leads` and in the CRM | same |
| Campaign record per inquiry | status `inquiry`, linked to company, contact and lead; package derived from the inquiry | HTTP and logic tests |
| Review workflow | inquiry → in review → approved → scheduled → live → completed; rejected or cancelled at allowed points; each step audited | `tests/crm_logic_test.php` |
| Approval and rejection | **owner only**; rejection needs a reason | logic and HTTP tests (a sales user is refused) |
| Sponsored-content disclosure | A label from a fixed list (Sponsored, Advertisement, Partner content) is **required before approval**; the rendering helper always shows a label and escapes it | logic tests |
| Safe destination | Approval requires a landing URL that passes the existing safe-URL check (no `javascript:`, no unsafe schemes) | logic tests |
| Attribution | An attribution code (`c<id>-<company>`) is assigned at approval; leads carrying `utm_campaign=<code>` are counted per campaign | logic and HTTP tests |
| Reporting definitions | Published on `/advertise.php` and shown per campaign: inquiry, attributed lead, outbound click, newsletter click (not defined: no newsletter), impression (**not measured**) | browser and HTTP tests |
| Advertiser FAQ | existing | – |

## Rules enforced by code and tests
* Advertising never changes organic ranking, evidence levels or recommendations (source scan; sponsorship-independence tests from Phase 5 still pass).
* An internal indicative quote can be stored on a campaign but is **never published**; no EUR price appears on any public page (tested: `€199` and `€499` are absent, `$19` is present).
* Newsletter sponsorship inquiries are recorded only as inquiries; nothing is sold or scheduled, and the page states the newsletter has not launched.
* No audience, traffic or conversion number is displayed anywhere.

## The two proposed offers
| Offer | Evaluation | Status |
|---|---|---|
| Featured placement EUR 199/month | Roughly four 7-day featured runs at list price ($196), so not a like-for-like swap; currency, term and VAT undecided | **Not published; needs owner approval.** Internal quotes can be recorded per campaign |
| Sponsored newsletter EUR 499/month | No newsletter, no confirmed audience, no inventory | **Blocked.** Do not sell until a launched newsletter has measured clicks |

## Operating procedure (owner/sales)
1. Open **CRM → Campaigns**. Read the inquiry; check the company website and whether the tool fits the directory's methodology.
2. Set the disclosure label, a safe https landing URL, dates and (optionally) an internal quote; move to **In review**.
3. An **owner** approves or rejects (with a reason). Approval assigns the attribution code to give the advertiser.
4. Move to Scheduled/Live/Completed as agreed in writing **outside this system**. Nothing here invoices or charges.
5. Report only the counts defined above, with the date range.

## Not built / needs approval
Payment links, invoices, automated placements on the public site (a placement still requires a deliberate site change reviewed by a person), click reporting dashboards per campaign, any newsletter inventory.
