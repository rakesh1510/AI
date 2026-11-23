<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
?>
</main>

<?php $__js_app = @filemtime(__DIR__ . '/assets/js/app.js') ?: '1'; ?>
<footer class="siteFooter">
  <div class="wrap">
    <?php if (($footer_newsletter ?? true) !== false): $__nl = newsletter_launched(); ?>
    <section class="footerNews footerNews--compact" aria-labelledby="newsTitle">
      <div class="footerNews__text">
        <h2 id="newsTitle"><?= $__nl ? 'Get AIHubpedia updates' : 'Get launch updates' ?></h2>
        <p class="small"><?= $__nl ? 'An email when we publish a new guide or notice a notable price or plan change. Free; unsubscribe any time.' : 'The AIHubpedia newsletter has not launched yet. Leave your email to hear when it does. Free; unsubscribe any time.' ?></p>
      </div>
      <form class="newsForm" method="post" action="/subscribe.php">
        <input type="hidden" name="form_token" value="<?= h(form_token('newsletter')) ?>">
        <input type="hidden" name="source_page" value="<?= h(rel_url(seo_default_canonical())) ?>">
        <div class="hp" aria-hidden="true"><label>Leave empty<input type="text" name="website_url_confirm" tabindex="-1" autocomplete="off"></label></div>
        <label class="sr" for="footerEmail">Email address</label>
        <div class="newsForm__row">
          <input class="input" id="footerEmail" type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
          <button class="btn" type="submit" data-cta="footer_newsletter">Subscribe</button>
        </div>
        <label class="newsForm__consent"><input type="checkbox" name="consent" value="1" required> I agree to receive these emails. <a href="/privacy.php">Privacy policy</a></label>
      </form>
    </section>
    <?php endif; ?>

    <div class="simpleFooterGrid">
      <div class="footerCol">
        <h3>Discover</h3>
        <a href="/categories.php">All categories</a>
        <a href="/directory.php">All tools (A to Z)</a>
        <a href="/top.php">Top AI tools</a>
        <a href="/compare.php">Compare tools</a>
        <a href="/guides.php">Guides</a>
        <a href="/recommend.php">Recommendation wizard</a>
      </div>
      <div class="footerCol">
        <h3>For business</h3>
        <a href="/services.php#inquiry">Discuss your project</a>
        <a href="/services.php">AI development services</a>
        <a href="/advertise.php">Advertise a tool</a>
        <a href="/partners.php">Partnerships</a>
        <a href="/submit.php">Submit your AI tool</a>
        <a href="/contact.php">Contact</a>
      </div>
      <div class="footerCol">
        <h3>Company</h3>
        <a href="/about.php">About AIHubpedia</a>
        <a href="/methodology.php">How we review</a>
        <a href="/disclosure.php">Advertising &amp; affiliate disclosure</a>
        <a href="/blog.php">Blog</a>
        <a href="/sitemap.xml">Sitemap</a>
      </div>
      <div class="footerCol">
        <h3>Legal</h3>
        <a href="/privacy.php">Privacy policy</a>
        <a href="/terms.php">Terms of service</a>
        <a href="/impressum.php">Impressum</a>
        <a href="/disclaimer.php">Disclaimer</a>
      </div>
    </div>

    <div class="footerBrandRow">
      <a href="/" aria-label="AIHubpedia Home"><img src="/assets/images/logo-44.webp" srcset="/assets/images/logo-44.webp 1x, /assets/images/logo-88.webp 2x" width="119" height="28" alt="AIHubpedia logo" class="footerBrandLogo" loading="lazy"></a>
      <p class="small">Discover, compare and choose AI tools, with sources and dates.</p>
      <p class="footerContact"><a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a></p>
    </div>

    <div class="simpleFooterBottom">
      <div>© <?= date('Y') ?> AIHubpedia · <button type="button" class="linkBtn js-only" data-privacy-open>Privacy choices</button></div>
      <div class="small footerLegalNote">All trademarks, logos and product names are the property of their respective owners. AIHubpedia is an independent AI tools directory and is not affiliated with the listed companies.</div>
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
