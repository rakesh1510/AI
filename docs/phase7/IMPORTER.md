# Phase 7 candidate importer (`scripts/uni_import.php`)

Status: tested by `tests/phase7_import_test.php` (235 checks) against a local **MariaDB 10.11.14**, in no-trigger and trigger mode and under a least-privilege
database account. MySQL 8.0 / 8.4 were **not** run locally (CI runs the Phase 7 tests on them; treat them as unverified until that job is green).

## What it is, and what it is not

A command-line tool that turns a reviewed JSON (or CSV) file of *leads* into **proposed** rows of the provenance ledger. Every row it creates has
`origin = 'import'` and `state = 'proposed'`. It never creates a verified row, never names a verifier, never opens a URL, never merges, overwrites or deletes.
Verification stays a human act: an import row can only be superseded by a NEW version (origin `human` or `ai_assisted`, created with `uni_correct`) that a
*different* verifier accepts (`uni_core.php` and the CHECK `chk_*_import_not_verified`).

## Running it

```
php scripts/uni_import.php --file=PATH --user=EDITOR_USERNAME            # DRY RUN (the default): validate, look up duplicates, report. READ ONLY session.
php scripts/uni_import.php --file=PATH --user=EDITOR_USERNAME --apply    # write
```

| option | meaning |
|---|---|
| `--file=PATH` | required. `.json` or `.csv`; the extension must match `--format`. Regular file only: `..` segments, symlinks, directories, devices are refused. Maximum 2 MiB. |
| `--user=NAME` | required, no default. A real, **active** `crm_users` row with role **`editor`** (not owner, verifier, sales, viewer). It becomes `proposed_by` / `created_by` and the audit actor. Create it with `scripts/crm_user.php add NAME "Display" editor` (password on stdin). |
| `--apply` | write. Without it nothing is written. `--apply` together with `--dry-run` is refused. |
| `--format=json\|csv`, `--kind=entities\|events\|edges` | CSV carries one record kind per file (`--kind` required for CSV, refused for JSON). |
| `--batch-size=N` | commit every N records (default 0: the whole file is one transaction). Earlier committed batches stay when a later one fails; re-running is safe (idempotent). |
| `--base-dir=DIR` | the file must resolve inside this directory. |
| `--json` | machine-readable report. |

Exit codes: `0` ok (skips are not errors), `1` input or database error (the failing transaction is rolled back), `2` refused request (bad options, wrong user, another import running).
The web server never runs it: the script exits with 404 unless `PHP_SAPI === 'cli'`, and it is registered as kind `cli` in `tests/security_manifest.json`.

Database connection: the usual `AIHUB_DB_HOST/NAME/USER/PASS` settings. To run under a dedicated least-privilege account set `AIHUB_IMPORT_DB_USER` and
`AIHUB_IMPORT_DB_PASS` (they override the application account for this process only). See `SECURITY_AND_PRIVILEGES.md` for the exact grants.

## Input format (`aihubpedia-import-v1`)

Strict JSON (RFC 8259, UTF-8). Unknown fields, duplicate object keys, depth over 6, strings over 8000 bytes, NUL / control characters, bidi override characters and lone
surrogates are errors. Strings must be strings (no numbers or booleans where text is expected). Nothing is silently truncated or rewritten.

```json
{
  "format": "aihubpedia-import-v1",
  "synthetic": true,
  "sources":  [ {"id": "s1", "url": "https://...", "publisher": "...", "retrieved_at": "2025-01-15 09:30:00", "quote": "non-empty verbatim text", "content_hash": "<64 hex, optional>"} ],
  "entities": [ {"key": "optional", "entity_type": "company|product|model|version", "slug": "lower-case-a-z0-9._-", "name": "...", "summary": "optional", "sources": ["s1"]} ],
  "events":   [ {"key": "optional", "entity": "<slug>", "event_type": "...", "event_date": "YYYY[-MM[-DD]]", "date_precision": "optional", "date_kind": "announced|ga|preview", "title": "...", "summary": "optional", "sources": ["s1"]} ],
  "edges":    [ {"key": "optional", "from": "<slug>", "to": "<slug>", "edge_type": "...", "valid_from": "optional", "valid_to": "optional", "notes": "optional", "sources": ["s1"]} ]
}
```

* **Sources are mandatory.** Every entity, event and edge lists 1 to 10 source ids; each source needs an `http(s)` URL (no credentials, dotted host, no `javascript:` or `data:`),
  a publisher, a `retrieved_at` date (not in the future, not before 1990) and a non-blank quote (zero-width and whitespace-only quotes do not count).
  Entities need a source too, because an entity without evidence cannot later be submitted for review.
* Event and edge `entity` / `from` / `to` are entity **slugs**: an entity of the same file or an existing current entity. An unknown slug fails the run.
* Limits: 2 MiB file; 2000 sources, 2000 entities, 5000 events, 5000 edges. Field limits are the service layer's (slug 120, name 190, summary 500 (events 1000), title 255, notes 500, URL 2048, publisher 190, quote 1000).
* Fields that carry state (`state`, `origin`, `verified_by`, `review_id`, ...) are **unknown fields** and are rejected. The importer writes them itself, as constants.
* Not supported: releases and release parents (they need a verified-quality source id and parent ordering; propose them in the editor UI).

CSV: one header row, one record kind per file, a single inline source per row (columns `source_url`, `source_publisher`, `source_retrieved_at`, `source_quote`, optional `source_content_hash`),
RFC 4180 quoting. Blank lines, stray quotes, unknown or duplicate columns and wrong column counts are errors with a line number. Values are stored as text and never interpreted
(a leading `=` is not a formula here; keep it that way if the data is later exported to a spreadsheet). Examples: `tests/fixtures/uni_import_synthetic.json` and
`tests/fixtures/uni_import_synthetic_events.csv`, both **synthetic**: invented companies, no real claims. Never import the fixtures into a real ledger.

## Validation, reports and error numbers

Validation runs completely before the database is touched and reports up to 50 problems at once as `entities[3] line 41 field slug: slug_invalid`. For JSON the line is the line
the record starts on, for CSV the physical row. The report names the record, the field and a stable code, **never the offending value** (nothing hostile is echoed to the terminal)
and never personal data beyond record keys/slugs and titles already in the file. Output is limited to printable ASCII.

Dry run and apply run the same checks and the same duplicate lookups, so a clean dry run predicts the apply (unless the data changes in between).

## Idempotency and duplicates

* Every record has a **stable key**: `entity:<slug>`, `event:<entity>|<date>|<normalised title>`, `edge:<from>|<type>|<to>`, or `<kind>:<key>` when the record carries an explicit `"key"`.
  After a record is created the service writes an audit row `import_record` whose summary is `import:` plus the SHA-256 of that key. A re-run finds the key and reports
  `skipped_already_imported`: **nothing is created, and nothing is updated even if the content in the file changed** (also after a human has verified a corrected version).
  An explicit `key` keeps a record idempotent across renames of its slug or title.
* A record whose key is new but which collides with existing data is a **duplicate**: an entity whose slug is already a current row, an event with the same entity lineage, date and normalised title, an edge that already exists. It is reported
  `skipped_duplicate` with the reason; the existing row is never touched. An entity with a different slug but the same type and normalised name is created and **flagged**
  (`duplicate_flag` audit row, `flagged` counter). A same-title event on another date is created and recorded as a conflict candidate (`conflict_recorded`).
  Nothing is merged automatically.
* Within one file, duplicate slugs, duplicate event/edge identities and duplicate keys are validation errors.

## Transactions and failure

The whole file is one transaction (or one per `--batch-size`). Any service refusal (`entity_not_found`, `slug_taken` raced by another writer, a database rule) rolls the open transaction back and the
report lists the affected rows as `rolled_back`. `GET_LOCK('aihub_uni_import')` keeps two importers from running at once (exit 2). A dry run uses `SET TRANSACTION READ ONLY`: the database itself refuses writes.
A successful apply that created anything adds one `import_run` audit row with the file SHA-256 and the counts.

## What the importer does through the service layer, and where it does not

`uni_actor`, `uni_source_create`, `uni_propose_entity`, `uni_propose_event`, `uni_propose_edge` and `uni_audit` (all in `uni_core.php`). The one deliberate difference: instead of
`uni_attach_source` the importer inserts the source link itself (`imp_link_source`) and writes the same `source_attach` audit row, because `uni_attach_source` locks the target with
`SELECT ... FOR UPDATE`, which needs an UPDATE privilege, and the row was inserted by the same transaction a moment before. This is what lets the importer run without any UPDATE right.

## Security properties (each has a test)

CLI only; named active editor required; dry run default; no network code (static check) and URLs are only stored; safe path handling (no `..`, regular file only, symlinks refused, `lstat`/`fstat` identity check
against a swap after the check, optional base dir); size/count/depth/length limits; strict UTF-8; prepared statements only (SQL-injection-shaped text is stored literally, never executed); no
secrets in output or logs (database failures print only a generic code; the log line carries the exception class and error number, never the message); rows can never be verified; idempotent;
duplicates never merged; transactional with a clear report.

## Known limits

* Free text is stored as text. Output escaping is the display layer's job (the public pages escape). The importer rejects control and bidi characters but does not strip HTML.
* A leading `javascript:` is refused by the URL check, but URLs are otherwise not resolved: the importer cannot know whether a link is live or says what the quote claims. That is the human verifier's job.
* The service layer's own error log (`uni_tx`) records the first 160 characters of an unexpected database exception message; those can contain a rejected value. Keep the security log (`AIHUB_SECURITY_LOG`) access-restricted.
* The name-based duplicate flag compares against at most 100000 current entities.
* Two importers on different hosts share the MySQL advisory lock only if they use the same database server.
