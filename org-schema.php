<?php
// Only identity facts visible on the site; no unverified profiles or founding date.
?>
<script type="application/ld+json"><?= seo_json_ld([
    '@context' => 'https://schema.org', '@type' => 'Organization',
    '@id' => SEO_ORIGIN . '/#organization', 'name' => 'AIHubpedia',
    'url' => SEO_ORIGIN . '/', 'logo' => SEO_ORIGIN . '/logo.png',
    'parentOrganization' => ['@type' => 'Organization', 'name' => 'TrustNova Tech']
]) ?></script>
