# AIHubpedia Blog — SEO Strategy & Publishing Plan

## Architecture Analysis (What Was There)

The original system was functional but bare:

| File | Problem |
|---|---|
| `blog.php` | Single flat list, no categories, no search, no featured post, no SEO tags |
| `post.php` | No OG tags, no schema, no internal links, no related tools, no reading time |
| Data layer | Posts have `slug`, `title`, `date`/`published_at`, `excerpt`, `content` — solid foundation, just underused |

The data layer (MySQL with JSON fallback) is well-built. The gap was entirely in the presentation and SEO layers.

---

## What Was Implemented

### `blog-seo.php` (new shared library)

One file included by both `blog.php` and `post.php`. Contains:

- **`blog_post_type()`** — classifies each post as `review | comparison | best_list | howto | use_case | guide | general` purely from slug + title. No DB column needed.
- **`blog_post_topic()`** — detects topic (SEO, Writing, Coding, etc.) from slug + title + excerpt.
- **`blog_seo_title()`** — generates `[Post Title] — In-Depth Review 2026 | AIHubpedia` style titles automatically.
- **`blog_seo_description()`** — uses `seo_description` field → `excerpt` → stripped content (155 chars).
- **`blog_emit_head_seo()`** — emits OG tags + Article schema + Breadcrumb schema + FAQ schema (if `faqs` array present in post data).
- **`blog_inject_internal_links()`** — scans post HTML, wraps first occurrence of every known tool name in a `<a href="tool.php?id=…">` link. Also links category phrases like "AI writing tools" to category pages. Zero false positives: skips existing `<a>` tags.
- **`blog_related_tools()`** — scores tools by name mention frequency in content + topic match + click count. Returns top 4.
- **`blog_filter_posts()`** — search + category filter.
- **`blog_all_categories()`** — derives categories dynamically from post set.
- **`blog_render_homepage_widget()`** — drop `<?php blog_render_homepage_widget(3); ?>` anywhere in `index.php` to show 3 latest posts.
- Render helpers: `blog_render_featured_card()`, `blog_render_post_card()`, `blog_render_breadcrumb()`, `blog_render_meta_bar()`, `blog_render_compare_cta()`, `blog_render_related_tools()`.

### `blog.php` (rebuilt)

- Hero section with search bar + category dropdown
- Category pills (auto-generated from post set, no manual maintenance)
- **Featured Article** — uses `featured: true` field on post, or falls back to newest post
- **Latest Articles** — 6 newest posts excluding featured
- **Popular Articles** — ranked by word count proxy (upgrade to view count once available)
- Search/filter results mode replaces the homepage layout when `?q=` or `?cat=` is set
- Breadcrumb + OG + JSON-LD schema in head
- Content type quick links (Reviews / Comparisons / Best Lists / How-To / etc.)
- Bottom CTA linking to tool directory and compare

### `post.php` (rebuilt)

- Full `blog_emit_head_seo()` in head: Article schema, Breadcrumb schema, FAQ schema (if data present), OG tags, Twitter card, canonical URL
- Breadcrumb nav
- Meta bar with post type badge, author, date, reading time
- Excerpt shown as a pull-quote under the title
- Post body in `.postBody` class (styled: h2/h3 spacing, tables, blockquotes, code)
- **Automatic internal links** — every tool name in content becomes a clickable link
- **Compare CTA** — shown on reviews and comparisons, auto-derives compare URL from slug
- **Related tools** panel (4 tools matched to post content)
- **Related posts** panel (same topic first, then recent)
- Bottom directory CTA

---

## How to Add the Homepage Widget

In `index.php`, add this one line wherever you want the blog section to appear (recommended: before the Partner section at the bottom):

```php
<?php
require_once __DIR__ . '/blog-seo.php';
blog_render_homepage_widget(3);
?>
```

---

## How to Add FAQ Schema to a Post

If your posts table has a `faqs` JSON column, populate it like this:

```json
[
  {"q": "Is B12 good for small businesses?", "a": "Yes. B12 is designed for …"},
  {"q": "How much does B12 cost?", "a": "B12 offers plans starting from …"}
]
```

The schema is emitted automatically by `blog_emit_head_seo()`.

---

## Optional: Add `seo_title` and `seo_description` Columns

If you want per-post SEO control without code changes:

```sql
ALTER TABLE posts ADD COLUMN seo_title       VARCHAR(120) NULL AFTER title;
ALTER TABLE posts ADD COLUMN seo_description VARCHAR(320) NULL AFTER seo_title;
ALTER TABLE posts ADD COLUMN featured        TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE posts ADD COLUMN faqs            JSON NULL AFTER content;
ALTER TABLE posts ADD COLUMN updated_at      DATETIME NULL AFTER published_at;
ALTER TABLE posts ADD COLUMN image           VARCHAR(512) NULL AFTER excerpt;
```

All fields are optional. The system works perfectly without them.

---

## Content Strategy: Priority Post Types

### Tier 1 — Highest SEO value (publish first)

These target high-intent, high-volume queries where AIHubpedia can rank fast because it already has the tool data.

| Format | Example Title | Target Keyword |
|---|---|---|
| Best List | Best AI Website Builders in 2026 | best ai website builder |
| Best List | Best AI Coding Tools in 2026 | best ai coding tools |
| Best List | Best AI SEO Tools in 2026 | best ai seo tools |
| Best List | Best Free AI Tools in 2026 | free ai tools |
| Best List | Best AI Chatbots in 2026 | best ai chatbots |
| Best List | Best AI Writing Tools in 2026 | best ai writing tools |
| Comparison | B12 vs Wix AI (2026) | b12 vs wix |
| Comparison | ChatGPT vs Claude (2026) | chatgpt vs claude |
| Comparison | Claude vs Gemini (2026) | claude vs gemini |
| Comparison | Jasper vs Copy.ai (2026) | jasper vs copy.ai |
| Review | B12 Review 2026 | b12 review |
| Review | Midjourney Review 2026 | midjourney review |
| Review | GitHub Copilot Review 2026 | github copilot review |

### Tier 2 — How-To and Use Case (medium term)

These build topical authority and capture mid-funnel readers.

| Format | Example Title |
|---|---|
| How-To | How to Build a Website with AI in 2026 |
| How-To | How to Use AI for SEO Content Writing |
| How-To | How to Automate Your Workflow with AI Tools |
| Use Case | Best AI Tools for Freelancers |
| Use Case | Best AI Tools for Small Business |
| Use Case | Best AI Tools for Marketing Teams |

### Tier 3 — Long-tail guides (ongoing)

| Example Title |
|---|
| What is an AI Website Builder? Complete Guide |
| Free vs Paid AI Writing Tools: Which Should You Use? |
| AI Tools for Students: 10 Apps That Actually Help |

---

## Publishing Schedule (Quality Over Quantity)

**Recommended: 2 posts per week**

| Week | Post 1 (Tuesday) | Post 2 (Friday) |
|---|---|---|
| 1 | Best AI Website Builders 2026 | B12 Review 2026 |
| 2 | B12 vs Wix AI | Best Free AI Tools 2026 |
| 3 | Best AI Writing Tools 2026 | Jasper vs Copy.ai |
| 4 | How to Build a Website with AI | Best AI Chatbots 2026 |
| 5 | Best AI Coding Tools 2026 | GitHub Copilot Review |
| 6 | ChatGPT vs Claude | Best AI SEO Tools 2026 |
| 7 | Claude vs Gemini | Best AI Tools for Freelancers |
| 8 | Midjourney Review | How to Use AI for SEO |

**After 8 weeks:** refresh Week 1 posts (update date, add new tools, re-publish) — Google rewards freshness on "Best X" lists.

---

## Internal Linking Rules

The `blog_inject_internal_links()` function handles tool-name linking automatically. Beyond that, follow these manual rules when writing:

1. Every "Best X Tools" post links to the relevant category page: `/?cat=Writing`
2. Every review links to the tool's page: `tool.php?id=b12`
3. Every comparison links to: `compare.php?a=b12&b=wix-ai`
4. Every post links back to blog.php in the breadcrumb (auto-generated)
5. Each post should have 2–4 "See also" links to related posts

---

## Affiliate Conversion Strategy

Place CTA blocks in this order inside long-form posts:

1. **Top of post** — "Try [Tool] Free →" (affiliate link, after intro)
2. **Middle of post** — comparison table with affiliate CTAs per tool
3. **Bottom of post** — "Our Verdict" section with primary CTA button
4. **Sidebar / Related Tools** — auto-rendered by `blog_render_related_tools()` using `tool_open_url()` which already picks the affiliate link first

For comparison posts, always include a "Who should use X vs Y" table — these convert at 2–3x the rate of pure informational posts.

---

## SEO Technical Checklist

All items are now handled automatically by the new code:

- [x] Unique `<title>` per post with year + site name
- [x] Meta description (155 chars)
- [x] Canonical URL
- [x] OG title / description / type / url
- [x] Twitter card
- [x] Article JSON-LD schema
- [x] BreadcrumbList JSON-LD schema
- [x] FAQPage JSON-LD schema (when `faqs` data present)
- [x] Internal links to tool pages (auto-injected)
- [x] Internal links to category pages (auto-injected)
- [x] Related tools section on every post
- [x] Breadcrumb navigation
- [x] Reading time meta
- [x] `noindex` not set on published posts
- [ ] Sitemap — add post URLs to your sitemap generator
- [ ] Image alt text — add `image` field to posts for OG image
- [ ] Core Web Vitals — ensure images use `loading="lazy"` and have explicit `width`/`height`

---

## Measuring Success

Track these monthly:

| Metric | Tool | Target (6 months) |
|---|---|---|
| Organic clicks | Google Search Console | 500+/month |
| Average position | GSC | Top 20 for target keywords |
| Blog → tool page CTR | GA4 / server logs | 15%+ |
| Affiliate click-through | Your click tracker | 5%+ of blog visitors |
| Pages indexed | GSC Coverage | 100% of published posts |
| Backlinks to blog | Ahrefs / GSC | 10+ referring domains |
