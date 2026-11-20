# Staging acceptance test script (Phases 8A to 8D)

Run only after the owner approves checkpoint A4, on **staging**, using the role accounts created from the CLI. Record Pass, Fail or Accepted exception, who ran it and when. Do not use real customer data: create clearly synthetic records (prefix "SYNTHETIC") and void or trash them afterwards. No payment, email or customer contact is involved at any step.

Placeholders: `$S` = staging base URL, `$JAR_<role>` = cookie jar of that role (created by signing in; never put passwords in commands or notes).

## A. Authentication, authorization, CSRF
| ID | Step | Expected |
|---|---|---|
| A1 | Open `$S/admin-center.php` anonymously | Only the sign-in form; no figures |
| A2 | Sign in with a wrong password; with the inactive account | Both fail; message does not reveal which part was wrong |
| A3 | Make 6 failed sign-ins in 15 minutes | Limiter blocks further attempts |
| A4 | Sign in as each role; inspect the cookie in the browser tools | `Secure`, `HttpOnly`, `SameSite=Strict`; no sensitive data in the cookie value |
| A5 | Leave a session idle past the idle limit; keep one past the maximum | Both end; the next action asks to sign in |
| A6 | Open every Control Center section as owner, sales, viewer, editor, verifier | Owner all; sales CRM and Advertising; viewer CRM; editor Universe and Tool management; verifier Universe; others show 403 "Not allowed" |
| A7 | Check the audit log after A6 | One denied row per refused section |
| A8 | POST an action with no token; with a wrong token; with another role's token; with the right token and a foreign `Origin` header | Every request returns 403 and nothing changes |
| A9 | `curl -X PUT` or `DELETE` to `/admin-center.php` | 405 |
| A10 | GET a URL that looks like an action (`?act=...`) | No data changes |
| A11 | Sign out (POST with token) and press Back | Session ended; no cached private page is shown |
| A12 | Check response headers on admin pages | no-store or private caching, `noindex` |

## B. Control Center (8A)
| ID | Step | Expected |
|---|---|---|
| B1 | Overview as owner | Tiles reflect real records; planned modules say "planned"; no invented figures |
| B2 | System health as owner only | Shows the real engine, content source and storage status |
| B3 | Open the CRM, Universe editor and legacy admin links | They open their own pages with their own permissions |
| B4 | Keyboard: skip link, tab order, focus visible | First Tab is the skip link; no keyboard trap |

## C. Analytics (8B). Tracking stays OFF
| ID | Step | Expected |
|---|---|---|
| C1 | Open Analytics as owner before the migration | Honest "storage not installed" or "no data" state |
| C2 | After the 8B migration, open Analytics with no rollup run | "Rollup not run yet", no zero shown as a result |
| C3 | Run `php scripts/adm_rollup.php --since-first` once, then again | Idempotent: the same numbers; exit code 0 |
| C4 | Run two rollups at once under `flock -n` | The second exits without running |
| C5 | Export each CSV as owner; try as editor and sales | Owner gets a safe CSV and an audit row; others 403 |
| C6 | Confirm `AIHUB_ANALYTICS` is unset and the health row says tracking not enabled | True. Any request to enable tracking is a separate approval (A9) |

## D. Tool management (8C)
| ID | Step | Expected |
|---|---|---|
| D1 | Create a SYNTHETIC tool as editor | Draft; the editor cannot publish |
| D2 | Publish as owner (complete record) | Published; the tool page loads; appears on exactly one valid category page |
| D3 | Unpublish as editor, trash as owner, restore as owner | Unpublished and trashed tools return 404 at their URL and leave the sitemap; restore returns a draft or unpublished, never published |
| D4 | Edit a classified tool and a curated tool | Name changes; category and curated fields are read-only; public page unchanged apart from the name |
| D5 | Bulk: select 3 SYNTHETIC tools, review, apply publish; include one incomplete tool | Review shows the block; the whole batch is refused; nothing changed |
| D6 | Categories: try to rename or delete a public category | Refused as protected |
| D7 | Submission: use the public form with SYNTHETIC data, then approve as owner | Draft only; paid plan blocked until marked paid; a reason is required to reject |
| D8 | Featured placement on a published SYNTHETIC tool | Mirrors into the featured fields; one active placement per tool; cancel works; public page shows the sponsored label |
| D9 | Create a tool with a slug that exists in the catalogue | Refused as reserved |
| D10 | Re-run `import-json-to-mysql.php` only if the owner approved it (D3) | Adds missing records only; managed records unchanged |

## E. Finance (8D)
| ID | Step | Expected |
|---|---|---|
| E1 | Open all eight Finance sections as owner; try each as sales, viewer, editor, verifier | Owner only; others 403 with an audit row |
| E2 | Record an expense (SYNTHETIC description) | Stored; audit row; shows in Expenses; counts in profit and loss |
| E3 | Record a recurring monthly and an annual expense | Expanded per month in the profit and loss table |
| E4 | Void an expense with a reason | Stays visible as voided; no longer counts |
| E5 | Record a refund and a credit note against a confirmed contract (create a SYNTHETIC contract in the CRM first) | Limits enforced; flows into net income and outstanding; void works |
| E6 | Set a target for the first month of monitoring, then change it | One active, one superseded; status shows recorded versus target honestly |
| E7 | Scenario calculator with typed values | Arithmetic only; labelled hypothetical |
| E8 | Download all six CSVs as owner | Safe cells, data-state and VAT columns, audited; each denied to other roles |
| E9 | Compare CRM tables before and after E2 to E6 (`CHECKSUM TABLE`) | CRM and submissions tables unchanged |
| E10 | Legacy listing payments | Shown separately; never added to CRM totals |
| E11 | Empty or missing data | "No data" or "unavailable" text; no zero shown as achievement; no invented customer or revenue anywhere |

## F. SEO and public regression
| ID | Step | Expected |
|---|---|---|
| F1 | Crawl the sitemap before and after deployment (status, title, canonical, robots, H1, JSON-LD hash for every URL) | Identical for all existing URLs |
| F2 | All 14 category pages; `?name=SEO` | 200; `SEO` redirects to `Marketing`; unknown category 404 |
| F3 | A classified tool, a curated tool, an unclassified tool | Same category, breadcrumb and schema as before |
| F4 | Search, compare, recommendation and guide pages | Unchanged behaviour |
| F5 | `robots.txt` | Disallows `/admin-center.php`; sitemap line present |
| F6 | `X-Robots-Tag` or basic auth on staging | Staging is not indexable |
| F7 | `php tests/seo_test.php $S` and `php scripts/seo_audit.php` | No new failures |

## G. Devices and accessibility
| ID | Step | Expected |
|---|---|---|
| G1 | Each module at 1280, 768 and 360 px, light and dark | No horizontal page scroll; targets at least 44 px |
| G2 | Safari on iPhone, Chrome on Android, iPad, Firefox and Chrome desktop | Layout intact; forms usable |
| G3 | Keyboard-only through a full create, edit and void flow | Every control reachable; focus visible; errors announced |
| G4 | Screen reader spot check of a chart and its data table | Title, description and table read correctly |
| G5 | Lighthouse lab run for home, tool, category and overview pages | Numbers recorded; no claim beyond them |

## H. Logs and cleanup
| ID | Step | Expected |
|---|---|---|
| H1 | Read the web server and PHP error logs for the test window | No fatal errors or warnings from the new code |
| H2 | Read the security log | No secrets or tokens |
| H3 | Trash or void every SYNTHETIC record; confirm the backup checksum is still valid | Done |
| H4 | Write the result summary: counts of Pass, Fail, Accepted exceptions, and the defect list | Presented at checkpoint A5 |
