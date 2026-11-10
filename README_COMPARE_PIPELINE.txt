AIHubpedia Compare Pipeline (new tables only)
=============================================

This compare pipeline does not change the existing tools table or any current content table.
It adds new compare-specific tables and makes compare.php read from them when data exists.

New files:
- database/install_compare_tables.sql
- compare_install.php
- compare_sync_catalog.php
- compare_fetch_source.php
- compare_enrich_tool.php
- compare_refresh_all.php

Setup
-----
1) Create the new compare tables:
   php compare_install.php

2) Copy current tools into compare_tool_profiles without touching old tables:
   php compare_sync_catalog.php

3) Fetch at least one official source page for a tool:
   php compare_fetch_source.php beam

4) Enrich saved source text with the API:
   OPENAI_API_KEY=... OPENAI_MODEL=... php compare_enrich_tool.php beam

5) Open compare.php?a=tool-a&b=tool-b

Notes
-----
- compare.php now prefers compare tables.
- If a tool has no compare profile yet, compare.php falls back to basic facts only.
- Repeated generic pros/cons from old JSON are no longer required for compare.
- You can fetch more than one source page by passing a URL and source type:
  php compare_fetch_source.php beam https://beam.ai/pricing pricing

Recommended flow
----------------
- Run compare_sync_catalog.php once after adding new tools.
- For each tool, fetch homepage + pricing + docs/integrations pages.
- Run compare_enrich_tool.php after fetching sources.
- Re-run enrichment whenever the official site changes.
