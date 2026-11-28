<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
?>
</main>

<?php $__js_app = @filemtime(__DIR__ . '/assets/js/app.js') ?: '1'; ?>
<footer class="siteFooter stage-footer">
  <div class="wrap">
    <?php $__nl = newsletter_launched(); ?>
    <section class="fHero" aria-labelledby="<?= ($footer_newsletter ?? true) !== false ? 'newsTitle' : 'ftCta' ?>">
      <div>
        <?php if (($footer_newsletter ?? true) !== false): ?>
          <span class="eyebrow"><?= ui_icon('mail') ?> Newsletter</span>
          <h2 id="newsTitle"><?= $__nl ? 'Get AIHubpedia updates' : 'Get launch updates' ?></h2>
          <p><?= $__nl ? 'An email when we publish a new guide or notice a notable price or plan change. Free; unsubscribe any time.' : 'The AIHubpedia newsletter has not launched yet. Leave your email to hear when it does. Free; unsubscribe any time.' ?></p>
          <form class="newsForm" method="post" action="/subscribe.php">
            <input type="hidden" name="form_token" value="<?= h(form_token('newsletter')) ?>">
            <input type="hidden" name="source_page" value="<?= h(rel_url(seo_default_canonical())) ?>">
            <div class="hp" aria-hidden="true"><label>Leave empty<input type="text" name="website_url_confirm" tabindex="-1" autocomplete="off"></label></div>
            <label class="sr" for="footerEmail">Email address</label>
            <div class="newsForm__row">
              <input class="input" id="footerEmail" type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
              <button class="btn" type="submit" data-cta="footer_newsletter">Subscribe</button>
            </div>
            <label class="newsForm__consent"><input type="checkbox" name="consent" value="1" required><span>I agree to receive these emails. <a href="/privacy.php">Privacy policy</a></span></label>
          </form>
        <?php else: ?>
          <span class="eyebrow"><?= ui_icon('briefcase') ?> For business</span>
          <h2 id="ftCta">Have a project in mind?</h2>
          <p>TrustNova Tech builds custom AI agents, workflow automation, chatbots, API integrations and custom software. Book a free consultation: tell us your goal and we will reply by email to arrange it if it is a fit.</p>
          <div class="fHero__cta"><a class="btn btn--accent" href="/services.php#inquiry" data-cta="footer_project">Book a Free Consultation</a><a class="btn secondary" href="/advertise.php">Advertise a tool</a></div>
        <?php endif; ?>
      </div>
      <div class="fHero__art" aria-hidden="true"><?= ($footer_newsletter ?? true) !== false ? art_mail() : art_orb() ?></div>
    </section>

    <div class="fCols">
      <div class="fBrand">
        <a class="fLogo brand" href="/" aria-label="AIHubpedia home"><img class="brandMark" src="/assets/images/logo-mark-44.webp" srcset="/assets/images/logo-mark-44.webp 1x, /assets/images/logo-mark-88.webp 2x" width="34" height="34" alt="" loading="lazy"><span class="brandWord" aria-hidden="true">Hubpedia</span></a>
        <p>Discover, compare and choose AI tools, with sources and dates. An independent directory run by TrustNova Tech.</p>
        <a class="fMail" href="mailto:contact@aihubpedia.com"><?= ui_icon('mail') ?> contact@aihubpedia.com</a>
        <?php if (($footer_newsletter ?? true) !== false): ?><div style="margin-top:8px"><a class="btn sm secondary" href="/services.php#inquiry" data-cta="footer_project">Book a Free Consultation</a></div><?php endif; ?>
      </div>
      <nav class="footerCol" aria-label="Discover">
        <h3>Discover</h3>
        <a href="/categories.php">All categories</a>
        <a href="/directory.php">All tools (A to Z)</a>
        <a href="/top.php">Top AI tools</a>
        <a href="/compare.php">Compare tools</a>
        <a href="/guides.php">Guides</a>
        <a href="/recommend.php">Recommendation wizard</a>
      </nav>
      <nav class="footerCol" aria-label="For business">
        <h3>For business</h3>
        <a href="/services.php#inquiry">Book a free consultation</a>
        <a href="/services.php">AI development services</a>
        <a href="/advertise.php">Advertise a tool</a>
        <a href="/partners.php">Partnerships</a>
        <a href="/submit.php">Submit your AI tool</a>
        <a href="/contact.php">Contact</a>
      </nav>
      <nav class="footerCol" aria-label="Company">
        <h3>Company</h3>
        <a href="/about.php">About AIHubpedia</a>
        <a href="/methodology.php">How we review</a>
        <a href="/disclosure.php">Advertising &amp; affiliate disclosure</a>
        <a href="/blog.php">Blog</a>
        <a href="/sitemap.xml">Sitemap</a>
      </nav>
      <nav class="footerCol" aria-label="Legal">
        <h3>Legal</h3>
        <a href="/privacy.php">Privacy policy</a>
        <a href="/terms.php">Terms of service</a>
        <a href="/impressum.php">Impressum</a>
        <a href="/disclaimer.php">Disclaimer</a>
      </nav>
    </div>

    <div class="fBottom">
      <div>© <?= date('Y') ?> AIHubpedia · <button type="button" class="linkBtn js-only" data-privacy-open>Privacy choices</button></div>
      <div class="footerLegalNote">All trademarks, logos and product names are the property of their respective owners. AIHubpedia is an independent AI tools directory and is not affiliated with the listed companies.</div>
    </div>
  </div>
</footer>

<div class="compareTray" data-compare-tray hidden role="region" aria-label="Compare tray">
  <span class="compareTray__label" data-compare-label>Pick one more tool to compare</span>
  <a class="btn sm" data-compare-go href="/compare.php" aria-disabled="true">Compare</a>
  <button type="button" class="btn sm ghost" data-compare-clear>Clear</button>
</div>

<dialog class="privacyDialog" data-privacy-dialog aria-labelledby="privTitle">
  <form method="dialog">
    <h2 id="privTitle">Privacy choices</h2>
    <p class="small">AIHubpedia works without cookies or trackers. Your theme, saved tools, saved shortlists and recently viewed tools are stored only in this browser. If you allow it, we also record anonymous usage events (searches, clicks, comparisons) to improve the site; no name, email or IP address is attached.</p>
    <label class="check"><input type="checkbox" data-consent-toggle> Allow anonymous usage statistics</label>
    <div class="actions"><button class="btn" value="close">Done</button></div>
  </form>
</dialog>

<?php $__js_min = is_file(__DIR__ . '/assets/js/app.min.js') && is_file(__DIR__ . '/assets/js/interactions.min.js'); $__ext = $__js_min ? '.min.js' : '.js'; ?>
<script src="/assets/js/interactions<?= $__ext ?>?v=<?= h((string)(@filemtime(__DIR__ . '/assets/js/interactions' . $__ext) ?: 1)) ?>" defer></script>
<script src="/assets/js/app<?= $__ext ?>?v=<?= h((string)(@filemtime(__DIR__ . '/assets/js/app' . $__ext) ?: 1)) ?>" defer></script>
</body>
</html>
