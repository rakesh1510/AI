# AI Universe real batch, wave 0 (PROPOSED leads, not verified)

Collected from public APIs on the day shown in `import.json` (`retrieved_at`): Wikidata (CC0) and the Hugging Face Hub API. Nothing here is verified. Nothing was written to any database
except scratch test databases. Every claim carries a quote that was mechanically confirmed to be a verbatim substring of the stored capture (`captures.tar.gz`, with SHA-256 in each source).

| Content | Count |
|---|---|
| Organisations (company entities, resolved on Wikidata with exact label + organisation-like description) | 24 across US, Canada, France, Germany, UK, China, South Korea, India, UAE, Israel |
| Models (top 3 by downloads per organisation namespace, plus base models of documented fine-tunes) | 80 |
| Events ("Model repository created on the Hugging Face Hub" with the API `createdAt` date; not an announcement date) | 69 |
| Relationships: `developed_by` (Hub namespace -> company) 80, `derived_from` (explicit `base_model:finetune` tag) 13 | 93 |
| Sources | 266 |
Skipped, not guessed: 8 organisations without an exact Wikidata label match (see `run_report.json`). Not covered yet: releases, pricing/feature/benchmark facts, most regions' official sites.

Files: `import.json` (importer format), `review_batch.csv` (one line per record: claim, source URL, quote, what to check), `run_report.json`, `directory_links.json` (AIHubpedia directory tools whose
website matches a company's official site; leads only), `directory_leads.csv` (other directory tools as future discovery leads), `captures.tar.gz` (evidence).

## What reviewers must know
* The company-to-Hub-namespace mapping (e.g. namespace `Qwen` -> Alibaba Group) comes from AIHubpedia's seed list, not from a source. The verifier must confirm each `developed_by` edge (official site or organisation page).
* The event date is the Hub repository creation date; it may differ from the public announcement.
* Wikidata descriptions are short and copied verbatim; the website and country in a company summary are Wikidata statements.
* Review order: entities first, then events and edges (an event or edge cannot be verified before its entity).
* Effort estimate: about 266 records x 2 to 4 minutes = 9 to 18 hours for the first verifier, plus the 20% second-review sample.

## Exact staging procedure (run by YOUR administrator on the staging VPS only; I have not touched it)
Directory `/var/www/html/AI-PHASE3-TEST`, database `aihub_phase6_test`. Phase 6 and Phase 7 schemas are already installed; no new migration is needed for this batch.
1. Update the staging checkout to the approved commit of `feat/aihubpedia-ai-universe-phase7` (git fetch, checkout that commit). Do not touch `/var/www/html/AI` (production).
2. Create real people: one `editor` (the person adopting leads) and at least two different `verifier` users with `php scripts/crm_user.php` (CLI; see its usage). The editor must not be a verifier.
3. Dry run (read-only): `php scripts/uni_import.php --file=data/uni_batch_wave0/import.json --user=EDITOR --json` -> expect `would_create: 266`, `errors: 0`.
4. Apply: same command with `--apply`. The records are now `proposed`, origin `import`, private (the public pages still say "No verified data yet").
5. The editor reads `review_batch.csv`, then adopts: `php scripts/uni_adopt.php --user=EDITOR` (dry run), then with `--apply`. This creates human-origin versions in `in_review`.
6. Verifiers (two different people, not the editor) log in to `/universe-editor.php`, work through the review queue entities-first: open the source URL, compare the quote, confirm the claim and its caveats above, accept or reject. The first 25 accepted items and a 20% sample need a second verifier.
7. Check `https://staging.aihubpedia.com/universe.php`: counts and pages appear only for verified records.
Rollback: nothing is deleted by these steps; rows you do not want published stay `proposed`/`in_review` or are rejected by a verifier; verified rows are immutable (corrections are new versions).
