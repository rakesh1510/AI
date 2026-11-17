# Metrics and experiments (Phase 5)

Analytics is **disabled by default** (`AIHUB_ANALYTICS`); nothing here turns it on. Metrics marked "now" are measured in the repository; "after launch" ones need real traffic and, for any visitor-level measure, a lawful basis and privacy text first.

## Measured now (reproducible)
| Metric | Value | Command |
|---|---|---|
| Tools / reviewed / editor-verified | 509 / 20 / 0 | `tests/editorial_review_test.php` |
| Search quality (28 fixture queries): precision@5, success@3, MRR | 0.679 / 0.964 / 0.912 (Phase 4.1: 0.593 / 0.893 / 0.806) | `php scripts/search_quality.php --verbose` |
| Facts that are "editor verified" | 0 of 1,527 (509 tools x 3 facts) | `php tests/editorial_review_test.php` |
| Backlog rows passing the numeric gate / blocked | 61 / 52 of 113 | `php scripts/content_backlog.php` |
| Sitemap URLs / template-only tool pages | 617 / 489 | `tests/content_backlog_test.php` |
| Search PHP time ("transcription meeting", median) | 9.6 ms (Phase 4.1: 8.4 ms; +1.2 ms, one run each, same machine, within run-to-run noise but recorded) | `php scripts/bench.php` |

Search-quality caveat: relevance is judged by the catalogue taxonomy by the team, not by users. It detects regressions and compares versions; it does not prove the ranking is what visitors want.

## Metrics after launch (definitions)
* **Search success rate**: share of searches followed by a click on a result within the session. Needs events (consent/legal first).
* **Zero-result rate** and top zero-result queries: a to-do list for synonyms and editorial work.
* **Wizard completion** (start to results) and **shortlist saves**.
* **Evidence engagement**: opens of the evidence ledger and source links (proxy for trust).
* **Newsletter**: confirmed subscribers, confirmation rate, click-through (not opens, which are unreliable), unsubscribe rate.
* **B2B**: leads by source, qualified leads, time to first response, notification failures (already visible to the admin).
* **Editorial throughput**: profiles verified per month; median age of verified facts versus the freshness rules; share of the top 50 queue verified.
* **Search Console**: indexed pages by type, impressions, clicks, queries.

## Experiments (each needs approval before running on real visitors)
| Hypothesis | Change | Success measure | Guardrail |
|---|---|---|---|
| Showing per-fact states increases clicks on source links without lowering result clicks | Ledger open versus collapsed by default | Source-link click rate | Page speed, no layout shift |
| Task verbs ignored in search improve results | Already shipped on the fixture; confirm with live zero-result and click data | Search success rate | No rise in zero results |
| Noindexing template-only listings does not reduce useful traffic | `AIHUB_THIN_TOOL_POLICY=noindex` | Impressions and clicks of the 128 retained pages versus the 489 removed | Roll back if reviewed-page traffic falls |
| A "what we could not verify" box on problem pages increases return visits | Add the box | Return rate (needs consent) | Accessibility tests |
| Newsletter signup copy variants | Two copy variants | Confirmed (not just entered) addresses | No dark patterns; pre-ticked boxes forbidden |

## Privacy constraints for all experiments
No third-party trackers, no fingerprinting, no personal data in events (existing event allow-list stays), experiments off unless consent and the privacy page cover them, results reported with sample sizes and no causal claims from small samples.
