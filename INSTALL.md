# Phase 1 – Trust & Authority: Installation Guide

## Files in this package

| File | Purpose |
|------|---------|
| `affiliate-disclosure.php` | Full affiliate disclosure page |
| `editorial-policy.php` | Full editorial policy page |
| `partner.php` | Partner With Us page (with enquiry form) |
| `trust-badges.php` | Badge helper functions — include everywhere |
| `trust-badges.css` | All badge + trust strip styles |
| `homepage-trust-section.php` | Drop-in trust strip for index.php |
| `org-schema.php` | Organization schema.org JSON-LD for TrustNova Tech |
| `footer-links-snippet.php` | Footer nav snippet (paste into layout_bottom.php) |

---

## Step 1 — Copy files to your site root

Upload all `.php` and `.css` files into the **same folder as your other PHP files** (where `helpers.php`, `layout_top.php`, etc. live).

---

## Step 2 — Add the CSS to your `<head>`

In `layout_top.php`, add inside `<head>`:

```html
<link rel="stylesheet" href="trust-badges.css">
```

---

## Step 3 — Add the Organization schema to `<head>`

In `layout_top.php`, inside `<head>` (after the CSS line):

```php
<?php require __DIR__ . '/org-schema.php'; ?>
```

---

## Step 4 — Add the trust-badges helper to `helpers.php`

At the bottom of `helpers.php`, add:

```php
require_once __DIR__ . '/trust-badges.php';
```

This makes `trust_badge()`, `trust_badge_row()`, and `reviewed_by_block()` available everywhere.

---

## Step 5 — Add the homepage trust strip to `index.php`

Find a good spot on your homepage (e.g. below the hero or tool grid) and add:

```php
<?php require __DIR__ . '/homepage-trust-section.php'; ?>
```

---

## Step 6 — Add footer links to `layout_bottom.php`

Open `footer-links-snippet.php`, copy the HTML block, and paste it inside your `<footer>` in `layout_bottom.php`.

Or add a single require at that location:

```php
<?php require __DIR__ . '/footer-links-snippet.php'; ?>
```

---

## Step 7 — Add trust badges to tool listing cards

Wherever a tool card is rendered (e.g. in a loop in `index.php` or `tool.php`), use:

```php
<?php
// Show badges based on tool data
$badges = ['verified'];
if (!empty($tool['signup_verified'])) $badges[] = 'editorial';
if (!empty($tool['affiliate']))       $badges[] = 'affiliate';
if (!empty($tool['featured_level']))  $badges[] = 'featured';
echo trust_badge_row($badges);
?>
```

---

## Step 8 — Add "Reviewed by" block to review/tool pages

In `tool.php`, near the top of the content area:

```php
<?php echo reviewed_by_block($tool['updated_at'] ?? $tool['added'] ?? ''); ?>
```

---

## Step 9 — Add badges to `compare.php`

`compare.php` already outputs Live Data Checked / data-source chips. To also show the full badge row on comparison cards, add inside the card loop (after the `<h2>` tool name):

```php
<?php
$cmpBadges = ['verified'];
if ($bundle['live_ok'])   $cmpBadges[] = 'live';
if ($bundle['real_data']) $cmpBadges[] = 'editorial';
echo trust_badge_row($cmpBadges);
?>
```

---

## Step 10 — Handle the partner form (optional)

The `partner.php` form posts to `submit-partner.php`. Create that file to handle the submission — at minimum:

```php
<?php
// submit-partner.php — simple email handler
require_once __DIR__ . '/helpers.php';
$name    = strip_tags(trim($_POST['name']    ?? ''));
$company = strip_tags(trim($_POST['company'] ?? ''));
$email   = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$message = strip_tags(trim($_POST['message'] ?? ''));
$type    = strip_tags(trim($_POST['type']    ?? ''));

if ($email && $name) {
    $subject = "New partnership enquiry from {$name} ({$company})";
    $body    = "Name: {$name}\nCompany: {$company}\nEmail: {$email}\nType: {$type}\n\n{$message}";
    mail('partners@aihubpedia.com', $subject, $body, "From: noreply@aihubpedia.com");
}
header('Location: partner.php?sent=1');
exit;
```

---

## Badge reference

| Type key | Badge label | When to use |
|----------|-------------|-------------|
| `verified` | ✓ Verified Listing | Tool manually checked by editor |
| `live` | 📡 Live Data Checked | `live_ok === true` in compare bundle |
| `editorial` | ✍ Editor Reviewed | `signup_verified` or manually reviewed |
| `affiliate` | 🤝 Affiliate Partner | Tool has an affiliate link |
| `sponsored` | ⭐ Sponsored | Paid placement |
| `featured` | 🏆 Featured Tool | `featured_level > 0` |

---

## What's next — Phase 2

Phase 2 builds dedicated review pages for ChatGPT, Claude, Gemini, and 17 other major AI tools, each with overview, features, pros/cons, pricing, alternatives, and a verdict section.
