# Release risks and owner decisions

Items that could make a release unsafe or need a decision before anything is deployed. Nothing in this phase touches production, credentials or customer data.

## Security (carry-over from Phase 1, still open)
1. **Secrets in git history**: `data/site.json` holds an SMTP app password and admin password (`admin123`); `db.php` holds a database password fallback. Rotate all, move to environment variables, and purge history if the repository is or becomes public.
2. **Public maintenance scripts**: `install-*.php`, `import-json-to-mysql.php`, `compare_*.php`, `generate_compare_pages.php` are web-reachable. Protect or remove from the web root.
3. **Write endpoints without CSRF protection or rate limiting**: `rate.php`, `vote-tool-ajax.php`, `submit-comment.php`, `toggle-bookmark.php`; `get_ip_hash()` uses a hard-coded salt; `rate.php` enables `display_errors`.
4. **Plain-text admin password** compare in `admin.php`.
5. **Data files writable by the web server** (`data/*.json`): `.htaccess` blocks reads on Apache only.
6. `.htaccess` needs `AllowOverride All`; on nginx the rules must be ported (404 page, deny `/data`, `/database`, `/scripts`, `/tests`, sitemap rewrite).

## Phase 2 additions to review
| Item | Risk | Mitigation |
|---|---|---|
| Analytics | Privacy law (GDPR/ePrivacy/CCPA) | Off by default; no cookies/IP/UA stored; DNT/GPC honoured; get legal sign-off and mention it in the privacy policy before enabling |
| Reclassification | URLs/categories changed for ~440 tools; `category.php?name=SEO` now 301s | Staging review; monitor Search Console coverage for 4 weeks; rollback = empty `data/classification.json` |
| Medium-confidence classifications | ~8% wrong on the small sample | Flagged `needs_review`; tool pages state "assigned automatically"; review queue in `audit_catalog.php` |
| 166 tools in "Other" | Poor navigation, thin pages | `noindex` for the bucket; improve descriptions/editor review |
| Reviewed profiles | Facts from vendor search snippets, not verified by a person; conflicting vendor pages | Labelled "pending editor verification"; a person must run `review_profile.php` after checking live pages. **Do not market profiles as verified until then** |
| Pricing statements | Prices change; wrong prices damage trust | "As of" dates, conflicts shown, 90-day stale audit |
| Thin tool pages | 489 template pages may trigger quality demotion | Honest labelling now; policy switch `AIHUB_THIN_TOOL_POLICY=noindex` when data supports it |
| Outbound links in sources | `rel="nofollow noopener"` already | — |
| `llms.txt` | Not used by Google; harmless | Keep or remove; no ranking claim is made |
| Training-crawler policy | Content may be used for model training | Owner decision (see AI_SEARCH_READINESS.md) |

## Legal / brand
- The Impressum names an individual owner at a German address; **TrustNova Tech is not mentioned anywhere on the site**. If TrustNova Tech is the operator, the legal notice (and privacy policy controller) must be updated; German law requires accurate provider identification. Not changed here.
- Guides and profiles use vendor names; disclaimer states non-affiliation. Keep trademark use factual.

## Not verified in this phase
- Live behaviour of vendor pages (profiles built from search results).
- Competitor traffic/keywords/backlinks (no licensed data).
- `scripts/check_websites.php` and `scripts/indexnow.php --apply` against the real internet (sandbox has no outbound access; logic is unit-tested).
- Production performance and capacity.
- Any ranking, citation or traffic outcome.
