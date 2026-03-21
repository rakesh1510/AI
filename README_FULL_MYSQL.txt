AIHubpedia Full MySQL Version

This package converts the main content layer from JSON to MySQL.

Moved to MySQL
- tools
- categories
- posts
- use_cases
- site_settings
- tool_clicks
- tool_votes
- submissions

How to install
1. Create a MySQL database, for example: aihub
2. Edit db.php with your DB credentials
3. Open install-full-mysql.php once in your browser
4. Open import-json-to-mysql.php once in your browser

After import
- index.php, category.php, top.php, tool.php, blog.php and use-case pages will read from MySQL first
- if DB tables are missing, the project still falls back to JSON

Important
- This package keeps your original JSON files as fallback safety
- Interactions (clicks/votes) still use tool slugs for compatibility with your current pages
- submit.php uses the submissions table

Files added
- AIS/database/full_mysql_schema.sql
- AIS/install-full-mysql.php
- AIS/import-json-to-mysql.php
- AIS/README_FULL_MYSQL.txt

Files updated
- AIS/db.php
- AIS/helpers.php
