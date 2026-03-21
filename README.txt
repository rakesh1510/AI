AIHubpedia — Futurepedia-level PHP directory (all pages)

Included pages:
404.php, about.php, admin.php, blog.php, categories.php, category.php, compare.php, comparisons.php, contact.php,
disclaimer.php, helpers.php, index.php, layout_top.php, layout_bottom.php, post.php, privacy.php, rate.php,
redirect.php, robots.txt, sitemap.php, submit.php, terms.php, tool.php, top.php, subscribe.php,
generate_compare_pages.php and /compare directory.

Install:
1) Upload to /var/www/html/AI (or your preferred folder)
2) Ensure /data/*.json is writable by PHP (for clicks/ratings/submissions/subscribers)
3) Open index.php

Admin:
admin.php?pass=admin123  (change in data/site.json)

Generate comparison SEO pages:
php generate_compare_pages.php 200


---
Paid listings (monetization)

1) Edit payment URLs in data/site.json:
   - payment.paypal_url  (use PayPal.me or a checkout link)
   - payment.stripe_url  (use Stripe Payment Links)

2) Submit page: submit.php
   Users can choose:
   - Free
   - $19 Priority (24h)
   - $49 Featured (7 days)
   - $99 Homepage Spotlight (14 days)

3) Admin flow:
   - Open: admin.php?pass=admin123
   - For paid submissions: click "Mark paid", then "Approve"
   - Featured tools will appear on the homepage in "Featured listings"
