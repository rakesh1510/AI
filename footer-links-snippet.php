<?php
/**
 * footer-links-snippet.php
 * Paste the HTML block below into your layout_bottom.php footer.
 * The PHP comment at the top explains where to place it.
 *
 * Placement: inside your <footer> element, at the bottom of layout_bottom.php.
 */
?>

<!-- ============================================================
     PHASE 1 FOOTER LINKS — paste into layout_bottom.php footer
     ============================================================ -->
<div class="footer-trust-links" style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border,#e5e7eb)">
  <nav aria-label="Legal & editorial">
    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:.5rem 1.25rem">
      <li><a class="small" href="affiliate-disclosure.php">Affiliate Disclosure</a></li>
      <li><a class="small" href="editorial-policy.php">Editorial Policy</a></li>
      <li><a class="small" href="partner.php">Partner With Us</a></li>
      <li><a class="small" href="privacy.php">Privacy Policy</a></li>
      <li><a class="small" href="terms.php">Terms</a></li>
      <li><a class="small" href="contact.php">Contact</a></li>
    </ul>
  </nav>
  <p class="footer-trust-note" style="margin-top:.75rem">
    © <?= date('Y') ?> AIHubpedia — operated by <strong>TrustNova Tech</strong>, Germany.
    An independent AI tool directory. Affiliate links are disclosed.
  </p>
</div>
<!-- ============================================================ -->
