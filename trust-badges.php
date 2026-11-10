<?php
/**
 * trust-badges.php
 * Include this file anywhere with: require_once __DIR__ . '/trust-badges.php';
 *
 * Functions:
 *   trust_badge(string $type): string  — returns HTML for a single badge
 *   trust_badge_row(array $types): string — returns a div.trust-badge-row with multiple badges
 *   reviewed_by_block(string $date = ''): string — "Reviewed by AIHubpedia Editorial Team" block
 */

function trust_badge(string $type): string {
    $badges = [
        'verified'  => ['✓ Verified Listing',         'trust-badge--verified'],
        'live'      => ['📡 Live Data Checked',        'trust-badge--live'],
        'editorial' => ['✍ Editor Reviewed',           'trust-badge--editorial'],
        'affiliate' => ['🤝 Affiliate Partner',        'trust-badge--affiliate'],
        'sponsored' => ['⭐ Sponsored',                'trust-badge--sponsored'],
        'featured'  => ['🏆 Featured Tool',            'trust-badge--featured'],
    ];
    if (!isset($badges[$type])) return '';
    [$label, $cls] = $badges[$type];
    return '<span class="trust-badge ' . htmlspecialchars($cls, ENT_QUOTES) . '">'
         . htmlspecialchars($label, ENT_QUOTES)
         . '</span>';
}

function trust_badge_row(array $types): string {
    $html = '<div class="trust-badge-row">';
    foreach ($types as $t) {
        $html .= trust_badge($t);
    }
    $html .= '</div>';
    return $html;
}

/**
 * "Reviewed by AIHubpedia Editorial Team · Last Updated: …" block.
 *
 * @param string $date  Pass a date string, or leave empty to use today's date.
 */
function reviewed_by_block(string $date = ''): string {
    $displayDate = $date !== ''
        ? htmlspecialchars($date, ENT_QUOTES)
        : date('j F Y');
    return '<p class="reviewed-by-block small">'
         . 'Reviewed by <strong>AIHubpedia Editorial Team</strong>'
         . ' &nbsp;·&nbsp; Last Updated: <strong>' . $displayDate . '</strong>'
         . '</p>';
}
