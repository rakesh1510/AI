// Phase 8C database-free frontend check: renders the static synthetic previews (tests/adm_tools_preview.php) at desktop, tablet and mobile,
// light and dark, and checks axe (WCAG 2.x A/AA), horizontal overflow, 44px targets and the skip link. Optional screenshots.
// Usage: node tests/browser_phase8c_preview_test.js PREVIEW_DIR [SHOT_DIR]   (needs playwright; AXE_PATH or axe-core)
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const [dir, shots] = process.argv.slice(2); const axeSrc = fs.readFileSync(process.env.AXE_PATH || require.resolve('axe-core/axe.min.js'), 'utf8');
let p = 0, f = 0; const check = (c, m) => { if (c) p++; else { f++; console.log('  FAIL: ' + m); } };
const sizes = [['desktop', 1280, 800], ['tablet', 768, 1024], ['mobile', 360, 740]];
(async () => {
  const browser = await chromium.launch(); if (shots) fs.mkdirSync(shots, { recursive: true });
  for (const file of fs.readdirSync(dir).filter(x => x.endsWith('.html')).sort()) for (const scheme of ['light', 'dark']) for (const [name, w, h] of sizes) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion: 'reduce', deviceScaleFactor: shots ? 2 : 1 }); const page = await ctx.newPage(); const tag = `${file} ${scheme}/${name}`;
    await page.goto('file://' + path.resolve(dir, file)); await page.addScriptTag({ content: axeSrc });
    const v = await page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] } })).violations.map(x => x.id + ':' + x.nodes.length));
    check(v.length === 0, `${tag}: axe violations ${JSON.stringify(v)}`);
    const over = await page.evaluate(() => { const vw = document.documentElement.clientWidth; if (document.documentElement.scrollWidth <= vw + 1) return []; return [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.right > vw + 1 && !e.closest('.scroll') && r.width > 0; }).slice(0, 4).map(e => e.tagName + '.' + (e.className || '')); });
    check(over.length === 0, `${tag}: horizontal overflow ${JSON.stringify(over)}`);
    const small = await page.evaluate(() => [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea')].filter(e => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); if (e.type === 'checkbox') return false; return r.width > 0 && cs.visibility !== 'hidden' && (r.height < 43.5 || r.width < 43.5) && !e.classList.contains('skip'); }).map(e => (e.textContent || e.name || e.tagName).trim().slice(0, 30)));
    check(small.length === 0, `${tag}: targets under 44px ${JSON.stringify(small)}`);
    if (shots && ['list.html', 'detail-published.html', 'form-new-errors.html'].includes(file) && scheme === 'light') await page.screenshot({ path: path.join(shots, `${file.replace('.html', '')}-${name}.png`), fullPage: false });
    if (shots && ['categories.html', 'submission-blocked.html', 'featured.html', 'bulk-review.html', 'submissions.html', 'form-edit-curated.html', 'form-edit-classified.html', 'form-new.html', 'detail-curated.html'].includes(file) && scheme === 'light' && name !== 'tablet') await page.screenshot({ path: path.join(shots, `${file.replace('.html', '')}-${name}.png`), fullPage: false });
    if (shots && file.startsWith('fin-') && scheme === 'light' && ((['fin-overview.html'].includes(file)) || (['fin-revenue.html', 'fin-expenses.html', 'fin-targets-scenario.html', 'fin-refunds.html', 'fin-overview-empty.html', 'fin-advertising.html'].includes(file) && name !== 'tablet'))) await page.screenshot({ path: path.join(shots, `${file.replace('.html', '')}-${name}.png`), fullPage: false });
    if (shots && file === 'fin-overview.html' && scheme === 'dark') await page.screenshot({ path: path.join(shots, `fin-overview-dark-${name}.png`), fullPage: false });
    if (shots && file === 'list.html' && scheme === 'dark') await page.screenshot({ path: path.join(shots, `list-dark-${name}.png`), fullPage: false });
    await page.keyboard.press('Tab'); check(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('skip')), `${tag}: first Tab stop is the skip link`);
    await ctx.close();
  }
  await browser.close(); console.log(`browser: PASSED ${p} FAILED ${f}`); process.exit(f ? 1 : 0);
})().catch(e => { console.log('  FAIL: ' + e.message); process.exit(1); });
