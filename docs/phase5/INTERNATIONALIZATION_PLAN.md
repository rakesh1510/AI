# Internationalisation plan (Phase 5, Priority 4)

English is the only live language. The architecture for German, French, Spanish and Hindi is implemented and tested, but **no translated page exists and none may be published until a qualified person has reviewed it**. Tool pricing and feature statements are exactly the content a careless machine translation corrupts.

## URL strategy
Path prefixes on the same domain: English stays at `/…`; other locales at `/{code}/…` (for example `/de/tool.php?id=chatgpt`). Reasons: shares domain authority, needs no new certificates or DNS, simple to operate on the current hosting. Alternatives (country domains, subdomains) were rejected as costly with no benefit at this size. **Routing is not wired:** `locale_split_path()` recognises a prefix only for locales marked `live`, so `/de/…` stays a 404 today.

## Rules (all enforced by `i18n.php`, tested by `tests/i18n_test.php`, 35 checks)
* A locale is routable, linked, in hreflang and in the sitemap only if it is `live` **and** the specific page has a recorded human review (reviewer name and date in `data/translations.json`).
* Each page is self-canonical in its own language; the canonical never points to another language.
* hreflang sets are reciprocal, include a self reference and `x-default` (English), and are omitted when a page has a single version.
* A translated page identical to its English source is a duplicate and must not be indexable (the validator flags it).
* No language switcher until a page has two or more publishable versions.
* `<html lang>` and `dir` come from the locale; right-to-left locales are supported by the registry.
* The sitemap and `llms.txt` list only publishable versions.

## Translation review status
`data/locales.json`: `en` live (source), `de`/`fr`/`es`/`hi` planned, review "not started". `data/translations.json`: empty. The old multilingual demo page that showed unreviewed German and Spanish text was replaced with a plain English statement.

## Rollout order (suggested, owner decision)
1. Finish the English evidence base first; translations of unverified profiles would translate uncertainty.
2. Start with static trust pages (about, methodology, disclosure), reviewed by a native speaker.
3. Then problem pages with 3+ verified tools. Do not translate the 489 template listings.
4. Hindi needs font and typography testing; German and French need legal wording (imprint, privacy) reviewed by a qualified person for each market.
5. Add per-locale newsletter lists only with a lawful consent text in that language.

## Accessibility and metadata
Each translated page needs a translated title, description, alt text, form labels and error messages, and its own accessibility test. Metadata comes from the translated record, not from English.

## Not done
No locale routing, no switcher UI, no translated copy, no per-locale sitemaps, no legal text in other languages.
