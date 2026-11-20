# Financial model toward EUR 979,000 per month (Phase 8H)

**Everything in this document is scenario arithmetic on stated hypotheses. It is not a forecast, a promise or a measurement.** No customer, contract, visitor or revenue figure exists in the repository today; none is claimed. Every assumption below is to be replaced by measured values from the CRM and the Finance module as soon as real data exists. The model exists to show **what must be true** to reach the target and where the business is fragile.

## 1. How the model works
A single engine of **service-led recurring and project revenue** (TrustNova Tech). The AIHubpedia advertiser and vendor stream is **excluded** from the numbers until the audience is measured (section 6).

For each month *t*:
- New accounts won: `new(t) = min(cap, max(0, t - m0 + 1) x g)`: nothing until the first win month `m0`, then a growing rate that is limited by sales capacity (`cap`).
- Active accounts: `N(t) = N(t-1) x (1 - churn) + new(t)`.
- Revenue: `R(t) = N(t) x ARPA`, where ARPA is the blended average monthly revenue per active account (retainers plus project revenue spread over its duration).
- Gross profit `= R x gross margin`. Delivery capacity `= R / revenue per delivery person`.
- Operating profit `= gross profit - new(t) x CAC - 10% of R - EUR 6,000 fixed`. The owner's own pay, tax, financing costs and currency effects are **not** included.
- Cash: revenue is collected one month after it is earned; costs are paid when incurred. "Cumulative cash" is the running total from month 1 with no outside funding.

## 2. Assumptions (hypotheses, all challengeable)
| Assumption | Conservative | Base | Ambitious |
|---|---|---|---|
| First win in month `m0` | 5 | 4 | 3 |
| Growth of the monthly win rate `g` (accounts per month, per month) | 0.10 | 0.18 | 0.28 |
| Ceiling `cap` (new accounts per month, sales capacity) | 4 | 7 | 9 |
| ARPA (EUR per active account per month) | 4,500 | 8,000 | 14,000 |
| Monthly churn of active accounts | 4% | 3% | 2% |
| Cost to acquire one account, CAC (EUR) | 3,500 | 3,000 | 2,500 |
| Gross margin | 35% | 42% | 50% |
| Revenue per delivery person per month (EUR) | 15,000 | 18,000 | 22,000 |
| Implied loaded cost per delivery person per month | 9,750 | 10,440 | 11,000 |

Meaning of each case: **Conservative** is a founder-led start with small accounts and slow referrals. **Base** needs disciplined outbound, a proven offer, early partner referrals and hiring or contractors. **Ambitious** needs a working partner channel and enterprise-size accounts; it has a low probability and is shown only to size the effort.

## 3. Results under those assumptions
| Result | Conservative | Base | Ambitious |
|---|---|---|---|
| Month revenue first reaches EUR 10,000 | 11 | 7 | 4 |
| EUR 50,000 | 20 | 12 | 7 |
| EUR 100,000 | 28 | 15 | 9 |
| EUR 250,000 | 47 | 23 | 14 |
| EUR 500,000 | **not reached** (revenue levels off near EUR 450,000) | 33 | 19 |
| **EUR 979,000** | **not reached** | **49** | **26** |
| Accounts won by month 12 / 24 | 3.6 / 21 | 8.1 / 42 | 15 / 71 |
| First month with positive operating profit | 19 | 10 | 5 |
| Lowest cumulative cash (no outside funding), and the month | about -147,000 (month 24) | about -85,000 (month 13) | about -57,000 (month 8) |

Snapshots (revenue per month / active accounts / operating profit per month):
| Month | Conservative | Base | Ambitious |
|---|---|---|---|
| 6 | 1,300 / 0.3 / -6,400 | 8,500 / 1.1 / -4,900 | 38,000 / 2.7 / 6,600 |
| 12 | 14,800 / 3.3 / -5,100 | 59,900 / 7.5 / 8,300 | 203,000 / 14.5 / 68,000 |
| 24 | 74,300 / 16.5 / 5,600 | 274,700 / 34.3 / 70,500 | 865,800 / 61.8 / 324,900 |
| 36 | 163,100 / 36.2 / 23,600 | 600,000 / 75.0 / 168,200 | n/a (target reached month 26) |
| 48 | 265,500 / 59.0 / 46,400 | 972,300 / 121.5 / 284,100 | n/a |

**Reading these honestly**
- The **Conservative** path never reaches EUR 979,000 on these assumptions: acquisition capacity of 4 accounts per month and 4% churn cap the business near 100 accounts. The target needs a larger average account, lower churn, a bigger sales engine or partner leverage. That is the real strategic message.
- The **Base** path reaches the target only after about four years and needs **about 120 active accounts and about 54 delivery people**. Capacity and hiring, not demand alone, become the constraint.
- Even the Ambitious path needs about 70 active accounts and about 45 delivery people at the target.
- Early cash is negative in every case. Without outside funding or customer prepayment the first one to two years are funded by the owner. **No funding source is assumed and none is requested here.**

## 4. What EUR 979,000 per month requires, by target step
Customers = rung divided by ARPA, rounded up. Delivery people = rung divided by revenue per person.
| Monthly revenue | Customers (Cons. / Base / Amb.) | Delivery people (Cons. / Base / Amb.) |
|---|---|---|
| 10,000 | 3 / 2 / 1 | 0.7 / 0.6 / 0.5 |
| 50,000 | 12 / 7 / 4 | 3.3 / 2.8 / 2.3 |
| 100,000 | 23 / 13 / 8 | 6.7 / 5.6 / 4.5 |
| 250,000 | 56 / 32 / 18 | 16.7 / 13.9 / 11.4 |
| 500,000 | 112 / 63 / 36 | 33.3 / 27.8 / 22.7 |
| 979,000 | 218 / 123 / 70 | 65.3 / 54.4 / 44.5 |
**Larger accounts are the lever.** At EUR 25,000 per month per account (a mix of retainers and a few large programmes), the target needs about 40 accounts. That is why the first sales motion should aim at accounts that can grow, not at many small ones.

## 5. Sensitivity (Base case, month-24 revenue of EUR 274,650)
| Change | Month-24 revenue | Effect |
|---|---|---|
| ARPA 25% lower / higher | 206,000 / 343,300 | -25% / +25% |
| Monthly churn 6% / 1.5% | 230,500 / 301,600 | -16% / +10% |
| Acquisition rate 30% lower / higher | 192,300 / 357,000 | -30% / +30% |
| First win 3 months later | 209,000 | -24% |
Reading: **time to the first customer, deal size and acquisition rate dominate**. Churn matters less in the first two years than getting the first accounts early. Everything that shortens the time to the first signed contract is worth more than any admin feature.

## 6. AIHubpedia advertiser and vendor stream (not in the numbers)
- Arithmetic only: at a **hypothetical, unapproved** EUR 199 per month per placement, EUR 10,000 per month needs about 51 paying placements; at EUR 499, about 21. These need an audience that advertisers value, and **no audience has been measured** (analytics tracking is off). There is no basis to price or promise reach.
- Gate: sell placements only after at least three consecutive months of measured data and verified profiles to show, as already recommended in the Phase 6 revenue document. Until then this stream is a pilot with two or three vendors at most, priced by the owner.
- Newsletter sponsorship stays blocked until a newsletter exists with measured clicks.

## 7. Funnel arithmetic for the first customer (hypothesis ranges, to be replaced by measured rates)
| Step | Hypothesis | Needed for one signed account at those rates |
|---|---|---|
| Qualified target accounts approached with personalised outreach | reply rate 8 to 15% | about 100 accounts give 8 to 15 replies |
| Replies that become discovery calls | 40 to 60% | 4 to 8 calls |
| Calls that become qualified opportunities | 30 to 50% | 2 to 3 opportunities |
| Opportunities that become a proposal, then a win | 25% win rate | about 0.5 wins |
So roughly **150 to 250 well-chosen accounts** are needed for the first win at these rates. These rates are not known for this business; the first 50 accounts will measure them. If measured results are half as good, the time to the first customer doubles.

## 8. Cash and funding questions for the owner
- Costs before first revenue are small if delivery is subcontracted per project, but the model shows a cumulative deficit of about EUR 57,000 to 147,000 before the business funds itself, depending on the case.
- Reduce the deficit with **fixed-price sprints paid 50% upfront**, retainers billed monthly in advance, and delivery that is paid by the project, not by salary.
- Any outside capital, loan or spending needs the owner's explicit approval; none is assumed.

## 9. How the model connects to the platform
- Actual revenue, expenses, refunds and targets are recorded in the **Finance module** and shown against the EUR 979,000 ladder, labelled as recorded and not bank-verified.
- The pipeline and win rate come from the **CRM** (stage-weighted forecast is an estimate).
- Replace each assumption above with the measured value after the first 90 days. A model that is not updated from real data is decoration.

## 10. What would make this model wrong
No evidence yet exists on reply rates, win rates, prices customers accept, delivery cost, sales-cycle length, churn, capacity or the availability of delivery staff. The conservative case may still be optimistic for a company with no references. Treat the model as a stress test for decisions, not as a plan to be believed.
