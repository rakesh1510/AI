// Unified Admin Control Center shell, DATABASE-FREE browser check on static synthetic previews: axe (WCAG 2.x A/AA), overflow, 44px targets, skip link, environment banner colours,
// the mobile drawer (open, focus, Escape, scrim, focus trap), and remembered group collapse on desktop. Optional screenshots.
// Usage: node tests/browser_unified_shell_test.js PREVIEW_DIR [SHOT_DIR]   (needs playwright; AXE_PATH or axe-core)
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const [dir, shots] = process.argv.slice(2); const axeSrc = fs.readFileSync(process.env.AXE_PATH || require.resolve('axe-core/axe.min.js'), 'utf8');
let p = 0, f = 0; const check = (c, m) => { if (c) p++; else { f++; console.log('  FAIL: ' + m); } };
const sizes = [['desktop', 1280, 800], ['tablet', 768, 1024], ['mobile', 360, 740]];
const shotNames = { 'shell-owner-staging': ['desktop', 'tablet', 'mobile'], 'shell-owner-production': ['desktop'], 'shell-editor-tools': ['mobile'], 'shell-account-owner': ['desktop', 'mobile'], 'signin': ['desktop', 'mobile'] };
(async () => {
  const browser = await chromium.launch(); if (shots) fs.mkdirSync(shots, { recursive: true });
  for (const file of fs.readdirSync(dir).filter(x => x.endsWith('.html')).sort()) for (const scheme of ['light', 'dark']) for (const [name, w, h] of sizes) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion: 'reduce', deviceScaleFactor: shots ? 2 : 1 }); const page = await ctx.newPage(); const tag = `${file} ${scheme}/${name}`; const base = file.replace('.html', '');
    await page.goto('file://' + path.resolve(dir, file)); await page.waitForTimeout(60);
    if (shots && scheme === 'light' && (shotNames[base] || []).includes(name)) await page.screenshot({ path: path.join(shots, `${base}-${name}.png`) });
    if (shots && scheme === 'dark' && base === 'shell-owner-staging' && name === 'desktop') await page.screenshot({ path: path.join(shots, `${base}-dark-${name}.png`) });
    await page.addScriptTag({ content: axeSrc });
    const v = await page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] } })).violations.map(x => x.id + ':' + x.nodes.length)); check(v.length === 0, `${tag}: axe violations ${JSON.stringify(v)}`);
    const over = await page.evaluate(() => { const vw = document.documentElement.clientWidth; if (document.documentElement.scrollWidth <= vw + 1) return []; return [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.right > vw + 1 && !e.closest('.scroll') && r.width > 0; }).slice(0, 4).map(e => e.tagName + '.' + (e.className || '')); }); check(over.length === 0, `${tag}: horizontal overflow ${JSON.stringify(over)}`);
    const small = await page.evaluate(() => [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,summary')].filter(e => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); if (e.closest('details:not([open]) > :not(summary)')) return false; return r.width > 0 && cs.visibility !== 'hidden' && (r.height < 43.5 || r.width < 43.5) && !e.classList.contains('skip'); }).map(e => (e.textContent || e.name || e.tagName).trim().slice(0, 30))); check(small.length === 0, `${tag}: targets under 44px ${JSON.stringify(small)}`);
    await page.keyboard.press('Tab'); check(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('skip')), `${tag}: first Tab stop is the skip link`);
    if (base.startsWith('shell-owner-staging') || base === 'shell-owner-production') {
      const bg = await page.evaluate(() => getComputedStyle(document.querySelector('.admEnv')).backgroundColor); const want = base.endsWith('production') ? 'rgb(185, 28, 28)' : 'rgb(253, 230, 138)'; check(bg === want, `${tag}: environment banner colour ${bg}`);
      check(await page.evaluate(() => !!document.querySelector('.admId') && document.querySelector('.admId').offsetWidth > 0), `${tag}: the signed-in identity is always visible`);
    }
    if (base === 'shell-owner-staging' && scheme === 'light') {
      if (name === 'mobile') {
        const vis = () => page.evaluate(() => { const n = document.getElementById('admNav'); return getComputedStyle(n).visibility !== 'hidden' && n.getBoundingClientRect().right > 0; });
        check(await page.locator('#admMenuBtn').isVisible(), `${tag}: the Menu button is visible on a phone`); check(!(await vis()), `${tag}: the drawer starts closed`);
        await page.click('#admMenuBtn'); await page.waitForTimeout(80); check(await vis() && (await page.getAttribute('#admMenuBtn', 'aria-expanded')) === 'true', `${tag}: the Menu button opens the drawer and sets aria-expanded`);
        check(await page.evaluate(() => document.getElementById('admNav').contains(document.activeElement)), `${tag}: focus moves into the drawer`);
        await page.keyboard.press('Escape'); await page.waitForTimeout(80); check(!(await vis()) && (await page.getAttribute('#admMenuBtn', 'aria-expanded')) === 'false' && await page.evaluate(() => document.activeElement && document.activeElement.id === 'admMenuBtn'), `${tag}: Escape closes the drawer and returns focus to the button`);
        await page.click('#admMenuBtn'); await page.waitForTimeout(80); await page.click('#admMenuClose'); await page.waitForTimeout(80); check(!(await vis()), `${tag}: the Close button closes the drawer`);
        await page.click('#admMenuBtn'); await page.waitForTimeout(80); await page.mouse.click(340, 400); await page.waitForTimeout(80); check(!(await vis()), `${tag}: tapping outside the drawer closes it`);
        await page.click('#admMenuBtn'); await page.waitForTimeout(80); await page.evaluate(() => { const f = document.getElementById('admNav').querySelectorAll('a[href], button, summary'); f[f.length - 1].focus(); }); await page.keyboard.press('Tab'); check(await page.evaluate(() => document.getElementById('admNav').contains(document.activeElement)), `${tag}: Tab stays inside the open drawer`);
        if (shots) await page.screenshot({ path: path.join(shots, 'shell-drawer-open-mobile.png') });
      }
      if (name === 'desktop') {
        check(!(await page.locator('#admMenuBtn').isVisible()), `${tag}: no Menu button on desktop`);
        const g = page.locator('details.admGroup[data-group="money"] > summary'); await g.click(); check(await page.evaluate(() => !document.querySelector('details.admGroup[data-group="money"]').open), `${tag}: a group collapses`);
        await page.reload(); await page.waitForTimeout(80); const kept = await page.evaluate(() => !document.querySelector('details.admGroup[data-group="money"]').open); check(kept, `${tag}: the collapsed state is remembered in this browser`);
        await page.locator('details.admGroup[data-group="money"] > summary').click(); await page.waitForTimeout(100); await page.reload(); await page.waitForTimeout(60); check(await page.evaluate(() => document.querySelector('details.admGroup[data-group="money"]').open), `${tag}: a group can be opened again`);
      }
    }
    await ctx.close();
  }
  await browser.close(); console.log(`browser: PASSED ${p} FAILED ${f}`); process.exit(f ? 1 : 0);
})().catch(e => { console.log('  FAIL: ' + e.message); process.exit(1); });
