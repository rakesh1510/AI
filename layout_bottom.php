</main>

<footer class="footer simpleFooter">
  <div class="wrap simpleFooterInner">
    <div class="simpleFooterGrid">

      <div class="footerCol">
        <h3>DISCOVER</h3>
        <a href="/categories.php">All categories</a>
        <a href="/top.php">Top AI tools</a>
        <a href="/compare.php">Compare tools</a>
        <a href="/guides.php">AI tool guides</a>
        <a href="/comparisons.php">Popular comparisons</a>
        <a href="/leaderboard.php">Leaderboard</a>
      </div>

      <div class="footerCol">
        <h3>USE CASES</h3>
        <a href="/ai-use-case.php?slug=write-blog-posts">AI tools for Writing & content</a>
        <a href="/ai-use-case.php?slug=design-graphics">AI tools for Image & design</a>
        <a href="/ai-use-case.php?slug=automate-work">AI tools for Automation</a>
        <a href="/ai-use-case.php?slug=build-websites">AI tools for Coding & dev</a>
      </div>

      <div class="footerCol">
        <h3>TOOLS</h3>
        <a href="/submit.php">Submit your AI tool</a>
        <a href="/contact.php">Contact</a>
        <a href="/blog.php">Blog</a>
      </div>

      <div class="footerCol">
        <h3>COMPANY</h3>
        <a href="/about.php">About AIHubpedia</a>
        <a href="/methodology.php">Methodology</a>
        <a href="/privacy.php">Privacy policy</a>
        <a href="/terms.php">Terms of service</a>
        <a href="/impressum.php">Impressum</a>
        <a href="/disclaimer.php">Disclaimer</a>
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
    <img src="/assets/images/logo-44.webp" srcset="/assets/images/logo-44.webp 1x, /assets/images/logo-88.webp 2x" width="119" height="28" alt="AIHubpedia logo" class="footerBrandLogo" loading="lazy">
  </a>
</div>
          <div class="footerBrandText">
            Discover, compare, and launch AI tools in one place.
          </div>
        </div>

      </div>

      <div class="footerContact"><a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a></div>
    </div>

    <div class="simpleFooterBottom">
      <div>© <?= date('Y') ?> AIHubpedia — Launch, grow, and discover AI tools faster.</div>
      <div class="small footerLegalNote">
        All trademarks, logos, and product names are the property of their respective owners. AIHubpedia is an independent AI tools directory.
      </div>
    </div>
  </div>
</footer>



<script src="/assets/js/interactions.js?v=<?= h((string)(@filemtime(__DIR__ . '/assets/js/interactions.js') ?: 1)) ?>" defer></script>
</body>
</html>