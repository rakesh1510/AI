</main>

<footer class="footer simpleFooter">
<?php // Footer links are rendered below. ?>
  <div class="wrap simpleFooterInner">
    <div class="simpleFooterGrid">

      <div class="footerCol">
        <h3>DISCOVER</h3>
        <a href="categories.php">All categories</a>
        <a href="top.php">Top AI tools</a>
        <a href="compare.php">Compare tools</a>
        <a href="leaderboard.php">Leaderboard</a>
      </div>

      <div class="footerCol">
        <h3>USE CASES</h3>
        <a href="ai-use-case.php?slug=write-blog-posts">AI tools for blog writing & SEO</a>
        <a href="ai-use-case.php?slug=design-graphics">AI tools for image generation & design</a>
        <a href="ai-use-case.php?slug=automate-work">AI tools for automation & workflows</a>
        <a href="ai-use-case.php?slug=build-websites">AI tools for coding & development</a>
      </div>

      <div class="footerCol">
        <h3>TOOLS</h3>
        <a href="/submit.php">Submit your AI tool</a>
        <a href="/advertise.php" data-conversion="sponsorship_interest">Sponsored listings</a>
        <a href="/partner.php" data-conversion="partnership_interest">Affiliate partnerships</a>
        <a href="/contact.php" data-conversion="development_interest">Discuss a custom AI development project</a>
        <a href="contact.php">Contact</a>
        <a href="blog.php">Blog</a>
      </div>

      <div class="footerCol">
  <h3>COMPANY</h3>
  <a href="about.php">About AIHubpedia</a>
  <a href="affiliate-disclosure.php">Affiliate Disclosure</a>
  <a href="editorial-policy.php">Editorial Policy</a>
  <a href="partner.php">Partner With Us</a>
  <a href="privacy.php">Privacy policy</a>
  <a href="terms.php">Terms of service</a>
  <a href="impressum.php">Impressum</a>
  <a href="/sitemap.xml">Sitemap</a>
</div>

    </div>

    <!-- ✅ Updated Branding Section -->
    <div class="simpleFooterBrandRow">
      <div class="footerBrandBox">

        <!--<img src="/favicon.png" alt="AIHubpedia" class="footerBrandIcon">-->

        <div>
          <div class="footerBrand">
  <a href="/" aria-label="AIHubpedia Home">
    <img src="/logo.png" alt="AIHubpedia logo" class="footerBrandLogo">
  </a>
</div>
          <div class="footerBrandText">
            Discover, compare, and launch AI tools in one place.
          </div>
        </div>

      </div>

      <div class="footerContact">contact@aihubpedia.com</div>
    </div>

    <div class="simpleFooterBottom">
      <div>
  © <?= date('Y') ?> AIHubpedia<br>
  <span style="font-size:13px;color:#6B7280;">
    A product of <strong>TrustNova Tech</strong> — Germany
  </span>
</div>
      <div class="small footerLegalNote">
        All trademarks, logos, and product names are the property of their respective owners. AIHubpedia is an independent AI tools directory.
      </div>
    </div>
  </div>
</footer>

<!-- ✅ Add this CSS (if not already in style.css) -->
<style>
.footerBrandBox{
  display:flex;
  align-items:center;
  gap:12px;
}

.footerBrandIcon{
  width:44px;
  height:44px;
  border-radius:10px;
}

.footerBrandLogo{
  height:28px;
  width:auto;
  display:block;
    cursor: pointer;
}
.footerBrand a {
  display: inline-block;
}

.footerBrandText{
  font-size:14px;
  color:#6B7280;
  margin-top:4px;
}

.footerBrandIcon{
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
</style>

<script src="/assets/js/interactions.js?v=9999"></script>
</body>
</html>
