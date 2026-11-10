<?php
/**
 * org-schema.php
 * Outputs Organization schema.org JSON-LD for TrustNova Tech / AIHubpedia.
 * Include inside <head> in layout_top.php:
 *   <?php require __DIR__ . '/org-schema.php'; ?>
 */
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "AIHubpedia",
  "legalName": "TrustNova Tech",
  "url": "https://aihubpedia.com",
  "logo": "https://aihubpedia.com/assets/logo.png",
  "description": "AIHubpedia is an independent AI tool directory operated by TrustNova Tech, Germany. We help users discover, compare, and choose the right AI tools.",
  "foundingDate": "2024",
  "address": {
    "@type": "PostalAddress",
    "addressCountry": "DE"
  },
  "contactPoint": {
    "@type": "ContactPoint",
    "contactType": "editorial",
    "email": "editorial@aihubpedia.com"
  },
  "sameAs": [
    "https://twitter.com/aihubpedia",
    "https://linkedin.com/company/aihubpedia"
  ]
}
</script>
