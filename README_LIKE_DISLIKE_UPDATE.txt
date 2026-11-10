AIHubpedia like/dislike update

Included in this package:
- Like and dislike buttons on homepage tool cards
- Like and dislike buttons on category pages
- Like and dislike buttons on top tools page
- Like and dislike summary on each tool page
- Session-based vote switching (one active vote per visitor session per tool)
- Backward-compatible migration from the old vote JSON array format

Files updated:
- helpers.php
- vote-tool.php
- tool.php
- index.php
- category.php
- top.php
- assets/css/style.css

No database migration is required because votes are stored in /data/tool_votes.json.
