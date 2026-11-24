// DB-free browser validation of the premium UI. Run against a local `php -S`: node tests/browser_premium_ui_test.js http://127.0.0.1:8123 [shotDir]
const { chromium } = require('playwright');
const base = (process.argv[2] || 'http://127.0.0.1:8123').replace(/\/$/, '');
const shotDir = process.argv[3] || '';
let pass = 0, fail = 0;
const check = (ok, msg) => { if (ok) pass++; else { fail++; console.log('FAIL', msg); } };
const pages = ['/', '/categories.php', '/compare.php', '/guides.php', '/business.php', '/advertise.php', '/services.php', '/contact.php', '/directory.php', '/search.php?q=chat'];
(async () => {
  const browser = await chromium.launch();
  for (const [vw, vh] of [[1440, 900], [390, 844], [360, 800]]) {
    for (const scheme of ['light', 'dark']) {
      const ctx = await browser.newContext({ viewport: { width: vw, height: vh }, colorScheme: scheme });
      const page = await ctx.newPage();
      const P = `${vw}/${scheme}`;
      for (const path of pages) {
        const r = await page.goto(base + path, { waitUntil: 'load' });
        if (r.status() >= 400 && !['/search.php?q=chat'].includes(path)) { check(false, `[${P}] ${path} status ${r.status()}`); continue; }
        const m = await page.evaluate(() => ({
          ov: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          uni: [...document.querySelectorAll('header a, footer a, nav a')].filter(a => /universe|graph\.php|heatmap|lineage|battle/i.test(a.getAttribute('href') || '')).length,
          hdr: !!document.querySelector('.siteHeader'), cta: !!document.querySelector('.hdrCta'),
          small: [...document.querySelectorAll('.siteHeader a, .siteHeader button, .siteHeader summary')].filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && (b.height < 43.5 || b.width < 43.5) && getComputedStyle(e).visibility !== 'hidden'; }).map(e => (e.className || e.tagName) + ':' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height)).slice(0, 4),
          ph: /Ad placeholder/i.test(document.body.innerText),
        }));
        check(m.ov <= 0, `[${P}] ${path} horizontal overflow ${m.ov}`);
        check(m.uni === 0, `[${P}] ${path} Universe link in nav/footer`);
        check(m.hdr && m.cta, `[${P}] ${path} header + CTA present`);
        check(!m.ph, `[${P}] ${path} ad placeholder`);
        if (m.small.length) check(false, `[${P}] ${path} header targets <44px: ${m.small}`);
      }
      // homepage
      await page.goto(base + '/', { waitUntil: 'load' });
      await page.waitForTimeout(600);
      const h = await page.evaluate(() => ({
        cards: document.querySelectorAll('#discover .tCard, .discovery .tCard').length || document.querySelectorAll('main .tCard').length,
        h1: document.querySelector('h1') && document.querySelector('h1').innerText,
        hidden: [...document.querySelectorAll('main section')].filter(s => s.getBoundingClientRect().height < 20).length,
      }));
      check(h.cards <= 12 && h.cards >= 6, `[${P}] homepage tool cards ${h.cards}`);
      check(/right AI tool/i.test(h.h1 || ''), `[${P}] h1 text`);
      check(h.hidden === 0, `[${P}] empty sections ${h.hidden}`);
      // menus
      if (vw >= 1181) {
        const sum = page.locator('.siteMore__details > summary');
        await sum.click();
        const box = await page.locator('.siteMore__panel').boundingBox();
        check(box && box.y + box.height <= vh && box.x >= 0 && box.x + box.width <= vw, `[${P}] More panel fits viewport`);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Escape');
        check(await page.locator('.siteMore__details[open]').count() === 0, `[${P}] Escape closes More`);
      } else {
        const t = page.locator('.navToggle'); await t.click();
        check(await t.getAttribute('aria-expanded') === 'true', `[${P}] drawer opens`);
        const dr = await page.evaluate(() => { const n = document.querySelector('#siteNav'); const b = n.getBoundingClientRect(); return b.right <= innerWidth + 1 && getComputedStyle(n).display !== 'none'; });
        check(dr, `[${P}] drawer visible within viewport`);
        if (shotDir && scheme === 'light') await page.screenshot({ path: `${shotDir}/menu-${vw}.png` });
        await page.keyboard.press('Escape');
        check(await t.getAttribute('aria-expanded') === 'false', `[${P}] Escape closes drawer`);
      }
      if (shotDir) {
        await page.goto(base + '/', { waitUntil: 'load' }); await page.waitForTimeout(2800);
        await page.screenshot({ path: `${shotDir}/home-${vw}-${scheme}.png`, fullPage: true });
      }
      await ctx.close();
    }
  }
  // v2 page structure
  { const c2 = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const pg = await c2.newPage();
    const cnt = async (u, sel) => { await pg.goto(base + u, { waitUntil: 'load' }); return pg.locator(sel).count(); };
    check(await cnt('/categories.php', '.catTile') >= 10, 'categories: colourful category tiles');
    await pg.fill('[data-cat-filter]', 'writing'); check(await pg.locator('.catTile:visible').count() >= 1 && await pg.locator('.catTile:visible').count() < 5, 'categories: filter narrows the list');
    check(await cnt('/guides.php', '.magFeature') === 1 && await pg.locator('.gCard').count() >= 2, 'guides: featured article and guide cards');
    check(await cnt('/compare.php', '.vsCard') >= 4, 'compare: popular comparison cards');
    check(await cnt('/compare.php?a=chatgpt&b=claude', '.vsHead .lt') === 2 && await pg.locator('h1').count() === 1, 'compare: head-to-head with one h1');
    check(await cnt('/business.php', '.svcTile') >= 4 && await pg.locator('.timeline li').count() === 4 && await pg.locator('#request form').count() >= 1, 'business: services, timeline and enquiry form');
    check(await cnt('/advertise.php', '.priceCard') === 4 && await pg.locator('.priceCard--rec').count() === 1 && await pg.locator('table.pkgTable').count() === 1, 'advertise: four price cards, one recommended, comparison table below');
    check(await pg.locator('.priceCard .artPlace').count() === 4, 'advertise: placement previews');
    await c2.close(); }
  // search + compare, form validation, reduced motion, no-JS
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  await page.goto(base + '/', { waitUntil: 'load' });
  const rm = await page.evaluate(() => [...document.querySelectorAll('.reveal')].every(e => getComputedStyle(e).opacity === '1'));
  check(rm, 'reduced motion: reveal content visible');
  const q = page.locator('.heroCopy input[type=search], .searchBox input').first();
  await q.fill('chat'); await q.press('Enter'); await page.waitForLoadState('load');
  check(/q=chat/.test(page.url()) && await page.locator('.tCard').count() > 0, 'search returns cards');
  await page.goto(base + '/services.php', { waitUntil: 'load' });
  const f = page.locator('#inquiry form').first();
  await f.locator('button[type=submit]').click();
  check(/services|inquiry/.test(page.url()), 'empty submit stays on page (validation)');
  const nojs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
  const np = await nojs.newPage(); await np.goto(base + '/', { waitUntil: 'load' });
  check(await np.evaluate(() => [...document.querySelectorAll('main section, main .reveal')].every(e => getComputedStyle(e).opacity !== '0')), 'no-JS: nothing hidden');
  await browser.close();
  console.log(`premium ui: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
