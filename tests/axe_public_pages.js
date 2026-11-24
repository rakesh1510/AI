// WCAG A/AA axe run over the whole public-page inventory. AXE_PATH=/path/to/axe.min.js node tests/axe_public_pages.js <baseUrl>
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const pages = JSON.parse(fs.readFileSync(path.join(__dirname, 'public_pages.json'), 'utf8'));
const base = (process.argv[2] || 'http://127.0.0.1:8123').replace(/\/$/, '');
const axeSrc = fs.readFileSync(process.env.AXE_PATH || require.resolve('axe-core/axe.min.js'), 'utf8');
(async () => {
  const browser = await chromium.launch(); let total = 0; const byRule = {};
  for (const [w, h] of [[1440, 900], [390, 844]]) for (const scheme of ['light', 'dark']) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion: 'reduce' }); const page = await ctx.newPage();
    for (const [name, url] of pages) {
      await page.goto(base + url, { waitUntil: 'load' }); await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' });
      await page.evaluate(() => document.querySelectorAll('.reveal').forEach(e => e.classList.add('in')));
      await page.evaluate(axeSrc); const r = await page.evaluate(() => axe.run(document, { runOnly: ['wcag2a', 'wcag2aa'] }));
      for (const v of r.violations) { total += v.nodes.length; const k = `${v.id} [${v.impact}]`; (byRule[k] = byRule[k] || []).push(`${w}/${scheme} ${name}: ${v.nodes[0].target.join(' ').slice(0, 70)}`); }
    }
    await ctx.close();
  }
  await browser.close();
  for (const [k, v] of Object.entries(byRule)) console.log(k, v.length, 'pages\n   e.g.', v.slice(0, 3).join('\n        '));
  console.log('axe violations (nodes):', total); process.exit(total ? 1 : 0);
})();
