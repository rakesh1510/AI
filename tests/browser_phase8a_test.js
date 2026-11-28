// Phase 8A Control Center browser test: sign in, then axe (WCAG 2.x A/AA), keyboard, overflow and target size at desktop, tablet and mobile, light and dark.
// Usage: node tests/browser_phase8a_test.js BASE_URL USERNAME PASSWORD   (needs playwright and AXE_PATH; BASE_URL serves a test database)
const { chromium } = require('playwright');
const fs = require('fs');
const [base, user, pass] = process.argv.slice(2);
const axeSrc = fs.readFileSync(process.env.AXE_PATH || require.resolve('axe-core/axe.min.js'), 'utf8');
let pass_n = 0, fail_n = 0;
const check = (c, m) => { if (c) pass_n++; else { fail_n++; console.log('  FAIL: ' + m); } };
const sizes = [['desktop', 1280, 800], ['tablet', 768, 1024], ['mobile', 360, 740]];
(async () => {
  const browser = await chromium.launch();
  // sign in ONCE (the sign-in limiter allows 5 attempts per 15 minutes) and reuse the session for every viewport and colour scheme
  const lc = await browser.newContext({ viewport: { width: 1280, height: 800 } }); const lp = await lc.newPage();
  await lp.goto(base + '/admin-center.php'); await lp.waitForTimeout(1300); await lp.fill('#admUser', user); await lp.fill('#admPw', pass); await lp.press('#admPw', 'Enter'); await lp.waitForLoadState('load');
  const state = await lc.storageState(); await lc.close();
  for (const scheme of ['light', 'dark']) for (const [name, w, h] of sizes) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion: 'reduce', storageState: state });
    const page = await ctx.newPage(); await page.goto(base + '/admin-center.php');
    const tag = `${scheme}/${name}`;
    check(await page.locator('nav[aria-label="Control Center sections"]').count() === 1, `${tag}: signed in and navigation present`);
    for (const path of ['/admin-center.php', '/admin-center.php?m=finance', ...(process.env.EXTRA_PATHS || '').split(',').filter(Boolean)]) {
      await page.goto(base + path);
      await page.addScriptTag({ content: axeSrc });
      const v = await page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] } })).violations.map(x => x.id + ':' + x.nodes.length));
      check(v.length === 0, `${tag} ${path}: axe violations ${JSON.stringify(v)}`);
      const over = await page.evaluate(() => { const vw = document.documentElement.clientWidth; if (document.documentElement.scrollWidth <= vw + 1) return []; return [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.right > vw + 1 && !e.closest('.scroll') && r.width > 0; }).slice(0, 4).map(e => e.tagName + '.' + (e.className || '') + ' right=' + Math.round(e.getBoundingClientRect().right)); });
      check(over.length === 0, `${tag} ${path}: no horizontal page overflow ${JSON.stringify(over)}`);
      const small = await page.evaluate(() => [...document.querySelectorAll('a[href],button,input,select')].filter(e => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && cs.visibility !== 'hidden' && (r.height < 43.5 || r.width < 43.5) && !e.classList.contains('skip'); }).map(e => (e.textContent || e.name || e.tagName).trim().slice(0, 30)));
      check(small.length === 0, `${tag} ${path}: targets under 44px ${JSON.stringify(small)}`);
    }
    // keyboard: the first Tab stop is the skip link and it moves focus to main
    await page.goto(base + '/admin-center.php'); await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('skip')), `${tag}: first Tab stop is the skip link`);
    await page.keyboard.press('Enter'); check(await page.evaluate(() => document.activeElement && document.activeElement.id === 'main'), `${tag}: skip link moves focus to the main content`);
    check(await page.evaluate(() => !!document.querySelector('[aria-current="page"]')), `${tag}: current section is marked`);
    await ctx.close();
  }
  await browser.close();
  console.log(`browser: PASSED ${pass_n} FAILED ${fail_n}`); process.exit(fail_n ? 1 : 0);
})().catch(e => { console.log('  FAIL: ' + e.message); process.exit(1); });
