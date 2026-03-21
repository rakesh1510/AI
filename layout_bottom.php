</main>

<footer class="footer simpleFooter">
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
        <a href="ai-use-case.php?slug=write-blog-posts">AI tools for Writing & content</a>
        <a href="ai-use-case.php?slug=design-graphics">AI tools for Image & design</a>
        <a href="ai-use-case.php?slug=automate-work">AI tools for Automation</a>
        <a href="ai-use-case.php?slug=build-websites">AI tools for Coding & dev</a>
      </div>

      <div class="footerCol">
        <h3>TOOLS</h3>
        <a href="submit.php">Submit your AI tool</a>
        <a href="contact.php">Contact</a>
        <a href="blog.php">Blog</a>
      </div>

      <div class="footerCol">
        <h3>COMPANY</h3>
        <a href="about.php">About AIHubpedia</a>
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
      <div>© <?= date('Y') ?> AIHubpedia — Launch, grow, and discover AI tools faster.</div>
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

<script src="assets/js/interactions.js"></script>
</body>
</html>